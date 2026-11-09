<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

/**
 * Static checks on docker/Dockerfile, the gate's target image. Building it needs
 * Docker and minutes per PHP version (scripts/check-target-image.sh does that), so
 * composer ci reads the recipe instead and pins the rules that keep the old PHP
 * images building without loosening apt for everyone else.
 */
final class TargetImageRecipeTest extends TestCase
{
    private const DOCKERFILE = __DIR__ . '/../docker/Dockerfile';

    private function recipe(): string
    {
        $contents = file_get_contents(self::DOCKERFILE);
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * The recipe's instructions with comments dropped and continuation lines joined.
     *
     * @return list<string>
     */
    private function instructions(): array
    {
        $out = [];
        $current = '';
        foreach (preg_split('/\R/', $this->recipe()) ?: [] as $line) {
            if ($current === '' && preg_match('/^\s*(#|$)/', $line) === 1) {
                continue;
            }
            $current .= ' ' . trim($line);
            if (str_ends_with(rtrim($line), '\\')) {
                $current = substr(rtrim($current), 0, -1);

                continue;
            }
            $out[] = trim($current);
            $current = '';
        }

        return $out;
    }

    /**
     * The RUN instruction that rewrites apt's sources for the archived releases.
     */
    private function archiveRun(): string
    {
        foreach ($this->instructions() as $instruction) {
            if (str_starts_with($instruction, 'RUN ') && str_contains($instruction, 'archive.debian.org')) {
                return $instruction;
            }
        }
        self::fail('No RUN in docker/Dockerfile points apt at archive.debian.org.');
    }

    public function test_every_archived_release_php_images_use_is_handled(): void
    {
        // php:5.5 is jessie, 5.6 and 7.0 stretch, 7.1 and 7.2 buster, 7.3 to 8.0 bullseye.
        self::assertStringContainsString('jessie|stretch|buster|bullseye)', $this->archiveRun());
    }

    public function test_archived_releases_get_current_debian_keys_and_current_releases_do_not(): void
    {
        $run = $this->archiveRun();
        $case = strpos($run, 'jessie|stretch|buster|bullseye)');
        $copy = strpos($run, '/etc/apt/trusted.gpg.d/booster-');
        $esac = strrpos($run, 'esac');
        self::assertNotFalse($case);
        self::assertNotFalse($copy, 'The archived releases must get the current Debian keys.');
        self::assertNotFalse($esac);
        self::assertGreaterThan($case, $copy, 'Keys are copied only inside the archived-release branch.');
        self::assertLessThan($esac, $copy);
        // The staged keys never stay in the image, whatever the release.
        self::assertStringContainsString('rm -rf /usr/local/share/booster-archive-keys', substr($run, $esac));
    }

    public function test_the_key_images_are_pinned_multi_arch_debian_images(): void
    {
        $recipe = $this->recipe();
        self::assertMatchesRegularExpression('/^FROM debian:bullseye-slim@sha256:[0-9a-f]{64} AS keys-bullseye$/m', $recipe);
        self::assertMatchesRegularExpression('/^FROM debian:trixie-slim@sha256:[0-9a-f]{64} AS keys-trixie$/m', $recipe);
        self::assertStringContainsString('COPY --from=keys-bullseye /etc/apt/trusted.gpg.d/ /usr/local/share/booster-archive-keys/', $recipe);
        // Only the active ring: trixie's removed-keys ring holds retired keys that never expire.
        self::assertStringContainsString('COPY --from=keys-trixie /usr/share/keyrings/debian-archive-keyring.pgp ', $recipe);
        self::assertStringNotContainsString('removed-keys', implode("\n", $this->instructions()));
    }

    public function test_unsigned_packages_are_allowed_on_jessie_alone_and_only_while_the_image_builds(): void
    {
        $instructions = $this->instructions();
        $all = implode("\n", $instructions);
        self::assertSame(1, substr_count($all, 'AllowUnauthenticated'), 'Only one place may allow unsigned packages.');
        self::assertSame(1, substr_count($all, 'AllowInsecureRepositories'));

        $run = $this->archiveRun();
        $jessie = strpos($run, 'if [ "${codename}" = jessie ]; then');
        $allow = strpos($run, 'AllowUnauthenticated');
        $fi = strpos($run, 'fi', (int) $allow);
        self::assertNotFalse($jessie, 'Unsigned packages are a jessie-only exception.');
        self::assertNotFalse($allow);
        self::assertGreaterThan($jessie, $allow);
        self::assertNotFalse($fi);
        self::assertStringNotContainsString('stretch', substr($run, (int) $jessie, $fi - (int) $jessie));

        // The exception is gone once the last apt user (the extension installer) is done.
        $lastApt = null;
        foreach ($instructions as $index => $instruction) {
            if (str_starts_with($instruction, 'RUN ') && (str_contains($instruction, 'apt-get') || str_contains($instruction, 'install-php-extensions'))) {
                $lastApt = $index;
            }
        }
        self::assertNotNull($lastApt);
        self::assertStringContainsString('rm -f /etc/apt/apt.conf.d/99jessie-expired-keys', $instructions[$lastApt]);
    }
}
