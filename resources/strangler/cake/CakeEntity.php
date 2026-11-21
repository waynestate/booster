<?php

namespace App\Http\Controllers\Legacy\Cake;

use Cake\Utility\Inflector;

/**
 * Written by Booster's strangler (cakephp-to-laravel): the CakePHP entity
 * API on a generated Eloquent model, so ported entity code (accessors,
 * mutators, `$_accessible`, `$_fields`) and moved templates read and write
 * it the way they did: `_getX()` and `_setX()` run on property reads and
 * writes, values carry CakePHP's types (an int, a bool, a
 * `Cake\I18n\DateTime`), associations are plain arrays of entities, and
 * errors and the new flag work as `getErrors()` and `isNew()` did.
 *
 * Only CakeTable saves the model, so fields that are not columns (a
 * `tag_string`) and associations never reach the database by mistake.
 */
trait CakeEntity
{
    /** @var array<string, mixed> associations, by property */
    public array $cakeAssociated = [];

    /** @var array<string, array<string, string>> */
    public array $cakeErrors = [];

    /** @var array<string, mixed> */
    public array $cakeInvalid = [];

    /** @var array<string, true> */
    public array $cakeDirty = [];

    public bool $cakeNew = true;

    public string $cakeSource = '';

    /** The legacy app's database (CakeDatabase), not the Laravel half's default connection. */
    public function getConnectionName(): ?string
    {
        return CakeDatabase::name();
    }

    public function isNew(): bool
    {
        return $this->cakeNew;
    }

    public function setNew(bool $new): static
    {
        $this->cakeNew = $new;

        return $this;
    }

    public function getSource(): string
    {
        return $this->cakeSource;
    }

    /**
     * @return array<string, mixed>
     */
    public function getErrors(): array
    {
        return $this->cakeErrors;
    }

    /**
     * @return array<string, string>
     */
    public function getError(string $field): array
    {
        return $this->cakeErrors[$field] ?? [];
    }

    /**
     * @param array<string, array<string, string>> $errors
     */
    public function setErrors(array $errors, bool $overwrite = false): static
    {
        foreach ($errors as $field => $messages) {
            $this->cakeErrors[$field] = $overwrite ? $messages : ($messages + ($this->cakeErrors[$field] ?? []));
        }

        return $this;
    }

    /**
     * @param array<string, string>|string $errors
     */
    public function setError(string $field, array|string $errors, bool $overwrite = false): static
    {
        return $this->setErrors([$field => (array) $errors], $overwrite);
    }

    public function hasErrors(bool $includeNested = true): bool
    {
        if (array_filter($this->cakeErrors) !== []) {
            return true;
        }
        if ($includeNested) {
            foreach ($this->cakeAssociated as $value) {
                foreach (is_array($value) ? $value : [$value] as $item) {
                    if (is_object($item) && method_exists($item, 'hasErrors') && $item->hasErrors()) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function getInvalidField(string $field): mixed
    {
        return $this->cakeInvalid[$field] ?? null;
    }

    public function setInvalidField(string $field, mixed $value): static
    {
        $this->cakeInvalid[$field] = $value;

        return $this;
    }

    /**
     * @param array<string, mixed>|string $field
     * @param array<string, mixed> $options
     */
    public function set(array|string $field, mixed $value = null, array $options = []): static
    {
        if (is_string($field)) {
            $field = [$field => $value];
        } else {
            $options = is_array($value) ? $value : [];
        }
        $guard = (bool) ($options['guard'] ?? false);
        foreach ($field as $name => $v) {
            $name = (string) $name;
            if ($guard && !$this->isAccessible($name)) {
                continue;
            }
            $this->cakeSet($name, $v, (bool) ($options['setter'] ?? true));
        }

        return $this;
    }

    public function get(string $field): mixed
    {
        return $this->cakeGet($field);
    }

    public function has(string|array $field): bool
    {
        foreach ((array) $field as $name) {
            if ($this->cakeRaw($name) === null && !$this->hasGetter($name)) {
                return false;
            }
        }

        return true;
    }

    public function hasValue(string $field): bool
    {
        $value = $this->cakeGet($field);

        return $value !== null && $value !== '' && $value !== [];
    }

    public function isDirty($attributes = null)
    {
        return $attributes === null ? $this->cakeDirty !== [] : isset($this->cakeDirty[(string) $attributes]);
    }

    public function setDirty(string $field, bool $dirty = true): static
    {
        if ($dirty) {
            $this->cakeDirty[$field] = true;
        } else {
            unset($this->cakeDirty[$field]);
        }

        return $this;
    }

    public function clean(): void
    {
        $this->cakeDirty = [];
        $this->cakeErrors = [];
        $this->cakeInvalid = [];
    }

    public function isAccessible(string $field): bool
    {
        $accessible = property_exists($this, '_accessible') ? $this->_accessible : [];
        if (array_key_exists($field, $accessible)) {
            return (bool) $accessible[$field];
        }

        return (bool) ($accessible['*'] ?? false);
    }

    /**
     * @param array<string, bool> $fields
     */
    public function cakeWithAccessible(array $fields): static
    {
        if (property_exists($this, '_accessible')) {
            $this->_accessible = $fields + $this->_accessible;
        }

        return $this;
    }

    /**
     * Fields and associations, the way CakePHP's `$this->_fields` held them.
     *
     * @return array<string, mixed>
     */
    public function cakeFields(): array
    {
        return $this->attributes + $this->cakeAssociated;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        $hidden = property_exists($this, '_hidden') ? $this->_hidden : [];
        $virtual = property_exists($this, '_virtual') ? $this->_virtual : [];
        foreach (array_merge(array_keys($this->cakeFields()), $virtual) as $field) {
            if (in_array($field, $hidden, true)) {
                continue;
            }
            $value = $this->cakeGet($field);
            if (is_array($value)) {
                $value = array_map(static fn($v) => is_object($v) && method_exists($v, 'toArray') ? $v->toArray() : $v, $value);
            } elseif (is_object($value) && method_exists($value, 'toArray') && in_array(CakeEntity::class, class_uses($value), true)) {
                $value = $value->toArray();
            }
            $out[$field] = $value;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // Eloquent's magic, routed through CakePHP's accessors and mutators.

    public function __get($key)
    {
        if ($key === '_fields') {
            return $this->cakeFields();
        }

        return $this->cakeGet((string) $key);
    }

    public function __set($key, $value)
    {
        $this->cakeSet((string) $key, $value, true);
    }

    public function __isset($key)
    {
        if ($key === '_fields') {
            return true;
        }

        return $this->cakeGet((string) $key) !== null;
    }

    public function __unset($key)
    {
        unset($this->attributes[$key], $this->cakeAssociated[$key]);
    }

    public function offsetGet($offset): mixed
    {
        return $this->cakeGet((string) $offset);
    }

    public function offsetSet($offset, $value): void
    {
        $this->cakeSet((string) $offset, $value, true);
    }

    public function offsetExists($offset): bool
    {
        return $this->cakeGet((string) $offset) !== null;
    }

    public function cakeRaw(string $field): mixed
    {
        if (array_key_exists($field, $this->cakeAssociated)) {
            return $this->cakeAssociated[$field];
        }

        return $this->attributes[$field] ?? null;
    }

    private function hasGetter(string $field): bool
    {
        return method_exists($this, '_get' . Inflector::camelize($field));
    }

    public function cakeGet(string $field): mixed
    {
        $value = $this->cakeRaw($field);
        $getter = '_get' . Inflector::camelize($field);
        if (method_exists($this, $getter)) {
            return $this->{$getter}($value);
        }

        return $value;
    }

    public function cakeSet(string $field, mixed $value, bool $setter = true): void
    {
        $method = '_set' . Inflector::camelize($field);
        if ($setter && method_exists($this, $method)) {
            $value = $this->{$method}($value);
        }
        $this->cakeDirty[$field] = true;
        if (is_array($value) || (is_object($value) && in_array(CakeEntity::class, class_uses($value), true))) {
            $this->cakeAssociated[$field] = $value;
            unset($this->attributes[$field]);

            return;
        }
        unset($this->cakeAssociated[$field]);
        $this->attributes[$field] = $value;
    }

    /**
     * The row as CakeTable read it, with CakePHP's types.
     *
     * @param array<string, mixed> $row
     */
    public function cakeHydrate(array $row, string $source): static
    {
        $this->attributes = $row;
        $this->cakeNew = false;
        $this->exists = true;
        $this->cakeSource = $source;
        $this->cakeDirty = [];

        return $this;
    }

    public function __toString(): string
    {
        return (string) json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}
