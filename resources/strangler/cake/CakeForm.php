<?php

namespace App\Http\Controllers\Legacy\Cake;

use Cake\Utility\Inflector;
use Cake\Utility\Text;
use Illuminate\Http\Request;

use function Cake\Core\h;

/**
 * Written by Booster's strangler (cakephp-to-laravel): CakePHP 5's
 * FormHelper for moved templates, printing the same markup from the same
 * string templates (copied from the legacy app's FormHelper when the first
 * form moved) and the same widget rules: ids, names, labels, the div.input
 * wrapper, required and its validity message, maxlength, error classes and
 * messages. The CSRF field is Laravel's _token, so the form posts to a
 * moved action behind Laravel's own CSRF check. A form or postLink for an
 * action that is still legacy carries the legacy app's own CSRF token
 * instead (the bridge), and the legacy app checks it as before.
 *
 * Covered: create, end, control, label, error, isFieldError, text,
 * password, email, tel, number, url, search, textarea, hidden, checkbox,
 * select, button, submit and postLink. Anything else throws, so a page
 * never prints half a form. Removed by the finish step once the views are
 * idiomatic Blade.
 */
final class CakeForm
{
    /** @var array<string, string> FormHelper's string templates (from the legacy app's FormHelper) */
    public const TEMPLATES = /*BOOSTER:TEMPLATES*/ [];

    /** @var array<string, string> FormHelper's typeMap (schema type to input type) */
    public const TYPE_MAP = /*BOOSTER:TYPEMAP*/ [];

    /** The legacy app's App.encoding (create()'s accept-charset). */
    public const ENCODING = /*BOOSTER:ENCODING*/ 'utf-8';

    /** @var array<string, true> */
    private const COMPACT = ['allowfullscreen' => true, 'async' => true, 'autofocus' => true, 'autoload' => true, 'autoplay' => true, 'checked' => true, 'compact' => true, 'controls' => true, 'declare' => true, 'default' => true, 'defaultchecked' => true, 'defaultmuted' => true, 'defaultselected' => true, 'defer' => true, 'disabled' => true, 'enabled' => true, 'formnovalidate' => true, 'hidden' => true, 'indeterminate' => true, 'inert' => true, 'ismap' => true, 'itemscope' => true, 'loop' => true, 'multiple' => true, 'muted' => true, 'nohref' => true, 'noresize' => true, 'noshade' => true, 'novalidate' => true, 'nowrap' => true, 'open' => true, 'pauseonexit' => true, 'readonly' => true, 'required' => true, 'reversed' => true, 'scoped' => true, 'seamless' => true, 'selected' => true, 'sortable' => true, 'truespeed' => true, 'typemustmatch' => true, 'visible' => true];

    private ?CakeFormContext $context = null;

    private ?string $requestType = null;

    /** Whether the open form posts to a legacy action (it then carries the legacy token). */
    private bool $legacyTarget = false;

    public function __construct(private readonly Request $request) {}

    /**
     * @param array<string, mixed> $options
     */
    public function create(mixed $context = null, array $options = []): string
    {
        $this->context = new CakeFormContext($context);
        if (isset($options['context'])) {
            throw new \LogicException('Booster: FormHelper::create() with a context option is not covered; port this form by hand.');
        }
        foreach (['templates', 'idPrefix', 'valueSources'] as $key) {
            if (isset($options[$key])) {
                throw new \LogicException('Booster: FormHelper::create() option ' . $key . ' is not covered; port this form by hand.');
            }
        }
        unset($options['templates'], $options['idPrefix'], $options['valueSources']);
        if (isset($options['method'])) {
            $options['type'] = $options['method'];
        }
        $isCreate = $this->context->isCreate();
        $options += ['type' => $isCreate ? 'post' : 'put', 'url' => null, 'encoding' => self::ENCODING];
        $append = '';
        if ($options['url'] === false) {
            $action = null;
            $path = $this->request->getRequestUri();
        } else {
            $path = $options['url'] === null ? $this->request->getRequestUri() : (string) $options['url'];
            $action = self::url($this->request, $path);
        }
        unset($options['url']);
        $this->legacyTarget = $this->isLegacy($path);

        $html = [];
        switch (strtolower((string) $options['type'])) {
            case 'get':
                $html['method'] = 'get';
                break;
            case 'file':
                $html['enctype'] = 'multipart/form-data';
                $options['type'] = $isCreate ? 'post' : 'put';
                // no break
            case 'put':
            case 'delete':
            case 'patch':
                $append .= $this->hidden('_method', ['name' => '_method', 'value' => strtoupper((string) $options['type'])]);
                // no break
            default:
                $html['method'] = 'post';
        }
        if (isset($options['enctype'])) {
            $html['enctype'] = strtolower((string) $options['enctype']);
        }
        $this->requestType = strtolower((string) $options['type']);
        if (!empty($options['encoding'])) {
            $html['accept-charset'] = $options['encoding'];
        }
        unset($options['type'], $options['encoding']);
        $html += $options;
        if ($this->requestType !== 'get') {
            $append .= $this->csrfField($this->legacyTarget);
        }
        if ($append !== '') {
            $append = $this->hiddenBlock($append);
        }

        return $this->format('formStart', ['attrs' => self::attributes($html) . self::attributes(['action' => $action, 'escape' => false]), 'templateVars' => $options['templateVars'] ?? []]) . $append;
    }

    /**
     * @param array<string, mixed> $secureAttributes
     */
    public function end(array $secureAttributes = []): string
    {
        $this->requestType = null;
        $this->context = null;
        $this->legacyTarget = false;

        return $this->format('formEnd', []);
    }

    public function isFieldError(string $field): bool
    {
        return $this->context()->hasError($field);
    }

    /**
     * @param array<int|string, string>|string|null $text
     * @param array<string, mixed> $options
     */
    public function error(string $field, array|string|null $text = null, array $options = []): string
    {
        if (str_ends_with($field, '._ids')) {
            $field = substr($field, 0, -5);
        }
        $options += ['escape' => true];
        $context = $this->context();
        if (!$context->hasError($field)) {
            return '';
        }
        $error = $context->error($field);
        if (is_array($text)) {
            $tmp = [];
            foreach ($error as $k => $e) {
                $tmp[] = $text[$k] ?? $text[$e] ?? $e;
            }
            $text = $tmp;
        }
        if ($text !== null) {
            $error = $text;
        }
        if ($options['escape']) {
            $error = h($error);
        }
        if (is_array($error)) {
            if (count($error) > 1) {
                $items = [];
                foreach ($error as $err) {
                    $items[] = $this->format('errorItem', ['text' => $err]);
                }
                $error = $this->format('errorList', ['content' => implode('', $items)]);
            } else {
                $error = (string) array_pop($error);
            }
        }

        return $this->format('error', ['content' => $error, 'id' => self::domId($field) . '-error']);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function label(string $fieldName, ?string $text = null, array $options = []): string
    {
        if ($text === null) {
            $text = $fieldName;
            if (str_ends_with($text, '._ids')) {
                $text = substr($text, 0, -5);
            }
            if (str_contains($text, '.')) {
                $parts = explode('.', $text);
                $text = (string) array_pop($parts);
            }
            if (str_ends_with($text, '_id')) {
                $text = substr($text, 0, -3);
            }
            $text = \Cake\I18n\__(Inflector::humanize(Inflector::underscore($text)));
        }
        if (isset($options['for'])) {
            $for = $options['for'];
            unset($options['for']);
        } else {
            $for = self::domId($fieldName);
        }
        $attrs = $options + ['for' => $for, 'text' => $text];
        if (isset($options['input'])) {
            if (is_array($options['input'])) {
                $attrs = $options['input'] + $attrs;
            }

            return $this->labelWidget('nestingLabel', $attrs);
        }

        return $this->labelWidget('label', $attrs);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function control(string $fieldName, array $options = []): string
    {
        $options += ['type' => null, 'label' => null, 'error' => null, 'required' => null, 'options' => null, 'templates' => [], 'templateVars' => [], 'labelOptions' => true];
        if ($options['templates'] !== []) {
            throw new \LogicException('Booster: FormHelper::control() with templates is not covered; port this form by hand.');
        }
        unset($options['templates']);
        $options = $this->parseOptions($fieldName, $options);
        $options += ['id' => self::domId($fieldName)];

        $aria = !($options['type'] === 'hidden' || ($options['type'] === 'select' && ($options['multiple'] ?? null) === 'checkbox'));
        if ($aria) {
            $isError = $this->isFieldError($fieldName);
            $options += ['aria-invalid' => $isError ? 'true' : null];
            if (str_contains(self::TEMPLATES['error'], '{{id}}') && str_contains(self::TEMPLATES['inputContainerError'], '{{error}}')) {
                $options += ['aria-describedby' => $isError ? self::domId($fieldName) . '-error' : null];
            }
            if (isset($options['placeholder']) && $options['label'] === false) {
                $options += ['aria-label' => $options['placeholder']];
            }
        }
        $error = null;
        $errorSuffix = '';
        if ($options['type'] !== 'hidden' && $options['error'] !== false) {
            $error = is_array($options['error']) ? $this->error($fieldName, $options['error'], $options['error']) : $this->error($fieldName, $options['error']);
            $errorSuffix = $error !== '' ? 'Error' : '';
            unset($options['error']);
        }
        $label = $options['label'];
        unset($options['label']);
        $labelOptions = $options['labelOptions'];
        unset($options['labelOptions']);
        $nested = $options['type'] === 'checkbox';
        $nested = $options['nestedInput'] ?? $nested;
        unset($options['nestedInput']);
        if ($nested === true && $options['type'] === 'checkbox' && !array_key_exists('hiddenField', $options) && $label !== false) {
            $options['hiddenField'] = '_split';
        }
        $input = $this->getInput($fieldName, $options + ['labelOptions' => $labelOptions]);
        if ($options['type'] === 'hidden' || $options['type'] === 'submit') {
            return is_array($input) ? implode('', $input) : $input;
        }
        $labelHtml = $this->getLabel($fieldName, ['input' => $input, 'label' => $label, 'error' => $error, 'nestedInput' => $nested] + $options);
        $group = $options['type'] . 'FormGroup';
        if (!isset(self::TEMPLATES[$group]) || self::TEMPLATES[$group] === '') {
            $group = 'formGroup';
        }
        $result = $this->format($group, ['input' => $nested ? [] : $input, 'label' => $labelHtml, 'error' => $error, 'templateVars' => $options['templateVars'] ?? []]);
        $container = $options['type'] . 'Container' . $errorSuffix;
        if (!isset(self::TEMPLATES[$container]) || self::TEMPLATES[$container] === '') {
            $container = 'inputContainer' . $errorSuffix;
        }

        return $this->format($container, [
            'content' => $result,
            'error' => $error,
            'label' => $labelHtml === false ? '' : $labelHtml,
            'required' => $options['required'] ? ' ' . self::TEMPLATES['requiredClass'] : '',
            'type' => $options['type'],
            'containerClass' => self::TEMPLATES['containerClass'],
            'templateVars' => $options['templateVars'] ?? [],
        ]);
    }

    /**
     * text(), password(), email() and the other input types.
     *
     * @param list<mixed> $params
     */
    public function __call(string $method, array $params): string
    {
        if (!in_array($method, ['text', 'password', 'email', 'tel', 'number', 'url', 'search', 'color', 'range'], true)) {
            throw new \LogicException('Booster: FormHelper::' . $method . '() is not covered; port this form by hand.');
        }
        $options = $params[1] ?? [];
        $options['type'] ??= $method;
        $options = $this->initInputField((string) $params[0], $options);

        return $this->basic($options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function textarea(string $fieldName, array $options = []): string
    {
        $options = $this->initInputField($fieldName, $options);
        unset($options['type']);
        $data = $options + ['val' => '', 'name' => '', 'escape' => true, 'rows' => 5, 'templateVars' => []];
        $data = $this->setRequired($data);
        if (!array_key_exists('maxlength', $data) && isset($data['fieldName'])) {
            $data = $this->setMaxLength($data, (string) $data['fieldName']);
        }

        return $this->format('textarea', [
            'name' => $data['name'],
            'value' => $data['escape'] ? h($data['val']) : $data['val'],
            'templateVars' => $data['templateVars'],
            'attrs' => self::attributes($data, ['name', 'val']),
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function hidden(string $fieldName, array $options = []): string
    {
        $options += ['required' => false];
        unset($options['secure']);
        $options = $this->initInputField($fieldName, $options);
        $options['type'] = 'hidden';

        return $this->basic($options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{hidden: string, input: string}|string
     */
    public function checkbox(string $fieldName, array $options = []): array|string
    {
        $options += ['hiddenField' => true, 'value' => 1];
        $value = $options['value'];
        unset($options['value']);
        $options = $this->initInputField($fieldName, $options);
        $options['value'] = $value;
        $output = '';
        if ($options['hiddenField'] !== false && is_scalar($options['hiddenField'])) {
            $hidden = [
                'name' => $options['name'],
                'value' => $options['hiddenField'] !== true && $options['hiddenField'] !== '_split' ? (string) $options['hiddenField'] : '0',
                'form' => $options['form'] ?? null,
            ];
            if (!empty($options['disabled'])) {
                $hidden['disabled'] = 'disabled';
            }
            $output = $this->hidden($fieldName, $hidden);
        }
        $split = $options['hiddenField'] === '_split';
        unset($options['hiddenField'], $options['type']);
        $data = $options + ['name' => '', 'value' => 1, 'val' => null, 'disabled' => false, 'templateVars' => []];
        $data = $this->setRequired($data);
        if (array_key_exists('checked', $data) ? (bool) $data['checked'] : (string) $data['val'] === (string) $data['value']) {
            $data['checked'] = true;
        }
        unset($data['val']);
        $input = $this->format('checkbox', ['name' => $data['name'], 'value' => $data['value'], 'templateVars' => $data['templateVars'], 'attrs' => self::attributes($data, ['name', 'value'])]);
        if ($split) {
            return ['hidden' => $output, 'input' => $input];
        }

        return $output . $input;
    }

    /**
     * @param iterable<mixed> $options
     * @param array<string, mixed> $attributes
     */
    public function select(string $fieldName, iterable $options = [], array $attributes = []): string
    {
        $attributes += ['disabled' => null, 'escape' => true, 'hiddenField' => true, 'multiple' => null, 'secure' => true, 'empty' => null];
        if ($attributes['multiple'] === 'checkbox') {
            throw new \LogicException('Booster: FormHelper::select() with multiple checkboxes is not covered; port this form by hand.');
        }
        if ($attributes['empty'] === null) {
            $required = $this->context()->isRequired($fieldName);
            $attributes['empty'] = $required === null ? false : !$required;
        }
        unset($attributes['label'], $attributes['secure']);
        $attributes = $this->initInputField($fieldName, $attributes);
        $attributes['options'] = $options;
        $hidden = '';
        if ($attributes['multiple'] && $attributes['hiddenField']) {
            $hidden = $this->hidden($fieldName, ['name' => $attributes['name'], 'value' => '', 'form' => $attributes['form'] ?? null]);
        }
        unset($attributes['hiddenField'], $attributes['type']);
        $data = $attributes + ['name' => '', 'empty' => false, 'escape' => true, 'options' => [], 'disabled' => null, 'val' => null, 'templateVars' => []];
        $data = $this->setRequired($data);
        $content = $this->selectOptions($data);
        $name = $data['name'];
        unset($data['name'], $data['options'], $data['empty'], $data['val'], $data['escape']);
        if (isset($data['disabled']) && is_array($data['disabled'])) {
            unset($data['disabled']);
        }
        $template = 'select';
        if (!empty($data['multiple'])) {
            $template = 'selectMultiple';
            unset($data['multiple']);
        }

        return $hidden . $this->format($template, ['name' => $name, 'templateVars' => $data['templateVars'], 'attrs' => self::attributes($data), 'content' => implode('', $content)]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function button(string $title, array $options = []): string
    {
        $options += ['type' => 'submit', 'escapeTitle' => true, 'escape' => true, 'confirm' => null];
        unset($options['secure']);
        $options['text'] = $title;
        $confirm = $options['confirm'];
        unset($options['confirm']);
        if ($confirm) {
            $options['data-confirm-message'] = $confirm;
            $options['onclick'] = $this->format('confirmJs', ['confirmMessage' => h($confirm), 'confirm' => self::confirm('return true;', 'return false;')]);
        }
        $data = $options + ['text' => '', 'type' => 'submit', 'escapeTitle' => true, 'escape' => true, 'templateVars' => []];

        return $this->format('button', ['text' => $data['escapeTitle'] ? h($data['text']) : $data['text'], 'templateVars' => $data['templateVars'], 'attrs' => self::attributes($data, ['text', 'escapeTitle'])]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function submit(?string $caption = null, array $options = []): string
    {
        $caption ??= \Cake\I18n\__d('cake', 'Submit');
        $options += ['type' => 'submit', 'templateVars' => []];
        unset($options['secure']);
        if (str_contains($caption, '://') || preg_match('/\.(jpg|jpe|jpeg|gif|png|ico)$/', $caption) === 1) {
            throw new \LogicException('Booster: FormHelper::submit() with an image is not covered; port this form by hand.');
        }
        $type = $options['type'];
        unset($options['type']);
        $options['value'] = $caption;
        $input = $this->format('inputSubmit', ['type' => $type, 'attrs' => self::attributes($options), 'templateVars' => $options['templateVars']]);

        return $this->format('submitContainer', ['content' => $input, 'templateVars' => $options['templateVars']]);
    }

    /**
     * @param string $url the path CakePHP's router built (the move resolved the URL array)
     * @param array<string, mixed> $options
     */
    public function postLink(string $title, string $url, array $options = []): string
    {
        $options += ['block' => null, 'confirm' => null];
        if ($options['block'] || isset($options['data'])) {
            throw new \LogicException('Booster: FormHelper::postLink() with block or data is not covered; port this link by hand.');
        }
        $method = 'POST';
        if (!empty($options['method'])) {
            $method = strtoupper((string) $options['method']);
            unset($options['method']);
        }
        $confirm = $options['confirm'];
        unset($options['confirm'], $options['block']);
        $name = str_replace('.', '', uniqid('post_', true));
        $form = ['name' => $name, 'method' => 'post'];
        $hiddenClass = self::TEMPLATES['hiddenClass'] ?? '';
        if ($hiddenClass === '') {
            $form['style'] = 'display:none;';
        } else {
            $form['class'] = $hiddenClass;
        }
        if (isset($options['target'])) {
            $form['target'] = $options['target'];
            unset($options['target']);
        }
        $out = $this->format('formStart', ['attrs' => self::attributes($form) . self::attributes(['action' => self::url($this->request, $url), 'escape' => false])]);
        $out .= $this->hidden('_method', ['value' => $method]);
        $out .= $this->csrfField($this->isLegacy($url));
        $out .= $this->format('formEnd', []);
        $onClick = 'document.' . $name . '.requestSubmit();';
        if ($confirm) {
            $onClick = self::confirm($onClick, '') . 'event.returnValue = false; return false;';
            $onClick = $this->format('confirmJs', ['confirmMessage' => h($confirm), 'formName' => $name, 'confirm' => $onClick]);
            $options['data-confirm-message'] = $confirm;
        } else {
            $onClick .= ' event.returnValue = false; return false;';
        }
        $options['onclick'] = $onClick;

        return $out . CakeHtml::link($title, '#', $options);
    }

    // ---------------------------------------------------------------- internals

    private function context(): CakeFormContext
    {
        return $this->context ??= new CakeFormContext(null);
    }

    /**
     * The CSRF field: Laravel's _token for a moved action, the legacy app's
     * own token (its cookie, minted when the browser has none yet) for an
     * action that is still legacy.
     */
    private function csrfField(bool $legacy): string
    {
        $csrf = CakeView::LEGACY_CSRF;
        if ($csrf === null) {
            // The legacy app checked no CSRF token, so its forms carried none.
            return '';
        }
        if ($legacy) {
            return $this->hidden('_csrfToken', ['value' => CakeView::legacyCsrfToken($csrf['cookie'])]);
        }

        return $this->hidden('_token', ['value' => csrf_token()]);
    }

    /** Whether a form's target is an action that is still legacy. */
    private function isLegacy(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = $this->request->getBasePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return !CakeView::isMovedPath($path === '' ? '/' : $path, 'POST');
    }

    private function hiddenBlock(string $content): string
    {
        $class = self::TEMPLATES['hiddenClass'] ?? '';

        return $this->format('hiddenBlock', ['content' => $content, 'attrs' => self::attributes($class !== '' ? ['class' => $class] : ['style' => 'display:none;'])]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function parseOptions(string $fieldName, array $options): array
    {
        $magic = false;
        if (empty($options['type'])) {
            $magic = true;
            $options['type'] = $this->inputType($fieldName, $options);
        }
        $options += ['templateVars' => []];
        $options = $this->requiredAndValidity($fieldName, $options);
        if ((in_array($options['type'], ['radio', 'select'], true) || $magic) && in_array($options['type'], ['text', 'number', 'radio', 'select'], true)) {
            $options = $this->optionsOptions($fieldName, $options);
        }
        if ($magic && str_ends_with($fieldName, '._ids')) {
            $options['type'] = 'select';
            if (!isset($options['multiple']) || ($options['multiple'] && $options['multiple'] !== 'checkbox')) {
                $options['multiple'] = true;
            }
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function inputType(string $fieldName, array $options): string
    {
        $context = $this->context();
        if ($context->isPrimaryKey($fieldName)) {
            return 'hidden';
        }
        if (str_ends_with($fieldName, '_id')) {
            return 'select';
        }
        $type = 'text';
        $internal = $context->type($fieldName);
        if ($internal !== null && isset(self::TYPE_MAP[$internal])) {
            $type = self::TYPE_MAP[$internal];
        }
        $last = array_slice(explode('.', $fieldName), -1)[0];

        return match (true) {
            isset($options['checked']) => 'checkbox',
            isset($options['options']) => 'select',
            in_array($last, ['passwd', 'password'], true) => 'password',
            in_array($last, ['tel', 'telephone', 'phone'], true) => 'tel',
            $last === 'email' => 'email',
            isset($options['rows']) || isset($options['cols']) => 'textarea',
            $last === 'year' => 'year',
            default => $type,
        };
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function optionsOptions(string $fieldName, array $options): array
    {
        if (isset($options['options'])) {
            return $options;
        }
        $pluralize = true;
        if (str_ends_with($fieldName, '._ids')) {
            $fieldName = substr($fieldName, 0, -5);
            $pluralize = false;
        } elseif (str_ends_with($fieldName, '_id')) {
            $fieldName = substr($fieldName, 0, -3);
        }
        $fieldName = array_slice(explode('.', $fieldName), -1)[0];
        $var = Inflector::variable($pluralize ? Inflector::pluralize($fieldName) : $fieldName);
        $value = CakeView::viewVar($var);
        if (!is_iterable($value)) {
            return $options;
        }
        if ($options['type'] !== 'radio') {
            $options['type'] = 'select';
        }
        $options['options'] = $value;

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function requiredAndValidity(string $fieldName, array $options): array
    {
        $context = $this->context();
        if (!isset($options['required']) && $options['type'] !== 'hidden') {
            $options['required'] = $context->isRequired($fieldName);
        }
        $message = $context->getRequiredMessage($fieldName);
        $message = $message === null ? null : h($message);
        if ($options['required'] && $message) {
            $options['templateVars']['customValidityMessage'] = $message;
            $condition = $options['type'] === 'checkbox' ? 'this.checked' : 'this.value';
            $options['data-validity-message'] = $message;
            $options['oninvalid'] = "this.setCustomValidity(''); if (!{$condition}) this.setCustomValidity(this.dataset.validityMessage)";
            $options['oninput'] = "this.setCustomValidity('')";
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{hidden: string, input: string}|string
     */
    private function getInput(string $fieldName, array $options): array|string
    {
        $label = $options['labelOptions'];
        unset($options['labelOptions']);
        $type = strtolower((string) $options['type']);
        switch ($type) {
            case 'select':
                $opts = $options['options'] ?? [];
                unset($options['options']);

                return $this->select($fieldName, $opts ?? [], $options + ['label' => $label]);
            case 'textarea':
                return $this->textarea($fieldName, $options);
            case 'hidden':
                return $this->hidden($fieldName, $options);
            case 'checkbox':
                return $this->checkbox($fieldName, $options);
            case 'radio':
            case 'multicheckbox':
            case 'file':
            case 'datetime':
            case 'date':
            case 'time':
            case 'year':
            case 'month':
            case 'datetime-local':
                throw new \LogicException('Booster: FormHelper::control() of type ' . $type . ' is not covered; port this form by hand.');
            default:
                return $this->__call($type, [$fieldName, $options]);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function getLabel(string $fieldName, array $options): string|false
    {
        if ($options['type'] === 'hidden') {
            return false;
        }
        $label = $options['label'] ?? null;
        if ($label === false && $options['type'] === 'checkbox') {
            return is_array($options['input']) ? implode('', $options['input']) : (string) $options['input'];
        }
        if ($label === false) {
            return false;
        }
        $options += ['id' => null, 'input' => null, 'nestedInput' => false, 'templateVars' => []];
        $attrs = ['templateVars' => $options['templateVars']];
        if (is_array($label)) {
            $text = $label['text'] ?? null;
            unset($label['text']);
            $attrs = array_merge($attrs, $label);
        } else {
            $text = $label;
        }
        $attrs['for'] = $options['id'];
        if ($options['nestedInput']) {
            $attrs['input'] = $options['input'];
        }
        if (isset($options['escape'])) {
            $attrs['escape'] = $options['escape'];
        }

        return $this->label($fieldName, $text, $attrs);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function labelWidget(string $template, array $data): string
    {
        if (is_array($data['input'] ?? null)) {
            $data['hidden'] = $data['input']['hidden'];
            $data['input'] = $data['input']['input'];
        }
        $data += ['text' => '', 'input' => '', 'hidden' => '', 'escape' => true, 'templateVars' => []];

        return $this->format($template, [
            'text' => $data['escape'] ? h($data['text']) : $data['text'],
            'input' => $data['input'],
            'hidden' => $data['hidden'],
            'templateVars' => $data['templateVars'],
            'attrs' => self::attributes($data, ['text', 'input', 'hidden']),
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function initInputField(string $field, array $options): array
    {
        $options += ['fieldName' => $field];
        unset($options['secure']);
        if (isset($options['id']) && $options['id'] === true) {
            $options['id'] = self::domId($field);
        }
        if (!isset($options['name'])) {
            $brackets = '';
            if (str_ends_with($field, '[]')) {
                $field = substr($field, 0, -2);
                $brackets = '[]';
            }
            $parts = explode('.', $field);
            $first = array_shift($parts);
            $options['name'] = $first . ($parts !== [] ? '[' . implode('][', $parts) . ']' : '') . $brackets;
        }
        if (isset($options['value']) && !isset($options['val'])) {
            $options['val'] = $options['value'];
            unset($options['value']);
        }
        if (!isset($options['val'])) {
            $options['val'] = $this->sourceValue($field, ['default' => $options['default'] ?? null, 'schemaDefault' => $options['schemaDefault'] ?? true]);
        }
        if (!isset($options['val']) && isset($options['default'])) {
            $options['val'] = $options['default'];
        }
        unset($options['value'], $options['default'], $options['schemaDefault']);
        if ($options['val'] instanceof \BackedEnum) {
            $options['val'] = $options['val']->value;
        }
        if ($this->context()->hasError($field)) {
            $options = self::addClass($options, self::TEMPLATES['errorClass']);
        }

        return $options;
    }

    /**
     * FormHelper's value sources, in its default order: the posted data,
     * then the entity.
     *
     * @param array<string, mixed> $options
     */
    private function sourceValue(string $field, array $options): mixed
    {
        $value = data_get($this->request->request->all(), $field);
        if ($value !== null) {
            return $value;
        }

        return $this->context()->val($field, $options);
    }

    /**
     * BasicWidget.
     *
     * @param array<string, mixed> $data
     */
    private function basic(array $data): string
    {
        $data += ['name' => '', 'val' => null, 'type' => 'text', 'escape' => true, 'templateVars' => []];
        $data = $this->setRequired($data);
        $data['value'] = $data['val'];
        unset($data['val']);
        if ($data['value'] === false) {
            $data['value'] = '0';
        }
        $fieldName = $data['fieldName'] ?? null;
        if ($fieldName) {
            if ($data['type'] === 'number' && !isset($data['step'])) {
                $type = $this->context()->type((string) $fieldName);
                $attributes = $this->context()->attributes((string) $fieldName);
                if ($type === 'decimal' && isset($attributes['precision'])) {
                    $data['step'] = sprintf('%.' . $attributes['precision'] . 'F', 10 ** (-1 * $attributes['precision']));
                } elseif ($type === 'float') {
                    $data['step'] = 'any';
                }
            }
            if (!array_key_exists('maxlength', $data) && in_array($data['type'], ['text', 'email', 'tel', 'url', 'search'], true)) {
                $data = $this->setMaxLength($data, (string) $fieldName);
            }
        }

        return $this->format('input', ['name' => $data['name'], 'type' => $data['type'], 'templateVars' => $data['templateVars'], 'attrs' => self::attributes($data, ['name', 'type'])]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function setRequired(array $data): array
    {
        if (isset($data['fieldName']) && !array_key_exists('required', $data)
            && empty($data['disabled']) && (!isset($data['type']) || $data['type'] !== 'hidden')
            && $this->context()->isRequired((string) $data['fieldName'])) {
            $data['required'] = true;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function setMaxLength(array $data, string $fieldName): array
    {
        $max = $this->context()->getMaxLength($fieldName);
        if ($max !== null) {
            $data['maxlength'] = min($max, 100000);
        }

        return $data;
    }

    /**
     * SelectBoxWidget's options.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function selectOptions(array $data): array
    {
        $options = $data['options'];
        if ($options instanceof \Traversable) {
            $options = iterator_to_array($options);
        }
        if (!empty($data['empty'])) {
            $empty = $data['empty'] === true ? ['' => ''] : (is_array($data['empty']) ? $data['empty'] : ['' => $data['empty']]);
            $options = $empty + (array) $options;
        }
        if (!$options) {
            return [];
        }
        $disabled = isset($data['disabled']) && is_array($data['disabled']) ? $data['disabled'] : null;

        return $this->renderOptions($options, $disabled, $data['val'] ?? null, $data['templateVars'], (bool) $data['escape']);
    }

    /**
     * @param iterable<mixed> $options
     * @param list<mixed>|null $disabled
     * @param array<string, mixed> $templateVars
     *
     * @return list<string>
     */
    private function renderOptions(iterable $options, ?array $disabled, mixed $selected, array $templateVars, bool $escape): array
    {
        $out = [];
        foreach ($options as $key => $val) {
            $iterable = is_iterable($val);
            if ((!is_int($key) && $iterable) || (is_int($key) && $iterable && (isset($val['options']) || !isset($val['value'])))) {
                $opts = $val;
                $label = (string) $key;
                $attrs = [];
                if (isset($val['options'], $val['text'])) {
                    $opts = $val['options'];
                    $label = $val['text'];
                    $attrs = (array) $val;
                }
                $out[] = $this->format('optgroup', [
                    'label' => $escape ? h($label) : $label,
                    'content' => implode('', $this->renderOptions($opts, $disabled, $selected, $templateVars, $escape)),
                    'templateVars' => $templateVars,
                    'attrs' => self::attributes($attrs, ['text', 'options']),
                ]);
                continue;
            }
            $attrs = ['value' => $key, 'text' => $val, 'templateVars' => []];
            if (is_array($val) && isset($val['text'], $val['value'])) {
                $attrs = $val;
                $key = $attrs['value'];
            }
            $attrs['templateVars'] ??= [];
            if (self::isSelected((string) $key, $selected)) {
                $attrs['selected'] = true;
            }
            if ($disabled !== null && in_array((string) $key, $disabled, !is_numeric((string) $key))) {
                $attrs['disabled'] = true;
            }
            if ($templateVars) {
                $attrs['templateVars'] = array_merge($templateVars, $attrs['templateVars']);
            }
            $attrs['escape'] = $escape;
            $out[] = $this->format('option', [
                'value' => $escape ? h($attrs['value']) : $attrs['value'],
                'text' => $escape ? h($attrs['text']) : $attrs['text'],
                'templateVars' => $attrs['templateVars'],
                'attrs' => self::attributes($attrs, ['text', 'value']),
            ]);
        }

        return $out;
    }

    private static function isSelected(string $key, mixed $selected): bool
    {
        if ($selected === null) {
            return false;
        }
        if (!is_array($selected)) {
            $selected = $selected === false ? '0' : $selected;

            return $key === (string) $selected;
        }

        return in_array($key, $selected, !is_numeric($key));
    }

    private static function confirm(string $ok, string $cancel): string
    {
        return "if (confirm(this.dataset.confirmMessage)) { {$ok} } {$cancel}";
    }

    /** IdGeneratorTrait::_domId() without an id prefix. */
    public static function domId(string $value): string
    {
        return mb_strtolower(Text::slug($value, '-'));
    }

    /** The URL CakePHP's UrlHelper prints for a path: the base path, escaped. */
    public static function url(Request $request, string $path): string
    {
        if (preg_match('#^([a-z][a-z0-9+\-.]*:|//)#i', $path) === 1) {
            return h($path);
        }

        return h($request->getBasePath() . $path);
    }

    /**
     * StringTemplate::format().
     *
     * @param array<string, mixed> $data
     */
    public function format(string $name, array $data): string
    {
        $template = self::TEMPLATES[$name] ?? null;
        if ($template === null) {
            throw new \LogicException('Booster: no FormHelper template named ' . $name);
        }
        if (isset($data['templateVars'])) {
            $data += $data['templateVars'];
            unset($data['templateVars']);
        }

        return (string) preg_replace_callback('#\{\{([\w.]+)\}\}#', static function (array $m) use ($data): string {
            $value = $data[$m[1]] ?? null;
            if (is_array($value)) {
                $value = implode('', $value);
            }

            return (string) $value;
        }, $template);
    }

    /**
     * StringTemplate::formatAttributes().
     *
     * @param array<int|string, mixed>|null $options
     * @param list<string> $exclude
     */
    public static function attributes(?array $options, array $exclude = []): string
    {
        $options = (array) $options + ['escape' => true];
        $skip = ['escape' => true, 'idPrefix' => true, 'templateVars' => true, 'fieldName' => true] + array_flip($exclude);
        $escape = $options['escape'];
        $out = [];
        foreach ($options as $key => $value) {
            if (!isset($skip[$key]) && $value !== false && $value !== null) {
                $out[] = self::attribute((string) $key, $value, (bool) $escape);
            }
        }
        $text = trim(implode(' ', $out));

        return $text !== '' ? ' ' . $text : '';
    }

    private static function attribute(string $key, mixed $value, bool $escape): string
    {
        if (is_array($value)) {
            $value = implode(' ', $value);
        }
        if (is_numeric($key)) {
            return "{$value}=\"{$value}\"";
        }
        $minimized = isset(self::COMPACT[$key]);
        if (preg_match('/\A(\w|[.-])+\z/', $key) !== 1) {
            $key = h($key);
        }
        if ($minimized && in_array($value, [1, '1', true, 'true', $key], true)) {
            return "{$key}=\"{$key}\"";
        }
        if ($minimized) {
            return '';
        }

        return $key . '="' . ($escape ? h($value) : $value) . '"';
    }

    /**
     * StringTemplate::addClass() on an options array.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function addClass(array $options, string $class): array
    {
        if ($class === '') {
            return $options;
        }
        $current = $options['class'] ?? [];
        if (!is_array($current)) {
            $current = is_string($current) && $current !== '' ? explode(' ', $current) : [];
        }
        $options['class'] = array_values(array_unique(array_merge($current, explode(' ', $class))));

        return $options;
    }
}
