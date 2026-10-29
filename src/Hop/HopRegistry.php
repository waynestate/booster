<?php

declare(strict_types=1);

namespace Waynestate\Booster\Hop;

use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\ToolError;

/**
 * Every hop Booster knows. The version data lives in data/versions.json, which
 * bin/refresh-hop-data.php regenerates from the public feeds and CREED's
 * version policy, so a new major shows up here without anyone editing this
 * class. The recipes themselves (Rector sets, notes, links) stay human work.
 */
final class HopRegistry
{
    /** The Node pin hop. The major it pins to lives in the data file. */
    public const NODE_PIN_ID = 'node-pin-24';

    /** The Mix to Vite hop. The package versions it installs live in the data file. */
    public const MIX_TO_VITE_ID = 'mix-to-vite';

    /** The lowest Laravel major whose upgrade guide ships Vite as the front-end tool. */
    public const VITE_LARAVEL_FLOOR = '9';

    /** @var list<string> */
    private readonly array $phpChain;

    /** @var list<string> */
    private readonly array $laravelChain;

    /** @var list<string> */
    private readonly array $phpunitChain;

    private readonly string $nodePinTarget;

    private readonly string $viteVersion;

    private readonly string $laravelVitePluginVersion;

    private readonly string $vuePluginVersion;

    /** @var array<string, string> */
    private readonly array $phpunitPhp;

    /** @var array<string, string> */
    private readonly array $laravelPhpunit;

    /** @var array<string, array{0: string, 1: string}> */
    private readonly array $laravelPhp;

    /** @var array<string, Hop> */
    private array $hops = [];

    public function __construct(?string $dataPath = null)
    {
        $data = self::load($dataPath ?? PharFiles::path('data/versions.json'));
        $this->phpChain = self::chain($data, 'php');
        $this->laravelChain = self::chain($data, 'laravel');
        $this->phpunitChain = self::chain($data, 'phpunit');
        $this->nodePinTarget = self::scalar($data, 'node_pin_target');
        $this->viteVersion = self::scalar($data, 'vite_version');
        $this->laravelVitePluginVersion = self::scalar($data, 'laravel_vite_plugin_version');
        $this->vuePluginVersion = self::scalar($data, 'vue_plugin_version');
        $this->phpunitPhp = self::stringTable($data, 'phpunit_php');
        $this->laravelPhpunit = self::stringTable($data, 'laravel_phpunit');
        $this->laravelPhp = self::rangeTable($data, 'laravel_php');

        $this->addPhpHops();
        $this->addLaravelHops();
        $this->addPhpunitHops();
        $this->add($this->sesameHop());
        $this->add($this->nodePinHop());
        $this->add($this->mixToViteHop());
        $this->add($this->vueHop());
    }

    public static function phpHopId(string $from, string $to): string
    {
        return sprintf('php-%s-to-%s', str_replace('.', '', $from), str_replace('.', '', $to));
    }

    public static function laravelHopId(string $from, string $to): string
    {
        return sprintf('laravel-%s-to-%s', $from, $to);
    }

    public static function phpunitHopId(string $from, string $to): string
    {
        return sprintf('phpunit-%s-to-%s', $from, $to);
    }

    public function get(string $id): Hop
    {
        return $this->hops[$id] ?? throw new ToolError(sprintf('Unknown hop "%s". Run `booster hops` to list them.', $id));
    }

    public function has(string $id): bool
    {
        return isset($this->hops[$id]);
    }

    /**
     * @return list<Hop>
     */
    public function all(): array
    {
        return array_values($this->hops);
    }

    /** @return list<string> */
    public function phpChain(): array
    {
        return $this->phpChain;
    }

    /** @return list<string> */
    public function laravelChain(): array
    {
        return $this->laravelChain;
    }

    /** @return list<string> */
    public function phpunitChain(): array
    {
        return $this->phpunitChain;
    }

    public function nodePinTarget(): string
    {
        return $this->nodePinTarget;
    }

    public function viteVersion(): string
    {
        return $this->viteVersion;
    }

    public function laravelVitePluginVersion(): string
    {
        return $this->laravelVitePluginVersion;
    }

    public function vuePluginVersion(): string
    {
        return $this->vuePluginVersion;
    }

    /**
     * The lowest PHP version each PHPUnit major runs on
     * (sebastianbergmann/phpunit composer.json).
     *
     * @return array<string, string>
     */
    public function phpunitPhp(): array
    {
        return $this->phpunitPhp;
    }

    /**
     * The lowest PHPUnit major each Laravel major's skeleton ships
     * (laravel.com upgrade guides).
     *
     * @return array<string, string>
     */
    public function laravelPhpunit(): array
    {
        return $this->laravelPhpunit;
    }

    /**
     * PHP versions each Laravel major runs on (laravel.com/docs/<n>.x/releases,
     * support policy), lowest and highest.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function laravelPhp(): array
    {
        return $this->laravelPhp;
    }

    /** The PHP version Booster can move to from $from, or null at the end of the chain. */
    public function nextPhp(string $from): ?string
    {
        return self::nextIn($this->phpChain, $from);
    }

    public function nextLaravel(string $from): ?string
    {
        return self::nextIn($this->laravelChain, $from);
    }

    public function nextPhpunit(string $from): ?string
    {
        return self::nextIn($this->phpunitChain, $from);
    }

    public function laravelSupportsPhp(string $laravel, string $php): bool
    {
        if (!isset($this->laravelPhp[$laravel])) {
            return false;
        }
        [$low, $high] = $this->laravelPhp[$laravel];

        return version_compare($php, $low, '>=') && version_compare($php, $high, '<=');
    }

    /** @return array<string, mixed> */
    private static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new ToolError(sprintf('Hop data file "%s" is missing.', $path));
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            throw new ToolError(sprintf('Hop data file "%s" is not a JSON object.', $path));
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function chain(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || $value === [] || array_diff_key($value, array_keys($value)) !== []) {
            throw new ToolError(sprintf('Hop data key "%s" must be a non-empty list of version strings.', $key));
        }
        $chain = [];
        foreach ($value as $version) {
            if (!is_string($version) || $version === '') {
                throw new ToolError(sprintf('Hop data key "%s" must contain only version strings.', $key));
            }
            $chain[] = $version;
        }

        return $chain;
    }

    /** @param array<string, mixed> $data */
    private static function scalar(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ToolError(sprintf('Hop data key "%s" must be a non-empty string.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private static function stringTable(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new ToolError(sprintf('Hop data key "%s" must be a JSON object.', $key));
        }
        $table = [];
        foreach ($value as $major => $floor) {
            if (!is_string($floor) || $floor === '') {
                throw new ToolError(sprintf('Hop data key "%s" must map majors to version strings.', $key));
            }
            $table[(string) $major] = $floor;
        }

        return $table;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function rangeTable(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new ToolError(sprintf('Hop data key "%s" must be a JSON object.', $key));
        }
        $table = [];
        foreach ($value as $major => $range) {
            if (!is_array($range) || count($range) !== 2 || !is_string($range[0]) || !is_string($range[1])) {
                throw new ToolError(sprintf('Hop data key "%s" must map majors to [lowest, highest] pairs.', $key));
            }
            $table[(string) $major] = [$range[0], $range[1]];
        }

        return $table;
    }

    /**
     * @param list<string> $chain
     */
    private static function nextIn(array $chain, string $from): ?string
    {
        $i = array_search($from, $chain, true);

        return $i === false ? null : ($chain[$i + 1] ?? null);
    }

    private function add(Hop $hop): void
    {
        $this->hops[$hop->id] = $hop;
    }

    private function addPhpHops(): void
    {
        $setFor = static fn(string $v): string => 'Rector\Set\ValueObject\SetList::PHP_' . str_replace('.', '', $v);

        foreach ($this->phpChain as $i => $from) {
            $to = $this->phpChain[$i + 1] ?? null;
            if ($to === null) {
                break;
            }
            if (!defined($setFor($to))) {
                // Rector has no ruleset for this PHP version yet, so there is no hop to offer.
                continue;
            }
            $this->add(new Hop(
                id: self::phpHopId($from, $to),
                kind: HopKind::Php,
                from: $from,
                to: $to,
                summary: sprintf('Move PHP %s code to PHP %s.', $from, $to),
                rectorSets: [$setFor($to)],
                composerSet: ['php' => '^' . $to],
                notes: $this->phpNotes($to),
                links: [sprintf('https://www.php.net/manual/en/migration%s.php', str_replace('.', '', $to))],
                extraReview: $to === '8.0',
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function phpNotes(string $to): array
    {
        return match ($to) {
            '7.0' => ['Rector cannot replace the removed mysql_* functions; move to PDO or mysqli by hand.'],
            '8.0' => [
                'Largest risk hop: loose comparison of numbers and strings changed, many warnings became thrown errors, and arithmetic on arrays throws.',
                'Write or extend tests before applying this hop; only tests and review find behaviour changes here.',
            ],
            '8.1' => [
                'mysqli now throws on errors by default; PDO on MySQL returns native int and float types; htmlspecialchars() now escapes single quotes.',
                'Passing null to a non-nullable built-in parameter is deprecated: check the log after the run.',
            ],
            '8.4' => [
                'Implicitly nullable parameters (T $a = null) are deprecated; fputcsv(), fgetcsv() and str_getcsv() want an explicit escape argument.',
            ],
            '8.5' => [
                'Deprecated: the (boolean), (integer), (double) and (binary) casts, the backtick operator, __sleep() and __wakeup(), curl_close() and imagedestroy().',
                'PDO driver constants such as PDO::MYSQL_ATTR_* move to Pdo\Mysql::*; check them by hand.',
            ],
            default => [],
        };
    }

    private function addLaravelHops(): void
    {
        foreach ($this->laravelChain as $i => $from) {
            $to = $this->laravelChain[$i + 1] ?? null;
            if ($to === null) {
                break;
            }
            if (!defined('RectorLaravel\Set\LaravelSetList::LARAVEL_' . $to . '0')) {
                // rector-laravel has no ruleset for this major yet, so there is no hop to offer.
                continue;
            }
            $this->add(new Hop(
                id: self::laravelHopId($from, $to),
                kind: HopKind::Laravel,
                from: $from,
                to: $to,
                summary: sprintf('Move a Laravel %s app to Laravel %s.', $from, $to),
                rectorSets: ['RectorLaravel\Set\LaravelSetList::LARAVEL_' . $to . '0'],
                composerSet: ['laravel/framework' => '^' . $to . '.0'],
                notes: [
                    'Check the shared database server against the Laravel ' . $to . ' database floor before you apply this hop.',
                    'Update first-party packages (Sanctum, Livewire, Inertia) and your own forks by hand; Rector does not touch them.',
                ],
                links: [sprintf('https://laravel.com/docs/%s.x/upgrade', $to)],
            ));
        }
    }

    private function addPhpunitHops(): void
    {
        foreach ($this->phpunitChain as $i => $from) {
            $to = $this->phpunitChain[$i + 1] ?? null;
            if ($to === null) {
                break;
            }
            $this->add(new Hop(
                id: self::phpunitHopId($from, $to),
                kind: HopKind::Phpunit,
                from: $from,
                to: $to,
                summary: sprintf('Move PHPUnit %s tests to PHPUnit %s.', $from, $to),
                rectorSets: ['Rector\PHPUnit\Set\PHPUnitSetList::COMPOSER_BASED'],
                composerSet: ['phpunit/phpunit' => '^' . $to . '.0'],
                notes: [
                    'Run phpunit --migrate-configuration after this hop; Rector rewrites the test code but not phpunit.xml.',
                ],
                links: [sprintf('https://phpunit.de/articles/migrating-to-phpunit-%s.html', $to)],
            ));
        }
    }

    private function sesameHop(): Hop
    {
        return new Hop(
            id: 'cas-to-sesame',
            kind: HopKind::Sesame,
            from: 'wayne-cas',
            to: 'sesame',
            summary: 'Swap the wayne-cas package for SESAME using its compatibility classes (stub recipe).',
            classRenames: [
                'WSU\WayneCas\WayneAuthenticate' => 'Waynestate\Sesame\WayneCas\WayneAuthenticate',
                'WSU\WayneCas\WayneCasable' => 'Waynestate\Sesame\WayneCas\WayneCasable',
                'WSU\WayneCas\ReadOnlyAttributes' => 'Waynestate\Sesame\WayneCas\ReadOnlyAttributes',
                'WSU\WayneCas\WayneCasServiceProvider' => 'Waynestate\Sesame\Laravel\SesameServiceProvider',
            ],
            composerSet: ['waynestate/sesame' => '^0.1'],
            composerRemove: ['cit-web-infra/wayne-cas'],
            notes: [
                'This recipe renames classes and swaps the Composer requirement. It does not move config/wayne-cas.php to the SESAME config; do that by hand.',
                'SESAME is on Packagist: run composer require waynestate/sesame:^0.1 (no extra repository needed).',
                'Log in through the pre-production host and check the session timeout, the Access ID attribute and Duo before you ship.',
            ],
            links: [],
        );
    }

    private function nodePinHop(): Hop
    {
        $target = $this->nodePinTarget;

        return new Hop(
            id: self::NODE_PIN_ID,
            kind: HopKind::Node,
            from: 'any',
            to: $target,
            summary: sprintf('Pin the app to Node %s: set engines.node in package.json and write .nvmrc.', $target),
            npmSet: ['engines.node' => '>=' . $target],
            notes: [
                sprintf('The pin is a floor, not a ceiling: engines.node ">=%s" still allows newer Node majors.', $target),
                sprintf('Later front-end hops run in the same node:%s container, so this hop comes before them.', $target),
            ],
            links: ['https://nodejs.org/en/about/previous-releases'],
        );
    }

    /**
     * The Mix to Vite hop. It converts the simple shape of webpack.mix.js and
     * refuses anything else; the planner offers it only after node-pin-24 and
     * only when the app will sit on Laravel 9 or newer, where Vite is the
     * front-end tool.
     */
    private function mixToViteHop(): Hop
    {
        return new Hop(
            id: self::MIX_TO_VITE_ID,
            kind: HopKind::Node,
            from: 'laravel-mix',
            to: 'vite',
            summary: 'Move a simple Laravel Mix front end to Vite (simple webpack.mix.js files only).',
            buildGate: true,
            notes: [
                'BOOSTER converts the simple shape only: mix.js, .vue(), .sass(), .postCss() and .version(). Anything else is refused so you follow the manual Vite migration guide.',
                'Blade mix() script and link tags become one @vite([...]) line per layout when every referenced asset maps to a Mix entry; unmapped references are listed as manual notes.',
                'Replace withoutMix() with withoutVite() by hand; BOOSTER lists the files that call it.',
            ],
            links: ['https://laravel.com/docs/9.x/upgrade#vite'],
        );
    }

    private function vueHop(): Hop
    {
        return new Hop(
            id: 'vue-2-to-3',
            kind: HopKind::Vue,
            from: '2',
            to: '3',
            summary: 'Move a Vue 2 front end to Vue 3 (playbook only; Rector does not read Vue).',
            notes: [
                'Run the official Vue 3 migration build first, then fix each warning it prints.',
                'Vue 2 reached end of life on 2023-12-31; the policy lists it as past its grace window.',
                'Moving to Vue 3 usually means moving Vuex to Pinia and Vue Router 3 to 4.',
            ],
            links: ['https://v3-migration.vuejs.org/'],
        );
    }
}
