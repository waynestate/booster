<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Console\Invocation;

final class InvocationTest extends TestCase
{
    public function test_a_booster_path_keeps_its_path_as_typed(): void
    {
        self::assertSame('php /home/u/booster/bin/booster', Invocation::program('/home/u/booster/bin/booster'));
        self::assertSame('php bin/booster', Invocation::program('bin/booster'));
        self::assertSame('php C:\\tools\\booster', Invocation::program('C:\\tools\\booster'));
    }

    public function test_a_phar_keeps_its_path_as_typed(): void
    {
        self::assertSame('php booster.phar', Invocation::program('booster.phar'));
        self::assertSame('php /opt/booster.phar', Invocation::program('/opt/booster.phar'));
    }

    public function test_the_bare_alias_stays_bare_until_a_local_file_exists(): void
    {
        $dir = $this->tempDir();
        $old = getcwd();
        chdir($dir);
        try {
            self::assertSame('booster', Invocation::program('booster'));
            file_put_contents('booster', '#!/usr/bin/env php');
            self::assertSame('php booster', Invocation::program('booster'));
        } finally {
            chdir($old);
        }
    }

    public function test_the_test_runner_falls_back_to_the_checkout_form(): void
    {
        self::assertSame('php bin/booster', Invocation::program(''));
        self::assertSame('php bin/booster', Invocation::program('phpunit'));
        // No argument means "read argv[0]", which under phpunit is not a booster path.
        self::assertSame('php bin/booster', Invocation::program(null));
    }

    public function test_path_arg_quotes_only_when_there_is_a_space(): void
    {
        self::assertSame('examples/raw-php', Invocation::pathArg('examples/raw-php'));
        self::assertSame('"my app"', Invocation::pathArg('my app'));
        self::assertSame('.', Invocation::pathArg('.'));
    }
}
