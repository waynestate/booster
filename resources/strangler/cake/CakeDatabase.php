<?php

namespace App\Http\Controllers\Legacy\Cake;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Written by Booster's strangler (cakephp-to-laravel): the database moved
 * ORM code reads, which is the legacy app's own. CakePHP's `default`
 * Datasource (legacy/config/app.php with app_local.php over it, read at
 * runtime, the way the legacy half reads it on the same server) becomes the
 * Laravel connection `cake`, so both halves read and write the same rows.
 * A `cake` connection in config/database.php wins, for a driver this does
 * not map. Removed by the finish step with the rest of the runtime.
 */
final class CakeDatabase
{
    public const NAME = 'cake';

    private static bool $ready = false;

    /** The connection name moved models and queries use. */
    public static function name(): string
    {
        if (!self::$ready) {
            if (config('database.connections.' . self::NAME) === null) {
                config(['database.connections.' . self::NAME => self::fromLegacy(base_path('legacy'))]);
            }
            self::$ready = true;
        }

        return self::NAME;
    }

    public static function connection(): Connection
    {
        return DB::connection(self::name());
    }

    /**
     * The Laravel connection settings for the legacy app's `default`
     * Datasource.
     *
     * @return array<string, mixed>
     */
    public static function fromLegacy(string $legacy): array
    {
        // app.php names CakePHP's path constants (ROOT, CACHE, LOGS); the legacy app defines them in paths.php.
        if (!defined('ROOT') && is_file($legacy . '/config/paths.php')) {
            require_once $legacy . '/config/paths.php';
        }
        $config = [];
        foreach (['app.php', 'app_local.php'] as $file) {
            if (is_file($legacy . '/config/' . $file)) {
                $data = require $legacy . '/config/' . $file;
                $config = array_replace_recursive($config, is_array($data) ? $data : []);
            }
        }
        $source = $config['Datasources']['default'] ?? null;
        if (!is_array($source)) {
            throw new \LogicException('Booster: the legacy app has no Datasources.default in config/app.php or config/app_local.php, so moved code has no database; set database.connections.cake in config/database.php.');
        }
        if (is_string($source['url'] ?? null) && $source['url'] !== '') {
            return ['url' => $source['url'], 'prefix' => ''];
        }
        $driver = is_string($source['driver'] ?? null) ? substr((string) strrchr('\\' . $source['driver'], '\\'), 1) : '';
        $common = [
            'host' => $source['host'] ?? null,
            'port' => $source['port'] ?? null,
            'database' => $source['database'] ?? null,
            'username' => $source['username'] ?? null,
            'password' => $source['password'] ?? null,
            'prefix' => '',
        ];

        return match ($driver) {
            'Sqlite' => ['driver' => 'sqlite', 'database' => $source['database'] ?? '', 'prefix' => '', 'foreign_key_constraints' => false],
            'Mysql' => ['driver' => 'mysql', 'charset' => $source['encoding'] ?? 'utf8mb4', 'timezone' => self::offset($source['timezone'] ?? null)] + $common,
            'Postgres' => ['driver' => 'pgsql', 'charset' => $source['encoding'] ?? 'utf8', 'search_path' => $source['schema'] ?? 'public'] + $common,
            'Sqlserver' => ['driver' => 'sqlsrv', 'charset' => $source['encoding'] ?? 'utf8'] + $common,
            default => throw new \LogicException('Booster: the legacy Datasources.default driver `' . (string) ($source['driver'] ?? '') . '` has no Laravel connection here; set database.connections.cake in config/database.php.'),
        };
    }

    /** CakePHP's `timezone` option (UTC, +00:00) as MySQL's session time zone, or null. */
    private static function offset(mixed $timezone): ?string
    {
        if (!is_string($timezone) || $timezone === '') {
            return null;
        }

        return strtoupper($timezone) === 'UTC' ? '+00:00' : $timezone;
    }
}
