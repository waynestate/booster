---
title: The scans
description: The two advisory scans Booster runs, and how to run either one alone.
---

# The scans

This page covers both advisory scans Booster runs with `plan`, how to run either one alone, the audit `run` adds, and the dynamic-properties list on the PHP 8.2 step. Next: [The dependency check](dependency-check.md).

## The PHPCompatibility scan

`plan` ends with an advisory scan: PHP_CodeSniffer runs the PHPCompatibility standard over the app and asks what will
break on the PHP version the first PHP hop lands on. The findings are hints for the person doing the upgrade, never a
gate: they do not change the exit code, and a scan that cannot run (missing tooling, a crash) is reported as "could
not run" while the plan still prints in full. `--no-scan` skips both advisory scans.

The scan needs no change to the app: Booster ships PHP_CodeSniffer and the PHPCompatibility standard with its own
dependencies, from the PHAR and from a clone alike. Booster looks for a PHP_CodeSniffer that carries the standard,
trying the app's own `vendor/bin/phpcs`, then Booster's `vendor/bin/phpcs`, then `phpcs` on the PATH. When it runs
Booster's own copy, it points PHP_CodeSniffer at the bundled standard with `--runtime-set installed_paths`, because
the registration file that Composer's installer plugin writes cannot be trusted inside a PHAR unpacked into a temp
directory. When none of the launchers has the standard, the scan reports a skip and the plan goes on; the skip says
to reinstall Booster's dependencies (`composer install` in Booster's folder) or download the PHAR again.

The scan ignores `vendor/`, `node_modules/` and `storage/`, because those hold third-party code and generated files,
not the app's. It reads the app only and changes nothing. `booster scan --path=../my-app --php=<target>` runs the same scan
on its own, for any PHP version Booster knows, and exits 0 even when it finds a wall of removed functions.
If the app's `composer.json` declares `ext-oci8` (in `require`, `require-dev` or `config.platform`), the scan leaves out the "`oci_*()` is removed since PHP 8.4" findings: PHP 8.4 moved oci8 out of the PHP download and into PECL, and an app that declares the extension installs the PECL one on purpose, so those lines would be false alarms. Every other finding stays.
In an app that requires Pest (`pestphp/pest`), the scan also leaves out the "Closures / anonymous functions only have access to `$this` if used within a class" warning for files under `tests/`: Pest binds every test closure to a test case, so `$this` in `it()` and `test()` is the test case, and the warning fired on every test. In a Laravel app it is left out for `routes/console.php` too, whose closures Laravel binds to their command. The same warning anywhere else stays.

## The PHPStan scan

`plan` ends with a second advisory scan: PHPStan runs over the app at the destination PHP version, using Pinstripe's
`base.neon` preset. The findings are hints for the person doing the upgrade, never a gate: they do not change the
exit code, and a scan that cannot run is reported with the fix that enables it, while the plan still prints in full.
`--no-scan` skips it.

The scan needs no change to the app: Booster ships PHPStan and Pinstripe's preset with its own dependencies, from
the PHAR and from a clone alike. Booster looks for a PHPStan binary, trying the app's own `vendor/bin/phpstan`, then
Booster's `vendor/bin/phpstan`, then `phpstan` on the PATH, and for the preset it tries the app's vendor tree and
Booster's. When either is missing, the report says to reinstall Booster's dependencies (`composer install` in
Booster's folder) or download the PHAR again.

It ignores `vendor/`, `node_modules/` and `storage/`, reads the app only and changes nothing. `booster scan
--path=../my-app --php=8.5 --phpstan` runs the same scan on its own, for any PHP version Booster knows, and exits 0
even when it finds a wall of missing types. In an app that requires Pest, "Call to an undefined method Pest\..." findings under `tests/` are left out: Pest's higher-order tests (`it('...')->get('/')`) call through magic proxies such as `TestCall` that PHPStan cannot follow.

## The composer audit during the run

`run` carries a third advisory check, per hop: after a hop changes `composer.json` or `composer.lock`, Booster runs `composer audit --format=json --no-interaction` in the app and lists advisories and abandoned packages in the step's report with counts. It never changes the exit code, a clean audit says `Composer audit: clean (no advisories, no abandoned packages).`, and a hop that changed no composer file skips it and says so. A dry run previews the hops and runs no audit.

Composer's audit exits 1 for advisories, 2 for abandoned packages and 3 for both. Booster reads the JSON report on every one of those exits, so an app with abandoned packages gets the list, not a "could not run". Only an exit with no JSON report behind it (Composer missing, a crash) is reported as "could not run".

## Dynamic properties

PHP 8.2 deprecates dynamic properties: assigning `$this->name` in a class that never declares `$name`. The plan step that crosses PHP 8.2 reads the app's own classes (it skips `vendor/`, `node_modules/` and `storage/`) and lists every such assignment as `file:line Class::$prop`, or says how many classes it read and found none. `run` repeats them on that step as MANUAL lines. Booster never changes them: declare each property in its class, or mark the class `#[\AllowDynamicProperties]`.

A class that declares the property in a parent or a trait, has a `__set()`, or already carries `#[\AllowDynamicProperties]` is not listed. A class whose parent or trait lives outside the app's own code (a library in `vendor/`) cannot be checked from the app alone, so it is named on a "not checked" line for you to check by hand. `plan --format=json` carries the same list under `dynamic_properties`.
