<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Strangler\CakeModels;
use Waynestate\Booster\Strangler\CakeMover;
use Waynestate\Booster\Strangler\CakeUnitSource;
use Waynestate\Booster\Strangler\Unit;

/**
 * The CakePHP mover (design sections 4 and 9.2): files only, an action
 * moves only when every statement maps, and a refusal names file:line.
 * (The rendered bytes were compared with real CakePHP 5.4 and Laravel 13
 * in research/booster-1210-strangler-1120.md; here the files are checked.)
 */
final class CakeMoverTest extends TestCase
{
    private const APP = __DIR__ . '/fixtures/strangler/cake-app';

    public function test_framework_is_cakephp(): void
    {
        $this->assertSame('cakephp', (new CakeMover())->framework());
    }

    public function test_a_page_moves_with_its_template_the_layout_and_the_models(): void
    {
        $app = $this->app();
        $result = (new CakeMover())->move($this->unit($app, 'GET /notes/hello/{name?}'), $app);

        $this->assertTrue($result->moved);
        $this->assertSame([], $result->refusals);
        $this->assertSame([
            'app/Http/Controllers/Legacy/Cake/CakeView.php',
            'app/Http/Controllers/Legacy/Cake/Notes/HelloAction.php',
            'app/Models/Note.php',
            'resources/views/legacy/Notes/hello.blade.php',
            'resources/views/legacy/element/flash/error.blade.php',
            'resources/views/legacy/element/flash/success.blade.php',
            'resources/views/legacy/flash.blade.php',
            'resources/views/legacy/layout/default.blade.php',
            'routes/web.php',
        ], $result->changedFiles);

        $action = (string) file_get_contents($app . '/app/Http/Controllers/Legacy/Cake/Notes/HelloAction.php');
        $this->assertStringContainsString('namespace App\Http\Controllers\Legacy\Cake\Notes;', $action);
        $this->assertStringContainsString('use DateTimeImmutable;', $action);
        $this->assertStringContainsString('public function __invoke(Request $request, $name = \'world\'): mixed', $action);
        $this->assertStringContainsString('$viewData = array_merge($viewData, compact(\'name\', \'year\'));', $action);
        $this->assertStringContainsString("return CakeView::render('legacy.Notes.hello', 'legacy.layout.default', \$viewData, 'Notes');", $action);
        $this->assertStringNotContainsString('parent::', $action);

        $this->assertSame("<p>Hello, <?= e(\$name) ?>! It is <?= \$year ?>.</p>\n", file_get_contents($app . '/resources/views/legacy/Notes/hello.blade.php'));

        $web = (string) file_get_contents($app . '/routes/web.php');
        $this->assertStringContainsString(CakeMover::SKIP_INPUT, $web);
        $this->assertStringContainsString(
            "// booster:strangler GET /notes/hello/{name?} from legacy/src/Controller/NotesController.php:32\n"
            . "\\Illuminate\\Support\\Facades\\Route::match(['GET'], 'notes/hello/{name?}', \\App\\Http\\Controllers\\Legacy\\Cake\\Notes\\HelloAction::class);\n",
            $web,
        );
        $this->assertContains(
            'resources/views/legacy/Notes/hello.blade.php is legacy/templates/Notes/hello.php with its CakePHP helpers mapped (the PHP stays PHP, so the HTML is the same bytes); rewrite it as idiomatic Blade by hand when you like',
            $result->manual,
        );
        $this->assertContains(
            'app/Models/User.php already exists, so Booster did not write the skeleton for its legacy Table; merge the table, key and associations from legacy/src/Model/Table by hand',
            $result->manual,
        );
    }

    public function test_the_layout_maps_its_helpers_and_keeps_blade_out_of_its_html(): void
    {
        $app = $this->app();
        (new CakeMover())->move($this->unit($app, 'GET /notes/hello/{name?}'), $app);
        $layout = (string) file_get_contents($app . '/resources/views/legacy/layout/default.blade.php');

        $this->assertStringContainsString("<?= '<meta charset=\"utf-8\">' ?>", $layout);
        $this->assertStringContainsString("<title><?= \$description ?>: <?= (\$__blocks['title'] ?? '') ?></title>", $layout);
        $this->assertStringContainsString("e(request()->getBasePath()) . '/css/base.css'", $layout);
        $this->assertStringContainsString("'\" class=\"brand\">' . e('Home') . '</a>'", $layout);
        $this->assertStringContainsString("\$__env->make('legacy.flash',", $layout);
        // "notes@example.edu {{ not blade }}" is HTML, so it is echoed with Blade's characters escaped.
        $this->assertStringContainsString('<?php echo " mail us at notes\x40example.edu \x7b\x7b not blade }}\x3c/nav>', $layout);
        $this->assertStringNotContainsString('$this->', $layout);
    }

    public function test_a_form_moves_with_its_flash_page_and_maps_request_flash_and_redirect(): void
    {
        $app = $this->app(step0Done: true);
        $result = (new CakeMover())->move($this->unit($app, 'FORM /'), $app);

        $this->assertTrue($result->moved, json_encode($result->refusals, JSON_PRETTY_PRINT) ?: '');
        $add = (string) file_get_contents($app . '/app/Http/Controllers/Legacy/Cake/Notes/AddAction.php');
        $this->assertStringContainsString("if (\$request->isMethod('POST')) {", $add);
        $this->assertStringContainsString("\$title = trim((string) data_get(\$request->post(), 'title'));", $add);
        $this->assertStringContainsString("session()->push('booster_flash.flash', ['message' => 'Saved.', 'key' => 'flash', 'element' => 'flash/success', 'params' => []]);", $add);
        $this->assertStringContainsString("return CakeView::redirect(\$request, '/');", $add);
        $this->assertStringContainsString("return CakeView::redirect(\$request, '/notes/add');", $add);
        $this->assertStringContainsString("\$viewData['title'] = (string) data_get(\$request->query(), 'title', '');", $add);

        $index = (string) file_get_contents($app . '/app/Http/Controllers/Legacy/Cake/Notes/IndexAction.php');
        $this->assertStringContainsString("\$viewData['who'] = data_get(\$request->user(), 'name');", $index);

        $web = (string) file_get_contents($app . '/routes/web.php');
        $this->assertStringContainsString("Route::match(['GET'], '/', \\App\\Http\\Controllers\\Legacy\\Cake\\Notes\\IndexAction::class)\n    ->name('home');", $web);
        $this->assertStringContainsString("Route::match(['GET', 'POST'], 'notes/add', \\App\\Http\\Controllers\\Legacy\\Cake\\Notes\\AddAction::class)\n    ->withoutMiddleware([", $web);
        // Index lets guests in (addUnauthenticatedActions), so no auth middleware.
        $this->assertStringNotContainsString('auth.sesame', $web);
        $this->assertContains(
            'legacy/src/Controller/NotesController.php:16 POST /notes/add had no CSRF check in CakePHP and has none in Laravel either (the route skips PreventRequestForgery so the legacy form still posts); add one when its form is ported',
            $result->manual,
        );
    }

    public function test_a_signed_in_unit_waits_for_step_0_then_moves_behind_sesame(): void
    {
        $app = $this->app();
        $result = (new CakeMover())->move($this->unit($app, 'GET /notes/me'), $app);

        $this->assertFalse($result->moved);
        $this->assertSame([], $result->changedFiles);
        $this->assertSame([[
            'file' => 'legacy/src/Controller/NotesController.php',
            'line' => 38,
            'reason' => 'GET /notes/me needs a signed-in user; it moves once Step 0 (sign-in on SESAME) is done',
        ]], $result->refusals);

        $app = $this->app(step0Done: true);
        $result = (new CakeMover())->move($this->unit($app, 'GET /notes/me'), $app);
        $this->assertTrue($result->moved);
        $this->assertStringContainsString(
            "Route::match(['GET'], 'notes/me', \\App\\Http\\Controllers\\Legacy\\Cake\\Notes\\MeAction::class)\n    ->middleware('auth.sesame');",
            (string) file_get_contents($app . '/routes/web.php'),
        );
        $this->assertStringContainsString(
            "\$viewData['user'] = data_get(\$request->user(), 'name');",
            (string) file_get_contents($app . '/app/Http/Controllers/Legacy/Cake/Notes/MeAction.php'),
        );
    }

    public function test_an_action_with_a_statement_that_does_not_map_refuses_with_file_and_line(): void
    {
        $app = $this->app();
        $before = (string) file_get_contents($app . '/routes/web.php');
        $result = (new CakeMover())->move($this->unit($app, 'GET /notes/report'), $app);

        $this->assertFalse($result->moved);
        $this->assertSame([], $result->changedFiles);
        $this->assertSame([], $result->manual);
        $this->assertSame([[
            'file' => 'legacy/src/Controller/NotesController.php',
            'line' => 45,
            'reason' => '`$this->Notes->find` has no mapping to Laravel (component, model, controller API or property)',
        ]], $result->refusals);
        $this->assertSame($before, file_get_contents($app . '/routes/web.php'));
        $this->assertDirectoryDoesNotExist($app . '/app/Http/Controllers/Legacy');
    }

    public function test_a_write_refuses_when_the_legacy_app_checks_csrf_tokens(): void
    {
        $app = $this->app(step0Done: true);
        $file = $app . '/legacy/src/Application.php';
        file_put_contents($file, str_replace('->add(new RoutingMiddleware($this))', '->add(new RoutingMiddleware($this))->add(new \Cake\Http\Middleware\CsrfProtectionMiddleware())', (string) file_get_contents($file)));
        $result = (new CakeMover())->move($this->unit($app, 'FORM /'), $app);

        $this->assertFalse($result->moved);
        $this->assertCount(1, $result->refusals);
        $this->assertSame(16, $result->refusals[0]['line']);
        $this->assertStringContainsString('the legacy app checks CSRF tokens (CsrfProtectionMiddleware)', $result->refusals[0]['reason']);
    }

    public function test_a_unit_moves_once_and_needs_the_mounted_laravel_half(): void
    {
        $app = $this->app();
        $mover = new CakeMover();
        $unit = $this->unit($app, 'GET /notes/hello/{name?}');
        $this->assertTrue($mover->move($unit, $app)->moved);
        $again = $mover->move($unit, $app);
        $this->assertFalse($again->moved);
        $this->assertSame('GET /notes/hello/{name?} is already in routes/web.php', $again->refusals[0]['reason']);

        $bare = $this->app();
        unlink($bare . '/routes/web.php');
        $this->assertSame(
            'the Laravel half is not mounted (no routes/web.php); run the mount step first',
            $mover->move($unit, $bare)->refusals[0]['reason'],
        );
    }

    public function test_an_unmapped_layout_helper_is_a_manual_line_not_a_refusal(): void
    {
        $app = $this->app();
        $layout = $app . '/legacy/templates/layout/default.php';
        file_put_contents($layout, str_replace('<?= $this->fetch(\'css\') ?>', '<?= $this->fetch(\'css\') ?><?= $this->Paginator->counter() ?>', (string) file_get_contents($layout)));
        $result = (new CakeMover())->move($this->unit($app, 'GET /notes/hello/{name?}'), $app);

        $this->assertTrue($result->moved);
        $this->assertContains(
            'legacy/templates/layout/default.php:14 the view helper call `$this->Paginator->counter` has no Blade mapping; port it by hand (in the Blade layout resources/views/legacy/layout/default.blade.php)',
            $result->manual,
        );
        $this->assertStringContainsString("/* MANUAL: \$this->Paginator->counter was not mapped */ ''", (string) file_get_contents($app . '/resources/views/legacy/layout/default.blade.php'));
    }

    public function test_blade_safe_escapes_blade_syntax_in_html_only(): void
    {
        $this->assertSame('<p>plain</p><?= $x ?>', CakeMover::bladeSafe('<p>plain</p><?= $x ?>'));
        $this->assertSame('<?php echo "\x7b\x7b \$x }}"; ?>', CakeMover::bladeSafe('{{ $x }}'));
        $this->assertNull(CakeMover::bladeSafe('<?php $a = "{{-- x --}}"; ?>'));
    }

    public function test_model_skeletons_from_table_and_entity(): void
    {
        $models = (new CakeModels())->skeletons(self::APP);

        $this->assertSame(['app/Models/Note.php', 'app/Models/User.php'], array_keys($models));
        $note = $models['app/Models/Note.php'];
        $this->assertStringContainsString("protected \$table = 'notes';", $note);
        $this->assertStringContainsString("const CREATED_AT = 'created';", $note);
        $this->assertStringContainsString("protected \$fillable = [\n        'title',\n        'body',\n    ];", $note);
        $this->assertStringContainsString("protected \$hidden = [\n        'secret',\n    ];", $note);
        $this->assertStringContainsString("return \$this->belongsTo(User::class, 'user_id');", $note);
        $this->assertStringContainsString("return \$this->belongsToMany(Tag::class, 'notes_tags', 'note_id', 'tag_id');", $note);
        $user = $models['app/Models/User.php'];
        $this->assertStringContainsString("protected \$table = 'users';", $user);
        $this->assertStringContainsString('public $timestamps = false;', $user);
        $this->assertStringContainsString("return \$this->hasMany(Note::class, 'user_id');", $user);
        $this->assertSame('Category', CakeModels::singular('Categories'));
        $this->assertSame('articles_tags', CakeModels::underscore('ArticlesTags'));
    }

    private function app(bool $step0Done = false): string
    {
        $dir = $this->tempDir();
        self::copy(self::APP, $dir);
        if ($step0Done) {
            file_put_contents($dir . '/booster-strangler.json', "{\n    \"step0\": \"done\"\n}\n");
        }

        return $dir;
    }

    private static function copy(string $from, string $to): void
    {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                mkdir($to . '/' . $entry, 0o775, true);
                self::copy($from . '/' . $entry, $to . '/' . $entry);
            } else {
                copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    }

    private function unit(string $app, string $id): Unit
    {
        foreach ((new CakeUnitSource())->units($app) as $unit) {
            if ($unit->id === $id) {
                return $unit;
            }
        }
        $this->fail('no unit ' . $id);
    }
}
