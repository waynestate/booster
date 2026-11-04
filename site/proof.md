---
title: "BOOSTER proof: real output"
description: "Booster plans and runs PHP and Laravel upgrades one hop at a time on Rector, gates every hop with your tests on the target PHP, and never commits or pushes for you."
url: https://booster.apps.wayne.edu/proof.html
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Real output, from Booster's own example apps

Captured on 2026-11-03 with Booster 1.8.1. Each app below is one of the example apps in the `examples/` folder. A dry run changes nothing. This page is regenerated each release.

## laravel-blade

### Plan

`booster plan --path=examples/laravel-blade`

```
BOOSTER plan for examples/laravel-blade
  app type: laravel-blade
  PHP: 8.2 -> 8.5
  Laravel: 10 -> 13
  PHPUnit: 10 -> 13
  policy: waynestate/creed 0.1.4 (today 2026-11-03)

1. phpunit-10-to-11
   Move PHPUnit 10 tests to PHPUnit 11.
   After this step your tests run in Docker.
2. laravel-10-to-11
   Move a Laravel 10 app to Laravel 11.
   After this step your tests run in Docker.
3. laravel-11-to-12
   Move a Laravel 11 app to Laravel 12.
   After this step your tests run in Docker.
   Behavior changes to check:
   When the app does not define a local disk, its root now defaults to storage/app/private instead of storage/app (config/filesystems.php)
     keep old: define the local disk with 'root' => storage_path('app')
     check: Check where Storage::disk('local') writes after the hop
4. php-82-to-83
   Move PHP 8.2 code to PHP 8.3.
   After this step your tests run in Docker.
   Removed between PHP 8.2 and PHP 8.3: none found.
5. phpunit-11-to-12
   Move PHPUnit 11 tests to PHPUnit 12.
   After this step your tests run in Docker.
6. laravel-12-to-13
   Move a Laravel 12 app to Laravel 13.
   After this step your tests run in Docker.
7. php-83-to-84
   Move PHP 8.3 code to PHP 8.4.
   After this step your tests run in Docker.
   Removed between PHP 8.3 and PHP 8.4: none found.
8. phpunit-12-to-13
   Move PHPUnit 12 tests to PHPUnit 13.
   After this step your tests run in Docker.
9. php-84-to-85
   Move PHP 8.4 code to PHP 8.5.
   After this step your tests run in Docker.
   Removed between PHP 8.4 and PHP 8.5: none found.

PHPCompatibility check for PHP 8.3: no findings.

PHPStan on PHP 8.5: 5 findings (code quality hints, not upgrade blockers).
   app/Models/Post.php:7 [class.notFound] Class App\Models\Post extends unknown class Illuminate\Database\Eloquent\Model.
   app/Models/Post.php:9 [missingType.property] Property App\Models\Post::$casts has no type specified.
   tests/PostTest.php:18 [property.onlyWritten] Property App\Tests\PostTest::$repository is never read, only written.
   tests/PostTest.php:27 [staticMethod.deprecated] Call to deprecated method isType() of class PHPUnit\Framework\Assert:
https://github.com/sebastianbergmann/phpunit/issues/6052
   tests/PostTest.php:33 [method.alreadyNarrowedType] Call to method PHPUnit\Framework\Assert::assertNotNull() with PHPUnit\Framework\MockObject\Rule\AnyInvokedCount will always evaluate to true.
   Hint: Laravel app without Larastan: Eloquent and facade calls show as errors. composer require --dev larastan/larastan for a cleaner scan.

Nothing was changed. The numbered list above is the order Booster would do things; "PHPCompatibility check" and "PHPStan" are advice
for you to read, not errors.
Booster does every numbered step above when you run it.
Preview the edits: php bin/booster run --path=examples/laravel-blade --dry-run
(The real run needs Docker, and your app committed in git: php bin/booster run --path=examples/laravel-blade)
```

### Dry run

`booster run --path=examples/laravel-blade --dry-run`

```
BOOSTER dry run (nothing was changed)

Baseline: SKIPPED (no tests ran in this run). A real run first runs your tests as they are now (the baseline), then again after every step (the gate).

1. phpunit-10-to-11: PREVIEWED (1 file)
   would run: composer update -W phpunit/phpunit
   Run phpunit --migrate-configuration after this hop; Rector rewrites the test code but not phpunit.xml.
   --- tests/PostTest.php
   --- Original
   +++ New
   @@ -15,11 +15,14 @@
     */
    final class PostTest extends TestCase
    {
   -    private \PHPUnit\Framework\MockObject\MockObject $repository;
   +    /**
   +     * @var \PHPUnit\Framework\MockObject\Stub&\App\Models\Post
   +     */
   +    private \PHPUnit\Framework\MockObject\Stub $repository;
    
        protected function setUp(): void
        {
   -        $this->repository = $this->createMock(Post::class);
   +        $this->repository = $this->createStub(Post::class);
        }
    
        public function testItFindsAPost(): void
   
2. laravel-10-to-11: PREVIEWED (incomplete)
   would run: composer update -W laravel/framework
   Preview incomplete: laravel-10-to-11 needs the app's vendor/ to recognize Laravel classes, and it is missing. Run composer install in the app, then dry-run again.
   If your database server is old, check its version against the minimum MySQL (or other database) version Laravel 11 needs before you apply this hop.
   Update first-party packages (Sanctum, Livewire, Inertia) and your own forks by hand; Rector does not touch them.
3. laravel-11-to-12: PREVIEWED (incomplete)
   would run: composer update -W laravel/framework
   Preview incomplete: laravel-11-to-12 needs the app's vendor/ to recognize Laravel classes, and it is missing. Run composer install in the app, then dry-run again.
   If your database server is old, check its version against the minimum MySQL (or other database) version Laravel 12 needs before you apply this hop.
   Update first-party packages (Sanctum, Livewire, Inertia) and your own forks by hand; Rector does not touch them.
   Behavior changes to check:
   When the app does not define a local disk, its root now defaults to storage/app/private instead of storage/app (config/filesystems.php)
     keep old: define the local disk with 'root' => storage_path('app')
     check: Check where Storage::disk('local') writes after the hop
4. php-82-to-83: PREVIEWED (0 files)
   No edits needed for this step (dry run).
5. phpunit-11-to-12: PREVIEWED (1 file)
   would run: composer update -W phpunit/phpunit
   Run phpunit --migrate-configuration after this hop; Rector rewrites the test code but not phpunit.xml.
   --- tests/PostTest.php
   --- Original
   +++ New
   @@ -15,16 +15,19 @@
     */
    final class PostTest extends TestCase
    {
   -    private \PHPUnit\Framework\MockObject\MockObject $repository;
   +    /**
   +     * @var \PHPUnit\Framework\MockObject\Stub&\App\Models\Post
   +     */
   +    private \PHPUnit\Framework\MockObject\Stub $repository;
    
        protected function setUp(): void
        {
   -        $this->repository = $this->createMock(Post::class);
   +        $this->repository = $this->createStub(Post::class);
        }
    
        public function testItFindsAPost(): void
        {
   -        $this->assertThat([new Post()], $this->isType('array'));
   +        $this->assertThat([new Post()], $this->isArray());
        }
    
        public function testItAcceptsAnyInvokedCount(): void
   
6. laravel-12-to-13: PREVIEWED (incomplete)
   would run: composer update -W laravel/framework
   Preview incomplete: laravel-12-to-13 needs the app's vendor/ to recognize Laravel classes, and it is missing. Run composer install in the app, then dry-run again.
   If your database server is old, check its version against the minimum MySQL (or other database) version Laravel 13 needs before you apply this hop.
   Update first-party packages (Sanctum, Livewire, Inertia) and your own forks by hand; Rector does not touch them.
7. php-83-to-84: PREVIEWED (0 files)
   No edits needed for this step (dry run).
   General notes for this step (nothing found in your app, just good to know):
   Implicitly nullable parameters (T $a = null) are deprecated; fputcsv(), fgetcsv() and str_getcsv() want an explicit escape argument.
8. phpunit-12-to-13: PREVIEWED (1 file)
   would run: composer update -W phpunit/phpunit
   Run phpunit --migrate-configuration after this hop; Rector rewrites the test code but not phpunit.xml.
   --- tests/PostTest.php
   --- Original
   +++ New
   @@ -15,21 +15,24 @@
     */
    final class PostTest extends TestCase
    {
   -    private \PHPUnit\Framework\MockObject\MockObject $repository;
   +    /**
   +     * @var \PHPUnit\Framework\MockObject\Stub&\App\Models\Post
   +     */
   +    private \PHPUnit\Framework\MockObject\Stub $repository;
    
        protected function setUp(): void
        {
   -        $this->repository = $this->createMock(Post::class);
   +        $this->repository = $this->createStub(Post::class);
        }
    
        public function testItFindsAPost(): void
        {
   -        $this->assertThat([new Post()], $this->isType('array'));
   +        $this->assertThat([new Post()], $this->isArray());
        }
    
        public function testItAcceptsAnyInvokedCount(): void
        {
   -        $matcher = $this->any();
   +        $matcher = new \PHPUnit\Framework\MockObject\Rule\AnyInvokedCount();
            $this->assertNotNull($matcher);
        }
    }
   
9. php-84-to-85: PREVIEWED (0 files)
   No edits needed for this step (dry run).
   General notes for this step (nothing found in your app, just good to know):
   Deprecated: the (boolean), (integer), (double) and (binary) casts, the backtick operator, __sleep() and __wakeup(), curl_close() and imagedestroy().
   PDO driver constants such as PDO::MYSQL_ATTR_* move to Pdo\Mysql::*; check them by hand.

Unique files across hops: 1. Later hops were previewed against the original tree, so the same file can appear in several hops.

RESULT: PASS (previewed, no tests ran)

Dry run: these changes were previewed, none were applied.
Nothing was changed. RESULT: PASS means the preview worked; no tests ran.
Next, if the edits look right: php bin/booster run --path=examples/laravel-blade
(It needs Docker and a clean git folder. Commit or stash first.)
```

## laravel-blade-vue

### Plan

`booster plan --path=examples/laravel-blade-vue`

```
BOOSTER plan for examples/laravel-blade-vue
  app type: laravel-blade-vue
  PHP: 8.3 -> 8.5
  Laravel: 12 -> 13
  policy: waynestate/creed 0.1.4 (today 2026-11-03)

1. laravel-12-to-13
   Move a Laravel 12 app to Laravel 13.
   After this step your tests run in Docker.
2. php-83-to-84
   Move PHP 8.3 code to PHP 8.4.
   After this step your tests run in Docker.
   Removed between PHP 8.3 and PHP 8.4: none found.
3. php-84-to-85
   Move PHP 8.4 code to PHP 8.5.
   After this step your tests run in Docker.
   Removed between PHP 8.4 and PHP 8.5: none found.
4. node-pin-24
   Pin the app to Node 26: set engines.node in package.json and write .nvmrc.
   After this step your tests run in Docker.

PHPCompatibility check for PHP 8.4: no findings.

PHPStan scan: could not run
   phpstan exited 1 without a JSON report.
   [ERROR] No files found to analyse.

Nothing was changed. The numbered list above is the order Booster would do things; "PHPCompatibility check" and "PHPStan" are advice
for you to read, not errors.
Booster does every numbered step above when you run it.
Preview the edits: php bin/booster run --path=examples/laravel-blade-vue --dry-run
(The real run needs Docker, and your app committed in git: php bin/booster run --path=examples/laravel-blade-vue)
```

### Dry run

`booster run --path=examples/laravel-blade-vue --dry-run`

```
BOOSTER dry run (nothing was changed)

Baseline: SKIPPED (no tests ran in this run). A real run first runs your tests as they are now (the baseline), then again after every step (the gate).

1. laravel-12-to-13: PREVIEWED (incomplete)
   would run: composer update -W laravel/framework
   Preview incomplete: laravel-12-to-13 needs the app's vendor/ to recognize Laravel classes, and it is missing. Run composer install in the app, then dry-run again.
   If your database server is old, check its version against the minimum MySQL (or other database) version Laravel 13 needs before you apply this hop.
   Update first-party packages (Sanctum, Livewire, Inertia) and your own forks by hand; Rector does not touch them.
2. php-83-to-84: PREVIEWED (0 files)
   No edits needed for this step (dry run).
   General notes for this step (nothing found in your app, just good to know):
   Implicitly nullable parameters (T $a = null) are deprecated; fputcsv(), fgetcsv() and str_getcsv() want an explicit escape argument.
3. php-84-to-85: PREVIEWED (0 files)
   No edits needed for this step (dry run).
   General notes for this step (nothing found in your app, just good to know):
   Deprecated: the (boolean), (integer), (double) and (binary) casts, the backtick operator, __sleep() and __wakeup(), curl_close() and imagedestroy().
   PDO driver constants such as PDO::MYSQL_ATTR_* move to Pdo\Mysql::*; check them by hand.
4. node-pin-24: PREVIEWED (0 files)
   Dry run: would set engines.node to >=26 in package.json and write .nvmrc with 26.
   The pin is a floor, not a ceiling: engines.node ">=26" still allows newer Node majors.
   Later front-end hops run in the same node:26 container, so this hop comes before them.

RESULT: PASS (previewed, no tests ran)

Nothing was changed.
```

## laravel-inertia-vue

### Plan

`booster plan --path=examples/laravel-inertia-vue`

```
BOOSTER plan for examples/laravel-inertia-vue
  app type: laravel-inertia-vue
  PHP: 8.3 -> 8.5
  Laravel: 12 -> 13
  policy: waynestate/creed 0.1.4 (today 2026-11-03)

1. laravel-12-to-13
   Move a Laravel 12 app to Laravel 13.
   After this step your tests run in Docker.
2. php-83-to-84
   Move PHP 8.3 code to PHP 8.4.
   After this step your tests run in Docker.
   Removed between PHP 8.3 and PHP 8.4: none found.
3. php-84-to-85
   Move PHP 8.4 code to PHP 8.5.
   After this step your tests run in Docker.
   Removed between PHP 8.4 and PHP 8.5: none found.
4. node-pin-24
   Pin the app to Node 26: set engines.node in package.json and write .nvmrc.
   After this step your tests run in Docker.

PHPCompatibility check for PHP 8.4: no findings.

PHPStan scan: could not run
   phpstan exited 1 without a JSON report.
   [ERROR] No files found to analyse.

Nothing was changed. The numbered list above is the order Booster would do things; "PHPCompatibility check" and "PHPStan" are advice
for you to read, not errors.
Booster does every numbered step above when you run it.
Preview the edits: php bin/booster run --path=examples/laravel-inertia-vue --dry-run
(The real run needs Docker, and your app committed in git: php bin/booster run --path=examples/laravel-inertia-vue)
```

### Dry run

`booster run --path=examples/laravel-inertia-vue --dry-run`

```
BOOSTER dry run (nothing was changed)

Baseline: SKIPPED (no tests ran in this run). A real run first runs your tests as they are now (the baseline), then again after every step (the gate).

1. laravel-12-to-13: PREVIEWED (incomplete)
   would run: composer update -W laravel/framework
   Preview incomplete: laravel-12-to-13 needs the app's vendor/ to recognize Laravel classes, and it is missing. Run composer install in the app, then dry-run again.
   If your database server is old, check its version against the minimum MySQL (or other database) version Laravel 13 needs before you apply this hop.
   Update first-party packages (Sanctum, Livewire, Inertia) and your own forks by hand; Rector does not touch them.
2. php-83-to-84: PREVIEWED (0 files)
   No edits needed for this step (dry run).
   General notes for this step (nothing found in your app, just good to know):
   Implicitly nullable parameters (T $a = null) are deprecated; fputcsv(), fgetcsv() and str_getcsv() want an explicit escape argument.
3. php-84-to-85: PREVIEWED (0 files)
   No edits needed for this step (dry run).
   General notes for this step (nothing found in your app, just good to know):
   Deprecated: the (boolean), (integer), (double) and (binary) casts, the backtick operator, __sleep() and __wakeup(), curl_close() and imagedestroy().
   PDO driver constants such as PDO::MYSQL_ATTR_* move to Pdo\Mysql::*; check them by hand.
4. node-pin-24: PREVIEWED (0 files)
   Dry run: would set engines.node to >=26 in package.json and write .nvmrc with 26.
   The pin is a floor, not a ceiling: engines.node ">=26" still allows newer Node majors.
   Later front-end hops run in the same node:26 container, so this hop comes before them.

RESULT: PASS (previewed, no tests ran)

Nothing was changed.
```

## laravel-wayne-cas

### Plan

`booster plan --path=examples/laravel-wayne-cas`

```
BOOSTER plan for examples/laravel-wayne-cas
  app type: laravel-blade
  PHP: 8.3 -> 8.5
  Laravel: 12 -> 13
  policy: waynestate/creed 0.1.4 (today 2026-11-03)

1. laravel-12-to-13
   Move a Laravel 12 app to Laravel 13.
   After this step your tests run in Docker.
2. php-83-to-84
   Move PHP 8.3 code to PHP 8.4.
   After this step your tests run in Docker.
   Removed between PHP 8.3 and PHP 8.4: none found.
3. php-84-to-85
   Move PHP 8.4 code to PHP 8.5.
   After this step your tests run in Docker.
   Removed between PHP 8.4 and PHP 8.5: none found.

Warning: The app uses wayne-cas. Add --sesame to plan the swap to SESAME.

Warning: Not checked: cit-web-infra/wayne-cas (not on Packagist or unreachable).

PHPCompatibility check for PHP 8.4: no findings.

PHPStan on PHP 8.5: 16 findings (code quality hints, not upgrade blockers).
   app/Http/Kernel.php:7 [missingType.property] Property App\Http\Kernel::$middlewareGroups has no type specified.
   app/Http/Kernel.php:9 [class.notFound] Class App\Http\Middleware\EncryptCookies not found.
   app/Http/Kernel.php:10 [class.notFound] Class Illuminate\Session\Middleware\StartSession not found.
   app/Http/Kernel.php:15 [missingType.property] Property App\Http\Kernel::$routeMiddleware has no type specified.
   app/Http/Kernel.php:16 [class.notFound] Class WSU\WayneCas\WayneAuthenticate not found.
   (11 more; run `booster scan --phpstan` to see all)
   Hint: Laravel app without Larastan: Eloquent and facade calls show as errors. composer require --dev larastan/larastan for a cleaner scan.

Nothing was changed. The numbered list above is the order Booster would do things; "PHPCompatibility check" and "PHPStan" are advice
for you to read, not errors.
Booster does every numbered step above when you run it.
Preview the edits: php bin/booster run --path=examples/laravel-wayne-cas --dry-run
(The real run needs Docker, and your app committed in git: php bin/booster run --path=examples/laravel-wayne-cas)
```

### Dry run

`booster run --path=examples/laravel-wayne-cas --dry-run`

```
BOOSTER dry run (nothing was changed)

Baseline: SKIPPED (no tests ran in this run). A real run first runs your tests as they are now (the baseline), then again after every step (the gate).

1. laravel-12-to-13: PREVIEWED (incomplete)
   would run: composer update -W laravel/framework
   Preview incomplete: laravel-12-to-13 needs the app's vendor/ to recognize Laravel classes, and it is missing. Run composer install in the app, then dry-run again.
   If your database server is old, check its version against the minimum MySQL (or other database) version Laravel 13 needs before you apply this hop.
   Update first-party packages (Sanctum, Livewire, Inertia) and your own forks by hand; Rector does not touch them.
2. php-83-to-84: PREVIEWED (0 files)
   No edits needed for this step (dry run).
   General notes for this step (nothing found in your app, just good to know):
   Implicitly nullable parameters (T $a = null) are deprecated; fputcsv(), fgetcsv() and str_getcsv() want an explicit escape argument.
3. php-84-to-85: PREVIEWED (0 files)
   No edits needed for this step (dry run).
   General notes for this step (nothing found in your app, just good to know):
   Deprecated: the (boolean), (integer), (double) and (binary) casts, the backtick operator, __sleep() and __wakeup(), curl_close() and imagedestroy().
   PDO driver constants such as PDO::MYSQL_ATTR_* move to Pdo\Mysql::*; check them by hand.

RESULT: PASS (previewed, no tests ran)

Nothing was changed.
```

## raw-php

### Plan

`booster plan --path=examples/raw-php`

```
BOOSTER plan for examples/raw-php
  app type: raw-php
  PHP: 7.4 -> 8.5
  policy: waynestate/creed 0.1.4 (today 2026-11-03)

1. php-74-to-80
   Move PHP 7.4 code to PHP 8.0.
   After this step your tests run in Docker.
   Give this hop the most review time.
   Removed between PHP 7.4 and PHP 8.0: none found.
   Behavior changes to check:
   Loose comparison of a number with a non-numeric string is now false: 0 == "foo", 0 == "" and 42 == "42foo" flip from true to false; < / <= against negative numbers also flip for such strings
     check: Audit loose comparisons of request parameters and string DB columns
   Default error_reporting is now E_ALL (previously excluded E_NOTICE and E_DEPRECATED); notices and deprecations now surface in logs and, with display_errors on, in output
     keep old: error_reporting = E_ALL & ~E_NOTICE & ~E_DEPRECATED in php.ini
     check: Watch error logs and rendered pages for new notices after the hop
2. php-80-to-81
   Move PHP 8.0 code to PHP 8.1.
   After this step your tests run in Docker.
   Removed between PHP 8.0 and PHP 8.1: none found.
3. php-81-to-82
   Move PHP 8.1 code to PHP 8.2.
   After this step your tests run in Docker.
   Removed between PHP 8.1 and PHP 8.2: none found.
4. php-82-to-83
   Move PHP 8.2 code to PHP 8.3.
   After this step your tests run in Docker.
   Removed between PHP 8.2 and PHP 8.3: none found.
5. php-83-to-84
   Move PHP 8.3 code to PHP 8.4.
   After this step your tests run in Docker.
   Removed between PHP 8.3 and PHP 8.4: none found.
6. php-84-to-85
   Move PHP 8.4 code to PHP 8.5.
   After this step your tests run in Docker.
   Removed between PHP 8.4 and PHP 8.5: none found.

PHPCompatibility check for PHP 8.0: no findings.

PHPStan on PHP 8.5: 3 findings (code quality hints, not upgrade blockers).
   src/Legacy.php:20 [missingType.parameter] Method Example\Legacy::className() has parameter $object with no type specified.
   src/Legacy.php:22 [return.type] Method Example\Legacy::className() should return string but returns class-string|false.
   src/Legacy.php:25 [parameter.implicitlyNullable] Deprecated in PHP 8.4: Parameter #1 $label (string) is implicitly nullable via default value null.

Nothing was changed. The numbered list above is the order Booster would do things; "PHPCompatibility check" and "PHPStan" are advice
for you to read, not errors.
Booster does every numbered step above when you run it.
Preview the edits: php bin/booster run --path=examples/raw-php --dry-run
(The real run needs Docker, and your app committed in git: php bin/booster run --path=examples/raw-php)
```

### Dry run

`booster run --path=examples/raw-php --dry-run`

```
BOOSTER dry run (nothing was changed)

Baseline: SKIPPED (no tests ran in this run). A real run first runs your tests as they are now (the baseline), then again after every step (the gate).

1. php-74-to-80: PREVIEWED (1 file)
   Largest risk hop: loose comparison of numbers and strings changed, many warnings became thrown errors, and arithmetic on arrays throws.
   Write or extend tests before applying this hop; only tests and review find behavior changes here.
   Behavior changes to check:
   Loose comparison of a number with a non-numeric string is now false: 0 == "foo", 0 == "" and 42 == "42foo" flip from true to false; < / <= against negative numbers also flip for such strings
     check: Audit loose comparisons of request parameters and string DB columns
   Default error_reporting is now E_ALL (previously excluded E_NOTICE and E_DEPRECATED); notices and deprecations now surface in logs and, with display_errors on, in output
     keep old: error_reporting = E_ALL & ~E_NOTICE & ~E_DEPRECATED in php.ini
     check: Watch error logs and rendered pages for new notices after the hop
   --- src/Legacy.php
   --- Original
   +++ New
   @@ -4,22 +4,18 @@
    
    class Legacy
    {
   -    /** @var string */
   -    private $name;
   -
   -    public function __construct(string $name)
   +    public function __construct(private string $name)
        {
   -        $this->name = $name;
        }
    
        public function mentionsAdmin(string $text): bool
        {
   -        return strpos($text, 'admin') !== false;
   +        return str_contains($text, 'admin');
        }
    
        public function className($object): string
        {
   -        return get_class($object);
   +        return $object::class;
        }
    
        public function label(string $label = null): string
   
2. php-80-to-81: PREVIEWED (0 files)
   No edits needed for this step (dry run).
   General notes for this step (nothing found in your app, just good to know):
   mysqli now throws on errors by default; PDO on MySQL returns native int and float types; htmlspecialchars() now escapes single quotes.
   Passing null to a non-nullable built-in parameter is deprecated: check the log after the run.
3. php-81-to-82: PREVIEWED (0 files)
   No edits needed for this step (dry run).
4. php-82-to-83: PREVIEWED (0 files)
   No edits needed for this step (dry run).
5. php-83-to-84: PREVIEWED (1 file)
   Implicitly nullable parameters (T $a = null) are deprecated; fputcsv(), fgetcsv() and str_getcsv() want an explicit escape argument.
   --- src/Legacy.php
   --- Original
   +++ New
   @@ -22,7 +22,7 @@
            return get_class($object);
        }
    
   -    public function label(string $label = null): string
   +    public function label(?string $label = null): string
        {
            return $label ?? $this->name;
        }
   
6. php-84-to-85: PREVIEWED (0 files)
   No edits needed for this step (dry run).
   General notes for this step (nothing found in your app, just good to know):
   Deprecated: the (boolean), (integer), (double) and (binary) casts, the backtick operator, __sleep() and __wakeup(), curl_close() and imagedestroy().
   PDO driver constants such as PDO::MYSQL_ATTR_* move to Pdo\Mysql::*; check them by hand.

Unique files across hops: 1. Later hops were previewed against the original tree, so the same file can appear in several hops.

RESULT: PASS (previewed, no tests ran)

Dry run: these changes were previewed, none were applied.
Nothing was changed. RESULT: PASS means the preview worked; no tests ran.
Next, if the edits look right: php bin/booster run --path=examples/raw-php
(It needs Docker and a clean git folder. Commit or stash first.)
```

## vue-spa

### Plan

`booster plan --path=examples/vue-spa`

```
BOOSTER plan for examples/vue-spa
  app type: raw-php-vue
  PHP: 8.0 -> 8.5
  policy: waynestate/creed 0.1.4 (today 2026-11-03)

1. php-80-to-81
   Move PHP 8.0 code to PHP 8.1.
   After this step your tests run in Docker.
   Removed between PHP 8.0 and PHP 8.1: none found.
2. php-81-to-82
   Move PHP 8.1 code to PHP 8.2.
   After this step your tests run in Docker.
   Removed between PHP 8.1 and PHP 8.2: none found.
3. php-82-to-83
   Move PHP 8.2 code to PHP 8.3.
   After this step your tests run in Docker.
   Removed between PHP 8.2 and PHP 8.3: none found.
4. php-83-to-84
   Move PHP 8.3 code to PHP 8.4.
   After this step your tests run in Docker.
   Removed between PHP 8.3 and PHP 8.4: none found.
5. php-84-to-85
   Move PHP 8.4 code to PHP 8.5.
   After this step your tests run in Docker.
   Removed between PHP 8.4 and PHP 8.5: none found.
6. node-pin-24
   Pin the app to Node 26: set engines.node in package.json and write .nvmrc.
   After this step your tests run in Docker.
7. vue-27-to-compat
   Move a Vue 2.7 front end to Vue 3 through the @vue/compat build.
   After this step your tests run in Docker.
8. vue-compat-codemod
   Run the vue-upgrade-tool codemod over the front end and keep every manual item it lists.
   After this step your tests run in Docker.

PHPCompatibility check for PHP 8.1: no findings.

PHPStan on PHP 8.5: no findings.

Nothing was changed. The numbered list above is the order Booster would do things; "PHPCompatibility check" and "PHPStan" are advice
for you to read, not errors.
Booster does every numbered step above when you run it.
Preview the edits: php bin/booster run --path=examples/vue-spa --dry-run
(The real run needs Docker, and your app committed in git: php bin/booster run --path=examples/vue-spa)
```

### Dry run

`booster run --path=examples/vue-spa --dry-run`

```
BOOSTER dry run (nothing was changed)

Baseline: SKIPPED (no tests ran in this run). A real run first runs your tests as they are now (the baseline), then again after every step (the gate).

1. php-80-to-81: PREVIEWED (0 files)
   No edits needed for this step (dry run).
   General notes for this step (nothing found in your app, just good to know):
   mysqli now throws on errors by default; PDO on MySQL returns native int and float types; htmlspecialchars() now escapes single quotes.
   Passing null to a non-nullable built-in parameter is deprecated: check the log after the run.
2. php-81-to-82: PREVIEWED (0 files)
   No edits needed for this step (dry run).
3. php-82-to-83: PREVIEWED (0 files)
   No edits needed for this step (dry run).
4. php-83-to-84: PREVIEWED (0 files)
   No edits needed for this step (dry run).
   General notes for this step (nothing found in your app, just good to know):
   Implicitly nullable parameters (T $a = null) are deprecated; fputcsv(), fgetcsv() and str_getcsv() want an explicit escape argument.
5. php-84-to-85: PREVIEWED (0 files)
   No edits needed for this step (dry run).
   General notes for this step (nothing found in your app, just good to know):
   Deprecated: the (boolean), (integer), (double) and (binary) casts, the backtick operator, __sleep() and __wakeup(), curl_close() and imagedestroy().
   PDO driver constants such as PDO::MYSQL_ATTR_* move to Pdo\Mysql::*; check them by hand.
6. node-pin-24: PREVIEWED (0 files)
   Dry run: would set engines.node to >=26 in package.json and write .nvmrc with 26.
   The pin is a floor, not a ceiling: engines.node ">=26" still allows newer Node majors.
   Later front-end hops run in the same node:26 container, so this hop comes before them.
7. vue-27-to-compat: PREVIEWED (0 files)
   Dry run: would set vue to ^3.5 in package.json, set @vue/compat to ^3.5 in package.json, set @vue/compiler-sfc to ^3.5 in package.json, set vue-loader to ^17.4 in package.json if it is listed and remove vue-template-compiler from package.json.
   Point the bundler at @vue/compat instead of vue (an alias in webpack or resolve.alias in Vite) and start with compat MODE 2 so the build warns instead of breaking.
   Fix every migration warning the build prints, then switch MODE to 3 to turn compat off, and remove @vue/compat from package.json.
   Move Vuex to Pinia and Vue Router 3 to 4 by hand; the compat build does not rewrite your store or routes.
8. vue-compat-codemod: PREVIEWED (0 files)
   Dry run: Refusing to run the codemod: package.json does not list @vue/compat, so the app needs vue-27-to-compat first. Run that hop, then run this hop again.
   vue-upgrade-tool is lightly maintained; review every change it makes before you trust the diff.
   The tool lists the items it cannot fix; Booster keeps them in the hop result, so work through that list before you switch @vue/compat MODE to 3.
   The codemod needs @vue/compat in package.json, which the vue-27-to-compat hop installs; run that hop first.

RESULT: PASS (previewed, no tests ran)

Nothing was changed.
```
