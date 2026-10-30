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

    /** The Tailwind 3 to 4 hop. The tool and pin versions live in the data file. */
    public const TAILWIND_ID = 'tailwind-3-to-4';

    /** The staged hop that moves a Vue 2.6 front end onto Vue 2.7. */
    public const VUE_26_TO_27_ID = 'vue-26-to-27';

    /** The staged hop that moves a Vue 2.7 front end onto Vue 3 through @vue/compat. */
    public const VUE_27_TO_COMPAT_ID = 'vue-27-to-compat';

    /** The staged hop that runs the vue-upgrade-tool codemod over a compat-mode front end. */
    public const VUE_COMPAT_CODEMOD_ID = 'vue-compat-codemod';

    /** The manual playbook that stands in for the three staged Vue hops. */
    public const VUE_2_TO_3_ID = 'vue-2-to-3';

    /** The manual playbook that moves a PHPUnit below 9 onto PHPUnit 9. */
    public const PHPUNIT_TO_9_MANUAL_ID = 'phpunit-to-9-manual';

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

    private readonly string $tailwindUpgradeVersion;

    private readonly string $tailwindVersion;

    private readonly string $vue27Version;

    private readonly string $vueCompatVersion;

    private readonly string $vueLoaderCompatVersion;

    private readonly string $vueUpgradeToolVersion;

    /** @var array<string, string> */
    private readonly array $phpunitPhp;

    /** @var array<string, string> */
    private readonly array $laravelPhpunit;

    /** @var array<string, array{0: string, 1: string}> */
    private readonly array $laravelPhp;

    /** @var array<string, string|null> */
    private readonly array $inertiaLaravel;

    /** @var array<string, string|null> */
    private readonly array $inertiaVue3;

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
        $this->tailwindUpgradeVersion = self::scalar($data, 'tailwind_upgrade_version');
        $this->tailwindVersion = self::scalar($data, 'tailwind_version');
        $this->vue27Version = self::scalar($data, 'vue_27');
        $this->vueCompatVersion = self::scalar($data, 'vue_compat');
        $this->vueLoaderCompatVersion = self::scalar($data, 'vue_loader_compat');
        $this->vueUpgradeToolVersion = self::scalar($data, 'vue_upgrade_tool_version');
        $this->phpunitPhp = self::stringTable($data, 'phpunit_php');
        $this->laravelPhpunit = self::stringTable($data, 'laravel_phpunit');
        $this->laravelPhp = self::rangeTable($data, 'laravel_php');
        $this->inertiaLaravel = self::nullableStringTable($data, 'inertia_laravel');
        $this->inertiaVue3 = self::nullableStringTable($data, 'inertia_vue3');

        $this->addPhpHops();
        $this->addLaravelHops();
        $this->addPhpunitHops();
        $this->add($this->phpunitTo9ManualHop());
        $this->add($this->sesameHop());
        $this->add($this->nodePinHop());
        $this->add($this->mixToViteHop());
        $this->add($this->tailwindHop());
        foreach ($this->vueHops() as $hop) {
            $this->add($hop);
        }
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

    public function tailwindUpgradeVersion(): string
    {
        return $this->tailwindUpgradeVersion;
    }

    public function tailwindVersion(): string
    {
        return $this->tailwindVersion;
    }

    public function vue27Version(): string
    {
        return $this->vue27Version;
    }

    public function vueCompatVersion(): string
    {
        return $this->vueCompatVersion;
    }

    public function vueLoaderCompatVersion(): string
    {
        return $this->vueLoaderCompatVersion;
    }

    public function vueUpgradeToolVersion(): string
    {
        return $this->vueUpgradeToolVersion;
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

    /**
     * The inertia-laravel constraint each Laravel major pairs with, or null
     * where BOOSTER pins nothing and the app keeps what it has.
     *
     * @return array<string, string|null>
     */
    public function inertiaLaravel(): array
    {
        return $this->inertiaLaravel;
    }

    /**
     * The @inertiajs/vue3 constraint each Laravel major pairs with, or null
     * where BOOSTER pins nothing and the app keeps what it has.
     *
     * @return array<string, string|null>
     */
    public function inertiaVue3(): array
    {
        return $this->inertiaVue3;
    }

    /**
     * The Inertia constraints a Laravel hop should raise for an Inertia/Vue
     * app moving to $laravelTo: composer and npm maps (empty where BOOSTER
     * pins nothing for that major) plus the note to surface when neither is
     * pinned. One source of truth for the executor and the hop's notes.
     *
     * @return array{composer: array<string, string>, npm: array<string, string>, notes: list<string>}
     */
    public function inertiaChanges(string $laravelTo): array
    {
        $composer = [];
        $constraint = $this->inertiaLaravel[$laravelTo] ?? null;
        if ($constraint !== null) {
            $composer['inertiajs/inertia-laravel'] = $constraint;
        }
        $npm = [];
        $constraint = $this->inertiaVue3[$laravelTo] ?? null;
        if ($constraint !== null) {
            $npm['@inertiajs/vue3'] = $constraint;
        }
        $notes = [];
        if ($composer === [] && $npm === []) {
            $notes[] = sprintf('Inertia: BOOSTER pins no inertia-laravel or @inertiajs/vue3 constraint for Laravel %s; check the Inertia docs for the versions that support it.', $laravelTo);
        }

        return ['composer' => $composer, 'npm' => $npm, 'notes' => $notes];
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
     * A table whose values may be null: null means BOOSTER pins nothing for
     * that major, which is a decision, not a missing entry.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string|null>
     */
    private static function nullableStringTable(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new ToolError(sprintf('Hop data key "%s" must be a JSON object.', $key));
        }
        $table = [];
        foreach ($value as $major => $constraint) {
            if ((!is_string($constraint) && !is_null($constraint)) || (is_string($constraint) && $constraint === '')) {
                throw new ToolError(sprintf('Hop data key "%s" must map majors to version strings or null.', $key));
            }
            $table[(string) $major] = $constraint;
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
            if (!defined('RectorLaravel\Set\LaravelSetList::LARAVEL_' . self::laravelSetSuffix($to))) {
                // rector-laravel has no ruleset for this major yet, so there is no hop to offer.
                continue;
            }
            $this->add(new Hop(
                id: self::laravelHopId($from, $to),
                kind: HopKind::Laravel,
                from: $from,
                to: $to,
                summary: sprintf('Move a Laravel %s app to Laravel %s.', $from, $to),
                rectorSets: ['RectorLaravel\Set\LaravelSetList::LARAVEL_' . self::laravelSetSuffix($to)],
                composerSet: ['laravel/framework' => '^' . $to . '.0'],
                composerRemove: $this->laravelComposerRemove($to),
                notes: [
                    'Check the shared database server against the Laravel ' . $to . ' database floor before you apply this hop.',
                    'Update first-party packages (Sanctum, Livewire, Inertia) and your own forks by hand; Rector does not touch them.',
                    ...$this->inertiaChanges($to)['notes'],
                ],
                links: [sprintf('https://laravel.com/docs/%s.x/upgrade', $to)],
            ));
        }
    }

    /**
     * Packages to drop when moving to $to. Laravel 9 ships its own
     * TrustProxies middleware, so the fideloper/proxy stand-in goes with it.
     *
     * @return list<string>
     */
    private function laravelComposerRemove(string $to): array
    {
        return match ($to) {
            '9' => ['fideloper/proxy'],
            default => [],
        };
    }

    /**
     * The suffix of rector-laravel's set constant for a Laravel major: "13"
     * becomes "130" and "5.8" becomes "58", because the constants are named
     * LARAVEL_130 and LARAVEL_58.
     */
    private static function laravelSetSuffix(string $major): string
    {
        $suffix = str_replace('.', '', $major);

        return str_contains($major, '.') ? $suffix : $suffix . '0';
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

    /**
     * The manual step for apps whose PHPUnit is below 9. PHPUnit 7 and 8 do
     * not run on PHP 8, so the planner puts this hop before the first step
     * that targets PHP 8.0 and counts the app as PHPUnit 9 from there on.
     */
    private function phpunitTo9ManualHop(): Hop
    {
        return new Hop(
            id: self::PHPUNIT_TO_9_MANUAL_ID,
            kind: HopKind::Phpunit,
            from: 'below-9',
            to: '9',
            summary: 'Move PHPUnit to ^9 by hand: PHPUnit below 9 does not run on PHP 8, so later gates cannot run until it moves.',
            notes: [
                'Raise phpunit/phpunit to ^9.5 in require-dev.',
                'Replace setUp() and tearDown() without : void.',
                'Replace assertInternalType and @expectedException.',
                'Run the tests.',
            ],
            links: ['https://phpunit.de/articles/migrating-to-phpunit-9.html'],
        );
    }

    private function sesameHop(): Hop
    {
        return new Hop(
            id: 'cas-to-sesame',
            kind: HopKind::Sesame,
            from: 'wayne-cas',
            to: 'sesame',
            summary: 'Swap the wayne-cas package for SESAME using its compatibility classes.',
            classRenames: [
                'WSU\WayneCas\WayneAuthenticate' => 'Waynestate\Sesame\WayneCas\WayneAuthenticate',
                'WSU\WayneCas\WayneCasable' => 'Waynestate\Sesame\WayneCas\WayneCasable',
                'WSU\WayneCas\ReadOnlyAttributes' => 'Waynestate\Sesame\WayneCas\ReadOnlyAttributes',
                'WSU\WayneCas\WayneCasServiceProvider' => 'Waynestate\Sesame\Laravel\SesameServiceProvider',
            ],
            composerSet: ['waynestate/sesame' => '^0.2'],
            composerRemove: ['cit-web-infra/wayne-cas'],
            notes: [
                'Booster writes config/sesame.php from config/wayne-cas.php when the old root is a plain string, and rewrites models that use WayneCasable as a trait to implements WayneCasable plus use ReadOnlyAttributes. The old config/wayne-cas.php stays in place and can be removed once SESAME is verified.',
                'MANUAL: SESAME does not carry Duo two-factor. If the app had use2FA enabled, re-enable two-factor in the app itself; the old DUO_HOSTNAME, DUO_IKEY, DUO_SKEY and DUO_AKEY settings do nothing under SESAME.',
                'SESAME is on Packagist: run composer require waynestate/sesame:^0.2 (no extra repository needed).',
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
            pinNode: true,
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
                'Booster converts the simple shape only: mix.js, .vue(), .sass(), .postCss(), .version(), and if (mix.inProduction()) { mix.version(); }, which is dropped with a note because Vite hashes production assets. Anything else is refused by name so you follow the manual Vite migration guide.',
                'Blade mix() script and link tags become one @vite([...]) line per layout when every referenced asset maps to a Mix entry; unmapped references are listed as manual notes.',
                'Replace withoutMix() with withoutVite() by hand; Booster lists the files that call it.',
            ],
            links: ['https://laravel.com/docs/9.x/upgrade#vite'],
        );
    }

    /**
     * The Tailwind 3 to 4 hop. It runs the official @tailwindcss/upgrade tool
     * in the Node container, then normalizes package.json itself: the tool
     * rewrites the CSS and the config, BOOSTER owns the dependency pins.
     */
    private function tailwindHop(): Hop
    {
        return new Hop(
            id: self::TAILWIND_ID,
            kind: HopKind::Node,
            from: '3',
            to: '4',
            summary: 'Move a Tailwind CSS 3 front end to Tailwind CSS 4 with the official upgrade tool.',
            buildGate: true,
            notes: [
                'Booster runs npx @tailwindcss/upgrade, then pins tailwindcss to ' . $this->tailwindVersion . ', adds the matching Vite or PostCSS plugin, and removes autoprefixer and postcss-import, which Tailwind 4 folds in.',
                'MANUAL: plugins listed in tailwind.config.js are not migrated by the tool; move their logic into CSS @plugin or @utility rules by hand.',
                'MANUAL: @apply inside Vue SFC <style> blocks needs @reference "tailwindcss" at the top of the block, or the classes do not resolve.',
                'Tailwind 4 raises the browser floor: Safari 16.4+, Chrome 111+, Firefox 128+. Check the app\'s supported browsers before you ship.',
            ],
            links: ['https://tailwindcss.com/docs/upgrade-guide'],
        );
    }

    /**
     * The Vue 2 to 3 hops in run order: the 2.6 to 2.7 step, the compat build
     * that lands on Vue 3, the codemod that rewrites what it can, and the
     * manual playbook that stands in for all of them. The planner picks the
     * steps an app still needs; the playbook stays so a person can follow the
     * whole move by hand.
     *
     * @return list<Hop>
     */
    public function vueHops(): array
    {
        return [
            $this->vue26To27Hop(),
            $this->vue27ToCompatHop(),
            $this->vueCompatCodemodHop(),
            $this->vue2To3Hop(),
        ];
    }

    private function vue26To27Hop(): Hop
    {
        return new Hop(
            id: self::VUE_26_TO_27_ID,
            kind: HopKind::Node,
            from: '2.6',
            to: '2.7',
            summary: 'Move a Vue 2.6 front end to Vue 2.7, the last Vue 2 release, which carries the Vue 3 reactivity API.',
            npmSet: ['dependencies.vue' => $this->vue27Version],
            npmRemove: ['vue-template-compiler'],
            buildGate: true,
            notes: [
                'Vue 2.7 ships the Vue 3 reactivity API under the Vue 2 syntax, so this is the safe first step and the rest of the code keeps working.',
                'vue-class-component and vue-property-decorator need their 2.7-compatible releases; check package.json after the hop.',
                'Vuex 3 and Vue Router 3 keep working on 2.7; move them only in the next hop.',
                'Drop vue-template-compiler: Vue 2.7 uses the compiler bundled with the vue package.',
            ],
            links: ['https://v2.vuejs.org/v2/guide/migration-vue-2-7.html'],
        );
    }

    private function vue27ToCompatHop(): Hop
    {
        return new Hop(
            id: self::VUE_27_TO_COMPAT_ID,
            kind: HopKind::Node,
            from: '2.7',
            to: '3',
            summary: 'Move a Vue 2.7 front end to Vue 3 through the @vue/compat build.',
            npmSet: [
                'dependencies.vue' => $this->vueCompatVersion,
                'dependencies.@vue/compat' => $this->vueCompatVersion,
                'devDependencies.@vue/compiler-sfc' => $this->vueCompatVersion,
            ],
            npmSetIfPresent: ['devDependencies.vue-loader' => $this->vueLoaderCompatVersion],
            npmRemove: ['vue-template-compiler'],
            buildGate: true,
            notes: [
                'Point the bundler at @vue/compat instead of vue (an alias in webpack or resolve.alias in Vite) and start with compat MODE 2 so the build warns instead of breaking.',
                'Fix every migration warning the build prints, then switch MODE to 3 to turn compat off, and remove @vue/compat from package.json.',
                'Move Vuex to Pinia and Vue Router 3 to 4 by hand; the compat build does not rewrite your store or routes.',
            ],
            links: ['https://v3-migration.vuejs.org/'],
        );
    }

    /**
     * The codemod hop. It runs the lightly maintained vue-upgrade-tool over
     * the app's front end in the Node container and keeps every manual item
     * the tool lists in the hop result. It expects @vue/compat in
     * package.json, so it lands after the compat hop and before the playbook.
     */
    private function vueCompatCodemodHop(): Hop
    {
        return new Hop(
            id: self::VUE_COMPAT_CODEMOD_ID,
            kind: HopKind::Node,
            from: '3-compat',
            to: '3',
            summary: 'Run the vue-upgrade-tool codemod over the front end and keep every manual item it lists.',
            buildGate: true,
            notes: [
                'vue-upgrade-tool is lightly maintained; review every change it makes before you trust the diff.',
                'The tool lists the items it cannot fix; Booster keeps them in the hop result, so work through that list before you switch @vue/compat MODE to 3.',
                'The codemod needs @vue/compat in package.json, which the vue-27-to-compat hop installs; run that hop first.',
            ],
            links: ['https://github.com/vuejs/vue-upgrade'],
        );
    }

    private function vue2To3Hop(): Hop
    {
        return new Hop(
            id: self::VUE_2_TO_3_ID,
            kind: HopKind::Vue,
            from: '2',
            to: '3',
            summary: 'Move a Vue 2 front end to Vue 3 (playbook only; Rector does not read Vue).',
            aliasOf: [self::VUE_26_TO_27_ID, self::VUE_27_TO_COMPAT_ID, self::VUE_COMPAT_CODEMOD_ID],
            notes: [
                'Booster stages this move as three Node hops, vue-26-to-27, vue-27-to-compat and vue-compat-codemod; this playbook covers the manual work between and around them.',
                'The vue-compat-codemod hop runs vue-upgrade-tool and keeps every manual item it lists in the hop result; work through that list, then switch @vue/compat MODE to 3 and remove the compat package.',
                'Vue 2 reached end of life on 2023-12-31; the policy lists it as past its grace window.',
                'Moving to Vue 3 usually means moving Vuex to Pinia and Vue Router 3 to 4.',
            ],
            links: ['https://v3-migration.vuejs.org/'],
        );
    }
}
