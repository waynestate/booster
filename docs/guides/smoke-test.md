---
title: A smoke test for WSU apps
description: One small test that proves the app boots and its front door still sends guests to CAS.
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
