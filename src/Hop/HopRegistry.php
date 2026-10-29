<?php

declare(strict_types=1);

namespace Waynestate\Booster\Hop;

use Waynestate\Booster\ToolError;

/**
 * Every hop BOOSTER knows. PHP hops walk one minor at a time from 5.6; Laravel
 * hops walk one major at a time from 8. The Rector sets are the maintained
 * ones from rector/rector and driftingly/rector-laravel; BOOSTER adds notes
 * for what those sets cannot do, and one class-rename set of its own (CAS to
 * SESAME).
 */
final class HopRegistry
{
    /** @var list<string> */
    public const PHP_CHAIN = ['5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    /** @var list<string> */
    public const LARAVEL_CHAIN = ['8', '9', '10', '11', '12', '13'];

    /** @var list<string> */
    public const PHPUNIT_CHAIN = ['9', '10', '11', '12', '13'];

    /** The Node pin hop and the major it pins to (Node LTS today: 24 and 26). */
    public const NODE_PIN_ID = 'node-pin-24';

    public const NODE_PIN_TARGET = '24';

    /**
     * The lowest PHP version each PHPUnit major runs on
     * (sebastianbergmann/phpunit composer.json).
     *
     * @var array<int, string>
     */
    public const PHPUNIT_PHP = [
        '9' => '7.3',
        '10' => '8.1',
        '11' => '8.2',
        '12' => '8.3',
        '13' => '8.4',
    ];

    /**
     * The lowest PHPUnit major each Laravel major's skeleton ships
     * (laravel.com upgrade guides).
     *
     * @var array<int, string>
     */
    public const LARAVEL_PHPUNIT = [
        '10' => '9',
        '11' => '10',
        '12' => '11',
        '13' => '12',
    ];

    /**
     * PHP versions each Laravel major runs on (laravel.com/docs/<n>.x/releases,
     * support policy), lowest and highest.
     *
     * @var array<int, array{string, string}>
     */
    public const LARAVEL_PHP = [
        '8' => ['7.3', '8.1'],
        '9' => ['8.0', '8.2'],
        '10' => ['8.1', '8.3'],
        '11' => ['8.2', '8.4'],
        '12' => ['8.2', '8.5'],
        '13' => ['8.3', '8.5'],
    ];

    /** @var array<string, Hop> */
    private array $hops = [];

    public function __construct()
    {
        $this->addPhpHops();
        $this->addLaravelHops();
        $this->addPhpunitHops();
        $this->add($this->sesameHop());
        $this->add($this->nodePinHop());
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

    /** The PHP version BOOSTER can move to from $from, or null at the end of the chain. */
    public static function nextPhp(string $from): ?string
    {
        $i = array_search($from, self::PHP_CHAIN, true);

        return $i === false ? null : (self::PHP_CHAIN[$i + 1] ?? null);
    }

    public static function nextLaravel(string $from): ?string
    {
        $i = array_search($from, self::LARAVEL_CHAIN, true);

        return $i === false ? null : (self::LARAVEL_CHAIN[$i + 1] ?? null);
    }

    public static function nextPhpunit(string $from): ?string
    {
        $i = array_search($from, self::PHPUNIT_CHAIN, true);

        return $i === false ? null : (self::PHPUNIT_CHAIN[$i + 1] ?? null);
    }

    public static function laravelSupportsPhp(string $laravel, string $php): bool
    {
        if (!isset(self::LARAVEL_PHP[$laravel])) {
            return false;
        }
        [$low, $high] = self::LARAVEL_PHP[$laravel];

        return version_compare($php, $low, '>=') && version_compare($php, $high, '<=');
    }

    private function add(Hop $hop): void
    {
        $this->hops[$hop->id] = $hop;
    }

    private function addPhpHops(): void
    {
        $setFor = static fn(string $v): string => 'Rector\Set\ValueObject\SetList::PHP_' . str_replace('.', '', $v);

        foreach (self::PHP_CHAIN as $i => $from) {
            $to = self::PHP_CHAIN[$i + 1] ?? null;
            if ($to === null) {
                break;
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
        foreach (self::LARAVEL_CHAIN as $i => $from) {
            $to = self::LARAVEL_CHAIN[$i + 1] ?? null;
            if ($to === null) {
                break;
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
        foreach (self::PHPUNIT_CHAIN as $i => $from) {
            $to = self::PHPUNIT_CHAIN[$i + 1] ?? null;
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
                'SESAME is not on Packagist yet: add its repository to composer.json before you run composer update.',
                'Log in through the pre-production host and check the session timeout, the Access ID attribute and Duo before you ship.',
            ],
            links: [],
        );
    }

    private function nodePinHop(): Hop
    {
        return new Hop(
            id: self::NODE_PIN_ID,
            kind: HopKind::Node,
            from: 'any',
            to: self::NODE_PIN_TARGET,
            summary: 'Pin the app to Node 24: set engines.node in package.json and write .nvmrc.',
            npmSet: ['engines.node' => '>=24'],
            notes: [
                'The pin is a floor, not a ceiling: engines.node ">=24" still allows Node 26.',
                'Later front-end hops run in the same node:24 container, so this hop comes before them.',
            ],
            links: ['https://nodejs.org/en/about/previous-releases'],
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
