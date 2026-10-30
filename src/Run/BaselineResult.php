<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * The baseline: the app's own tests, run once on the PHP the app is on now,
 * before the first hop touches anything. A red baseline means the suite was
 * already broken, so a later red gate is not Booster's doing.
 */
final readonly class BaselineResult
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /** composer install failed before the tests ran, so the suite's state is unknown. */
    public const INSTALL_FAILED = 'install-failed';

    /**
     * @param list<string> $output  the last lines of the run's output
     * @param string|null  $warning an advisory about the suite itself (for example that it holds only Laravel's stock examples)
     */
    public function __construct(
        public string $status,
        public ?string $php,
        public ?int $exitCode,
        public array $output = [],
        public ?string $warning = null,
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
        return [
            'status' => $this->status,
            'php' => $this->php,
            'exit_code' => $this->exitCode,
            'output' => $this->output,
            'warning' => $this->warning,
        ];
    }
}
