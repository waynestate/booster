<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Strangler;

/**
 * The strangler's stop signs (design section 6): each one means nothing
 * changes. The planner uses the PHP floor and the LTI check to leave the hop
 * out with a warning; the executor checks all of them again before Step 0,
 * because a plan can be old by the time it runs.
 */
final class StranglerStopSigns
{
    /** SESAME and Laravel 13 both need it. */
    public const PHP_FLOOR = '8.3';

    /** The guide every stop sign points at. */
    public const GUIDE = 'docs/guides/cakephp-and-slim-to-laravel.md';

    /**
     * The first LTI package the app requires (composer.json require), or
     * null. SESAME has no LTI driver, so an LTI tool's Laravel half could not
     * sign anyone in yet.
     */
    public static function ltiPackage(string $appRoot): ?string
    {
        $file = rtrim($appRoot, '/\\') . '/composer.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $require = is_array($data) && is_array($data['require'] ?? null) ? $data['require'] : [];
        foreach (array_keys($require) as $package) {
            if (preg_match('#(^|[/-])lti($|[-/]|1p3)#i', (string) $package) === 1) {
                return (string) $package;
            }
        }

        return null;
    }

    /**
     * The stop signs before run 1 (Step 0 and the mount), in the order a
     * person fixes them.
     *
     * @param string|null $line         the framework line the app is on (5.4, 4.15)
     * @param string      $newestMajor  the latest major Booster moves the framework to
     * @param string|null $replayReason why the replay cannot gate this run, or null when it can
     * @param bool        $dirty        the working copy has uncommitted changes
     *
     * @return list<string>
     */
    public static function beforeMount(string $appRoot, string $framework, ?string $line, string $newestMajor, string $php, bool $dirty, ?string $replayReason): array
    {
        $root = rtrim($appRoot, '/\\');
        $name = $framework === 'cakephp' ? 'CakePHP' : 'Slim';
        $signs = [];
        if ($line === null || explode('.', $line)[0] !== $newestMajor) {
            $signs[] = sprintf('The app is on %s %s, not %s %s: finish the %s %s move first (booster plan shows it).', $name, $line ?? 'an unknown line', $name, $newestMajor, $name, $newestMajor);
        }
        if (version_compare($php, self::PHP_FLOOR, '<')) {
            $signs[] = sprintf('The app is on PHP %s; the move onto Laravel needs PHP %s or newer. Run the PHP hops first.', $php, self::PHP_FLOOR);
        }
        if ($dirty) {
            $signs[] = 'The working copy has uncommitted changes. The mount moves the whole app into legacy/, so commit or stash first (--allow-dirty does not apply to this hop).';
        }
        if (is_dir($root . '/legacy')) {
            $signs[] = 'legacy/ is already there but booster-strangler.json is not. A mount that did not pass its gate leaves exactly this: undo it (git checkout -- . && git clean -fd), or rename the folder, and run again.';
        }
        // The mount moves the whole Composer root (a Slim skeleton's own app/
        // folder included) into legacy/, so only a Laravel half that is
        // already there collides with the one the mount lays down.
        foreach (['artisan', 'bootstrap/app.php', 'routes/web.php'] as $laravelFile) {
            if (is_file($root . '/' . $laravelFile)) {
                $signs[] = sprintf('%s is already at the app root, so a Laravel app (or half of one) is already there. The mount lays Laravel there itself: move that one out first.', $laravelFile);
                break;
            }
        }
        if ($replayReason !== null) {
            $signs[] = $replayReason;
        }
        $lti = self::ltiPackage($appRoot);
        if ($lti !== null) {
            $signs[] = sprintf('No strangler yet for LTI apps (this app requires %s): SESAME has no LTI driver yet. See %s.', $lti, self::GUIDE);
        }

        return $signs;
    }

    /** The stop sign for a run with no --replay, or null when the run replays. */
    public static function noReplay(bool $replays): ?string
    {
        return $replays
            ? null
            : 'The strangler requires the replay: every move is proven by the same answers before and after. Draft the request file with `booster replay init`, fill it in, commit it, and run again with --replay.';
    }
}
