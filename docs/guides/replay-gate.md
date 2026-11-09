---
title: The replay gate
description: Record how the app answers before an upgrade, replay the same requests after every hop, and fail the hop when an answer changes.
---

# The replay gate

This page shows how to make the gate prove more than the tests do: Booster records how the app answers a list of
requests before the upgrade, replays the same requests after every hop, and fails the hop when an answer changes.
Before this: [What a hop does](hops.md). Next: [Hand-copied libraries to Composer](framework-hops.md).

The test gate only knows what the tests ask. Plenty of WSU apps have one smoke test, or none, and the code a PHP hop
changes most (string to number comparisons, `null` passed to `strlen`, a dynamic property) sits in pages no test
visits. The replay gate takes the patient's vitals before the shot and again after every dose: it writes down what
the app said on the old PHP, and after every hop it asks again and compares. It is off unless you ask for it.

When the gate proves little (the app has no tests, so the gate is the `php -l` syntax check, or its tests are only
Laravel's stock examples) and the run has no `--replay`, `booster run` says so under the baseline, dry runs too:

    Replay: off, and the gate only proves every PHP file parses, so a hop can change what a page answers and still pass.
    Record the answers first: booster replay init --path=<app> drafts booster-replay.json from the routes; fill in its todo items, then run again with --replay.
    Step 1 (php-74-to-80) is the hop most likely to change an answer: loose comparisons of numbers and strings, and warnings that now throw.

The last line shows only when the plan has the PHP 8.0 hop, and the second one asks for just `--replay` when
`booster-replay.json` is already there. The JSON report carries the same nudge as `replay_suggestion`. It is advice,
never a failure: the exit code is the same with or without it.

## In three commands

<!-- docs-test: skip needs Docker, run on your own app -->
```sh
booster replay init --path=<app>           # draft booster-replay.json from the app's routes
# edit booster-replay.json: fill in every "todo", then delete it
booster replay record --path=<app>         # optional: record once and save the answers
booster run --path=<app> --replay          # record, then replay after every hop
```

`booster run --replay` needs nothing else: it records the baseline on the app's current PHP before the first hop
(right after the test baseline), so `replay record` is only for when you want to look at the answers first, keep
them, or check a hand-made change with `booster replay check --php=8.4`. `--replay=FILE` names another request file;
`--replay-out=DIR` picks where the transcripts go.

## What a run does with it

1. Before the first hop, Booster serves the untouched app with PHP's built-in server inside
   `booster-target:<old php>-<stamp>`, the same image the baseline uses, sends every request in the file, and writes
   the answers to `baseline-php<old>.txt`. The run says so next to the baseline:

       Replay: recorded 11 answers on PHP 7.4 from booster-replay.json.

   When a later hop runs the build gate (`vite-major`, `tailwind-3-to-4`, `typescript-major` and the other front-end
   hops), Booster first runs `npm ci` and `npm run build` in the Node container, so the baseline serves the built
   front end the hops will rebuild, not a missing or stub `public/build/manifest.json`. If the baseline cannot be
   recorded (the front end does not build, the setup command failed, the server never answered, an answer missed its
   `expect`), the upgrade does not start, and nothing is changed.
2. After each hop's tests pass, Booster serves the changed app on the new PHP, sends the same requests, and compares.
   A match adds `Replay passed: all 11 answers match the baseline from PHP 7.4.` to the hop. A difference fails the
   hop like a red test, leaves the changes in place, and shows the first difference:

       Replay failed on PHP 8.0: 1 of 2 answers differ from the baseline from PHP 7.4.
       First difference: 02 requests / a word: GET /compare?code=abc (- baseline, + now)
               "code": "abc",
         -     "matches_zero": true
         +     "matches_zero": false
           }
       Every difference: diff /tmp/booster-replay-notes-20261105-201500/baseline-php7.4.txt /tmp/booster-replay-notes-20261105-201500/step-1-php-74-to-80-php8.0.txt

   That one is real: `0 == "abc"` is true on PHP 7.4 and false on PHP 8, and no test in the app asked.
3. `--dry-run` sends no requests and says the replay was skipped.

The transcripts are plain text, one block per request, written outside the app (in the temp folder unless
`--replay-out` says otherwise), so `diff` and any editor read them. PHP errors and deprecations logged while serving are
counted and written next to the transcript (`baseline-php7.4.php-errors.txt`); they are reported, never compared,
because a hop is supposed to change them.

## The request file

`booster-replay.json` sits in the app's root. Keys that start with `$` are comments. A small one:

```json
{
    "version": 1,
    "serve": {
        "docroot": "public",
        "front_controller": "index.php",
        "setup": "php replay/seed.php",
        "env": { "DB_DATABASE": "/tmp/app.sqlite" },
        "ini": { "date.timezone": "UTC" }
    },
    "normalize": ["csrf", "timestamps"],
    "sequences": [
        {
            "name": "save then read back",
            "requests": [
                { "name": "form", "path": "/", "capture": { "token": "name=\"_token\" value=\"([^\"]+)\"" } },
                { "name": "save", "method": "POST", "path": "/notes",
                  "form": { "_token": "{{token}}", "title": "Replay note" },
                  "capture": { "id": "Location: /notes/(\\d+)" }, "expect": 302 },
                { "name": "read back", "path": "/notes/{{id}}", "expect": 200 }
            ]
        }
    ]
}
```

- **`serve`**: `docroot` (relative, inside the app; default `.`), `front_controller` (a PHP file in the docroot that
  every path without a file of its own goes to, like Apache's rewrite to `index.php`; leave it out and the server
  serves the docroot as files), `setup` (a shell command run once, in the app folder, before the server starts:
  seed a SQLite file, write a `.env`), `env` and `ini` (passed to the setup and the server), and `services`.
- **`requests`** or **`sequences`**: a request has `path`, and may have `name`, `method` (default GET), `query`,
  `headers`, `cookies`, one body (`form`, `json` or a raw `body`), `capture` and `expect`. A sequence shares a
  cookie jar, so a session started by one request carries to the next. Redirects are recorded, never followed.
- **`capture`**: a regular expression whose first group is saved under a name, matched against the body and the
  headers (as `Name: value` lines). Later requests in the same sequence use it as `{{name}}` in the path, query,
  headers, cookies or body. A placeholder nobody captured is an error when the file loads, not a surprise later.
- **`expect`**: the status the baseline must answer. It is checked while recording only, so a baseline that is
  already broken (a 500 where a 200 belongs) is refused instead of faithfully recorded.
- **`defaults`**: headers every request gets.
- **`todo`**: a note for a person, on the file, `serve` or a request. `record`, `check` and `run --replay` refuse a
  file that still has one.

### What is compared

For each answer: the status, `Content-Type`, `Location` (with the server's own address written as `<app>`), `Allow`,
the names of the cookies set (never their values), and the body. Everything else (`Date`, `X-Powered-By`, the
values of cookies) changes on every request or every PHP, and is left out. By default runs of spaces and blank
lines do not matter, and a JSON body is compared by value, so a reordered key or `1.0` against `1` is not a
difference. `"compare": {"whitespace": "exact", "json": "exact"}` compares byte for byte.

### Normalizers

A normalizer rewrites the volatile parts of an answer before it is compared and written. The presets:

| Name | Hides |
|---|---|
| `csrf` | CSRF tokens in hidden form fields and meta tags (`_token`, `csrf_token`, `authenticity_token` and friends) |
| `session-id` | session ids in links, forms and scripts (`PHPSESSID=...`, `laravel_session=...`) |
| `timestamps` | dates with a time of day, and Unix times from 2020 to 2033 |
| `asset-hash` | content hashes in built asset names (`app.3f9a1c2b.js`, Vite's `app-L1fqnEWe.js`, `app.js?id=3f9a1c`) |
| `preload-hints` | `<link rel="modulepreload">` tags, which list the chunks the bundler split out and change with any build; the tags that load the page stay compared |
| `inertia` | the Inertia page object's asset `version`, the fields Inertia 3 changed (`clearHistory` and `encryptHistory` when false, `sharedProps`) and its devtools id; component, props and url stay compared |

`booster replay init` lists `asset-hash` and `preload-hints` when the app builds with Vite, and `inertia` when it
requires `inertiajs/inertia-laravel`. Without them the first front-end hop fails the replay on a sound change: on WELD,
`inertia-2-to-3` changed only these parts of every page.

Anything else is an object: `{"name": "request id", "pattern": "req-[0-9a-f]{12}", "replace": "req-<ID>", "in": "body"}`,
where `in` is `body` (the default), `headers` or `all`. The patterns are PCRE without delimiters. Normalizers are
not part of the recording's fingerprint, so adding one after a failed check does not mean recording again; changing a
request does, and `check` says so.

## Design

- **Served the way the gate already runs.** The app is served by `php -S` inside the same `booster-target` image as
  the test gate, on the same PHP, with the app folder mounted. No Apache, no nginx, no second image to build. A
  small router script (shipped with Booster) gives the built-in server the front controller rewrite and leaves real
  files alone.
- **No network.** The replay container runs with `--network none`; the client talks to the server over the
  container's own loopback. A request that tries to reach a real database, CAS or an API fails the same way on both
  sides of the hop, instead of touching anything real. When the app needs a database server, `serve.services` starts
  fixture containers (`{"name": "db", "image": "mysql:8.4", "env": {...}}`) on a private Docker network created with
  `--internal`, reachable by name, removed afterwards. Fake data only.
- **Composer runs where the network is.** A standalone `replay record` or `check` runs `composer install` in a
  normal gate container first (`--no-install` skips it); inside `booster run` the test gate has already installed
  `vendor/`.
- **Old PHP included.** The client and router are written for PHP 5.5 and up, because the baseline of an app on PHP
  5.6 runs inside a PHP 5.6 image. A test holds them to it.
- **Fail closed.** No baseline, no upgrade. A recording made from a different request file is refused. A request
  that got no answer is a difference, not a pass.
- **Nothing written into the app.** The setup command may write where the app's own settings point (`/tmp` is
  good); Booster's scratch files live in a temp folder it mounts and removes. `booster replay record` saves
  `booster-replay.recording.json` in the app because you asked it to keep the answers; commit it or ignore it.

## Drafting the file

`booster replay init` writes a first draft and never overwrites one. It looks, in order, for:

1. `php artisan route:list --json`, when the app is Laravel and `vendor/` is installed (otherwise the `Route::`
   calls in `routes/`);
2. Slim-style route calls (`$app->get('/batches/:id', ...)`), the kind a legacy Slim 1 app has in its `index.php`;
3. PHP entry points under the docroot, skipping include folders, with a POST draft for each page that reads `$_POST`.

Every GET without parameters becomes a request, plus one unknown path (an honest 404 is an answer too). Every path
parameter, form and setup command gets a `todo`, because Booster cannot know which note id exists or what a valid
form looks like. Fill those in, delete the `todo` lines, and record.

## Limits, honestly

- **No real Oracle, SSO or CAS.** The replay never reaches a real system, by design. An app that cannot render a
  page without Oracle needs a fake for that page (an environment switch to SQLite, a stub repository), or that page
  stays out of the file. Pages behind CAS or Entra need the app's own test login (a fake user in the session, the
  way its tests sign in); without one, the honest request is the guest's: the redirect to sign-in.
- **No JavaScript.** The client speaks HTTP, like curl. A page that builds itself in the browser is compared as the
  HTML and JSON the server sends, which still catches a changed API answer, but not a broken click.
- **One server, one process.** `php -S` is not Apache: `.htaccess` rules other than the front controller rewrite,
  `mod_php` settings and server-side includes do not apply. Put the PHP settings that matter in `serve.ini`.
- **The same data on both sides.** The setup command runs again before every replay, so each side starts from the
  same fixture. An app that keeps state elsewhere (a file cache under `storage/`, a queue) can answer differently
  for reasons that are not the hop; clear it in `setup`.
- **Time, randomness and order.** Normalizers cover the common volatile parts. Anything else the app makes up per
  request (a request id, a shuffled list) needs a custom normalizer or a fixed seed in `setup`.
- **Answers, not side effects.** The gate compares what the app says, not what it wrote to a database or a mail
  queue. A follow-up request that reads the saved record back (like `read back` above) covers the common case.
