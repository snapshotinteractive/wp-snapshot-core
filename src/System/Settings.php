<?php

namespace Snapshot\System;

class Settings
{
    public const OPTION = 'snapshot_system_settings';

    private ?array $values = null;

    public function schema(): array
    {
        return snap_system_settings_schema();
    }

    public function get(string $key)
    {
        $schema = $this->schema();

        if ($this->locked($key)) {
            return constant($this->constant($key));
        }

        $values = $this->values ??= (array) get_option(self::OPTION, []);

        return array_key_exists($key, $values) ? $values[$key] : ($schema[$key]['default'] ?? null);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function string(string $key): string
    {
        return trim((string) $this->get($key));
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function constant(string $key): string
    {
        return 'SNAPSHOT_SYSTEM_'.strtoupper($key);
    }

    public function locked(string $key): bool
    {
        return defined($this->constant($key));
    }

    public function save(array $input, string $section): array
    {
        $current = (array) get_option(self::OPTION, []);
        $next = $current;
        $changed = [];

        foreach ($this->schema() as $key => $field) {
            if (($field['section'] ?? '') !== $section || $this->locked($key)) {
                continue;
            }

            $raw = $input[$key] ?? null;
            $value = match ($field['type']) {
                'bool' => ! empty($raw),
                'image' => max(0, (int) $raw),
                'color' => (string) (sanitize_hex_color((string) $raw) ?? ''),
                'select' => array_key_exists((string) $raw, $field['choices']) ? (string) $raw : $field['default'],
                'textarea' => sanitize_textarea_field((string) $raw),
                default => sanitize_text_field((string) $raw),
            };

            if (($current[$key] ?? $field['default']) !== $value) {
                $changed[] = $key;
            }

            $next[$key] = $value;
        }

        update_option(self::OPTION, $next, true);
        $this->values = $next;

        return $changed;
    }
}
