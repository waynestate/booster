<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legacy\Support;

use ArrayAccess;
use Illuminate\Contracts\Container\Container;
use LogicException;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The legacy services, as Laravel bindings under the `legacy.` prefix
 * (Booster's strangler, design section 10.2). App-owned. Legacy keys such as
 * session, view, config, request, user and flash mean something else in
 * Laravel, so each is bound as `legacy.<key>`; this shim lets legacy code
 * keep writing `$c['x']`, `$c->x` and `$c->get('x')`.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class LegacyContainer implements ArrayAccess, ContainerInterface
{
    public function __construct(private readonly Container $app) {}

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new RuntimeException('No legacy service ' . $id . ' (bind legacy.' . $id . ' in App\Providers\LegacyServiceProvider)');
        }

        return $this->app->make('legacy.' . $id);
    }

    public function has(string $id): bool
    {
        return $this->app->bound('legacy.' . $id);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Legacy services are bound in App\Providers\LegacyServiceProvider, not set at run time');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Legacy services are bound in App\Providers\LegacyServiceProvider, not unset at run time');
    }

    public function __get(string $name): mixed
    {
        return $this->has($name) ? $this->get($name) : null;
    }

    public function __isset(string $name): bool
    {
        return $this->has($name);
    }
}
