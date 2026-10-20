<?php

namespace Snapshot\Seo;

class Settings
{
    public const OPTION = 'snapshot_seo_settings';

    private ?array $values = null;

    private ?array $schema = null;

    public function schema(): array
    {
        return $this->schema ??= snap_seo_settings_schema();
    }

    public function get(string $key)
    {
        if ($this->locked($key)) {
            return $this->cast($key, constant($this->constant($key)));
        }

        $values = $this->all();
        $field = $this->schema()[$key] ?? [];

        if (array_key_exists($key, $values)) {
            $value = $this->cast($key, $values[$key]);

            if (($field['type'] ?? '') !== 'template' || trim((string) $value) !== '') {
                return $value;
            }
        }

        return $field['default'] ?? null;
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
        $lines = is_array($raw) ? $raw : preg_split('/[\r\n]+/', (string) $raw);

        return array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));
    }

    public function has(string $key): bool
    {
        return $this->string($key) !== '';
    }

    public function isDefault(string $key): bool
    {
        $values = $this->all();

        return ! array_key_exists($key, $values) || $values[$key] === '' || $values[$key] === ($this->schema()[$key]['default'] ?? null);
    }

    public function locked(string $key): bool
    {
        return defined($this->constant($key));
    }

    public function constant(string $key): string
    {
        return 'SNAPSHOT_SEO_'.strtoupper($key);
    }

    public function all(): array
    {
        if ($this->values === null) {
            $stored = get_option(self::OPTION, []);
            $this->values = is_array($stored) ? $stored : [];
        }

        return $this->values;
    }

    public function set(string $key, $value): void
    {
        $values = $this->all();
        $values[$key] = $value;
        update_option(self::OPTION, $values, true);
        $this->values = $values;
    }

    public function save(array $input, callable $inScope): array
    {
        $current = $this->all();
        $next = $current;
        $changed = [];

        foreach ($this->schema() as $key => $field) {
            if ($this->locked($key) || ! $inScope($key, $field)) {
                continue;
            }

            $type = $field['type'] ?? 'text';

            if ($type === 'key') {
                if (! empty($input["{$key}__generate"])) {
                    $next[$key] = self::newKey();
                }
            } elseif ($type === 'bool') {
                $next[$key] = ! empty($input[$key]);
            } elseif ($type === 'int' || $type === 'image') {
                $value = isset($input[$key]) && $input[$key] !== '' ? (int) $input[$key] : (int) ($field['default'] ?? 0);
                $value = max((int) ($field['min'] ?? ($type === 'image' ? 0 : PHP_INT_MIN)), $value);
                $value = min((int) ($field['max'] ?? PHP_INT_MAX), $value);
                $next[$key] = $value;
            } elseif ($type === 'select') {
                $value = (string) ($input[$key] ?? '');
                $next[$key] = array_key_exists($value, $field['choices'] ?? []) ? $value : $field['default'];
            } elseif ($type === 'lines') {
                $next[$key] = implode("\n", array_filter(array_map(
                    fn ($line) => sanitize_text_field($line),
                    preg_split('/[\r\n]+/', (string) ($input[$key] ?? ''))
                )));
            } elseif ($type === 'textarea') {
                $next[$key] = sanitize_textarea_field((string) ($input[$key] ?? ''));
            } elseif ($type === 'template') {
                $value = sanitize_text_field((string) ($input[$key] ?? ''));
                $next[$key] = $value === (string) ($field['default'] ?? '') ? '' : $value;
            } else {
                $next[$key] = sanitize_text_field((string) ($input[$key] ?? ''));
            }

            if (($current[$key] ?? null) !== ($next[$key] ?? null)) {
                $changed[] = $key;
            }
        }

        update_option(self::OPTION, $next, true);
        $this->values = $next;

        return $changed;
    }

    public static function newKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function cast(string $key, $value)
    {
        return match ($this->schema()[$key]['type'] ?? 'text') {
            'bool' => (bool) $value,
            'int', 'image' => (int) $value,
            default => $value,
        };
    }
}
