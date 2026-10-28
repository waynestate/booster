<?php

declare(strict_types=1);

namespace Waynestate\Booster\Policy;

use DateTimeImmutable;

/**
 * A PolicySource backed by a CREED-format policy.json.
 *
 * BOOSTER holds no policy of its own: dates, EOL grace and supported versions
 * all come from this file. Keys it does not know are ignored, so an older
 * BOOSTER can read a newer policy.
 */
final class JsonFilePolicySource implements PolicySource
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(private readonly string $file, private readonly string $label = '')
    {
        $this->data = self::read($file);
    }

    public function origin(): string
    {
        return $this->label !== '' ? $this->label : $this->file;
    }

    public function stateOf(string $component, string $version, DateTimeImmutable $date): string
    {
        $versions = $this->versionsOf($component);
        if (!array_key_exists($version, $versions)) {
            throw new PolicyException(sprintf('%s %s is not in the policy.', $component, $version));
        }

        $entry = $versions[$version];
        $security = self::parseDate($entry['security_until'] ?? null);
        if ($security === null) {
            throw new PolicyException(sprintf('%s %s has no valid security_until date.', $component, $version));
        }

        $active = self::parseDate($entry['active_until'] ?? null);
        if ($active !== null && $date <= $active) {
            return State::ACTIVE;
        }
        if ($date <= $security) {
            return State::SECURITY;
        }

        $rules = $this->data['rules'] ?? null;
        $grace = is_array($rules) ? ($rules['eol_grace_days'] ?? 0) : 0;
        $graceDays = is_int($grace) ? $grace : 0;

        return $date <= $security->modify(sprintf('+%d days', $graceDays))
            ? State::EOL
            : State::EOL_GRACE_EXPIRED;
    }

    public function supported(string $component, DateTimeImmutable $date): array
    {
        $out = [];
        foreach (array_keys($this->versionsOf($component)) as $version) {
            $version = (string) $version;
            $state = $this->stateOf($component, $version, $date);
            if ($state === State::ACTIVE || $state === State::SECURITY) {
                $out[] = $version;
            }
        }
        usort($out, static fn(string $a, string $b): int => version_compare($a, $b));

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function versionsOf(string $component): array
    {
        $components = $this->data['components'] ?? null;
        $entry = is_array($components) ? ($components[$component] ?? null) : null;
        $versions = is_array($entry) ? ($entry['versions'] ?? null) : null;
        if (!is_array($versions)) {
            throw new PolicyException(sprintf('The policy has no component "%s".', $component));
        }

        /** @var array<string, array<string, mixed>> $versions */
        return $versions;
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new PolicyException(sprintf('Policy file "%s" does not exist or is not readable.', $file));
        }

        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            throw new PolicyException(sprintf('Policy file "%s" is not valid JSON: %s', $file, json_last_error_msg()));
        }

        $components = $data['components'] ?? null;
        $php = is_array($components) && is_array($components['php'] ?? null) ? ($components['php']['versions'] ?? null) : null;
        if (!is_array($components) || !is_array($php) || $php === []) {
            throw new PolicyException(sprintf('Policy file "%s" is missing components.php.versions.', $file));
        }

        foreach ($components as $name => $component) {
            $versions = is_array($component) ? ($component['versions'] ?? null) : null;
            if (!is_array($versions)) {
                throw new PolicyException(sprintf('Policy file "%s": components.%s.versions is not an object.', $file, $name));
            }
            foreach ($versions as $version => $entry) {
                if (!is_array($entry) || self::parseDate($entry['security_until'] ?? null) === null) {
                    throw new PolicyException(sprintf(
                        'Policy file "%s": components.%s.versions["%s"] needs a security_until date (YYYY-MM-DD).',
                        $file,
                        $name,
                        $version,
                    ));
                }
                if (isset($entry['active_until']) && self::parseDate($entry['active_until']) === null) {
                    throw new PolicyException(sprintf(
                        'Policy file "%s": components.%s.versions["%s"].active_until is not a YYYY-MM-DD date.',
                        $file,
                        $name,
                        $version,
                    ));
                }
            }
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false || $date->format('Y-m-d') !== $value ? null : $date;
    }
}
