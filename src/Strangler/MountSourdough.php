<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

use Waynestate\Booster\Run\TargetImageRunner;
use Waynestate\Booster\ToolError;

/**
 * Bakes the Laravel half with Sourdough's own CLI, `sourdough new --laravel`,
 * at the version pinned here, inside the gate image (PHP plus Composer, with
 * the network, and the Composer cache when BOOSTER_COMPOSER_CACHE is set).
 * Nothing runs on the host. Sourdough bakes with `--no-install`, so the
 * folder holds the Laravel skeleton plus Sourdough's overlay, no vendor/.
 *
 * Playbook is off by default here: Sourdough checks that the developer can
 * read the private package before it writes anything, and the gate container
 * holds no git.wayne.edu credentials. The mount's report says how to add it.
 */
final class MountSourdough implements MountBaker
{
    /** The Sourdough release the mount bakes with (Packagist waynestate/sourdough). */
    public const VERSION = '0.4.2';

    /** The PHP the bake runs on: Sourdough needs ^8.3; the Laravel half's floor is Sourdough's call. */
    public const PHP = '8.5';

    public function __construct(
        private readonly TargetImageRunner $gate,
        private readonly bool $playbook = false,
        private readonly bool $advisor = true,
        private readonly string $php = self::PHP,
    ) {}

    public function bake(string $targetDir, string $name): void
    {
        $result = $this->gate->run($targetDir, $this->php, 'sh -c ' . escapeshellarg(self::script($name, $this->playbook, $this->advisor)));
        $outcome = TargetImageRunner::gateOutcome($result);
        if (!$outcome->successful() || !is_file($targetDir . '/composer.json') || !is_file($targetDir . '/artisan')) {
            throw new ToolError(sprintf(
                "Sourdough %s could not bake the Laravel half (exit %d), so nothing was mounted.\n%s",
                self::VERSION,
                $outcome->exitCode,
                implode("\n", array_slice($outcome->output, -20)),
            ));
        }
    }

    public function describe(): string
    {
        $flags = '--laravel' . ($this->playbook ? '' : ' --no-playbook') . ($this->advisor ? '' : ' --no-advisor');

        return sprintf('Sourdough %s (sourdough new %s) on PHP %s', self::VERSION, $flags, $this->php);
    }

    /**
     * The shell script the gate container runs: install the pinned Sourdough
     * to /tmp, then bake into /app (the empty folder). Exposed for tests.
     */
    public static function script(string $name, bool $playbook, bool $advisor): string
    {
        $new = ['php', '/tmp/booster-sourdough/bin/sourdough', 'new', '/app', '--laravel', '--no-input', '--name', $name];
        if (!$playbook) {
            $new[] = '--no-playbook';
        }
        if (!$advisor) {
            $new[] = '--no-advisor';
        }

        return implode("\n", [
            'set -e',
            'composer create-project ' . escapeshellarg('waynestate/sourdough:' . self::VERSION) . ' /tmp/booster-sourdough --no-dev --no-interaction --no-progress --prefer-dist >/tmp/booster-sourdough.log 2>&1 || { tail -n 30 /tmp/booster-sourdough.log; exit 1; }',
            implode(' ', array_map('escapeshellarg', $new)),
        ]);
    }
}
