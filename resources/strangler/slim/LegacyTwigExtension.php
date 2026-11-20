<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legacy\Support;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * slim/twig-view's Twig functions for legacy templates rendered by the
 * Laravel half through TwigBridge (design 10.2): url_for, full_url_for,
 * base_path and is_current_url, answering as Slim's RouteParser does, from
 * every named legacy route's pattern (the strangler's manifest lists them).
 * A route that has moved keeps its legacy name, so the URL is the same.
 * Written by Booster's strangler (slim-to-laravel); app-owned.
 */
final class LegacyTwigExtension extends AbstractExtension
{
    /**
     * @param array<string, string> $routes legacy route name to its Slim pattern
     */
    public function __construct(
        private readonly array $routes,
        private readonly string $basePath = '',
        private readonly string $path = '/',
        private readonly string $origin = '',
    ) {}

    /** The extension for the current Laravel request. */
    public static function forRequest(): self
    {
        $twig = (array) (SlimAdapter::manifest()['twig'] ?? []);
        $request = request();

        return new self((array) ($twig['routes'] ?? []), $request->getBaseUrl(), '/' . ltrim($request->getPathInfo(), '/'), $request->getSchemeAndHttpHost());
    }

    public function getName(): string
    {
        return 'slim';
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('url_for', $this->urlFor(...)),
            new TwigFunction('full_url_for', $this->fullUrlFor(...)),
            new TwigFunction('is_current_url', $this->isCurrentUrl(...)),
            new TwigFunction('current_url', $this->currentUrl(...)),
            new TwigFunction('get_uri', fn(): string => $this->origin . $this->basePath . $this->path),
            new TwigFunction('base_path', fn(): string => $this->basePath),
        ];
    }

    /**
     * @param array<string, scalar> $data
     * @param array<string, mixed>  $query
     */
    public function urlFor(string $name, array $data = [], array $query = []): string
    {
        return $this->basePath . $this->relativeUrlFor($name, $data, $query);
    }

    /**
     * @param array<string, scalar> $data
     * @param array<string, mixed>  $query
     */
    public function fullUrlFor(string $name, array $data = [], array $query = []): string
    {
        return $this->origin . $this->urlFor($name, $data, $query);
    }

    /** @param array<string, scalar> $data */
    public function isCurrentUrl(string $name, array $data = []): bool
    {
        return $this->urlFor($name, $data) === $this->basePath . $this->path;
    }

    public function currentUrl(bool $withQueryString = false): string
    {
        $query = $withQueryString ? (string) request()->getQueryString() : '';

        return $this->basePath . $this->path . ($query === '' ? '' : '?' . $query);
    }

    /**
     * Slim's RouteParser::relativeUrlFor(): the longest form of the pattern
     * whose placeholders all have data (optional segments dropped from the
     * end), the placeholders filled, the query appended.
     *
     * @param array<string, scalar> $data
     * @param array<string, mixed>  $query
     */
    public function relativeUrlFor(string $name, array $data = [], array $query = []): string
    {
        $pattern = $this->routes[$name] ?? null;
        if ($pattern === null) {
            throw new \RuntimeException('Named route does not exist for name: ' . $name);
        }
        $url = null;
        foreach (array_reverse(self::forms($pattern)) as $form) {
            $missing = false;
            $filled = (string) preg_replace_callback('/\{\s*(\w+)\s*(?::[^{}]*(?:\{[^{}]*\}[^{}]*)*)?\}/', static function (array $m) use ($data, &$missing): string {
                if (!array_key_exists($m[1], $data)) {
                    $missing = true;

                    return '';
                }

                return (string) $data[$m[1]];
            }, $form);
            if (!$missing) {
                $url = $filled;
                break;
            }
        }
        if ($url === null) {
            throw new \InvalidArgumentException('Missing data for URL segment of route ' . $name);
        }
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * A pattern's forms, shortest first: "/[login[/]]" gives "/", "/login"
     * and "/login/".
     *
     * @return list<string>
     */
    public static function forms(string $pattern): array
    {
        $outside = '';
        $cuts = [];
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '{') {
                $depth = 0;
                for ($j = $i; $j < $length; $j++) {
                    $depth += $pattern[$j] === '{' ? 1 : ($pattern[$j] === '}' ? -1 : 0);
                    if ($depth === 0) {
                        break;
                    }
                }
                $outside .= substr($pattern, $i, $j - $i + 1);
                $i = $j;

                continue;
            }
            if ($char === '[') {
                $cuts[] = strlen($outside);

                continue;
            }
            if ($char !== ']') {
                $outside .= $char;
            }
        }
        $forms = [];
        foreach ($cuts as $cut) {
            $forms[] = substr($outside, 0, $cut);
        }
        $forms[] = $outside;

        return array_values(array_map(static fn(string $f): string => $f === '' ? '/' : $f, $forms));
    }
}
