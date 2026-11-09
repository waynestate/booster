<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * The baseline: what the gate runs, once, on the PHP the app is on now,
 * before the first hop touches anything. That is the app's own tests, or the
 * built-in syntax check when the app has no tests. A red baseline means the
 * gate was already failing, so a later red gate is not Booster's doing.
 */
final readonly class BaselineResult
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /** composer install failed before the tests ran, so the suite's state is unknown. */
    public const INSTALL_FAILED = 'install-failed';

    /** The baseline ran the app's own test suite. */
    public const GATE_TESTS = 'tests';

    /** The baseline was the built-in php -l syntax check, because the app has no tests. */
    public const GATE_SYNTAX = 'syntax';

    /** The baseline was the command the user passed with --test-command. */
    public const GATE_CUSTOM = 'custom';

    /**
     * @param list<string> $output   the last lines of the run's output
     * @param string|null  $warning  an advisory about the suite itself (for example that it holds only Laravel's stock examples)
     * @param string       $gateKind which gate the baseline ran: the app's tests, the built-in syntax check, or a --test-command
     * @param string|null  $advice   for a failed install whose cause Booster can name, what to run instead of the general advice
     */
    public function __construct(
        public string $status,
        public ?string $php,
        public ?int $exitCode,
        public array $output = [],
        public ?string $warning = null,
        public string $gateKind = self::GATE_TESTS,
        public ?string $advice = null,
    ) {}

    public function failed(): bool
    {
        return in_array($this->status, [self::FAILED, self::INSTALL_FAILED], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'status' => $this->status,
            'php' => $this->php,
            'exit_code' => $this->exitCode,
            'output' => $this->output,
            'warning' => $this->warning,
            'gate' => $this->gateKind,
        ];
        // Only a failed install with a named cause carries the key, so every
        // other report keeps its shape.
        if ($this->advice !== null) {
            $data['advice'] = $this->advice;
        }

        return $data;
    }
}
