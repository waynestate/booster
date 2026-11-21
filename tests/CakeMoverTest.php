<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Strangler\CakeApp;
use Waynestate\Booster\Strangler\CakeModels;
use Waynestate\Booster\Strangler\CakeMover;
use Waynestate\Booster\Strangler\CakeRuntime;
use Waynestate\Booster\Strangler\CakeUnitSource;
use Waynestate\Booster\Strangler\HelmetHold;
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
            ...self::runtime(),
            'app/Http/Controllers/Legacy/Cake/Notes/HelloAction.php',
            'app/Models/Note.php',
            'composer.json',
            'resources/views/legacy/Error/error400.blade.php',
            'resources/views/legacy/Error/error500.blade.php',
            'resources/views/legacy/Notes/hello.blade.php',
            'resources/views/legacy/element/flash/error.blade.php',
            'resources/views/legacy/element/flash/success.blade.php',
            'resources/views/legacy/flash.blade.php',
            'resources/views/legacy/layout/default.blade.php',
            'resources/views/legacy/layout/error.blade.php',
            'routes/web.php',
        ], $result->changedFiles);

        $action = (string) file_get_contents($app . '/app/Http/Controllers/Legacy/Cake/Notes/HelloAction.php');
        $this->assertStringContainsString('namespace App\Http\Controllers\Legacy\Cake\Notes;', $action);
        $this->assertStringContainsString('use DateTimeImmutable;', $action);
        $this->assertStringContainsString('public function __invoke(Request $request, $name = \'world\'): mixed', $action);
        $this->assertStringContainsString('$viewData = array_merge($viewData, compact(\'name\', \'year\'));', $action);
        $this->assertStringContainsString("return CakeView::render('legacy.Notes.hello', 'legacy.layout.default', \$viewData, 'Notes');", $action);
        // No AuthorizationMiddleware in the fixture, so no end-of-action check.
        $this->assertStringNotContainsString('CakeAuth::ensure', $action);
        $this->assertStringNotContainsString('parent::', $action);

        $this->assertSame("<p>Hello, <?= \\Cake\\Core\\h(\$name) ?>! It is <?= \$year ?>.</p>\n", file_get_contents($app . '/resources/views/legacy/Notes/hello.blade.php'));

        $web = (string) file_get_contents($app . '/routes/web.php');
        $this->assertStringContainsString(CakeMover::SKIP_INPUT, $web);
        $this->assertStringContainsString(
            "// booster:strangler GET /notes/hello/{name?} from legacy/src/Controller/NotesController.php:32\n"
            . "\\Illuminate\\Support\\Facades\\Route::match(['GET'], 'notes/hello/{name?}', \\App\\Http\\Controllers\\Legacy\\Cake\\Notes\\HelloAction::class)" . HelmetHold::SKIP . ";\n",
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
        $this->assertStringContainsString("Route::match(['GET'], '/', \\App\\Http\\Controllers\\Legacy\\Cake\\Notes\\IndexAction::class)" . HelmetHold::SKIP . "\n    ->name('home');", $web);
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
            "Route::match(['GET'], 'notes/me', \\App\\Http\\Controllers\\Legacy\\Cake\\Notes\\MeAction::class)\n    ->middleware('auth.sesame')" . HelmetHold::SKIP . ';',
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
        $result = (new CakeMover())->move($this->unit($app, 'GET /notes/stats'), $app);

        $this->assertFalse($result->moved);
        $this->assertSame([], $result->changedFiles);
        $this->assertSame([], $result->manual);
        $this->assertContains([
            'file' => 'legacy/src/Controller/NotesController.php',
            'line' => 51,
            'reason' => '`Table::getConnection()` is outside the CakePHP table API the Laravel half covers (find, get, newEmptyEntity, newEntity, patchEntity, save, delete, exists)',
        ], $result->refusals);
        $this->assertSame($before, file_get_contents($app . '/routes/web.php'));
        $this->assertDirectoryDoesNotExist($app . '/app/Http/Controllers/Legacy');
    }

    public function test_a_write_keeps_laravel_csrf_when_the_legacy_app_checks_tokens(): void
    {
        $app = $this->app(step0Done: true);
        $file = $app . '/legacy/src/Application.php';
        file_put_contents($file, str_replace('->add(new RoutingMiddleware($this))', '->add(new RoutingMiddleware($this))->add(new \Cake\Http\Middleware\CsrfProtectionMiddleware([\'httponly\' => true]))', (string) file_get_contents($file)));
        $result = (new CakeMover())->move($this->unit($app, 'FORM /'), $app);

        $this->assertTrue($result->moved, json_encode($result->refusals, JSON_PRETTY_PRINT) ?: '');
        $web = (string) file_get_contents($app . '/routes/web.php');
        // Design 9.2: the moved form posts to a moved action, so Laravel checks the token.
        $this->assertStringContainsString("Route::match(['GET', 'POST'], 'notes/add', \\App\\Http\\Controllers\\Legacy\\Cake\\Notes\\AddAction::class);", $web);
        $this->assertStringNotContainsString('withoutMiddleware', $web);
        $this->assertStringContainsString(CakeMover::CSRF_BRIDGE, $web);
        $this->assertStringContainsString("\\Illuminate\\Cookie\\Middleware\\EncryptCookies::except('csrfToken');", $web);
        $view = (string) file_get_contents($app . '/app/Http/Controllers/Legacy/Cake/CakeView.php');
        $this->assertStringContainsString("'cookie' => 'csrfToken',", $view);
        $this->assertStringContainsString("'httponly' => true,", $view);
        // A bad token ends in the legacy error page, written as Blade without the debug block.
        $error = (string) file_get_contents($app . '/resources/views/legacy/Error/error500.blade.php');
        $this->assertStringContainsString("<h2><?= \\Cake\\I18n\\__d('cake', 'An Internal Error Has Occurred') ?></h2>", $error);
        $this->assertStringNotContainsString('Configure', $error);
        $this->assertStringNotContainsString('$this->', $error);
        $this->assertStringContainsString("e('javascript:history.back()')", (string) file_get_contents($app . '/resources/views/legacy/layout/error.blade.php'));
        $this->assertContains(
            'legacy/src/Controller/NotesController.php:16 POST /notes/add keeps Laravel\'s CSRF check (PreventRequestForgery; ValidateCsrfToken before Laravel 13): the moved form prints Laravel\'s _token, and a bad token gets the legacy 403 page',
            $result->manual,
        );
    }

    public function test_a_route_scope_csrf_middleware_counts(): void
    {
        $app = $this->app();
        $routes = $app . '/legacy/config/routes.php';
        file_put_contents($routes, preg_replace('/^<\?php/', "<?php\n\$routes->registerMiddleware('csrf', new \\Cake\\Http\\Middleware\\CsrfProtectionMiddleware(['cookieName' => 'tok']));", (string) file_get_contents($routes), 1));
        $settings = (new CakeRuntime(new CakeApp($app)))->csrfSettings();

        $this->assertSame(['cookie' => 'tok', 'field' => '_csrfToken', 'httponly' => false, 'file' => 'legacy/config/routes.php', 'session' => false], $settings);
    }

    public function test_a_table_call_maps_to_the_ported_table_and_moves_after_the_page_with_its_form(): void
    {
        $app = $this->app(step0Done: true);
        $result = (new CakeMover())->move($this->unit($app, 'FORM /notes/report'), $app);
        $this->assertFalse($result->moved);
        $this->assertContains('FORM /notes/report shares a form or a flash message with GET /notes/show/{id}, which has not moved yet; move GET /notes/show/{id} first', array_column($result->refusals, 'reason'));

        $this->assertTrue((new CakeMover())->move($this->unit($app, 'GET /notes/show/{id}'), $app)->moved);
        $result = (new CakeMover())->move($this->unit($app, 'FORM /notes/report'), $app);
        $this->assertTrue($result->moved, json_encode($result->refusals, JSON_PRETTY_PRINT) ?: '');
        $report = (string) file_get_contents($app . '/app/Http/Controllers/Legacy/Cake/Notes/ReportAction.php');
        $this->assertStringContainsString("\$rows = CakeTable::for('Notes')->find()->all();", $report);
        $table = (string) file_get_contents($app . '/app/Http/Controllers/Legacy/Cake/Model/Table/NotesTable.php');
        $this->assertStringContainsString('namespace App\\Http\\Controllers\\Legacy\\Cake\\Model\\Table;', $table);
        $this->assertStringContainsString('use App\\Http\\Controllers\\Legacy\\Cake\\CakeTable as Table;', $table);
        $this->assertStringContainsString("\$this->setTable('notes');", $table);
        $entity = (string) file_get_contents($app . '/app/Http/Controllers/Legacy/Cake/Model/Entity/Note.php');
        $this->assertStringContainsString("class Note extends \\Illuminate\\Database\\Eloquent\\Model\n{\n    use \\App\\Http\\Controllers\\Legacy\\Cake\\CakeEntity;", $entity);
    }

    public function test_a_form_helper_call_maps_to_the_form_runtime_with_the_legacy_templates(): void
    {
        $app = $this->app(step0Done: true);
        $mover = new CakeMover();
        $result = $mover->move($this->unit($app, 'GET /notes/show/{id}'), $app);

        $this->assertTrue($result->moved, json_encode($result->refusals, JSON_PRETTY_PRINT) ?: '');
        $show = (string) file_get_contents($app . '/resources/views/legacy/Notes/show.blade.php');
        $this->assertStringContainsString("\$__form->postLink('Delete', ", $show);
        $this->assertStringContainsString("['confirm' => 'Sure?'])", $show);
        $form = (string) file_get_contents($app . '/app/Http/Controllers/Legacy/Cake/CakeForm.php');
        $this->assertStringContainsString("'formStart' => '<form{{attrs}}>',", $form);
        $this->assertStringContainsString("'string' => 'text',", $form);
        $this->assertStringNotContainsString('/*BOOSTER:', $form);
        // No CSRF middleware in the fixture: the form prints no token.
        $this->assertStringContainsString('public const LEGACY_CSRF = null;', (string) file_get_contents($app . '/app/Http/Controllers/Legacy/Cake/CakeView.php'));
        $composer = json_decode((string) file_get_contents($app . '/composer.json'), true);
        // The legacy app's CakePHP minor (its composer.lock, else composer.json).
        $this->assertSame('^5.0', $composer['require']['cakephp/utility'] ?? null);
        $this->assertContains('composer.json', $result->changedFiles);
        $this->assertSame(['cakephp/collection' => '^5.0', 'cakephp/core' => '^5.0', 'cakephp/i18n' => '^5.0', 'cakephp/utility' => '^5.0'], $mover->composerAdds());
    }

    public function test_the_error_template_loses_only_its_debug_block(): void
    {
        $source = "<?php\nuse Cake\\Core\\Configure;\nuse Cake\\Error\\Debugger;\n\n\$this->layout = 'error';\n\nif (Configure::read('debug')) :\n    \$this->layout = 'dev_error';\nendif;\n?>\n<h2><?= h(\$message) ?></h2>\n";
        $this->assertSame("<?php\n\n\n?>\n<h2><?= h(\$message) ?></h2>\n", CakeRuntime::errorTemplate($source));
        $this->assertNull(CakeRuntime::errorTemplate("<?php\n?>\n<?= Configure::read('x') ?>"));
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
            'legacy/templates/layout/default.php:14 `$this->Paginator->counter`: PaginatorHelper markup is not covered yet; port it by hand (in the Blade layout resources/views/legacy/layout/default.blade.php)',
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

    /** @return list<string> the runtime files every move writes */
    private static function runtime(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__) . '/resources/strangler/cake/*.php') ?: [] as $file) {
            $out[] = CakeMover::DIR . basename($file);
        }
        sort($out);

        return $out;
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
