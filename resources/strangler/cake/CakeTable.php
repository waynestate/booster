<?php

namespace App\Http\Controllers\Legacy\Cake;

use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\Utility\Inflector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Written by Booster's strangler (cakephp-to-laravel): the part of
 * CakePHP's Table that moved actions use, over the generated Eloquent
 * models. Each legacy Table is copied below Legacy/Cake/Model/Table with
 * this class as its parent, so its initialize(), validationDefault(),
 * buildRules() and save hooks run as they were written.
 *
 * Covered: setTable, setPrimaryKey, setDisplayField, the Timestamp
 * behavior, belongsTo, hasOne, hasMany and belongsToMany (one level of
 * contain, belongsToMany saved with the replace strategy), find('all'),
 * find('list'), custom finders, findBy<Field>, get, newEmptyEntity,
 * newEntity, patchEntity, save, delete and exists. Anything else throws, so
 * a moved action never half works. Removed by the finish step once the
 * models are idiomatic Eloquent.
 */
abstract class CakeTable
{
    /** @var array<string, CakeTable> */
    private static array $registry = [];

    protected string $cakeAlias;

    protected string $cakeTable;

    protected string $cakePrimaryKey = 'id';

    protected ?string $cakeDisplayField = null;

    /** @var array<string, array<string, mixed>> */
    protected array $cakeBehaviors = [];

    /** @var array<string, array{type: string, alias: string, target: string, foreignKey: string, bindingKey: string, targetForeignKey: string|null, joinTable: string|null, property: string, dependent: bool}> */
    protected array $cakeAssociations = [];

    private ?CakeValidator $cakeValidator = null;

    private ?CakeRulesChecker $cakeRules = null;

    /** @var array<string, string>|null */
    private ?array $cakeSchema = null;

    /** The table for an alias (`Articles`): the copied legacy Table, or a plain one. */
    public static function for(string $alias): CakeTable
    {
        if (!isset(self::$registry[$alias])) {
            $class = __NAMESPACE__ . '\\Model\\Table\\' . $alias . 'Table';
            self::$registry[$alias] = class_exists($class) ? new $class() : new CakePlainTable($alias);
        }

        return self::$registry[$alias];
    }

    public function __construct(?string $alias = null)
    {
        $short = substr((string) strrchr('\\' . static::class, '\\'), 1);
        $this->cakeAlias = $alias ?? (str_ends_with($short, 'Table') ? substr($short, 0, -5) : $short);
        $this->cakeTable = Inflector::underscore($this->cakeAlias);
        $this->initialize([]);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function initialize(array $config): void {}

    public function setTable(string $table): static
    {
        $this->cakeTable = $table;

        return $this;
    }

    public function getTable(): string
    {
        return $this->cakeTable;
    }

    public function getAlias(): string
    {
        return $this->cakeAlias;
    }

    public function setPrimaryKey(string $key): static
    {
        $this->cakePrimaryKey = $key;

        return $this;
    }

    public function getPrimaryKey(): string
    {
        return $this->cakePrimaryKey;
    }

    public function setDisplayField(string $field): static
    {
        $this->cakeDisplayField = $field;

        return $this;
    }

    public function getDisplayField(): string
    {
        if ($this->cakeDisplayField !== null) {
            return $this->cakeDisplayField;
        }
        $columns = $this->schema();
        foreach (['title', 'name', 'label'] as $field) {
            if (isset($columns[$field])) {
                return $field;
            }
        }

        return $this->cakePrimaryKey;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function addBehavior(string $name, array $options = []): static
    {
        if ($name !== 'Timestamp' || $options !== []) {
            throw new \LogicException('Booster: the ' . $name . ' behavior is not covered in the Laravel half; port ' . $this->cakeAlias . 'Table by hand.');
        }
        $this->cakeBehaviors[$name] = $options;

        return $this;
    }

    public function hasBehavior(string $name): bool
    {
        return isset($this->cakeBehaviors[$name]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function belongsTo(string $alias, array $options = []): static
    {
        return $this->associate('belongsTo', $alias, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function hasOne(string $alias, array $options = []): static
    {
        return $this->associate('hasOne', $alias, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function hasMany(string $alias, array $options = []): static
    {
        return $this->associate('hasMany', $alias, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function belongsToMany(string $alias, array $options = []): static
    {
        return $this->associate('belongsToMany', $alias, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function associate(string $type, string $alias, array $options): static
    {
        $known = ['foreignKey', 'targetForeignKey', 'joinTable', 'className', 'propertyName', 'dependent', 'bindingKey', 'through', 'cascadeCallbacks', 'saveStrategy', 'joinType'];
        foreach (array_keys($options) as $key) {
            if (!in_array($key, $known, true) || in_array($key, ['through', 'cascadeCallbacks', 'bindingKey'], true) || ($key === 'saveStrategy' && $options[$key] !== 'replace') || ($key === 'joinType' && $type !== 'belongsTo')) {
                throw new \LogicException('Booster: the ' . $alias . ' association option ' . $key . ' is not covered; port ' . $this->cakeAlias . 'Table by hand.');
            }
        }
        $target = isset($options['className']) ? (string) preg_replace('/^.*[.\\\\]/', '', (string) $options['className']) : $alias;
        $target = str_ends_with($target, 'Table') ? substr($target, 0, -5) : $target;
        $singular = Inflector::singularize($this->cakeAlias);
        $foreignKey = match ($type) {
            'belongsTo' => Inflector::underscore(Inflector::singularize($alias)) . '_id',
            default => Inflector::underscore($singular) . '_id',
        };
        $property = match ($type) {
            'belongsTo', 'hasOne' => Inflector::underscore(Inflector::singularize($alias)),
            default => Inflector::underscore($alias),
        };
        $joinTable = null;
        if ($type === 'belongsToMany') {
            $pair = [Inflector::underscore($this->cakeAlias), Inflector::underscore($alias)];
            sort($pair, SORT_STRING);
            $joinTable = (string) ($options['joinTable'] ?? implode('_', $pair));
        }
        $this->cakeAssociations[$alias] = [
            'type' => $type,
            'alias' => $alias,
            'target' => $target,
            'foreignKey' => (string) ($options['foreignKey'] ?? $foreignKey),
            'bindingKey' => 'id',
            'targetForeignKey' => $type === 'belongsToMany' ? (string) ($options['targetForeignKey'] ?? Inflector::underscore(Inflector::singularize($alias)) . '_id') : null,
            'joinTable' => $joinTable,
            'property' => (string) ($options['propertyName'] ?? $property),
            'dependent' => (bool) ($options['dependent'] ?? false),
        ];

        return $this;
    }

    /** `$this->Tags` on a table: the associated table. */
    public function __get(string $name): CakeTable
    {
        if (!isset($this->cakeAssociations[$name])) {
            throw new \LogicException('Booster: ' . $this->cakeAlias . 'Table has no association ' . $name . '.');
        }

        return self::for($this->cakeAssociations[$name]['target']);
    }

    /**
     * @return array{type: string, alias: string, target: string, foreignKey: string, bindingKey: string, targetForeignKey: string|null, joinTable: string|null, property: string, dependent: bool}
     */
    public function association(string $name): array
    {
        if (!isset($this->cakeAssociations[$name])) {
            throw new \LogicException('Booster: ' . $this->cakeAlias . 'Table has no association ' . $name . ' to contain.');
        }

        return $this->cakeAssociations[$name];
    }

    /** The generated Eloquent model class for this table's rows. */
    public function entityClass(): string
    {
        $class = __NAMESPACE__ . '\\Model\\Entity\\' . Inflector::singularize($this->cakeAlias);

        return class_exists($class) ? $class : CakePlainEntity::class;
    }

    public function newModel(): Model
    {
        $class = $this->entityClass();
        /** @var Model $model */
        $model = new $class();
        $model->setTable($this->cakeTable);
        $model->setKeyName($this->cakePrimaryKey);
        $model->cakeSource = $this->cakeAlias;

        return $model;
    }

    /**
     * Column name to CakePHP type: integer, boolean, datetime, date, float, decimal or string.
     *
     * @return array<string, string>
     */
    public function schema(): array
    {
        if ($this->cakeSchema === null) {
            $this->cakeSchema = [];
            foreach (Schema::getColumns($this->cakeTable) as $column) {
                $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));
                $full = strtolower((string) ($column['type'] ?? $type));
                $this->cakeSchema[(string) $column['name']] = match (true) {
                    $full === 'tinyint(1)' || in_array($type, ['bool', 'boolean'], true) => 'boolean',
                    str_contains($type, 'int') => 'integer',
                    in_array($type, ['datetime', 'timestamp', 'timestamptz', 'datetimetz'], true) => 'datetime',
                    $type === 'date' => 'date',
                    in_array($type, ['float', 'double', 'real'], true) => 'float',
                    in_array($type, ['decimal', 'numeric'], true) => 'decimal',
                    in_array($type, ['text', 'mediumtext', 'longtext', 'clob'], true) => 'text',
                    default => 'string',
                };
            }
        }

        return $this->cakeSchema;
    }

    /** A database value as CakePHP's type layer returns it. */
    public function toPhp(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->schema()[$field] ?? 'string') {
            'integer' => is_numeric($value) ? (int) $value : $value,
            'boolean' => (bool) $value,
            'float' => (float) $value,
            'datetime' => $value instanceof DateTime ? $value : new DateTime((string) $value),
            'date' => $value instanceof Date ? $value : new Date((string) $value),
            default => is_resource($value) ? stream_get_contents($value) : (string) $value,
        };
    }

    /** A PHP value as CakePHP's type layer writes it. */
    public function toDatabase(string $field, mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(($this->schema()[$field] ?? '') === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    /** Request data as CakePHP's marshaller casts it for a column. */
    public function marshal(string $field, mixed $value): mixed
    {
        $type = $this->schema()[$field] ?? null;
        if ($type === null || $value === null || is_array($value)) {
            return $value;
        }

        return match ($type) {
            'integer' => $value === '' ? null : (is_numeric($value) ? (int) $value : $value),
            'float' => $value === '' ? null : (is_numeric($value) ? (float) $value : $value),
            'decimal' => $value === '' ? null : (is_numeric($value) ? (string) $value : $value),
            'boolean' => $value === '' ? null : (in_array($value, [true, 1, '1', 'true', 'on'], true) ? true : (in_array($value, [false, 0, '0', 'false', 'off'], true) ? false : $value)),
            'datetime', 'date' => $value === '' ? null : ($value instanceof \DateTimeInterface ? $value : (is_string($value) ? $this->toPhp($field, $value) : $value)),
            default => is_scalar($value) ? (string) $value : $value,
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    public function hydrate(array $row): Model
    {
        $typed = [];
        foreach ($row as $field => $value) {
            $typed[(string) $field] = $this->toPhp((string) $field, $value);
        }

        return $this->newModel()->cakeHydrate($typed, $this->cakeAlias);
    }

    // ---------------------------------------------------------------- finding

    public function query(): CakeQuery
    {
        return new CakeQuery($this);
    }

    public function find(string $type = 'all', mixed ...$args): CakeQuery
    {
        $query = $this->query();
        if ($type === 'all') {
            return $query;
        }
        if ($type === 'list') {
            return $query->asList($args['keyField'] ?? $this->cakePrimaryKey, $args['valueField'] ?? $this->getDisplayField());
        }
        $method = 'find' . ucfirst($type);
        if (!method_exists($this, $method)) {
            throw new \LogicException('Booster: ' . $this->cakeAlias . 'Table has no finder ' . $type . '.');
        }

        return $this->{$method}($query, ...$args);
    }

    /**
     * findBySlug($slug) and the other magic finders.
     *
     * @param list<mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        if (preg_match('/^findBy(\w+)$/', $method, $m) === 1 && !str_contains($m[1], 'And') && !str_contains($m[1], 'Or')) {
            return $this->query()->where([Inflector::underscore($m[1]) => $args[0] ?? null]);
        }
        throw new \LogicException('Booster: ' . $this->cakeAlias . 'Table::' . $method . '() is not covered in the Laravel half.');
    }

    /**
     * @param array<int|string, string>|string $contain
     */
    public function get(mixed $primaryKey, array|string $contain = [], mixed ...$rest): Model
    {
        if ($rest !== []) {
            throw new \LogicException('Booster: Table::get() options other than contain are not covered.');
        }
        $entity = $this->query()->where([$this->cakeAlias . '.' . $this->cakePrimaryKey => $primaryKey])->contain($contain)->first();
        if ($entity === null) {
            throw new CakeHttpException(404, sprintf('Record not found in table `%s`.', $this->cakeTable), false);
        }

        return $entity;
    }

    /**
     * @param array<string, mixed> $conditions
     */
    public function exists(array $conditions): bool
    {
        return $this->query()->where($conditions)->first() !== null;
    }

    // ---------------------------------------------------------------- entities

    public function newEmptyEntity(): Model
    {
        $entity = $this->newModel();
        $entity->cakeNew = true;

        return $entity;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function newEntity(array $data, array $options = []): Model
    {
        return $this->patchEntity($this->newEmptyEntity(), $data, $options);
    }

    /**
     * CakePHP's Marshaller::merge(): validate first, then set every field
     * that passed and is accessible, through the entity's mutators.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function patchEntity(Model $entity, array $data, array $options = []): Model
    {
        foreach (array_keys($options) as $key) {
            if (!in_array($key, ['accessibleFields', 'validate', 'fields'], true)) {
                throw new \LogicException('Booster: patchEntity() option ' . $key . ' is not covered.');
            }
        }
        if (isset($options['accessibleFields'])) {
            $entity->cakeWithAccessible($options['accessibleFields']);
        }
        $errors = [];
        if (($options['validate'] ?? true) !== false) {
            $errors = $this->getValidator()->validate($data, $entity->isNew(), $this, $entity);
        }
        $entity->setErrors($errors);
        foreach ($data as $field => $value) {
            $field = (string) $field;
            if (isset($errors[$field])) {
                $entity->setInvalidField($field, $value);
                continue;
            }
            if (isset($options['fields']) && !in_array($field, (array) $options['fields'], true)) {
                continue;
            }
            if (!$entity->isAccessible($field)) {
                continue;
            }
            if (isset($this->associationByProperty()[$field])) {
                $value = $this->marshalAssociation($this->associationByProperty()[$field], $value);
                if ($value === null) {
                    continue;
                }
            } else {
                $value = $this->marshal($field, $value);
            }
            if (!$entity->isNew() && !$entity->isDirty($field) && $entity->cakeRaw($field) === $value) {
                continue;
            }
            $entity->set($field, $value);
        }

        return $entity;
    }

    /**
     * @return array<string, array{type: string, alias: string, target: string, foreignKey: string, bindingKey: string, targetForeignKey: string|null, joinTable: string|null, property: string, dependent: bool}>
     */
    private function associationByProperty(): array
    {
        $out = [];
        foreach ($this->cakeAssociations as $assoc) {
            $out[$assoc['property']] = $assoc;
        }

        return $out;
    }

    /**
     * `tags._ids` for a belongsToMany; nested data otherwise is not covered.
     *
     * @param array{type: string, alias: string, target: string, foreignKey: string, bindingKey: string, targetForeignKey: string|null, joinTable: string|null, property: string, dependent: bool} $assoc
     *
     * @return list<Model>|null
     */
    private function marshalAssociation(array $assoc, mixed $value): ?array
    {
        if ($assoc['type'] === 'belongsToMany' && is_array($value) && array_key_exists('_ids', $value)) {
            $ids = array_values(array_filter((array) $value['_ids'], static fn($id): bool => $id !== '' && $id !== null));
            if ($ids === []) {
                return [];
            }
            $target = self::for($assoc['target']);

            return $target->query()->where([$target->getAlias() . '.' . $target->getPrimaryKey() . ' IN' => $ids])->all()->toList();
        }
        if ($value === '' || $value === null) {
            return null;
        }
        throw new \LogicException('Booster: patching the ' . $assoc['alias'] . ' association with nested data is not covered.');
    }

    public function validationDefault(CakeValidator $validator): CakeValidator
    {
        return $validator;
    }

    public function buildRules(CakeRulesChecker $rules): CakeRulesChecker
    {
        return $rules;
    }

    public function getValidator(): CakeValidator
    {
        return $this->cakeValidator ??= $this->validationDefault(new CakeValidator());
    }

    public function rulesChecker(): CakeRulesChecker
    {
        return $this->cakeRules ??= $this->buildRules(new CakeRulesChecker());
    }

    // ---------------------------------------------------------------- saving

    /**
     * CakePHP's Table::save(): errors stop it, then the application rules,
     * beforeSave, the row, the belongsToMany links (replace), afterSave, in
     * one transaction.
     *
     * @param array<string, mixed> $options
     */
    public function save(Model $entity, array $options = []): Model|false
    {
        foreach (array_keys($options) as $key) {
            if (!in_array($key, ['checkRules', 'atomic'], true)) {
                throw new \LogicException('Booster: save() option ' . $key . ' is not covered.');
            }
        }
        if ($entity->hasErrors()) {
            return false;
        }
        if ($entity->isNew() === false && !$entity->isDirty()) {
            return $entity;
        }

        try {
            return DB::transaction(fn(): Model|false => $this->saveRow($entity, $options));
        } catch (CakeRollback) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function saveRow(Model $entity, array $options): Model|false
    {
        {
            $new = $entity->isNew();
            if (($options['checkRules'] ?? true) && !$this->rulesChecker()->check($entity, $new ? 'create' : 'update', $this)) {
                return false;
            }
            $event = new CakeEvent('Model.beforeSave', $this);
            if (method_exists($this, 'beforeSave')) {
                $result = $this->beforeSave($event, $entity, new \ArrayObject($options));
                if ($result === false || $event->isStopped()) {
                    return $event->getResult() instanceof Model ? $event->getResult() : false;
                }
            }
            foreach ($this->cakeAssociations as $assoc) {
                if ($assoc['type'] === 'belongsTo' && $entity->isDirty($assoc['property']) && is_object($entity->cakeRaw($assoc['property']))) {
                    throw new \LogicException('Booster: saving a ' . $assoc['alias'] . ' entity through ' . $this->cakeAlias . ' is not covered.');
                }
            }
            $columns = $this->schema();
            $row = [];
            foreach ($entity->cakeFields() as $field => $value) {
                if (isset($columns[$field]) && ($new || $entity->isDirty($field)) && !is_array($value)) {
                    $row[$field] = $this->toDatabase($field, $value);
                }
            }
            if ($this->hasBehavior('Timestamp')) {
                $now = new DateTime();
                if ($new && isset($columns['created']) && !$entity->isDirty('created')) {
                    $entity->set('created', $now, ['setter' => false]);
                    $row['created'] = $this->toDatabase('created', $now);
                }
                if (isset($columns['modified']) && !$entity->isDirty('modified')) {
                    $entity->set('modified', $now, ['setter' => false]);
                    $row['modified'] = $this->toDatabase('modified', $now);
                }
            }
            $model = $this->newModel();
            if ($new) {
                $id = $model->newQuery()->toBase()->insertGetId($row, $this->cakePrimaryKey);
                if ($entity->cakeRaw($this->cakePrimaryKey) === null) {
                    $entity->set($this->cakePrimaryKey, $this->toPhp($this->cakePrimaryKey, $id), ['setter' => false]);
                }
            } elseif ($row !== []) {
                $model->newQuery()->toBase()->where($this->cakePrimaryKey, $this->toDatabase($this->cakePrimaryKey, $entity->cakeRaw($this->cakePrimaryKey)))->update($row);
            }
            foreach ($this->cakeAssociations as $assoc) {
                if ($assoc['type'] === 'belongsToMany' && $entity->isDirty($assoc['property']) && is_array($entity->cakeRaw($assoc['property']))) {
                    if (!$this->saveLinks($assoc, $entity)) {
                        throw new CakeRollback();
                    }
                } elseif (in_array($assoc['type'], ['hasMany', 'hasOne'], true) && $entity->isDirty($assoc['property'])) {
                    throw new \LogicException('Booster: saving the ' . $assoc['alias'] . ' association through ' . $this->cakeAlias . ' is not covered.');
                }
            }
            if (method_exists($this, 'afterSave')) {
                $this->afterSave(new CakeEvent('Model.afterSave', $this), $entity, new \ArrayObject($options));
            }
            $entity->setNew(false);
            $entity->exists = true;
            $entity->clean();

            return $entity;
        }
    }

    /**
     * @param array{type: string, alias: string, target: string, foreignKey: string, bindingKey: string, targetForeignKey: string|null, joinTable: string|null, property: string, dependent: bool} $assoc
     */
    private function saveLinks(array $assoc, Model $entity): bool
    {
        $target = self::for($assoc['target']);
        $ids = [];
        foreach ((array) $entity->cakeRaw($assoc['property']) as $item) {
            if (!$item instanceof Model) {
                continue;
            }
            if ($item->isNew() || $item->isDirty()) {
                if ($target->save($item) === false) {
                    return false;
                }
            }
            $ids[] = $item->cakeRaw($target->getPrimaryKey());
        }
        $own = $this->toDatabase($this->cakePrimaryKey, $entity->cakeRaw($this->cakePrimaryKey));
        $join = DB::table((string) $assoc['joinTable']);
        $existing = array_map(static fn($v) => (string) $v, $join->clone()->where($assoc['foreignKey'], $own)->pluck((string) $assoc['targetForeignKey'])->all());
        $keep = array_map(static fn($v) => (string) $v, $ids);
        $gone = array_values(array_diff($existing, $keep));
        if ($gone !== []) {
            DB::table((string) $assoc['joinTable'])->where($assoc['foreignKey'], $own)->whereIn((string) $assoc['targetForeignKey'], $gone)->delete();
        }
        foreach (array_values(array_unique(array_diff($keep, $existing))) as $id) {
            DB::table((string) $assoc['joinTable'])->insert([$assoc['foreignKey'] => $own, (string) $assoc['targetForeignKey'] => $id]);
        }

        return true;
    }

    /**
     * CakePHP's Table::delete(): beforeDelete, the belongsToMany links and
     * dependent rows, the row, afterDelete.
     *
     * @param array<string, mixed> $options
     */
    public function delete(Model $entity, array $options = []): bool
    {
        if ($options !== []) {
            throw new \LogicException('Booster: delete() options are not covered.');
        }

        return (bool) DB::transaction(function () use ($entity): bool {
            if (method_exists($this, 'beforeDelete')) {
                $event = new CakeEvent('Model.beforeDelete', $this);
                if ($this->beforeDelete($event, $entity, new \ArrayObject([])) === false || $event->isStopped()) {
                    return false;
                }
            }
            $own = $this->toDatabase($this->cakePrimaryKey, $entity->cakeRaw($this->cakePrimaryKey));
            foreach ($this->cakeAssociations as $assoc) {
                if ($assoc['type'] === 'belongsToMany') {
                    DB::table((string) $assoc['joinTable'])->where($assoc['foreignKey'], $own)->delete();
                } elseif (in_array($assoc['type'], ['hasMany', 'hasOne'], true) && $assoc['dependent']) {
                    self::for($assoc['target'])->newModel()->newQuery()->toBase()->where($assoc['foreignKey'], $own)->delete();
                }
            }
            $deleted = $this->newModel()->newQuery()->toBase()->where($this->cakePrimaryKey, $own)->delete();
            if ($deleted > 0 && method_exists($this, 'afterDelete')) {
                $this->afterDelete(new CakeEvent('Model.afterDelete', $this), $entity, new \ArrayObject([]));
            }

            return $deleted > 0;
        });
    }

    /** Validator provider `table`: validateUnique. */
    public function validateUnique(mixed $value, string $field, Model $entity): bool
    {
        $query = $this->newModel()->newQuery()->toBase()->where($field, $value);
        if (!$entity->isNew()) {
            $query->where($this->cakePrimaryKey, '!=', $this->toDatabase($this->cakePrimaryKey, $entity->cakeRaw($this->cakePrimaryKey)));
        }

        return !$query->exists();
    }
}
