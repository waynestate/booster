<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\FloorFollowUps;

/**
 * A floor rise leaves the app's CI matrix and deploy kit naming an older PHP.
 * Found on a real Laravel app after a run to PHP 8.5: the Preflight matrix
 * still tested 8.3 and 8.4 (both red on the first push) and the Squire kit
 * still named citadel/php-apache:8.4r5, and the report said nothing.
 */
final class FloorFollowUpsTest extends TestCase
{
    /** @param array<string, string> $files relative path => content */
    private function app(array $files): string
    {
        $dir = $this->tempDir();
        foreach ($files as $file => $content) {
            if (!is_dir(dirname($dir . '/' . $file))) {
                mkdir(dirname($dir . '/' . $file), 0775, true);
            }
            file_put_contents($dir . '/' . $file, $content);
        }

        return $dir;
    }

    public function test_a_preflight_matrix_and_a_squire_kit_below_the_floor_are_listed(): void
    {
        $dir = $this->app([
            '.gitlab-ci.yml' => "include:\n  - component: \$CI_SERVER_FQDN/enterprise-apps/preflight/php-test@0.5.0\n    inputs:\n      php_versions: [\"8.3\", \"8.4\", \"8.5\"]\n  - component: \$CI_SERVER_FQDN/enterprise-apps/preflight/php-test@0.5.0\n    inputs:\n      php_versions: [\"8.5\"]\n",
            '.env.squire.example' => "# The app image. citadel/php-apache:8.4r5 is the real Citadel image\n#   SQUIRE_BASE_IMAGE=citadel/php-apache:8.4r5@sha256:REPLACE\nSQUIRE_BASE_IMAGE=citadel/php-apache:8.4r5\n",
            'docker-compose.squire.yml' => "services:\n  app:\n    build:\n      args:\n        BASE_IMAGE: \${SQUIRE_BASE_IMAGE:-citadel/php-apache:8.4r5}\n",
        ]);

        self::assertSame([
            'Next: .gitlab-ci.yml:4 tests PHP 8.3 and 8.4, below the new floor 8.5: those jobs fail on the first push (Composer refuses to install below the floor). Drop them from the list.',
            'Next: .env.squire.example:3 names citadel/php-apache:8.4, below the new floor 8.5, which can no longer run the app. Move it to a PHP 8.5 image (Citadel tags carry a build revision, such as 8.5r3, that Booster cannot know, so it leaves the edit to you).',
            'Next: docker-compose.squire.yml:5 names citadel/php-apache:8.4, below the new floor 8.5, which can no longer run the app. Move it to a PHP 8.5 image (Citadel tags carry a build revision, such as 8.5r3, that Booster cannot know, so it leaves the edit to you).',
        ], FloorFollowUps::find($dir, '8.5'));
    }

    public function test_block_lists_github_matrices_and_ci_images(): void
    {
        $dir = $this->app([
            '.gitlab-ci.yml' => "test:\n  image: php:8.2-cli\n  variables:\n    PHP_VERSIONS:\n      - \"8.2\"\n      - \"8.4\"\n",
            '.github/workflows/ci.yml' => "jobs:\n  test:\n    strategy:\n      matrix:\n        php: ['8.1', '8.2', '8.4']\n",
        ]);

        self::assertSame([
            'Next: .gitlab-ci.yml:2 names php:8.2, below the new floor 8.4, which can no longer run the app. Move it to a PHP 8.4 image.',
            'Next: .gitlab-ci.yml:5 tests PHP 8.2, below the new floor 8.4: that job fails on the first push (Composer refuses to install below the floor). Drop it from the list.',
            'Next: .github/workflows/ci.yml:5 tests PHP 8.1 and 8.2, below the new floor 8.4: those jobs fail on the first push (Composer refuses to install below the floor). Drop them from the list.',
        ], FloorFollowUps::find($dir, '8.4'));
    }

    public function test_parity_json_an_apps_own_dockerfile_and_compose_files_below_the_floor_are_listed(): void
    {
        $dir = $this->app([
            'parity.json' => "{\n  \"version\": 1,\n  \"name\": \"hello\",\n  \"image\": \"citadel/php-apache:8.4r5\",\n  \"public_dir\": \"public\"\n}\n",
            'Dockerfile' => "# syntax=docker/dockerfile:1\nFROM php:8.3-apache\nCOPY . /var/www/html\n",
            'compose.yaml' => "services:\n  app:\n    image: php:8.5-fpm\n  worker:\n    image: php:8.2-cli\n",
        ]);

        self::assertSame([
            'Next: parity.json:4 names citadel/php-apache:8.4, below the new floor 8.5, which can no longer run the app. Move it to a PHP 8.5 image (Citadel tags carry a build revision, such as 8.5r3, that Booster cannot know, so it leaves the edit to you; parity image list shows the ones Parity can run).',
            'Next: Dockerfile:2 names php:8.3, below the new floor 8.5, which can no longer run the app. Move it to a PHP 8.5 image.',
            'Next: compose.yaml:5 names php:8.2, below the new floor 8.5, which can no longer run the app. Move it to a PHP 8.5 image.',
        ], FloorFollowUps::find($dir, '8.5'));
    }

    public function test_a_laravel_sail_runtime_below_the_floor_is_listed_on_both_its_lines(): void
    {
        // The docker-compose.yml that php artisan sail:install writes.
        $dir = $this->app([
            'docker-compose.yml' => "services:\n    laravel.test:\n        build:\n            context: './vendor/laravel/sail/runtimes/8.3'\n            dockerfile: Dockerfile\n        image: 'sail-8.3/app'\n    mysql:\n        image: 'mysql/mysql-server:8.0'\n",
        ]);
        $line = static fn(int $n): string => 'Next: docker-compose.yml:' . $n . ' names Laravel Sail\'s PHP 8.3 runtime, below the new floor 8.4, which can no longer run the app. Move it to 8.4 (the runtimes/8.4 build context and the sail-8.4/app image go together; update laravel/sail first if it has no 8.4 runtime yet).';

        self::assertSame([$line(4), $line(6)], FloorFollowUps::find($dir, '8.4'));
        self::assertSame([], FloorFollowUps::find($dir, '8.3'), 'a runtime at the floor is fine');
    }

    public function test_nothing_at_or_above_the_floor_and_no_files_means_no_lines(): void
    {
        $dir = $this->app([
            '.gitlab-ci.yml' => "image: composer:2.11.1\nphp_versions: [\"8.5\"]\n# php_versions: [\"8.3\"] in a comment\n",
            '.env.squire.example' => "SQUIRE_BASE_IMAGE=citadel/php-apache:8.5r3\n",
            'parity.json' => "{\"version\": 1, \"image\": \"citadel/php-apache:8.5r3\"}\n",
            'docker-compose.yml' => "services:\n  app:\n    build:\n      context: ./vendor/laravel/sail/runtimes/8.5\n    image: sail-8.5/app\n  db:\n    image: mysql:5.6\n",
        ]);

        self::assertSame([], FloorFollowUps::find($dir, '8.5'));
        self::assertSame([], FloorFollowUps::find($this->tempDir(), '8.5'));
    }

    public function test_the_composer_floor_is_the_lowest_version_require_php_names(): void
    {
        foreach (['^8.5' => '8.5', '>=8.4.1' => '8.4', '^7.4 || ^8.0' => '7.4'] as $php => $expected) {
            $dir = $this->app(['composer.json' => (string) json_encode(['require' => ['php' => $php]])]);
            self::assertSame($expected, FloorFollowUps::composerFloor($dir), $php);
        }
        self::assertNull(FloorFollowUps::composerFloor($this->tempDir()));
    }
}
