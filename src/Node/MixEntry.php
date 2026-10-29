<?php

declare(strict_types=1);

namespace Waynestate\Booster\Node;

/**
 * One asset entry in a webpack.mix.js: what goes in and what comes out.
 */
final readonly class MixEntry
{
    public const JS = 'js';

    public const SASS = 'sass';

    public const POSTCSS = 'postcss';

    public function __construct(
        public string $type,
        public string $input,
        public string $output,
    ) {}
}
