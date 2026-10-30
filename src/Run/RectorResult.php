<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * What one Rector run did (or, in a dry run, would do).
 */
final readonly class RectorResult
{
    /**
     * Prefix of the informational error added when Rector's JSON report contained
     * invalid UTF-8 that Booster repaired with JSON_INVALID_UTF8_SUBSTITUTE. It is a
     * note, not a failure: HopExecutor must not stop the run because of it.
     */
    public const REPAIRED_UTF8_NOTE = 'Rector output contained invalid UTF-8 and was repaired';

    /**
     * @param list<array{file: string, diff: string}> $files  files that changed, relative to the app
     * @param list<string>                            $errors Rector's own errors (parse failures and the like)
     * @param array<string, int>                      $rules  short rule name to number of files the rule changed
     */
    public function __construct(
        public array $files,
        public array $errors,
        public array $rules = [],
    ) {}

    public function changedCount(): int
    {
        return count($this->files);
    }

    /**
     * @return array<string, int> short rule name to number of files the rule changed
     */
    public function rules(): array
    {
        return $this->rules;
    }
}
