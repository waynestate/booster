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

    /**
     * Where the entry actually lands, relative to public/. When the output
     * argument is a directory (the common Mix shape), Mix keeps the input's
     * file name, and Sass compiles to .css.
     */
    public function resolvedOutput(): string
    {
        $output = preg_replace('#^public/#', '', $this->output) ?? $this->output;
        $last = substr($output, strrpos($output, '/') + 1);
        if ($last !== '' && str_contains($last, '.')) {
            return $output;
        }
        $name = basename(str_replace('\\', '/', $this->input));
        if ($this->type === self::SASS) {
            $name = preg_replace('/\.(?:scss|sass)$/i', '.css', $name) ?? $name;
        }
        $dir = rtrim($output, '/');

        return $dir === '' ? $name : $dir . '/' . $name;
    }
}
