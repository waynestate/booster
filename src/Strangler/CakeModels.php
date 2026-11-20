<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * Eloquent model skeletons from a CakePHP app's Table and Entity classes
 * (design section 4, ORM row): the table, the primary key, the timestamp
 * columns, the associations, `_accessible` as `$fillable` and `_hidden` as
 * `$hidden`. Queries, finders, validation and entity accessors stay in
 * legacy; a person ports them with the actions that use them. A model the
 * Laravel half already has (User, from the skeleton) is left alone.
 */
final class CakeModels
{
    /** @var list<string> */
    public array $manual = [];

    /**
     * Write every model that is missing; returns the app-relative files written.
     *
     * @return list<string>
     */
    public function write(string $appRoot): array
    {
        $appRoot = rtrim($appRoot, '/\\');
        $written = [];
        foreach ($this->skeletons($appRoot) as $file => $code) {
            $path = $appRoot . '/' . $file;
            if (is_file($path)) {
                if ((string) file_get_contents($path) !== $code) {
                    $this->manual[] = sprintf('%s already exists, so Booster did not write the skeleton for its legacy Table; merge the table, key and associations from legacy/src/Model/Table by hand', $file);
                }
                continue;
            }
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $code);
            $written[] = $file;
            $this->manual[] = sprintf('%s is a skeleton from the legacy Table and Entity; its queries, finders, validation rules and accessors stay in legacy until no legacy route uses them', $file);
        }

        return $written;
    }

    /**
     * Model code keyed by app-relative file.
     *
     * @return array<string, string>
     */
    public function skeletons(string $appRoot): array
    {
        $legacy = rtrim($appRoot, '/\\') . '/legacy';
        $tables = glob($legacy . '/src/Model/Table/*Table.php') ?: [];
        sort($tables, SORT_STRING);
        $out = [];
        foreach ($tables as $file) {
            $alias = substr(basename($file), 0, -strlen('Table.php'));
            if ($alias === '' || $alias === 'App') {
                continue;
            }
            $model = self::singular($alias);
            $out['app/Models/' . $model . '.php'] = $this->model($legacy, $alias, $model, (string) file_get_contents($file));
        }

        return $out;
    }

    private function model(string $legacy, string $alias, string $model, string $tableCode): string
    {
        $c = new CakeCode($tableCode);
        $code = $c->code(0, count($c->t) - 1);
        $table = self::underscore($alias);
        if (preg_match('/->setTable\(\s*[\'"]([\w.]+)[\'"]\s*\)/', $code, $m) === 1) {
            $table = $m[1];
        }
        $key = 'id';
        if (preg_match('/->setPrimaryKey\(\s*[\'"](\w+)[\'"]\s*\)/', $code, $m) === 1) {
            $key = $m[1];
        }
        $timestamps = preg_match('/->addBehavior\(\s*[\'"]Timestamp[\'"]/', $code) === 1;

        $relations = [];
        $imports = [];
        foreach (['belongsTo' => 'BelongsTo', 'hasMany' => 'HasMany', 'hasOne' => 'HasOne', 'belongsToMany' => 'BelongsToMany'] as $call => $type) {
            foreach ($c->calls('this', $call) as $open) {
                $args = $c->items($open);
                $name = isset($args[0]) ? $c->stringAt($args[0]) : null;
                if ($name === null) {
                    continue;
                }
                $options = isset($args[1]) ? ($c->arrayAt($args[1]) ?? []) : [];
                $target = isset($options['className']) && is_string($options['className']) ? self::singular((string) preg_replace('/^.*[.\\\\]/', '', $options['className'])) : self::singular($name);
                $method = lcfirst(str_replace('_', '', ucwords(self::underscore($call === 'belongsTo' || $call === 'hasOne' ? self::singular($name) : $name), '_')));
                $fk = is_string($options['foreignKey'] ?? null) ? $options['foreignKey'] : null;
                $args = [$target . '::class'];
                if ($call === 'belongsTo') {
                    $args[] = var_export($fk ?? self::underscore(self::singular($name)) . '_id', true);
                } elseif ($call === 'belongsToMany') {
                    $join = is_string($options['joinTable'] ?? null) ? $options['joinTable'] : implode('_', self::sorted($table, self::underscore($name)));
                    $args[] = var_export($join, true);
                    $args[] = var_export($fk ?? self::underscore($model) . '_id', true);
                    $args[] = var_export(is_string($options['targetForeignKey'] ?? null) ? $options['targetForeignKey'] : self::underscore(self::singular($name)) . '_id', true);
                } else {
                    $args[] = var_export($fk ?? self::underscore($model) . '_id', true);
                }
                $imports[$type] = 'use Illuminate\\Database\\Eloquent\\Relations\\' . $type . ';';
                $relations[] = sprintf("    /** CakePHP: \$this->%s('%s') */\n    public function %s(): %s\n    {\n        return \$this->%s(%s);\n    }", $call, $name, $method, $type, $call, implode(', ', $args));
            }
        }

        $fillable = [];
        $hidden = [];
        $entity = $legacy . '/src/Model/Entity/' . $model . '.php';
        if (is_file($entity)) {
            $e = new CakeCode((string) file_get_contents($entity));
            foreach ($e->t as $i => $token) {
                if ($token[0] !== T_VARIABLE || !in_array($token[1], ['$_accessible', '$_hidden'], true)) {
                    continue;
                }
                $eq = $e->next($i);
                $open = $eq === null ? null : $e->next($eq);
                if ($eq === null || $open === null || $e->t[$eq][1] !== '=') {
                    continue;
                }
                $end = $e->t[$open][1] === '[' && isset($e->close[$open]) ? $e->close[$open] : $open;
                $value = $e->arrayAt([$open, $end]) ?? [];
                if ($token[1] === '$_accessible') {
                    foreach ($value as $field => $allowed) {
                        if (is_string($field) && $field !== '*' && $allowed === true) {
                            $fillable[] = $field;
                        }
                    }
                } else {
                    $hidden = array_values(array_filter($value, 'is_string'));
                }
            }
        }

        ksort($imports);
        $body = [];
        $body[] = '    protected $table = ' . var_export($table, true) . ';';
        if ($key !== 'id') {
            $body[] = '    protected $primaryKey = ' . var_export($key, true) . ';';
        }
        if ($timestamps) {
            $body[] = "    const CREATED_AT = 'created';\n\n    const UPDATED_AT = 'modified';";
        } else {
            $body[] = '    public $timestamps = false;';
        }
        if ($fillable !== []) {
            $body[] = "    protected \$fillable = [\n" . implode('', array_map(static fn(string $f): string => '        ' . var_export($f, true) . ",\n", $fillable)) . '    ];';
        }
        if ($hidden !== []) {
            $body[] = "    protected \$hidden = [\n" . implode('', array_map(static fn(string $f): string => '        ' . var_export($f, true) . ",\n", $hidden)) . '    ];';
        }
        $body = array_merge($body, $relations);

        return "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n" . ($imports === [] ? '' : implode("\n", $imports) . "\n")
            . "\n/**\n * Written by Booster's strangler (cakephp-to-laravel) from legacy/src/Model/Table/" . $alias . "Table.php"
            . (is_file($entity) ? ' and legacy/src/Model/Entity/' . $model . '.php' : '') . ".\n * A skeleton: queries, finders, validation and accessors stay in legacy for now. App-owned.\n */\nclass "
            . $model . " extends Model\n{\n" . implode("\n\n", $body) . "\n}\n";
    }

    /** @return list<string> */
    private static function sorted(string $a, string $b): array
    {
        $pair = [$a, $b];
        sort($pair, SORT_STRING);

        return $pair;
    }

    /** CakePHP's underscore(): "ArticlesTags" to "articles_tags". */
    public static function underscore(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])|(?<=[A-Z])([A-Z])(?=[a-z])/', '_$1$2', $name));
    }

    /** The singular of a CakePHP alias, by the English rules CakePHP's Inflector uses most. */
    public static function singular(string $name): string
    {
        $irregular = ['People' => 'Person', 'Men' => 'Man', 'Women' => 'Woman', 'Children' => 'Child', 'Statuses' => 'Status', 'Addresses' => 'Address', 'Data' => 'Data', 'News' => 'News'];
        foreach ($irregular as $plural => $single) {
            if (str_ends_with($name, $plural)) {
                return substr($name, 0, -strlen($plural)) . $single;
            }
        }
        if (preg_match('/ies$/', $name) === 1) {
            return substr($name, 0, -3) . 'y';
        }
        if (preg_match('/(ss|sh|ch|x|z)es$/', $name) === 1) {
            return substr($name, 0, -2);
        }
        if (str_ends_with($name, 's') && !str_ends_with($name, 'ss')) {
            return substr($name, 0, -1);
        }

        return $name;
    }
}
