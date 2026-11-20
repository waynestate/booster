<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\Cake\AuthRewriter;

final class CakeAuthRewriterTest extends TestCase
{
    private const LOGOUT_NOTE = 'MANUAL: $this->Authentication->logout() returns the redirect URL or null (AuthComponent returned its logoutRedirect); check what the action does with it.';

    private const ALLOW_NOTE = "MANUAL: allowUnauthenticated() takes an array of action names, and AuthComponent's allow() also took one name or none (every action); check each converted call.";

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

    public function test_allow_becomes_allow_unauthenticated(): void
    {
        $result = (new AuthRewriter())->rewriteCalls(self::php(
            '$this->Auth->allow([\'index\', \'view\']);',
            '$this->Auth->Allow(array(\'display\'));',
        ));

        $this->assertSame(self::php(
            '$this->Authentication->allowUnauthenticated([\'index\', \'view\']);',
            '$this->Authentication->allowUnauthenticated(array(\'display\'));',
        ), $result['code']);
        $this->assertSame(['Rewrote 2 $this->Auth->allow() calls to the Authentication plugin.'], $result['changes']);
        $this->assertSame([], $result['manual']);

        $single = (new AuthRewriter())->rewriteCalls(self::php('$this->Auth->allow(\'login\');'));

        $this->assertSame(self::php('$this->Authentication->allowUnauthenticated(\'login\');'), $single['code']);
        $this->assertSame([self::ALLOW_NOTE], $single['manual']);
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
            '$this->Authentication->allowUnauthenticated([\'display\']);',
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
        $this->assertSame([
            "MANUAL: Auth config 'loginAction' dropped: it becomes the authentication service's unauthenticatedRedirect; scaffold getAuthenticationService() in src/Application.php.",
            "MANUAL: Auth config 'authorize' dropped: the Authentication plugin has no like-for-like; port it by hand.",
            "MANUAL: Auth config 'logoutRedirect' dropped: the Authentication plugin has no like-for-like; port it by hand.",
            "MANUAL: Auth config 'storage' dropped: the Authentication plugin has no like-for-like; port it by hand.",
            "MANUAL: Auth config 'authError' dropped: the Authentication plugin has no counterpart; flash a message on the login action if the app wants one.",
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
