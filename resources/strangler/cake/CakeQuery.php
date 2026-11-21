<?php

namespace App\Http\Controllers\Legacy\Cake;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * Written by Booster's strangler (cakephp-to-laravel): the part of
 * CakePHP's SelectQuery that moved actions use, run through the generated
 * Eloquent model's query builder: where() with CakePHP's condition arrays
 * (`field`, `Alias.field`, `field >`, `field IN`, `field LIKE`, `field IS`,
 * `OR`, `AND`, `NOT`), order() and orderBy(), limit(), page(), contain()
 * one level deep, all(), first(), firstOrFail(), count(), toArray(),
 * find('list') and iterating. Anything else throws.
 *
 * @implements \IteratorAggregate<int|string, mixed>
 */
final class CakeQuery implements \Countable, \IteratorAggregate, \JsonSerializable
{
    private Builder $builder;

    /** @var list<string> */
    private array $contain = [];

    /** @var array{0: string, 1: string}|null */
    private ?array $list = null;

    private ?CakeResultSet $results = null;

    public function __construct(private readonly CakeTable $table)
    {
        $this->builder = $table->newModel()->newQuery()->toBase();
    }

    public function getRepository(): CakeTable
    {
        return $this->table;
    }

    /**
     * @param array<int|string, mixed>|string $conditions
     */
    public function where(array|string $conditions = []): static
    {
        if (is_string($conditions)) {
            throw new \LogicException('Booster: where() with an SQL string is not covered; port this query by hand.');
        }
        $this->conditions($this->builder, $conditions, 'and');
        $this->results = null;

        return $this;
    }

    /**
     * @param array<int|string, mixed>|string $conditions
     */
    public function andWhere(array|string $conditions): static
    {
        return $this->where($conditions);
    }

    /**
     * @param array<int|string, mixed> $conditions
     */
    private function conditions(Builder $q, array $conditions, string $boolean): void
    {
        foreach ($conditions as $key => $value) {
            if (is_int($key)) {
                if (!is_array($value)) {
                    throw new \LogicException('Booster: a where() condition written as SQL is not covered; port this query by hand.');
                }
                $q->where(fn(Builder $nested) => $this->conditions($nested, $value, 'and'), null, null, $boolean);
                continue;
            }
            $upper = strtoupper($key);
            if (in_array($upper, ['OR', 'AND'], true)) {
                $q->where(fn(Builder $nested) => $this->conditions($nested, (array) $value, strtolower($upper)), null, null, $boolean);
                continue;
            }
            if ($upper === 'NOT') {
                $q->whereNot(fn(Builder $nested) => $this->conditions($nested, (array) $value, 'and'), null, null, $boolean);
                continue;
            }
            if (preg_match('/^\s*([\w.]+)\s*(=|!=|<>|<=|>=|<|>|IN|NOT IN|LIKE|NOT LIKE|IS|IS NOT)?\s*$/i', $key, $m) !== 1) {
                throw new \LogicException('Booster: the where() key ' . $key . ' is not covered; port this query by hand.');
            }
            $column = $this->column($m[1]);
            $op = strtoupper($m[2] ?? '=');
            $field = substr((string) strrchr('.' . $m[1], '.'), 1);
            $db = fn($v) => $this->table->toDatabase($field, $v);
            switch ($op) {
                case 'IN':
                case 'NOT IN':
                    $values = array_map($db, array_values((array) $value));
                    $q->whereIn($column, $values, $boolean, $op === 'NOT IN');
                    break;
                case 'IS':
                    $q->whereNull($column, $boolean);
                    break;
                case 'IS NOT':
                    $q->whereNotNull($column, $boolean);
                    break;
                default:
                    if ($value === null) {
                        throw new \LogicException('Booster: where() comparing ' . $key . ' with null is not covered (CakePHP needs IS there).');
                    }
                    if (is_array($value)) {
                        $q->whereIn($column, array_map($db, $value), $boolean, in_array($op, ['!=', '<>'], true));
                        break;
                    }
                    $q->where($column, $op === '<>' ? '!=' : strtolower($op), $db($value), $boolean);
            }
        }
    }

    /** `Articles.title` or `title`: a column of this table (CakePHP's alias is the table here). */
    private function column(string $name): string
    {
        $parts = explode('.', $name);
        if (count($parts) === 2) {
            if ($parts[0] !== $this->table->getAlias()) {
                throw new \LogicException('Booster: a condition on ' . $name . ' (another table) is not covered; port this query by hand.');
            }

            return $this->table->getTable() . '.' . $parts[1];
        }

        return $this->table->getTable() . '.' . $name;
    }

    /**
     * @param array<int|string, string>|string $fields
     */
    public function order(array|string $fields, bool $overwrite = false): static
    {
        return $this->orderBy($fields, $overwrite);
    }

    /**
     * @param array<int|string, string>|string $fields
     */
    public function orderBy(array|string $fields, bool $overwrite = false): static
    {
        if ($overwrite) {
            $this->builder->reorder();
        }
        foreach ((array) $fields as $key => $direction) {
            if (is_int($key)) {
                if (preg_match('/^\s*([\w.]+)(?:\s+(ASC|DESC))?\s*$/i', $direction, $m) !== 1) {
                    throw new \LogicException('Booster: order() by ' . $direction . ' is not covered.');
                }
                $this->builder->orderBy($this->column($m[1]), strtolower($m[2] ?? 'asc'));
                continue;
            }
            $this->builder->orderBy($this->column($key), strtolower($direction) === 'desc' ? 'desc' : 'asc');
        }
        $this->results = null;

        return $this;
    }

    public function orderAsc(string $field): static
    {
        return $this->orderBy([$field => 'ASC']);
    }

    public function orderDesc(string $field): static
    {
        return $this->orderBy([$field => 'DESC']);
    }

    public function limit(?int $limit): static
    {
        $this->builder->limit($limit);
        $this->results = null;

        return $this;
    }

    public function offset(?int $offset): static
    {
        $this->builder->offset($offset);
        $this->results = null;

        return $this;
    }

    public function page(int $page, ?int $limit = null): static
    {
        if ($limit !== null) {
            $this->builder->limit($limit);
        }
        $per = (int) ($this->builder->limit ?? 0);
        $this->builder->offset(($page - 1) * $per);
        $this->results = null;

        return $this;
    }

    /**
     * @param array<int|string, mixed>|string $associations
     */
    public function contain(array|string $associations = [], bool $override = false): static
    {
        if ($override) {
            $this->contain = [];
        }
        foreach ((array) $associations as $key => $value) {
            $name = is_int($key) ? (string) $value : (string) $key;
            if (!is_int($key) || str_contains($name, '.')) {
                throw new \LogicException('Booster: contain() deeper than one level, or with options, is not covered; port this query by hand.');
            }
            $this->table->association($name);
            $this->contain[] = $name;
        }
        $this->results = null;

        return $this;
    }

    public function asList(string $keyField, string $valueField): static
    {
        $this->list = [$keyField, $valueField];

        return $this;
    }

    public function all(): CakeResultSet
    {
        if ($this->results !== null) {
            return $this->results;
        }
        $rows = [];
        foreach ($this->builder->clone()->select($this->table->getTable() . '.*')->get() as $row) {
            $rows[] = $this->table->hydrate((array) $row);
        }
        foreach ($this->contain as $name) {
            $this->load($name, $rows);
        }
        if ($this->list !== null) {
            [$key, $value] = $this->list;
            $out = [];
            foreach ($rows as $row) {
                $out[$row->cakeGet($key)] = $row->cakeGet($value);
            }

            return $this->results = new CakeResultSet($out);
        }

        return $this->results = new CakeResultSet($rows);
    }

    public function first(): mixed
    {
        $clone = clone $this;
        $clone->builder = $this->builder->clone()->limit(1);
        $clone->results = null;

        return $clone->all()->first();
    }

    public function firstOrFail(): mixed
    {
        $row = $this->first();
        if ($row === null) {
            throw new CakeHttpException(404, sprintf('Record not found in table `%s`.', $this->table->getTable()), false);
        }

        return $row;
    }

    public function count(): int
    {
        // Builder::offset(null) is OFFSET 0, which SQLite refuses without a LIMIT: clear both.
        $builder = $this->builder->clone()->reorder();
        $builder->limit = null;
        $builder->offset = null;

        return $builder->count();
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toArray(): array
    {
        return $this->all()->toArray();
    }

    /**
     * @return list<mixed>
     */
    public function toList(): array
    {
        return $this->all()->toList();
    }

    public function getIterator(): \Iterator
    {
        return $this->all()->getIterator();
    }

    /**
     * @return array<int|string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->all()->jsonSerialize();
    }

    public function __clone()
    {
        $this->builder = $this->builder->clone();
    }

    /**
     * One level of contain, the way CakePHP's eager loader does it: one
     * query per association, rows matched back by key.
     *
     * @param list<Model> $rows
     */
    private function load(string $name, array $rows): void
    {
        $assoc = $this->table->association($name);
        $target = CakeTable::for($assoc['target']);
        $property = $assoc['property'];
        if ($rows === []) {
            return;
        }
        $pk = $this->table->getPrimaryKey();
        switch ($assoc['type']) {
            case 'belongsTo':
                $keys = array_values(array_unique(array_filter(array_map(static fn(Model $r) => $r->cakeRaw($assoc['foreignKey']), $rows), static fn($v) => $v !== null)));
                $found = [];
                if ($keys !== []) {
                    foreach ($target->query()->where([$target->getAlias() . '.' . $target->getPrimaryKey() . ' IN' => $keys])->all() as $t) {
                        $found[(string) $t->cakeRaw($target->getPrimaryKey())] = $t;
                    }
                }
                foreach ($rows as $row) {
                    $row->cakeAssociated[$property] = $found[(string) $row->cakeRaw($assoc['foreignKey'])] ?? null;
                }
                break;
            case 'hasOne':
            case 'hasMany':
                $keys = array_values(array_map(static fn(Model $r) => $r->cakeRaw($pk), $rows));
                $groups = [];
                foreach ($target->query()->where([$target->getAlias() . '.' . $assoc['foreignKey'] . ' IN' => $keys])->all() as $t) {
                    $groups[(string) $t->cakeRaw($assoc['foreignKey'])][] = $t;
                }
                foreach ($rows as $row) {
                    $group = $groups[(string) $row->cakeRaw($pk)] ?? [];
                    $row->cakeAssociated[$property] = $assoc['type'] === 'hasOne' ? ($group[0] ?? null) : $group;
                }
                break;
            case 'belongsToMany':
                $keys = array_values(array_map(fn(Model $r) => $this->table->toDatabase($pk, $r->cakeRaw($pk)), $rows));
                $join = (string) $assoc['joinTable'];
                $targetTable = $target->getTable();
                $query = CakeDatabase::connection()->table($targetTable)
                    ->join($join, $targetTable . '.' . $target->getPrimaryKey(), '=', $join . '.' . $assoc['targetForeignKey'])
                    ->whereIn($join . '.' . $assoc['foreignKey'], $keys)
                    ->select([$targetTable . '.*', $join . '.' . $assoc['foreignKey'] . ' as __booster_owner']);
                $groups = [];
                foreach ($query->get() as $record) {
                    $record = (array) $record;
                    $owner = (string) $record['__booster_owner'];
                    unset($record['__booster_owner']);
                    $groups[$owner][] = $target->hydrate($record);
                }
                foreach ($rows as $row) {
                    $row->cakeAssociated[$property] = $groups[(string) $row->cakeRaw($pk)] ?? [];
                }
                break;
        }
    }

    /**
     * @param list<mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        throw new \LogicException('Booster: the query method ' . $method . '() is not covered in the Laravel half; port this query by hand.');
    }
}
