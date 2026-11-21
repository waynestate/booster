<?php

namespace App\Http\Controllers\Legacy\Cake;

/**
 * Written by Booster's strangler (cakephp-to-laravel): an isUnique or
 * existsIn rule object, before CakeRulesChecker::add() files it.
 */
final class CakeRule
{
    /**
     * @param list<string> $fields
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $fields,
        public readonly ?string $target,
        public readonly ?string $message,
        public readonly bool $nulls,
    ) {}
}
