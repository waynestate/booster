<?php

namespace App\Http\Controllers\Legacy\Cake;

use Illuminate\Database\Eloquent\Model;

/**
 * Written by Booster's strangler (cakephp-to-laravel): CakePHP's
 * RulesChecker for a copied Table's buildRules(), with the two rules apps
 * use most: isUnique and existsIn. The errors land where CakePHP put them
 * (`field => [_isUnique => message]`). Anything else throws when added.
 */
final class CakeRulesChecker
{
    /** @var list<array{kind: string, fields: list<string>, target: string|null, message: string|null, errorField: string, on: string|null, allowMultipleNulls: bool}> */
    private array $rules = [];

    /** Only isUnique and existsIn objects are covered. */
    public function isUnique(array $fields, array|string|null $message = null): CakeRule
    {
        $options = is_array($message) ? $message : [];
        $text = is_string($message) ? $message : ($options['message'] ?? null);

        return new CakeRule('isUnique', array_values($fields), null, $text, (bool) ($options['allowMultipleNulls'] ?? false));
    }

    public function existsIn(array|string $fields, string $table, array|string|null $message = null): CakeRule
    {
        $options = is_array($message) ? $message : [];
        $text = is_string($message) ? $message : ($options['message'] ?? null);

        return new CakeRule('existsIn', array_values((array) $fields), $table, $text, (bool) ($options['allowNullableNulls'] ?? false));
    }

    /**
     * @param array<string, mixed>|string|null $name
     * @param array<string, mixed> $options
     */
    public function add(mixed $rule, array|string|null $name = null, array $options = []): static
    {
        return $this->push($rule, $name, $options, null);
    }

    /**
     * @param array<string, mixed>|string|null $name
     * @param array<string, mixed> $options
     */
    public function addCreate(mixed $rule, array|string|null $name = null, array $options = []): static
    {
        return $this->push($rule, $name, $options, 'create');
    }

    /**
     * @param array<string, mixed>|string|null $name
     * @param array<string, mixed> $options
     */
    public function addUpdate(mixed $rule, array|string|null $name = null, array $options = []): static
    {
        return $this->push($rule, $name, $options, 'update');
    }

    /**
     * @param array<string, mixed>|string|null $name
     * @param array<string, mixed> $options
     */
    private function push(mixed $rule, array|string|null $name, array $options, ?string $on): static
    {
        if (!$rule instanceof CakeRule) {
            throw new \LogicException('Booster: an application rule other than isUnique or existsIn is not covered; port buildRules() by hand.');
        }
        if (is_array($name)) {
            $options = $name;
        }
        $this->rules[] = [
            'kind' => $rule->kind,
            'fields' => $rule->fields,
            'target' => $rule->target,
            'message' => $options['message'] ?? $rule->message,
            'errorField' => (string) ($options['errorField'] ?? $rule->fields[0]),
            'on' => $on,
            'allowMultipleNulls' => $rule->nulls,
        ];

        return $this;
    }

    /**
     * @param list<mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        throw new \LogicException('Booster: the application rule ' . $method . '() is not covered in the Laravel half; port buildRules() by hand.');
    }

    public function check(Model $entity, string $mode, CakeTable $table): bool
    {
        $ok = true;
        foreach ($this->rules as $rule) {
            if ($rule['on'] !== null && $rule['on'] !== $mode) {
                continue;
            }
            $pass = $rule['kind'] === 'isUnique' ? $this->unique($rule, $entity, $table) : $this->exists($rule, $entity, $table);
            if (!$pass) {
                $ok = false;
                $key = '_' . $rule['kind'];
                $message = $rule['message'] ?? ($rule['kind'] === 'isUnique' ? \Cake\I18n\__d('cake', 'This value is already in use') : \Cake\I18n\__d('cake', 'This value does not exist'));
                $entity->setErrors([$rule['errorField'] => [$key => $message]]);
                $entity->setInvalidField($rule['errorField'], $entity->cakeRaw($rule['errorField']));
            }
        }

        return $ok;
    }

    /**
     * @param array{kind: string, fields: list<string>, target: string|null, message: string|null, errorField: string, on: string|null, allowMultipleNulls: bool} $rule
     */
    private function unique(array $rule, Model $entity, CakeTable $table): bool
    {
        if (!$entity->isNew()) {
            $dirty = false;
            foreach ($rule['fields'] as $field) {
                $dirty = $dirty || $entity->isDirty($field);
            }
            if (!$dirty) {
                return true;
            }
        }
        $query = $table->newModel()->newQuery()->toBase();
        foreach ($rule['fields'] as $field) {
            $value = $entity->cakeRaw($field);
            if ($value === null) {
                if ($rule['allowMultipleNulls']) {
                    return true;
                }
                $query->whereNull($field);
                continue;
            }
            $query->where($field, $table->toDatabase($field, $value));
        }
        if (!$entity->isNew()) {
            $query->where($table->getPrimaryKey(), '!=', $table->toDatabase($table->getPrimaryKey(), $entity->cakeRaw($table->getPrimaryKey())));
        }

        return !$query->exists();
    }

    /**
     * @param array{kind: string, fields: list<string>, target: string|null, message: string|null, errorField: string, on: string|null, allowMultipleNulls: bool} $rule
     */
    private function exists(array $rule, Model $entity, CakeTable $table): bool
    {
        $values = [];
        foreach ($rule['fields'] as $field) {
            $value = $entity->cakeRaw($field);
            if ($value === null) {
                // CakePHP's existsIn passes when a nullable key is null.
                return true;
            }
            $values[] = $value;
        }
        $targetName = (string) $rule['target'];
        try {
            $assoc = $table->association($targetName);
            $target = CakeTable::for($assoc['target']);
        } catch (\LogicException) {
            $target = CakeTable::for($targetName);
        }
        $query = $target->newModel()->newQuery()->toBase();
        $keys = (array) $target->getPrimaryKey();
        foreach ($values as $i => $value) {
            $query->where($keys[$i] ?? $keys[0], $target->toDatabase($keys[$i] ?? $keys[0], $value));
        }

        return $query->exists();
    }
}
