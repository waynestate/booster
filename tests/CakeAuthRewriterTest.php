<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\Cake\AuthRewriter;

final class CakeAuthRewriterTest extends TestCase
{
    private const LOGOUT_NOTE = 'MANUAL: $this->Authentication->logout() returns the redirect URL or null (AuthComponent returned its logoutRedirect); check what the action does with it.';

    private const ALLOW_NOTE = "MANUAL: addUnauthenticatedActions() takes an array of action names, and AuthComponent's allow() also took one name or null (every action); check each converted call.";

    public function test_user_with_a_plain_key(): void
    {
        $result = (new AuthRewriter())->rewriteCalls(self::php('$role = $this->Auth->user(\'ROLE\');'));

        $this->assertSame(self::php('$role = $this->Authentication->getIdentity()?->get(\'ROLE\');'), $result['code']);
        $this->assertSame(['Rewrote 1 $this->Auth->user() call to the Authentication plugin.'], $result['changes']);
        $this->assertSame([], $result['manual']);
    }

    public function test_user_with_a_dotted_key_uses_hash_get(): void
    {
        $result = (new AuthRewriter())->rewriteCalls(self::php('$admin = $this->Auth->user(\'ROLES.admin\');'));

        $this->assertSame(
            self::php('$admin = \Cake\Utility\Hash::get($this->Authentication->getIdentity()?->getOriginalData() ?? [], \'ROLES.admin\');'),
            $result['code'],
        );
        $this->assertSame(['Rewrote 1 $this->Auth->user() call to the Authentication plugin.'], $result['changes']);
    }

    public function test_user_with_a_variable_key_uses_hash_get(): void
    {
        $result = (new AuthRewriter())->rewriteCalls(self::php('$value = $this->Auth->user( $field );'));

        $this->assertSame(
            self::php('$value = \Cake\Utility\Hash::get($this->Authentication->getIdentity()?->getOriginalData() ?? [], $field);'),
            $result['code'],
        );
        $this->assertSame([], $result['manual']);
    }

    public function test_user_without_arguments_returns_original_data(): void
    {
        $result = (new AuthRewriter())->rewriteCalls(self::php(
            '$user = $this->Auth->user();',
            'if ($this->Auth->user()) {',
            '    return;',
            '}',
        ));

        $this->assertSame(self::php(
            '$user = $this->Authentication->getIdentity()?->getOriginalData();',
            'if ($this->Authentication->getIdentity()?->getOriginalData()) {',
            '    return;',
            '}',
        ), $result['code']);
        $this->assertSame(['Rewrote 2 $this->Auth->user() calls to the Authentication plugin.'], $result['changes']);
    }

    public function test_capital_u_user_is_the_same_rule(): void
    {
        $result = (new AuthRewriter())->rewriteCalls(self::php(
            '$id = $this->Auth->User(\'ACCESS_ID\');',
            '$name = $this->Auth->USER("NAME");',
            '$all = $this->Auth->User();',
        ));

        $this->assertSame(self::php(
            '$id = $this->Authentication->getIdentity()?->get(\'ACCESS_ID\');',
            '$name = $this->Authentication->getIdentity()?->get("NAME");',
            '$all = $this->Authentication->getIdentity()?->getOriginalData();',
        ), $result['code']);
        $this->assertSame(['Rewrote 3 $this->Auth->user() calls to the Authentication plugin.'], $result['changes']);
    }

    public function test_set_user_becomes_set_identity(): void
    {
        $result = (new AuthRewriter())->rewriteCalls(self::php(
            '$this->Auth->setUser($profile);',
            '$this->Auth->SetUser([\'ACCESS_ID\' => $id, \'ROLE\' => $role]);',
        ));

        $this->assertSame(self::php(
            '$this->Authentication->setIdentity($profile);',
            '$this->Authentication->setIdentity([\'ACCESS_ID\' => $id, \'ROLE\' => $role]);',
        ), $result['code']);
        $this->assertSame(['Rewrote 2 $this->Auth->setUser() calls to the Authentication plugin.'], $result['changes']);
        $this->assertSame([], $result['manual']);
    }

    public function test_logout_gets_a_manual_note(): void
    {
        $result = (new AuthRewriter())->rewriteCalls(self::php(
            'return $this->redirect($this->Auth->logout());',
            '$this->Auth->logout();',
        ));

        $this->assertSame(self::php(
            'return $this->redirect($this->Authentication->logout());',
            '$this->Authentication->logout();',
        ), $result['code']);
        $this->assertSame(['Rewrote 2 $this->Auth->logout() calls to the Authentication plugin.'], $result['changes']);
        $this->assertSame([self::LOGOUT_NOTE], $result['manual']);
    }

    // CAKE-10: allow() added to the list; allowUnauthenticated() replaces it, so a later call dropped the earlier names.
    public function test_allow_adds_unauthenticated_actions_like_auth_component(): void
    {
        $result = (new AuthRewriter())->rewriteCalls(self::php(
            '$this->Auth->allow([\'index\', \'view\']);',
            '$this->Auth->Allow(array(\'display\'));',
        ));

        $this->assertSame(self::php(
            '$this->Authentication->addUnauthenticatedActions([\'index\', \'view\']);',
            '$this->Authentication->addUnauthenticatedActions(array(\'display\'));',
        ), $result['code']);
        $this->assertSame(['Rewrote 2 $this->Auth->allow() calls to the Authentication plugin.'], $result['changes']);
        $this->assertSame([], $result['manual']);

        $single = (new AuthRewriter())->rewriteCalls(self::php('$this->Auth->allow(\'login\');', '$this->Auth->allow();', '$this->Auth->allow( /* all */ );'));
        $this->assertSame(self::php(
            '$this->Authentication->addUnauthenticatedActions([\'login\']);',
            '$this->Authentication->disableIdentityCheck();',
            '$this->Authentication->disableIdentityCheck();',
        ), $single['code']);
        $this->assertSame([], $single['manual']);

        $variable = (new AuthRewriter())->rewriteCalls(self::php('$this->Auth->allow($open);'));
        $this->assertSame(self::php('$this->Authentication->addUnauthenticatedActions((array) $open);'), $variable['code']);
        $this->assertSame([self::ALLOW_NOTE], $variable['manual']);
    }

    // CAKE-11: attendance's /auth/login redirected to /auth/login on 5.
    public function test_login_target_reads_only_plain_controller_and_action_arrays(): void
    {
        $rewriter = new AuthRewriter();
        self::assertSame(['controller' => 'Auth', 'action' => 'login'], $rewriter->loginTarget("[\n    'controller' => 'Auth',\n    'action' => 'login'\n]"));
        self::assertSame(['controller' => 'UserAccounts', 'action' => 'signIn'], $rewriter->loginTarget("array('controller' => 'user_accounts', 'action' => 'signIn', 'plugin' => false, 'prefix' => null)"));
        self::assertNull($rewriter->loginTarget("'/users/login'"));
        self::assertNull($rewriter->loginTarget("['controller' => 'Users', 'action' => 'login', 'plugin' => 'CakeDC/Users']"));
        self::assertNull($rewriter->loginTarget("['controller' => \$name, 'action' => 'login']"));
        self::assertNull($rewriter->loginTarget("['controller' => 'Users']"));
    }

    public function test_open_login_action_adds_the_action_after_the_parent_initialize(): void
    {
        $controller = "<?php\nclass AuthController extends AppController\n{\n    public function initialize(): void\n    {\n        parent::initialize();\n        \$this->x = 1;\n    }\n\n    public function login() {}\n}\n";
        $result = (new AuthRewriter())->openLoginAction($controller, 'login');

        self::assertTrue($result['changed']);
        self::assertNull($result['manual']);
        self::assertStringContainsString("        \$this->x = 1;\n        // AuthComponent let guests reach its loginAction; the Authentication plugin needs it named.\n        \$this->Authentication->addUnauthenticatedActions(['login']);\n    }\n\n    public function login()", $result['code']);
        self::assertFalse((new AuthRewriter())->openLoginAction($result['code'], 'login')['changed'], 'run twice, added once');

        $crlf = (new AuthRewriter())->openLoginAction(str_replace("\n", "\r\n", $controller), 'login');
        self::assertStringContainsString("addUnauthenticatedActions(['login']);\r\n    }", $crlf['code']);
    }

    public function test_open_login_action_adds_an_initialize_or_names_a_manual_step(): void
    {
        $bare = "<?php\nnamespace App\\Controller;\n\nclass UsersController extends AppController\n{\n    public function login() {}\n}\n";
        $result = (new AuthRewriter())->openLoginAction($bare, 'login');
        self::assertTrue($result['changed']);
        self::assertStringContainsString("{\n    public function initialize(): void\n    {\n        parent::initialize();\n        // AuthComponent let guests reach its loginAction; the Authentication plugin needs it named.\n        \$this->Authentication->addUnauthenticatedActions(['login']);\n    }\n\n    public function login() {}", $result['code']);

        $open = "<?php\nclass UsersController extends AppController\n{\n    public function initialize(): void\n    {\n        parent::initialize();\n        \$this->Authentication->addUnauthenticatedActions(['register', 'login']);\n    }\n}\n";
        self::assertSame(['code' => $open, 'changed' => false, 'manual' => null], (new AuthRewriter())->openLoginAction($open, 'login'));

        $noParent = "<?php\nclass UsersController extends AppController\n{\n    public function initialize(): void\n    {\n        \$this->loadComponent('Flash');\n    }\n}\n";
        $manual = (new AuthRewriter())->openLoginAction($noParent, 'login');
        self::assertFalse($manual['changed']);
        self::assertStringStartsWith('MANUAL: AuthComponent let guests reach its loginAction (login)', (string) $manual['manual']);

        $other = (new AuthRewriter())->openLoginAction("<?php\nclass UsersController extends Controller\n{\n}\n", 'login');
        self::assertFalse($other['changed']);
        self::assertNotNull($other['manual']);
    }

    public function test_unknown_auth_member_is_left_and_listed(): void
    {
        $source = self::php(
            'if (!$this->Auth->isAuthorized($user)) {',
            '    return $this->redirect($this->Auth->redirectUrl());',
            '}',
            '$back = $this->Auth->redirectUrl();',
            '$this->Auth->config(\'authError\', \'Nope\');',
            '$adapter = $this->Auth->authorize;',
        );
        $result = (new AuthRewriter())->rewriteCalls($source);

        $this->assertSame($source, $result['code']);
        $this->assertSame([], $result['changes']);
        $this->assertSame([
            'MANUAL: $this->Auth->isAuthorized() has no Authentication plugin like-for-like; port it by hand.',
            'MANUAL: $this->Auth->redirectUrl() has no Authentication plugin like-for-like; port it by hand.',
            'MANUAL: $this->Auth->config() has no Authentication plugin like-for-like; port it by hand.',
            'MANUAL: $this->Auth->authorize() has no Authentication plugin like-for-like; port it by hand.',
        ], $result['manual']);
    }

    public function test_strings_and_comments_are_not_rewritten(): void
    {
        $source = self::php(
            '// $this->Auth->user(\'ROLE\') used to live here',
            '/* $this->Auth->logout(); */',
            '/** @see $this->Auth->setUser() */',
            '$a = \'$this->Auth->user()\';',
            '$b = "call {$this->Auth->user(\'NAME\')} later";',
            '$c = <<<TXT',
            '    {$this->Auth->allow([\'x\'])}',
            '    TXT;',
            '$d = <<<\'TXT\'',
            '    $this->Auth->user()',
            '    TXT;',
            '# $this->Auth->allow();',
            '$e = $this->Auth->user(\'ROLE\'); // $this->Auth->user()',
        );
        $result = (new AuthRewriter())->rewriteCalls($source);

        $this->assertSame(str_replace(
            '$e = $this->Auth->user(\'ROLE\');',
            '$e = $this->Authentication->getIdentity()?->get(\'ROLE\');',
            $source,
        ), $result['code']);
        $this->assertSame(['Rewrote 1 $this->Auth->user() call to the Authentication plugin.'], $result['changes']);
        $this->assertSame([], $result['manual']);
    }

    public function test_rewrite_is_idempotent(): void
    {
        $rewriter = new AuthRewriter();
        $first = $rewriter->rewriteCalls(self::php(
            '$this->Auth->allow([\'display\']);',
            '$this->Auth->setUser($profile);',
            '$role = $this->Auth->User(\'ROLE\');',
            '$admin = $this->Auth->user(\'ROLES.admin\');',
            '$all = $this->Auth->user();',
            '$url = $this->Auth->logout();',
        ));
        $second = $rewriter->rewriteCalls($first['code']);

        $this->assertSame(self::php(
            '$this->Authentication->addUnauthenticatedActions([\'display\']);',
            '$this->Authentication->setIdentity($profile);',
            '$role = $this->Authentication->getIdentity()?->get(\'ROLE\');',
            '$admin = \Cake\Utility\Hash::get($this->Authentication->getIdentity()?->getOriginalData() ?? [], \'ROLES.admin\');',
            '$all = $this->Authentication->getIdentity()?->getOriginalData();',
            '$url = $this->Authentication->logout();',
        ), $first['code']);
        $this->assertSame([
            'Rewrote 3 $this->Auth->user() calls to the Authentication plugin.',
            'Rewrote 1 $this->Auth->setUser() call to the Authentication plugin.',
            'Rewrote 1 $this->Auth->logout() call to the Authentication plugin.',
            'Rewrote 1 $this->Auth->allow() call to the Authentication plugin.',
        ], $first['changes']);
        $this->assertSame($first['code'], $second['code']);
        $this->assertSame([], $second['changes']);
        $this->assertSame([], $second['manual']);
    }

    public function test_load_component_drops_config_and_lists_keys(): void
    {
        $result = (new AuthRewriter())->rewriteLoadComponent(self::php(
            'public function initialize(): void',
            '{',
            '    $this->loadComponent(\'Flash\');',
            '    $this->loadComponent(\'Auth\', [',
            '        \'loginAction\' => [',
            '            \'controller\' => \'Users\',',
            '            \'action\' => \'login\',',
            '        ],',
            '        \'authorize\' => [\'Controller\'],',
            '        \'logoutRedirect\' => \'/\',',
            '        \'storage\' => \'Session\',',
            '        \'authError\' => \'\',',
            '    ]);',
            '}',
        ));

        $this->assertSame(self::php(
            'public function initialize(): void',
            '{',
            '    $this->loadComponent(\'Flash\');',
            '    $this->loadComponent(\'Authentication.Authentication\');',
            '}',
        ), $result['code']);
        $this->assertSame(
            ["Replaced \$this->loadComponent('Auth') with \$this->loadComponent('Authentication.Authentication')."],
            $result['changes'],
        );
        // loginAction and authError move to src/Application.php's service (CAKE-12), so they get no line here.
        $this->assertSame([
            "MANUAL: Auth config 'authorize' dropped: the Authentication plugin has no like-for-like; port it by hand.",
            "MANUAL: Auth config 'logoutRedirect' dropped: the Authentication plugin has no like-for-like; port it by hand.",
            "MANUAL: Auth config 'storage' dropped: the Authentication plugin has no like-for-like; port it by hand.",
        ], $result['manual']);

        $again = (new AuthRewriter())->rewriteLoadComponent($result['code']);
        $this->assertSame($result['code'], $again['code']);
        $this->assertSame([], $again['changes']);
    }

    public function test_login_action_is_extracted(): void
    {
        $rewriter = new AuthRewriter();
        $source = self::php(
            '$this->loadComponent(\'Auth\', [',
            '    \'authError\' => \'\',',
            '    \'loginAction\' => [\'controller\' => \'Users\', \'action\' => \'login\', \'prefix\' => false],',
            ']);',
        );

        $this->assertSame("['controller' => 'Users', 'action' => 'login', 'prefix' => false]", $rewriter->loginActionOf($source));
        $this->assertSame("'/auth/login'", $rewriter->loginActionOf(self::php('$this->loadComponent("Auth", array(\'loginAction\' => \'/auth/login\'));')));
        $this->assertNull($rewriter->loginActionOf(self::php('$this->loadComponent(\'Auth\');')));
        $this->assertNull($rewriter->loginActionOf(self::php('$this->loadComponent(\'Flash\', [\'loginAction\' => \'/x\']);')));
    }

    public function test_component_properties_are_typed_and_auth_entry_renamed(): void
    {
        $result = (new AuthRewriter())->rewriteComponentProperties(self::php(
            'namespace App\Controller\Component;',
            '',
            'use Cake\Controller\Component;',
            '',
            'class DataManagementComponent extends Component',
            '{',
            '    public $components = [\'Auth\', \'Flash\'];',
            '',
            '    public function owner()',
            '    {',
            '        $components = [\'Auth\'];',
            '',
            '        return $this->Auth->user(\'ACCESS_ID\');',
            '    }',
            '}',
            '',
            'class LegacyComponent extends \Cake\Controller\Component',
            '{',
            '    var $components = array(\'Session\');',
            '}',
        ));

        $this->assertSame(self::php(
            'namespace App\Controller\Component;',
            '',
            'use Cake\Controller\Component;',
            '',
            'class DataManagementComponent extends Component',
            '{',
            '    protected array $components = [\'Authentication.Authentication\', \'Flash\'];',
            '',
            '    public function owner()',
            '    {',
            '        $components = [\'Auth\'];',
            '',
            '        return $this->Auth->user(\'ACCESS_ID\');',
            '    }',
            '}',
            '',
            'class LegacyComponent extends \Cake\Controller\Component',
            '{',
            '    protected array $components = array(\'Session\');',
            '}',
        ), $result['code']);
        $this->assertSame([
            'Changed $components in DataManagementComponent to protected array.',
            "Renamed 'Auth' to 'Authentication.Authentication' in DataManagementComponent's \$components.",
            'Changed $components in LegacyComponent to protected array.',
        ], $result['changes']);
        $this->assertSame([], $result['manual']);
    }

    public function test_non_component_class_properties_are_untouched(): void
    {
        $source = self::php(
            'class AppController extends Controller',
            '{',
            '    public $components = [\'Auth\'];',
            '}',
            '',
            'class Helper',
            '{',
            '    var $components = [\'Auth\'];',
            '}',
            '',
            '$name = Component::class;',
        );
        $result = (new AuthRewriter())->rewriteComponentProperties($source);

        $this->assertSame($source, $result['code']);
        $this->assertSame([], $result['changes']);
        $this->assertSame([], $result['manual']);
    }

    public function test_nested_parentheses_in_arguments(): void
    {
        $result = (new AuthRewriter())->rewriteCalls(self::php(
            '$v = $this->Auth->user(sprintf(\'%s.%s\', strtoupper($k), ($n + 1)));',
            '$this->Auth->setUser(array_merge($u, [\'X\' => $this->Auth->user(\'X\')]));',
        ));

        $this->assertSame(self::php(
            '$v = \Cake\Utility\Hash::get($this->Authentication->getIdentity()?->getOriginalData() ?? [], sprintf(\'%s.%s\', strtoupper($k), ($n + 1)));',
            '$this->Authentication->setIdentity(array_merge($u, [\'X\' => $this->Authentication->getIdentity()?->get(\'X\')]));',
        ), $result['code']);
        $this->assertSame([
            'Rewrote 2 $this->Auth->user() calls to the Authentication plugin.',
            'Rewrote 1 $this->Auth->setUser() call to the Authentication plugin.',
        ], $result['changes']);
    }

    private static function php(string ...$lines): string
    {
        return "<?php\n\n" . implode("\n", $lines) . "\n";
    }
}
