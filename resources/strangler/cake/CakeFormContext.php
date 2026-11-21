<?php

namespace App\Http\Controllers\Legacy\Cake;

use Illuminate\Database\Eloquent\Model;

/**
 * Written by Booster's strangler (cakephp-to-laravel): CakePHP's
 * EntityContext (and NullContext without an entity) for CakeForm: field
 * values, types, required flags, the required message, maxlength and
 * errors, from a moved entity and its copied Table.
 */
final class CakeFormContext
{
    private ?Model $entity;

    private ?CakeTable $table = null;

    public function __construct(mixed $context)
    {
        if ($context !== null && !$context instanceof Model) {
            throw new \LogicException('Booster: FormHelper::create() with a context that is not an entity is not covered; port this form by hand.');
        }
        $this->entity = $context;
        if ($context !== null && method_exists($context, 'getSource') && $context->getSource() !== '') {
            $this->table = CakeTable::for($context->getSource());
        }
    }

    public function isCreate(): bool
    {
        return $this->entity === null || !method_exists($this->entity, 'isNew') || $this->entity->isNew();
    }

    public function isPrimaryKey(string $field): bool
    {
        return $this->table !== null && !str_contains($field, '.') && $field === $this->table->getPrimaryKey();
    }

    public function type(string $field): ?string
    {
        if ($this->table === null || str_contains($field, '.')) {
            return null;
        }

        return $this->table->schema()[$field] ?? null;
    }

    /**
     * @return array{length?: int|null, precision?: int|null}
     */
    public function attributes(string $field): array
    {
        return [];
    }

    public function isRequired(string $field): ?bool
    {
        if ($this->table === null || str_contains($field, '.')) {
            return null;
        }
        $validator = $this->table->getValidator();
        if (!$validator->hasField($field)) {
            return null;
        }
        if ($this->type($field) !== 'boolean') {
            return !$validator->isEmptyAllowed($field, $this->isCreate());
        }

        return false;
    }

    public function getRequiredMessage(string $field): ?string
    {
        if ($this->table === null || str_contains($field, '.')) {
            return null;
        }

        return $this->table->getValidator()->requiredMessage($field);
    }

    public function getMaxLength(string $field): ?int
    {
        if ($this->table === null || str_contains($field, '.')) {
            return null;
        }
        $max = $this->table->getValidator()->maxLengthOf($field);
        if ($max !== null) {
            return $max;
        }

        return $this->columnLength($field);
    }

    private function columnLength(string $field): ?int
    {
        if ($this->table === null) {
            return null;
        }
        foreach (\Illuminate\Support\Facades\Schema::getColumns($this->table->getTable()) as $column) {
            if ($column['name'] === $field && preg_match('/^(?:var)?char\((\d+)\)$/i', (string) ($column['type'] ?? ''), $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function val(string $field, array $options = []): mixed
    {
        if ($this->entity === null) {
            return $options['default'] ?? null;
        }
        $parts = explode('.', $field);
        if (count($parts) === 2 && $parts[1] === '_ids') {
            $items = $this->entity->cakeGet($parts[0]);
            if (!is_array($items)) {
                return null;
            }
            $ids = [];
            foreach ($items as $item) {
                $ids[] = is_object($item) && method_exists($item, 'cakeRaw') ? $item->cakeRaw('id') : $item;
            }

            return $ids;
        }
        if (count($parts) > 1) {
            $value = $this->entity->cakeGet($parts[0]);
            foreach (array_slice($parts, 1) as $part) {
                $value = is_object($value) && method_exists($value, 'cakeGet') ? $value->cakeGet($part) : data_get($value, $part);
            }

            return $value;
        }
        $invalid = $this->entity->getInvalidField($field);
        if ($invalid !== null) {
            return $invalid;
        }
        if ($this->entity->has($field)) {
            return $this->entity->cakeGet($field);
        }
        if (($options['schemaDefault'] ?? true) && $this->isCreate() && $this->table !== null) {
            foreach (\Illuminate\Support\Facades\Schema::getColumns($this->table->getTable()) as $column) {
                if ($column['name'] === $field && $column['default'] !== null) {
                    return $options['default'] ?? trim((string) $column['default'], "'");
                }
            }
        }

        return $options['default'] ?? null;
    }

    public function hasError(string $field): bool
    {
        return $this->error($field) !== [];
    }

    /**
     * @return array<string, string>
     */
    public function error(string $field): array
    {
        if ($this->entity === null || str_contains($field, '.')) {
            return [];
        }

        return $this->entity->getError($field);
    }
}
