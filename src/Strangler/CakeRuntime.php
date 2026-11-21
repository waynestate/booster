<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * The CakePHP runtime a moved action runs on in the Laravel half (design
 * sections 4 and 9.2): Booster's `resources/strangler/cake/` classes
 * (CakeView, CakeForm, CakeTable, CakeQuery, CakeEntity, CakeValidator,
 * CakeRulesChecker and the rest), with the legacy app's own FormHelper
 * templates and CSRF settings filled in, plus ports of the legacy Tables,
 * Entities and Policies onto them. A port keeps the legacy code as written
 * (only namespaces and imports change), and a method a moved action
 * reaches that calls anything outside the covered API refuses with
 * file:line, so a moved action never half works.
 */
final class CakeRuntime
{
    public const NS = 'App\\Http\\Controllers\\Legacy\\Cake';

    public const DIR = 'app/Http/Controllers/Legacy/Cake/';

    /** The split CakePHP packages the runtime needs, by name (constraint from the legacy app's CakePHP). */
    public const PACKAGES = ['cakephp/collection', 'cakephp/core', 'cakephp/i18n', 'cakephp/utility'];

    /** Method names the runtime covers on tables, queries, results, entities, validators, rules, events and identities. */
    private const COVERED = [
        // CakeTable
        'initialize', 'settable', 'gettable', 'getalias', 'setprimarykey', 'getprimarykey', 'setdisplayfield', 'getdisplayfield',
        'addbehavior', 'hasbehavior', 'belongsto', 'hasone', 'hasmany', 'belongstomany', 'find', 'get', 'exists', 'newemptyentity',
        'newentity', 'patchentity', 'save', 'delete', 'query', 'validationdefault', 'buildrules', 'getvalidator',
        // CakeQuery
        'where', 'andwhere', 'order', 'orderby', 'orderasc', 'orderdesc', 'limit', 'offset', 'page', 'contain', 'all', 'first',
        'firstorfail', 'count', 'toarray', 'tolist', 'getrepository',
        // CakeResultSet (cakephp/collection)
        'extract', 'combine', 'map', 'filter', 'reject', 'reduce', 'each', 'indexby', 'groupby', 'sortby', 'isempty', 'contains',
        'some', 'every', 'sumof', 'max', 'min', 'last', 'take', 'skip', 'chunk', 'append', 'unfold', 'nest', 'countby',
        // CakeEntity
        'isnew', 'setnew', 'geterrors', 'geterror', 'seterrors', 'seterror', 'haserrors', 'getinvalidfield', 'setinvalidfield',
        'set', 'has', 'hasvalue', 'isdirty', 'setdirty', 'clean', 'isaccessible', 'getsource',
        // CakeValidator
        'requirepresence', 'allowemptystring', 'allowemptyfor', 'notemptystring', 'notblank', 'maxlength', 'minlength',
        'lengthbetween', 'email', 'inlist', 'numeric', 'integer', 'naturalnumber', 'nonnegativeinteger', 'boolean', 'scalar', 'add',
        // CakeRulesChecker
        'isunique', 'existsin', 'addcreate', 'addupdate',
        // CakeEvent
        'getsubject', 'stoppropagation', 'isstopped', 'getresult', 'setresult', 'getdata', 'setdata', 'getname',
        // CakeIdentity
        'getidentifier', 'getoriginaldata',
    ];

    /** Classes ported code may use: the split packages the runtime installs. */
    private const CLASSES = ['Cake\\Utility\\Text', 'Cake\\Utility\\Inflector', 'Cake\\Utility\\Hash', 'Cake\\Collection\\Collection', 'Cake\\I18n\\DateTime', 'Cake\\I18n\\Date', 'Cake\\I18n\\Number', 'ArrayObject', 'DateTimeImmutable', 'DateTime'];

    /** The legacy classes a port's imports map to. */
    private const IMPORTS = [
        'Cake\\ORM\\Table' => self::NS . '\\CakeTable',
        'Cake\\ORM\\Entity' => null,
        'Cake\\Validation\\Validator' => self::NS . '\\CakeValidator',
        'Cake\\ORM\\RulesChecker' => self::NS . '\\CakeRulesChecker',
        'Cake\\Event\\EventInterface' => self::NS . '\\CakeEvent',
        'Cake\\Event\\Event' => self::NS . '\\CakeEvent',
        'Cake\\ORM\\Query\\SelectQuery' => self::NS . '\\CakeQuery',
        'Cake\\ORM\\Query' => self::NS . '\\CakeQuery',
        'Cake\\Datasource\\EntityInterface' => 'Illuminate\\Database\\Eloquent\\Model',
        'Authorization\\IdentityInterface' => self::NS . '\\CakeIdentity',
    ];

    /** @var list<array{file: string, line: int, reason: string}> */
    public array $refusals = [];

    public function __construct(private readonly CakeApp $app) {}

    /**
     * Booster's runtime files, filled in for this app. FormHelper's
     * templates are read only when moved views print a form.
     *
     * @return array<string, string> app-relative file => code
     */
    public function files(bool $forms = true): array
    {
        $out = [];
        $templates = $forms ? $this->formTemplates() : ['templates' => [], 'typeMap' => []];
        foreach (glob(dirname(__DIR__, 2) . '/resources/strangler/cake/*.php') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            if (basename($file) === 'CakeForm.php') {
                $code = str_replace(
                    ['/*BOOSTER:TEMPLATES*/ []', '/*BOOSTER:TYPEMAP*/ []', "/*BOOSTER:ENCODING*/ 'utf-8'"],
                    [self::export($templates['templates']), self::export($templates['typeMap']), var_export($this->encoding(), true)],
                    $code,
                );
            }
            if (basename($file) === 'CakeView.php') {
                $code = str_replace(
                    ['/*BOOSTER:LEGACY_CSRF*/ null', "/*BOOSTER:ERROR_TEMPLATES*/ ['guest' => 'code', 'user' => 'code']"],
                    [self::export($this->csrf()), self::export($this->errorTemplates())],
                    $code,
                );
            }
            $out[self::DIR . basename($file)] = $code;
        }
        ksort($out);

        return $out;
    }

    /** The legacy app's CakePHP constraint, as `^major.minor` for the split packages. */
    public function constraint(): string
    {
        $lock = $this->app->legacy . '/composer.lock';
        if (is_file($lock)) {
            $data = json_decode((string) file_get_contents($lock), true);
            foreach ((array) ($data['packages'] ?? []) as $package) {
                if (is_array($package) && ($package['name'] ?? '') === 'cakephp/cakephp' && preg_match('/^v?(\d+)\.(\d+)/', (string) ($package['version'] ?? ''), $m) === 1) {
                    return '^' . $m[1] . '.' . $m[2];
                }
            }
        }
        $json = json_decode((string) @file_get_contents($this->app->legacy . '/composer.json'), true);
        $require = is_array($json) ? (string) ($json['require']['cakephp/cakephp'] ?? '') : '';
        if (preg_match('/(\d+)\.(\d+)/', $require, $m) === 1) {
            return '^' . $m[1] . '.' . $m[2];
        }

        return '^5.0';
    }

    /**
     * FormHelper's templates and typeMap from the legacy app's own CakePHP.
     *
     * @return array{templates: array<string, string>, typeMap: array<string, string>}
     */
    public function formTemplates(): array
    {
        $file = $this->app->legacy . '/vendor/cakephp/cakephp/src/View/Helper/FormHelper.php';
        if (!is_file($file)) {
            $this->refusals[] = ['file' => 'legacy/composer.json', 'line' => 1, 'reason' => 'legacy/vendor/cakephp/cakephp is not installed, so its FormHelper templates cannot be read; run composer install in legacy/'];

            return ['templates' => [], 'typeMap' => []];
        }
        $c = new CakeCode((string) file_get_contents($file));
        $out = ['templates' => [], 'typeMap' => []];
        foreach ($c->t as $i => $token) {
            if ($token[0] !== T_CONSTANT_ENCAPSED_STRING || !in_array(trim($token[1], '\'"'), ['templates', 'typeMap'], true)) {
                continue;
            }
            $arrow = $c->next($i);
            $open = $arrow === null ? null : $c->next($arrow);
            if ($arrow === null || $open === null || $c->t[$arrow][0] !== T_DOUBLE_ARROW || $c->t[$open][1] !== '[' || !isset($c->close[$open])) {
                continue;
            }
            $key = trim($token[1], '\'"');
            if ($out[$key] !== []) {
                continue;
            }
            $value = $c->arrayAt([$open, $c->close[$open]]);
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (is_string($k) && is_string($v)) {
                        $out[$key][$k] = $v;
                    }
                }
            }
        }
        if (!isset($out['templates']['formStart'], $out['templates']['input'], $out['templates']['inputContainer'])) {
            $this->refusals[] = ['file' => 'legacy/vendor/cakephp/cakephp/src/View/Helper/FormHelper.php', 'line' => 1, 'reason' => 'the FormHelper default templates could not be read'];
        }
        $this->checkCustomTemplates();

        return $out;
    }

    /** An app that changes FormHelper's templates prints other markup; that is not covered. */
    private function checkCustomTemplates(): void
    {
        foreach (['src/View/AppView.php', 'config/app_form.php'] as $file) {
            $path = $this->app->legacy . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $c = new CakeCode((string) file_get_contents($path));
            $code = $c->code(0, count($c->t) - 1);
            if ($file === 'config/app_form.php' || preg_match('/[\'"]templates[\'"]\s*=>|setTemplates\s*\(|loadHelper\(\s*[\'"]Form[\'"]/', $code) === 1) {
                $this->refusals[] = ['file' => 'legacy/' . $file, 'line' => 1, 'reason' => 'the app sets its own FormHelper templates or Form helper class, which the Laravel half does not print'];
            }
        }
    }

    private function encoding(): string
    {
        $file = $this->app->legacy . '/config/app.php';
        if (is_file($file)) {
            $c = new CakeCode((string) file_get_contents($file));
            if (preg_match('/[\'"]encoding[\'"]\s*=>\s*(?:env\(\s*[\'"]APP_ENCODING[\'"]\s*,\s*)?[\'"]([\w-]+)[\'"]/', $c->code(0, count($c->t) - 1), $m) === 1) {
                return strtolower($m[1]);
            }
        }

        return 'utf-8';
    }

    /**
     * Where the legacy app registers CsrfProtectionMiddleware (src/Application.php
     * or a route scope in config/routes.php), its cookie name and options.
     *
     * @return array{cookie: string, field: string, httponly: bool, file: string, session: bool}|null
     */
    public function csrfSettings(): ?array
    {
        foreach (['src/Application.php', 'config/routes.php'] as $file) {
            $path = $this->app->legacy . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $c = new CakeCode((string) file_get_contents($path));
            foreach ($c->t as $i => $token) {
                $short = in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) ? substr((string) strrchr('\\' . $token[1], '\\'), 1) : '';
                if (!in_array($short, ['CsrfProtectionMiddleware', 'SessionCsrfProtectionMiddleware'], true)) {
                    continue;
                }
                $prev = $c->prev($i);
                if ($prev === null || $c->t[$prev][0] !== T_NEW) {
                    continue;
                }
                if ($short === 'SessionCsrfProtectionMiddleware') {
                    return ['cookie' => '', 'field' => '_csrfToken', 'httponly' => false, 'file' => 'legacy/' . $file, 'session' => true];
                }
                $open = $c->next($i);
                $options = [];
                if ($open !== null && $c->t[$open][1] === '(' && isset($c->close[$open])) {
                    $items = $c->items($open);
                    $options = isset($items[0]) ? ($c->arrayAt($items[0]) ?? ['?' => true]) : [];
                }

                return [
                    'cookie' => is_string($options['cookieName'] ?? null) ? $options['cookieName'] : 'csrfToken',
                    'field' => is_string($options['field'] ?? null) ? $options['field'] : '_csrfToken',
                    'httponly' => ($options['httponly'] ?? false) === true,
                    'file' => 'legacy/' . $file,
                    'session' => false,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{cookie: string, field: string, httponly: bool}|null
     */
    private function csrf(): ?array
    {
        $s = $this->csrfSettings();

        return $s === null ? null : ['cookie' => $s['cookie'], 'field' => $s['field'], 'httponly' => $s['httponly']];
    }

    /**
     * Which error template a guest and a signed-in user saw. CakePHP renders
     * errors through ErrorController; when that controller's startup needs
     * a signed-in user (the Authentication component, with no
     * addUnauthenticatedActions in its beforeFilter), a guest's error falls
     * back to the safe output, which always uses error500.
     *
     * @return array{guest: string, user: string}
     */
    public function errorTemplates(): array
    {
        $error = $this->app->legacy . '/src/Controller/ErrorController.php';
        $appController = $this->app->legacy . '/src/Controller/AppController.php';
        $loadsAuth = is_file($appController) && str_contains((string) file_get_contents($appController), 'Authentication.Authentication');
        if (!$loadsAuth || !is_file($error)) {
            return ['guest' => 'code', 'user' => 'code'];
        }
        $code = (string) file_get_contents($error);
        $allows = preg_match('/addUnauthenticatedActions|allowUnauthenticated/', $code) === 1;

        return ['guest' => $allows ? 'code' : 'error500', 'user' => 'code'];
    }

    /**
     * The legacy error template as Blade source input: CakePHP's debug
     * block (the `if (Configure::read('debug')) : ... endif;` the app
     * skeleton ships) dropped, since debug is off in production; null when
     * the template is not in that shape.
     */
    public static function errorTemplate(string $source): ?string
    {
        $out = preg_replace('/^use Cake\\\\(Core\\\\Configure|Error\\\\Debugger);\R/m', '', $source);
        $out = (string) preg_replace('/^\$this->layout = \'error\';\R/m', '', (string) $out);
        $count = 0;
        $out = (string) preg_replace('/^if \(Configure::read\(\'debug\'\)\) :\R.*?^endif;\R/sm', '', $out, 1, $count);
        if ($count !== 1 || str_contains($out, 'Configure::') || str_contains($out, 'Debugger::')) {
            return null;
        }

        return $out;
    }

    /**
     * Ports of the legacy Tables and Entities (and the Policies when the
     * app uses Authorization), keyed by app-relative file.
     *
     * @param list<string> $reached `Alias::method` names moved code reaches ('Articles::save', 'Articles::find:tagged')
     *
     * @return array<string, string>
     */
    public function ports(array $reached): array
    {
        $out = [];
        $tables = [];
        foreach (glob($this->app->legacy . '/src/Model/Table/*Table.php') ?: [] as $file) {
            $alias = substr(basename($file), 0, -strlen('Table.php'));
            if ($alias !== '' && $alias !== 'App') {
                $tables[$alias] = $file;
            }
        }
        ksort($tables);
        $schemas = [];
        foreach ($tables as $alias => $file) {
            $code = (string) file_get_contents($file);
            $out[self::DIR . 'Model/Table/' . $alias . 'Table.php'] = $this->port($code, 'App\\Model\\Table', self::NS . '\\Model\\Table', 'legacy/src/Model/Table/' . basename($file));
            $schemas[$alias] = $this->tableFacts($code);
            $this->checkTable($alias, $code, 'legacy/src/Model/Table/' . basename($file), $reached);
        }
        foreach (glob($this->app->legacy . '/src/Model/Entity/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $code = (string) file_get_contents($file);
            $ported = $this->port($code, 'App\\Model\\Entity', self::NS . '\\Model\\Entity', 'legacy/src/Model/Entity/' . basename($file));
            $ported = $this->entityBody($ported);
            $out[self::DIR . 'Model/Entity/' . $name . '.php'] = $ported;
            $this->checkMethods($code, 'legacy/src/Model/Entity/' . basename($file), null);
        }
        foreach (glob($this->app->legacy . '/src/Policy/*Policy.php') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            $out[self::DIR . 'Policy/' . basename($file)] = $this->port($code, 'App\\Policy', self::NS . '\\Policy', 'legacy/src/Policy/' . basename($file));
            $this->checkMethods($code, 'legacy/src/Policy/' . basename($file), null);
        }
        ksort($out);

        return $out;
    }

    /**
     * A legacy class file in a new namespace, imports remapped.
     */
    private function port(string $code, string $from, string $to, string $file): string
    {
        $code = (string) preg_replace('/^namespace ' . preg_quote($from, '/') . ';/m', 'namespace ' . $to . ';', $code, 1);
        $code = (string) preg_replace_callback('/^use ([\w\\\\]+)(?: as (\w+))?;\R/m', static function (array $m): string {
            $class = $m[1];
            $alias = $m[2] ?? substr((string) strrchr('\\' . $class, '\\'), 1);
            if (array_key_exists($class, self::IMPORTS)) {
                $target = self::IMPORTS[$class];

                return $target === null ? '' : 'use ' . $target . ($alias !== substr((string) strrchr('\\' . $target, '\\'), 1) ? ' as ' . $alias : '') . ";\n";
            }
            foreach (['App\\Model\\Entity\\' => self::NS . '\\Model\\Entity\\', 'App\\Model\\Table\\' => self::NS . '\\Model\\Table\\', 'App\\Policy\\' => self::NS . '\\Policy\\'] as $prefix => $replace) {
                if (str_starts_with($class, $prefix)) {
                    $new = $replace . substr($class, strlen($prefix));

                    return 'use ' . $new . ($alias !== substr((string) strrchr('\\' . $new, '\\'), 1) ? ' as ' . $alias : '') . ";\n";
                }
            }

            return $m[0];
        }, $code);
        $note = "/*\n * Written by Booster's strangler (cakephp-to-laravel) from " . $file . ": the legacy code as written, on the\n * Laravel half's CakePHP runtime (namespaces and imports changed). App-owned.\n */\n";

        return (string) preg_replace('/^(namespace [^;]+;\R)/m', "$1\n" . $note, $code, 1);
    }

    /** An Entity port: an Eloquent model with the CakeEntity trait. */
    private function entityBody(string $code): string
    {
        $code = (string) preg_replace('/\bclass (\w+) extends Entity\b\s*\{/', "class $1 extends \\Illuminate\\Database\\Eloquent\\Model\n{\n    use \\" . self::NS . "\\CakeEntity;\n", $code, 1);

        return $code;
    }

    /**
     * @return array{table: string|null}
     */
    private function tableFacts(string $code): array
    {
        return ['table' => preg_match('/->setTable\(\s*[\'"]([\w.]+)[\'"]\s*\)/', $code, $m) === 1 ? $m[1] : null];
    }

    /**
     * The Table's methods that moved code reaches: initialize always,
     * validationDefault, buildRules and the save hooks on save, a finder
     * on find('name'), and the methods those call on $this.
     *
     * @param list<string> $reached
     */
    private function checkTable(string $alias, string $code, string $file, array $reached): void
    {
        $roots = ['initialize'];
        foreach ($reached as $r) {
            [$a, $what] = array_pad(explode('::', $r, 2), 2, '');
            if ($a !== $alias) {
                continue;
            }
            if (in_array($what, ['save', 'patchEntity', 'newEntity'], true)) {
                array_push($roots, 'validationDefault', 'buildRules', 'beforeSave', 'afterSave', 'beforeMarshal', 'beforeRules', 'afterRules', 'afterSaveCommit');
            }
            if ($what === 'delete') {
                array_push($roots, 'beforeDelete', 'afterDelete', 'afterDeleteCommit');
            }
            if (str_starts_with($what, 'find:')) {
                $roots[] = 'find' . ucfirst(substr($what, 5));
            }
            if (in_array($what, ['find', 'get'], true)) {
                array_push($roots, 'beforeFind');
            }
        }
        $this->checkMethods($code, $file, $roots);
    }

    /**
     * Every `->name(` and `Class::name(` in the reached methods must be in
     * the covered API; null roots checks every method.
     *
     * @param list<string>|null $roots
     */
    private function checkMethods(string $code, string $file, ?array $roots): void
    {
        $c = new CakeCode($code);
        $class = $c->firstClass();
        if ($class === null) {
            return;
        }
        $methods = $class['methods'];
        $imports = [];
        if (preg_match_all('/^use ([\w\\\\]+)(?: as (\w+))?;/m', $code, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $m) {
                $imports[strtolower($m[2] ?? substr((string) strrchr('\\' . $m[1], '\\'), 1))] = $m[1];
            }
        }
        $todo = $roots === null ? array_keys($methods) : array_map('strtolower', $roots);
        $seen = [];
        while ($todo !== []) {
            $name = strtolower((string) array_shift($todo));
            if (isset($seen[$name]) || !isset($methods[$name])) {
                continue;
            }
            $seen[$name] = true;
            $method = $methods[$name];
            for ($i = $method['open']; $i < $method['close']; $i++) {
                $token = $c->t[$i];
                if ($token[0] !== T_STRING) {
                    continue;
                }
                $prev = $c->prev($i);
                $next = $c->next($i);
                if ($prev === null || $next === null || $c->t[$next][1] !== '(') {
                    if ($prev !== null && $c->t[$prev][0] === T_NEW) {
                        $this->checkClass($imports, $token[1], $file, $c->line($i));
                    }
                    continue;
                }
                $lower = strtolower($token[1]);
                if (in_array($c->t[$prev][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    $object = $c->prev($prev);
                    if ($object !== null && $c->t[$object][1] === '$this' && isset($methods[$lower])) {
                        $todo[] = $lower;
                        continue;
                    }
                    if (!in_array($lower, self::COVERED, true) && preg_match('/^findby\w+$/', $lower) !== 1) {
                        $this->refusals[] = ['file' => $file, 'line' => $c->line($i), 'reason' => sprintf('`->%s()` in %s() is outside the CakePHP API the Laravel half covers', $token[1], $method['name'])];
                    }
                    continue;
                }
                if ($c->t[$prev][0] === T_DOUBLE_COLON) {
                    $classToken = $c->prev($prev);
                    $className = $classToken === null ? '' : $c->t[$classToken][1];
                    if (in_array(strtolower($className), ['parent', 'self', 'static'], true)) {
                        if (strtolower($className) !== 'parent' && isset($methods[$lower])) {
                            $todo[] = $lower;
                        } elseif (strtolower($className) === 'parent' && $lower !== $name) {
                            $this->refusals[] = ['file' => $file, 'line' => $c->line($i), 'reason' => sprintf('`parent::%s()` in %s() reaches CakePHP internals the Laravel half does not have', $token[1], $method['name'])];
                        }
                        continue;
                    }
                    $this->checkClass($imports, $className, $file, $c->line($i));
                    continue;
                }
                if ($c->t[$prev][0] !== T_FUNCTION && !in_array($lower, ['isset', 'empty', 'unset', 'array', 'list', 'compact', 'function', 'fn'], true)) {
                    if (in_array($lower, ['__', '__d', 'h'], true)) {
                        $this->refusals[] = ['file' => $file, 'line' => $c->line($i), 'reason' => sprintf('`%s()` in %s() is CakePHP\'s global function, which the Laravel half does not load', $token[1], $method['name'])];
                    } elseif (!function_exists($lower) || !(new \ReflectionFunction($lower))->isInternal()) {
                        $this->refusals[] = ['file' => $file, 'line' => $c->line($i), 'reason' => sprintf('`%s()` in %s() is not a PHP function the Laravel half has', $token[1], $method['name'])];
                    }
                }
            }
        }
    }

    /**
     * @param array<string, string> $imports
     */
    private function checkClass(array $imports, string $name, string $file, int $line): void
    {
        $fq = $imports[strtolower(ltrim($name, '\\'))] ?? ltrim($name, '\\');
        if (in_array($fq, self::CLASSES, true) || array_key_exists($fq, self::IMPORTS)) {
            return;
        }
        $this->refusals[] = ['file' => $file, 'line' => $line, 'reason' => sprintf('`%s` is a class outside the CakePHP runtime the Laravel half covers', $fq)];
    }

    private static function export(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (!is_array($value)) {
            return var_export($value, true);
        }
        $lines = [];
        foreach ($value as $k => $v) {
            $lines[] = '        ' . var_export($k, true) . ' => ' . (is_array($v) ? self::export($v) : var_export($v, true)) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n    ]";
    }
}
