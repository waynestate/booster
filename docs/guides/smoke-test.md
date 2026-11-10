---
title: A smoke test for WSU apps
description: One small test that proves the app boots and its front door still sends guests to CAS, and a script that does the same for a raw PHP app.
---

# A smoke test for WSU apps

This page shows the one small test that gives the gate something real to prove. Next: [What a hop does](hops.md).

Laravel's stock tests do not fit a WSU app. `tests/Feature/ExampleTest.php` asks for `/` and expects 200, but on a
real app the front page sits behind CAS, so an unauthenticated request is a redirect to the sign-in URL, never a
200. The stock suite also assumes an `APP_URL` that points somewhere real and an `APP_KEY` that exists; a fresh
checkout has neither, so the suite is red before any change, and even a green run of it proves nothing about the
upgrade. When Booster sees only the two stock example tests, it says so next to the baseline:

    The app's tests are only Laravel's stock examples, so the gate cannot prove anything. See "A smoke test for WSU apps" in the README.

Write one small test that proves the app boots and its front door still works. It needs a few environment values in
`phpunit.xml`, because the test environment starts empty:

```xml
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="APP_KEY" value="base64:c21va2UtdGVzdC1rZXkwMDAwMDAwMDAwMDAwMDAwMDA="/>
    <env name="APP_URL" value="http://localhost"/>
</php>
```

`APP_KEY` can be any throwaway 32-byte key (`php artisan key:generate --show`); it never leaves the test
environment. If the app touches the database while booting, point the tests at an in-memory SQLite database:

```xml
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
```

And the test itself, in `tests/Feature/SmokeTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SmokeTest extends TestCase
{
    public function test_a_guest_is_sent_to_the_cas_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect();
        $this->assertStringContainsString('/login', (string) $response->headers->get('Location'));
    }
}
```

That is the whole point: the app boots, encryption and sessions work, and the front door still sends guests to CAS.
It passes on the old PHP and must still pass on the new one, which is what the gate is for.

Those values live in `phpunit.xml`, committed with the app. When you would rather not commit them, pass them on the
command line instead; Booster forwards each one to the baseline and every gate container with `-e`, and the report
names the keys only, never the values:

    booster run --path=<app> --test-env DB_CONNECTION=sqlite --test-env DB_DATABASE=:memory:

## A raw PHP app

A raw PHP app (no framework, often no tests at all) gets the built-in syntax check, which proves the files parse on
the new PHP and nothing more. Give it the same kind of smoke test, as a plain script with no PHPUnit to install:
load what every page loads and touch each library the app leans on, then exit 0. Save it as `tests/smoke.php`:

```php
<?php
// Smoke test for a raw PHP app: the bootstrap and the libraries load on this PHP.
// Exit 0 is a pass. Booster runs it on the old PHP first, then after every hop.

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        return true; // Deprecations are next year's hop. Delete these lines to fail on them now.
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require __DIR__ . '/../config/bootstrap.php'; // the file every page includes first

    // One line per library the app leans on, for example a template that renders:
    // $view = new Smarty(); $view->setCompileDir(sys_get_temp_dir()); $view->fetch('string:ok');

    echo "Smoke test passed on PHP " . PHP_VERSION . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
```

Point the gate at it with `--test-command`; the dry run's Next line keeps the flag, so the real run
gets it too:

    booster run --path=<app> --test-command "php tests/smoke.php" --dry-run

Or add `"scripts": {"test": "php tests/smoke.php"}` to the app's `composer.json`, and Booster runs `composer test`
without the flag. If the bootstrap connects to a database as it loads, give the script fake settings with
`--test-env`, or keep that call out of what the script loads: the gate container has no database server. A run with
`--vendored` runs the syntax check instead of your script on the steps before the move to Composer, because the hand-copied libraries may not load on that PHP yet;
your script runs from that step on.

The script proves that the code loads. The pages are the [replay gate](replay-gate.md)'s job: it asks the app for
real URLs on the old PHP and after every hop, and the two together are a fair gate for an app with no tests.
