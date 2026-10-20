<?php

namespace Snapshot\Security;

class Settings
{
    public const OPTION = 'snapshot_security_settings';

    private ?array $values = null;

    private ?array $schema = null;

    public function schema(): array
    {
        return $this->schema ??= snap_security_settings_schema();
    }

    public function get(string $key)
    {
        if ($this->locked($key)) {
            return $this->cast($key, constant($this->constant($key)));
        }

        $values = $this->all();

        if (array_key_exists($key, $values)) {
            return $this->cast($key, $values[$key]);
        }

        return $this->schema()[$key]['default'] ?? null;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function string(string $key): string
    {
        return trim((string) $this->get($key));
    }

    public function lines(string $key): array
    {
        $raw = $this->get($key);
        $lines = is_array($raw) ? $raw : preg_split('/[\r\n,]+/', (string) $raw);

        return array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));
    }

    public function has(string $key): bool
    {
        return $this->string($key) !== '';
    }

    public function locked(string $key): bool
    {
        return defined($this->constant($key));
    }

    public function constant(string $key): string
    {
        return 'SNAPSHOT_SECURITY_'.strtoupper($key);
    }

    public function all(): array
    {
        if ($this->values === null) {
            $stored = get_option(self::OPTION, []);
            $this->values = is_array($stored) ? $stored : [];
        }

        return $this->values;
    }

    public function save(array $input, ?string $tab = null): array
    {
        $current = $this->all();
        $next = $current;
        $changed = [];
        $issuedToken = null;
        $rejected = [];

        foreach ($this->schema() as $key => $field) {
            if ($this->locked($key) || ($tab !== null && ($field['tab'] ?? '') !== $tab)) {
                continue;
            }

            $type = $field['type'] ?? 'text';

            if ($type === 'secret') {
                if (! empty($input["{$key}__clear"])) {
                    $next[$key] = '';
                } elseif (isset($input[$key]) && trim((string) $input[$key]) !== '') {
                    $next[$key] = trim(sanitize_text_field((string) $input[$key]));
                }
            } elseif ($type === 'token') {
                if (! empty($input["{$key}__clear"])) {
                    $next[$key] = '';
                } elseif (! empty($input["{$key}__generate"])) {
                    $issuedToken = bin2hex(random_bytes(24));
                    $next[$key] = hash('sha256', $issuedToken);
                }
            } elseif ($type === 'bool') {
                $next[$key] = ! empty($input[$key]);
            } elseif ($type === 'int') {
                $value = isset($input[$key]) ? (int) $input[$key] : (int) ($field['default'] ?? 0);
                $value = max((int) ($field['min'] ?? PHP_INT_MIN), $value);
                $value = min((int) ($field['max'] ?? PHP_INT_MAX), $value);
                $next[$key] = $value;
            } elseif ($type === 'select') {
                $value = (string) ($input[$key] ?? '');
                $next[$key] = array_key_exists($value, $field['choices'] ?? []) ? $value : $field['default'];
            } elseif ($type === 'lines') {
                $lines = array_filter(array_map(
                    fn ($line) => sanitize_text_field($line),
                    preg_split('/[\r\n]+/', (string) ($input[$key] ?? ''))
                ));

                if (($field['validate'] ?? '') === 'ip') {
                    $rejected = [...$rejected, ...array_filter($lines, fn ($line) => Ip::range($line) === null)];
                    $lines = array_filter($lines, fn ($line) => Ip::range($line) !== null);
                }

                $next[$key] = implode("\n", $lines);
            } else {
                $next[$key] = sanitize_text_field((string) ($input[$key] ?? ''));
            }

            if (($current[$key] ?? null) !== ($next[$key] ?? null)) {
                $changed[] = $key;
            }
        }

        update_option(self::OPTION, $next, true);
        $this->values = $next;

        return ['changed' => $changed, 'token' => $issuedToken, 'rejected' => array_values($rejected)];
    }

    private function cast(string $key, $value)
    {
        return match ($this->schema()[$key]['type'] ?? 'text') {
            'bool' => (bool) $value,
            'int' => (int) $value,
            default => $value,
        };
    }
}
