#!/usr/bin/env bash
# The strangler proof (design section 7, amended by 9.5): one public app per
# framework, gated by the real replay.
#
#   bash tests/fixtures/strangler/prove.sh <slim4|cake5> mount
#
# mount: copies <app>/before/ twice into a work folder, records the replay on
# the untouched copy, runs Booster's real mount on the other (Sourdough bakes
# the Laravel half in the gate image), does a clean dual-root install (one
# composer install at the root, which installs legacy/ through
# bin/install-legacy.php), then checks the mounted copy against the
# recording with --serve-changed and needs "all N answers match". Then it
# serves the mounted copy once more with BOOSTER_REPLAY=1 and checks which half
# answered: legacy for the app's own paths, Laravel for /_laravel/up.
#
# Needs Linux or WSL, Docker and PHP 8.3+ with Booster's vendor/ installed.
# Composer runs only in containers, through BOOSTER_COMPOSER_CACHE (a Docker
# volume such as stack-composer-cache, or a host folder) when it is set.
# Every container is named booster-prove-*; the script removes only those.
# Exit 0 means proved. The work folder is kept (its path is printed).

set -euo pipefail

app=${1:-}
step=${2:-mount}
here=$(cd "$(dirname "$0")" && pwd)
booster=$(cd "$here/../../.." && pwd)
php=${PHP:-php}
target_php=${BOOSTER_PROVE_PHP:-8.5}

case "$app" in
  slim4) framework=slim ;;
  cake5) framework=cakephp ;;
  *) echo "usage: prove.sh <slim4|cake5> mount" >&2; exit 2 ;;
esac
if [ "$step" != mount ]; then
  echo "prove.sh: this step is not here yet: $step (mount is)." >&2
  exit 2
fi

work=${BOOSTER_PROVE_WORK:-$(mktemp -d "${TMPDIR:-/tmp}/booster-prove-$app-XXXXXX")}
mkdir -p "$work"
echo "work folder: $work"
rm -rf "$work/before" "$work/mounted"
cp -a "$here/$app/before" "$work/before"
cp -a "$here/$app/before" "$work/mounted"

image=$("$php" -r 'require $argv[1] . "/vendor/autoload.php"; echo (new Waynestate\Booster\Run\TargetImageRunner(new Waynestate\Booster\Run\SymfonyProcessRunner(), $argv[1] . "/docker"))->imageTag($argv[2]);' "$booster" "$target_php")
if ! docker image inspect "$image" >/dev/null 2>&1; then
  echo "building $image"
  "$php" -r 'require $argv[1] . "/vendor/autoload.php"; (new Waynestate\Booster\Run\TargetImageRunner(new Waynestate\Booster\Run\SymfonyProcessRunner(), $argv[1] . "/docker"))->ensureImage($argv[2]);' "$booster" "$target_php"
fi

uid=$(id -u)
gid=$(id -g)
cache_args=()
if [ -n "${BOOSTER_COMPOSER_CACHE:-}" ]; then
  cache_dir=/tmp/composer-cache
  if [ -d "$BOOSTER_COMPOSER_CACHE" ]; then
    cache_args=(-v "$BOOSTER_COMPOSER_CACHE:$cache_dir" -e "COMPOSER_CACHE_DIR=$cache_dir")
  else
    # A Docker volume belongs to root: the user gets a folder of its own, as the gate does.
    docker run --rm --name "booster-prove-cache-$$" --user 0:0 -v "$BOOSTER_COMPOSER_CACHE:$cache_dir" "$image" \
      sh -c "mkdir -p $cache_dir/booster-uid-$uid && chown $uid:$gid $cache_dir/booster-uid-$uid" >/dev/null
    cache_args=(-v "$BOOSTER_COMPOSER_CACHE:$cache_dir" -e "COMPOSER_CACHE_DIR=$cache_dir/booster-uid-$uid")
  fi
fi

install() { # <dir> <label>
  echo "composer install: $2"
  docker run --rm --name "booster-prove-install-$$" -v "$1:/app" -w /app -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer \
    -e COMPOSER_PROCESS_TIMEOUT=0 "${cache_args[@]}" --user "$uid:$gid" "$image" \
    composer install --no-interaction --no-progress --prefer-dist >"$work/install-$2.log" 2>&1 \
    || { tail -n 40 "$work/install-$2.log"; echo "FAIL: composer install ($2)"; exit 1; }
}

# 1. The baseline, on the untouched app.
install "$work/before" before
"$php" "$booster/bin/booster" replay record --path "$work/before" --php "$target_php" --no-install \
  --recording "$work/recording.json" --out "$work/record" | tee "$work/record.txt"

# 2. The mount.
"$php" "$here/mount.php" "$work/mounted" "$framework" | tee "$work/mount.txt"
grep -q '^MOUNTED$' "$work/mount.txt" || { echo "FAIL: the mount refused"; exit 1; }

# 3. A clean dual-root install: one composer install at the root.
install "$work/mounted" mounted
for autoload in vendor/autoload.php legacy/vendor/autoload.php; do
  [ -f "$work/mounted/$autoload" ] || { echo "FAIL: the root install left no $autoload"; exit 1; }
done
grep -q 'install-legacy: ' "$work/install-mounted.log" || { echo "FAIL: the root install never ran bin/install-legacy.php"; exit 1; }
echo "dual-root install: vendor/ and legacy/vendor/ both installed by one composer install"

# 4. The check: the mounted app answers what the untouched one answered.
"$php" "$booster/bin/booster" replay check --path "$work/mounted" --php "$target_php" --no-install --serve-changed \
  --recording "$work/recording.json" --out "$work/check" | tee "$work/check.txt"
grep -Eq 'Replay passed: all [0-9]+ answers? match' "$work/check.txt" || { echo "FAIL: the replay check did not pass"; exit 1; }

# 5. Which half answered (X-Booster-Half, sent only with BOOSTER_REPLAY=1).
legacy_paths=$("$php" -r '$f = json_decode(file_get_contents($argv[1]), true); $p = []; foreach ($f["sequences"] ?? [["requests" => $f["requests"] ?? []]] as $s) { foreach ($s["requests"] as $r) { if (strtoupper($r["method"] ?? "GET") === "GET" && !isset($r["form"]) && !str_contains($r["path"], "{{")) { $p[] = $r["path"]; } } } echo implode(" ", array_unique($p));' "$work/mounted/booster-replay.json")
env_args=()
while IFS= read -r pair; do env_args+=(-e "$pair"); done < <("$php" -r '$f = json_decode(file_get_contents($argv[1]), true); foreach ($f["serve"]["env"] ?? [] as $k => $v) { echo $k, "=", $v, "\n"; }' "$work/mounted/booster-replay.json")
setup=$("$php" -r '$f = json_decode(file_get_contents($argv[1]), true); echo $f["serve"]["setup"] ?? "true";' "$work/mounted/booster-replay.json")
cat >"$work/halves.php" <<'PHP'
<?php
$fail = 0;
$probe = function (string $path, string $want, int $status = 0) use (&$fail): void {
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30]]);
    @file_get_contents('http://127.0.0.1:8766' . $path, false, $context);
    $headers = $http_response_header ?? [];
    $code = (int) (explode(' ', $headers[0] ?? '')[1] ?? 0);
    $half = '';
    foreach ($headers as $h) {
        if (stripos($h, 'X-Booster-Half:') === 0) {
            $half = trim(substr($h, 15));
        }
    }
    $ok = $half === $want && ($status === 0 || $code === $status);
    $fail += $ok ? 0 : 1;
    printf("%s %-40s half=%s status=%d\n", $ok ? 'ok  ' : 'FAIL', $path, $half === '' ? '(none)' : $half, $code);
};
foreach (array_slice($argv, 1) as $path) {
    $probe($path, 'legacy');
}
$probe('/_laravel/up', 'laravel', 200);
exit($fail === 0 ? 0 : 1);
PHP
echo "which half answered:"
# shellcheck disable=SC2086
docker run --rm --name "booster-prove-halves-$$" --network none -v "$work/mounted:/app" -v "$work:/prove" \
  -v "$booster/resources/replay:/booster-replay:ro" -w /app -e HOME=/tmp -e BOOSTER_REPLAY_FRONT=index.php \
  "${env_args[@]}" --user "$uid:$gid" "$image" sh -c "($setup) >/prove/halves-setup.log 2>&1 || { tail -n 20 /prove/halves-setup.log; exit 1; }
    php -d display_errors=0 -S 127.0.0.1:8766 -t public /booster-replay/router.php >/prove/halves-server.log 2>&1 &
    sleep 1; php /prove/halves.php $legacy_paths" \
  || { echo "FAIL: a request went to the wrong half (server log: $work/halves-server.log)"; exit 1; }

echo "PROVED: $app mount (all answers match, legacy answered the app's paths, Laravel answered /_laravel/up)"
