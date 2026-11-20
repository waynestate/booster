# Strangler proof fixtures

Two public apps, as `before/` trees, that `prove.sh` mounts and replays:

- `slim4/before/`: `slim/slim-skeleton` 4.5.0 (MIT), unchanged except for `public/robots.txt` (a static file
  for the dispatcher to serve), `booster-replay.json`, and the edits that make it PHP 8.5 clean, as Booster's
  hops leave an app before the strangler starts (the skeleton's error handler turns a deprecation into a 500):
  `php-di/php-di` ^7.0 (6.4's implicit nullable parameters), `monolog` ^2.8 or ^3.0, `?array $users` in
  `InMemoryUserRepository`, and `{$userId}` for the `${userId}` string in `ViewUserAction`. Its OPTIONS
  catch-all route makes every unknown path a 405, which the request file expects.
- `cake5/before/`: the CakePHP CMS tutorial (`cakephp/cms-tutorial`, MIT) without its web font files, plus
  `bin/booster-fixture-db.php` (a fixture SQLite database with fixed rows and one fixture user) and
  `booster-replay.json`. A guest sequence and a sign-in sequence with the form's `_csrfToken`.

Neither tree carries `vendor/`: the gate installs it through the Composer cache.

```
bash tests/fixtures/strangler/prove.sh slim4 mount
bash tests/fixtures/strangler/prove.sh cake5 mount
```

Run from the Booster root, on Linux or WSL with Docker. Set `BOOSTER_COMPOSER_CACHE` (for example
`stack-composer-cache`) so the installs come from the cache.
