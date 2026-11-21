<?php

namespace App\Http\Controllers\Legacy\Cake;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Written by Booster's strangler (cakephp-to-laravel): CakePHP's Validator
 * for a copied Table's validationDefault(). It records the same fluent
 * calls and checks each rule with Laravel's validator, then reports
 * CakePHP's error shape (`field => [rule => message]`) and CakePHP's own
 * default messages, so the form prints what it printed before.
 *
 * Covered: requirePresence, allowEmptyString, allowEmptyFor,
 * notEmptyString, maxLength, minLength, lengthBetween, email, inList,
 * numeric, integer, naturalNumber, nonNegativeInteger, boolean, scalar,
 * notBlank, and add() with validateUnique on the table provider. Anything
 * else throws when the rule is added.
 */
final class CakeValidator
{
    /** @var array<string, array{presence: bool|string, presenceMessage: string|null, allowEmpty: bool|string, emptyMessage: string|null, rules: list<array{name: string, rule: string, args: list<mixed>, message: string|null, on: string|null, provider: string|null}>}> */
    private array $fields = [];

    /**
     * @return array{presence: bool|string, presenceMessage: string|null, allowEmpty: bool|string, emptyMessage: string|null, rules: list<array{name: string, rule: string, args: list<mixed>, message: string|null, on: string|null, provider: string|null}>}
     */
    private function &field(string $field): array
    {
        $this->fields[$field] ??= ['presence' => false, 'presenceMessage' => null, 'allowEmpty' => false, 'emptyMessage' => null, 'rules' => []];

        return $this->fields[$field];
    }

    public function hasField(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    public function requirePresence(string|array $field, bool|string $mode = true, ?string $message = null): static
    {
        foreach ((array) $field as $name => $value) {
            [$name, $m] = is_int($name) ? [(string) $value, $mode] : [(string) $name, $value];
            $f = &$this->field($name);
            $f['presence'] = $m;
            $f['presenceMessage'] = $message;
        }

        return $this;
    }

    public function allowEmptyString(string $field, ?string $message = null, bool|string $when = true): static
    {
        $f = &$this->field($field);
        $f['allowEmpty'] = $when;
        $f['emptyMessage'] = $message;

        return $this;
    }

    public function allowEmptyFor(string $field, ?int $flags = null, bool|string $when = true, ?string $message = null): static
    {
        return $this->allowEmptyString($field, $message, $when);
    }

    public function notEmptyString(string $field, ?string $message = null, bool|string $when = false): static
    {
        $f = &$this->field($field);
        // CakePHP inverts `when`: empty is allowed when it matches.
        $f['allowEmpty'] = match ($when) {
            false => false,
            true => true,
            'create' => 'update',
            'update' => 'create',
            default => $when,
        };
        if ($when === false) {
            $f['allowEmpty'] = false;
        } elseif ($when === 'create') {
            $f['allowEmpty'] = 'update';
        } elseif ($when === 'update') {
            $f['allowEmpty'] = 'create';
        }
        $f['emptyMessage'] = $message;

        return $this;
    }

    public function notBlank(string $field, ?string $message = null, ?string $when = null): static
    {
        return $this->rule($field, 'notBlank', [], $message, $when);
    }

    public function maxLength(string $field, int $max, ?string $message = null, ?string $when = null): static
    {
        return $this->rule($field, 'maxLength', [$max], $message, $when);
    }

    public function minLength(string $field, int $min, ?string $message = null, ?string $when = null): static
    {
        return $this->rule($field, 'minLength', [$min], $message, $when);
    }

    /**
     * @param array{int, int} $range
     */
    public function lengthBetween(string $field, array $range, ?string $message = null, ?string $when = null): static
    {
        return $this->rule($field, 'lengthBetween', array_values($range), $message, $when);
    }

    public function email(string $field, bool $checkMX = false, ?string $message = null, ?string $when = null): static
    {
        if ($checkMX) {
            throw new \LogicException('Booster: email() with checkMX is not covered.');
        }

        return $this->rule($field, 'email', [], $message, $when);
    }

    /**
     * @param list<string> $list
     */
    public function inList(string $field, array $list, ?string $message = null, ?string $when = null): static
    {
        return $this->rule($field, 'inList', [$list], $message, $when);
    }

    public function numeric(string $field, ?string $message = null, ?string $when = null): static
    {
        return $this->rule($field, 'numeric', [], $message, $when);
    }

    public function integer(string $field, ?string $message = null, ?string $when = null): static
    {
        return $this->rule($field, 'integer', [], $message, $when);
    }

    public function naturalNumber(string $field, ?string $message = null, ?string $when = null): static
    {
        return $this->rule($field, 'naturalNumber', [], $message, $when);
    }

    public function nonNegativeInteger(string $field, ?string $message = null, ?string $when = null): static
    {
        return $this->rule($field, 'nonNegativeInteger', [], $message, $when);
    }

    public function boolean(string $field, ?string $message = null, ?string $when = null): static
    {
        return $this->rule($field, 'boolean', [], $message, $when);
    }

    public function scalar(string $field, ?string $message = null, ?string $when = null): static
    {
        return $this->rule($field, 'scalar', [], $message, $when);
    }

    /**
     * add($field, 'unique', ['rule' => 'validateUnique', 'provider' => 'table']).
     *
     * @param array<string, mixed>|string $name
     * @param array<string, mixed> $rule
     */
    public function add(string $field, array|string $name, array $rule = []): static
    {
        if (is_array($name)) {
            foreach ($name as $n => $r) {
                $this->add($field, (string) $n, (array) $r);
            }

            return $this;
        }
        $ruleName = $rule['rule'] ?? $name;
        $provider = $rule['provider'] ?? 'default';
        if ($ruleName !== 'validateUnique' || $provider !== 'table' || array_diff(array_keys($rule), ['rule', 'provider', 'message', 'on']) !== []) {
            throw new \LogicException('Booster: the ' . $field . ' rule ' . $name . ' is not covered; port this validation by hand.');
        }
        $f = &$this->field($field);
        $f['rules'][] = ['name' => $name, 'rule' => 'unique', 'args' => [], 'message' => $rule['message'] ?? null, 'on' => $rule['on'] ?? null, 'provider' => 'table'];

        return $this;
    }

    /**
     * @param list<mixed> $args
     */
    private function rule(string $field, string $rule, array $args, ?string $message, ?string $when): static
    {
        $f = &$this->field($field);
        $f['rules'][] = ['name' => $rule, 'rule' => $rule, 'args' => $args, 'message' => $message, 'on' => $when, 'provider' => null];

        return $this;
    }

    /**
     * @param list<mixed> $args
     */
    public function __call(string $method, array $args): static
    {
        throw new \LogicException('Booster: the validation rule ' . $method . '() is not covered in the Laravel half; port this validation by hand.');
    }

    public function isEmptyAllowed(string $field, bool $newRecord): bool
    {
        $allow = $this->fields[$field]['allowEmpty'] ?? true;

        return $allow === true || ($allow === 'create' && $newRecord) || ($allow === 'update' && !$newRecord);
    }

    public function isPresenceRequired(string $field, bool $newRecord): bool
    {
        $p = $this->fields[$field]['presence'] ?? false;

        return $p === true || ($p === 'create' && $newRecord) || ($p === 'update' && !$newRecord);
    }

    public function emptyMessage(string $field): string
    {
        return $this->fields[$field]['emptyMessage'] ?? \Cake\I18n\__d('cake', 'This field cannot be left empty');
    }

    /** FormContext::getMaxLength(): the maxLength rule's limit. */
    public function maxLengthOf(string $field): ?int
    {
        foreach ($this->fields[$field]['rules'] ?? [] as $rule) {
            if ($rule['rule'] === 'maxLength') {
                return (int) $rule['args'][0];
            }
        }

        return null;
    }

    /**
     * CakePHP's Validator::validate(): `field => [rule => message]`.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, array<string, string>>
     */
    public function validate(array $data, bool $newRecord, ?CakeTable $table = null, ?Model $entity = null): array
    {
        $errors = [];
        foreach ($this->fields as $name => $f) {
            $present = array_key_exists($name, $data);
            if (!$present) {
                if ($this->isPresenceRequired($name, $newRecord)) {
                    $errors[$name]['_required'] = $f['presenceMessage'] ?? \Cake\I18n\__d('cake', 'This field is required');
                }
                continue;
            }
            $value = $data[$name];
            $empty = $value === null || $value === '' || $value === [];
            if ($empty) {
                if (!$this->isEmptyAllowed($name, $newRecord)) {
                    $errors[$name]['_empty'] = $this->emptyMessage($name);
                }
                continue;
            }
            foreach ($f['rules'] as $rule) {
                if ($rule['on'] !== null && ($rule['on'] === 'create') !== $newRecord) {
                    continue;
                }
                if (!$this->passes($rule, $name, $value, $table, $entity)) {
                    $errors[$name][$rule['name']] = $rule['message'] ?? $this->defaultMessage($rule);
                }
            }
        }

        return $errors;
    }

    /**
     * @param array{name: string, rule: string, args: list<mixed>, message: string|null, on: string|null, provider: string|null} $rule
     */
    private function passes(array $rule, string $field, mixed $value, ?CakeTable $table, ?Model $entity): bool
    {
        $scalar = is_scalar($value) || $value instanceof \Stringable;
        $laravel = match ($rule['rule']) {
            'scalar' => null,
            'unique' => null,
            'notBlank' => null,
            'maxLength' => ['string', 'max:' . (int) $rule['args'][0]],
            'minLength' => ['string', 'min:' . (int) $rule['args'][0]],
            'lengthBetween' => ['string', 'between:' . (int) $rule['args'][0] . ',' . (int) $rule['args'][1]],
            'email' => ['email'],
            'inList' => [Rule::in(array_map('strval', (array) $rule['args'][0]))],
            'numeric' => ['numeric'],
            'integer' => ['integer'],
            'naturalNumber' => ['integer', 'min:1'],
            'nonNegativeInteger' => ['integer', 'min:0'],
            'boolean' => ['boolean'],
            default => throw new \LogicException('Booster: no rule ' . $rule['rule']),
        };
        switch ($rule['rule']) {
            case 'scalar':
                return is_scalar($value);
            case 'notBlank':
                return $scalar && preg_match('/[^\s]+/m', (string) $value) === 1;
            case 'unique':
                return $table === null || $entity === null || $table->validateUnique($value, $field, $entity);
        }
        if (!$scalar && !in_array($rule['rule'], ['boolean'], true)) {
            return false;
        }

        return Validator::make([$field => is_bool($value) ? $value : (string) $value], [$field => $laravel])->passes();
    }

    /**
     * @param array{name: string, rule: string, args: list<mixed>, message: string|null, on: string|null, provider: string|null} $rule
     */
    private function defaultMessage(array $rule): string
    {
        $text = match ($rule['rule']) {
            'maxLength' => \Cake\I18n\__d('cake', 'The provided value must be at most `{0}` characters long', $rule['args'][0]),
            'minLength' => \Cake\I18n\__d('cake', 'The provided value must be at least `{0}` characters long', $rule['args'][0]),
            'lengthBetween' => \Cake\I18n\__d('cake', 'The length of the provided value must be between `{0}` and `{1}`, inclusively', $rule['args'][0], $rule['args'][1]),
            'email' => \Cake\I18n\__d('cake', 'The provided value must be an e-mail address'),
            'inList' => \Cake\I18n\__d('cake', 'The provided value must be one of: `{0}`', implode(', ', (array) $rule['args'][0])),
            'numeric' => \Cake\I18n\__d('cake', 'The provided value must be numeric'),
            'integer' => \Cake\I18n\__d('cake', 'The provided value must be an integer'),
            'naturalNumber' => \Cake\I18n\__d('cake', 'The provided value must be a natural number'),
            'nonNegativeInteger' => \Cake\I18n\__d('cake', 'The provided value must be a non-negative integer'),
            'boolean' => \Cake\I18n\__d('cake', 'The provided value must be a boolean'),
            'scalar' => \Cake\I18n\__d('cake', 'The provided value must be scalar'),
            'notBlank' => \Cake\I18n\__d('cake', 'This field cannot be left empty'),
            default => \Cake\I18n\__d('cake', 'The provided value is invalid'),
        };

        return $text;
    }

    /** The notEmpty message FormHelper prints as the validity message. */
    public function requiredMessage(string $field): ?string
    {
        if (!isset($this->fields[$field])) {
            return null;
        }

        return $this->fields[$field]['emptyMessage'] ?? \Cake\I18n\__d('cake', 'This field cannot be left empty');
    }
}
