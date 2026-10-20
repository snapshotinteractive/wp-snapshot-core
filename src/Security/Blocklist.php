<?php

namespace Snapshot\Security;

class Blocklist
{
    private array $memo = [];

    public function table(): string
    {
        return Schema::table('blocks');
    }

    public function lookup(string $ip): ?object
    {
        return $this->rows($ip)[0] ?? null;
    }

    public function decide(string $ip, string $mode, bool $login): array
    {
        $rows = $this->rows($ip);
        $decision = ['allow' => false, 'deny' => null, 'suspect' => false];

        foreach ($rows as $row) {
            if ($row->action === 'allow') {
                return ['allow' => true, 'deny' => null, 'suspect' => false];
            }
        }

        foreach ($rows as $row) {
            $feed = str_starts_with($row->source, 'feed:');

            if ($row->action === 'block' && ($row->scope === 'site' || $login) && (! $feed || $mode === 'enforce')) {
                $decision['deny'] ??= $row;
            }

            if ($row->action === 'suspect' || ($feed && $row->action === 'block' && $mode === 'learning')) {
                $decision['suspect'] = $mode !== 'off';
            }
        }

        return $decision;
    }

    private function rows(string $ip): array
    {
        if (array_key_exists($ip, $this->memo)) {
            return $this->memo[$ip];
        }

        global $wpdb;

        $hex = Ip::hex($ip);

        if ($hex === null || ! Schema::ready()) {
            return $this->memo[$ip] = [];
        }

        $now = gmdate('Y-m-d H:i:s');
        $table = $this->table();
        $active = '(expires_at IS NULL OR expires_at > %s)';

        $rows = array_merge(
            (array) $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE is_range = 0 AND ip_start = %s AND {$active}",
                $hex,
                $now
            )),
            (array) $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE is_range = 1 AND ip_start <= %s AND ip_end >= %s AND {$active}",
                $hex,
                $hex,
                $now
            ))
        );

        usort($rows, fn ($a, $b) => $this->rank($a) <=> $this->rank($b));

        return $this->memo[$ip] = $rows;
    }

    public function add(string $target, string $action = 'block', string $scope = 'site', string $source = 'manual', string $reason = '', ?int $seconds = null): ?int
    {
        global $wpdb;

        $range = Ip::range($target);

        if ($range === null) {
            return null;
        }

        $expires = $seconds ? gmdate('Y-m-d H:i:s', time() + $seconds) : null;
        $table = $this->table();

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE ip_start = %s AND ip_end = %s AND source = %s AND scope = %s AND action = %s LIMIT 1",
            $range['start'],
            $range['end'],
            $source,
            $scope,
            $action
        ));

        $row = [
            'ip_start' => $range['start'],
            'ip_end' => $range['end'],
            'is_range' => $range['start'] === $range['end'] ? 0 : 1,
            'label' => $range['label'],
            'scope' => $scope,
            'action' => $action,
            'source' => $source,
            'reason' => mb_substr($reason, 0, 255),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => $expires,
        ];

        $this->memo = [];

        if ($existing) {
            $wpdb->update($table, $row, ['id' => (int) $existing]);

            return (int) $existing;
        }

        $wpdb->insert($table, $row);

        return (int) $wpdb->insert_id ?: null;
    }

    public function replaceSource(string $source, array $targets, string $action, string $scope, ?int $seconds): int
    {
        global $wpdb;

        $table = $this->table();
        $now = gmdate('Y-m-d H:i:s');
        $expires = $seconds ? gmdate('Y-m-d H:i:s', time() + $seconds) : null;
        $rows = [];

        foreach ($targets as $target) {
            $range = Ip::range((string) $target);

            if ($range !== null) {
                $rows[$range['start'].$range['end']] = $range;
            }
        }

        $wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE source = %s", $source));

        foreach (array_chunk(array_values($rows), 400) as $chunk) {
            $values = [];

            foreach ($chunk as $range) {
                $values[] = $wpdb->prepare(
                    '(%s, %s, %d, %s, %s, %s, %s, %s, %s, '.($expires ? '%s' : 'NULL').')',
                    ...array_filter([
                        $range['start'],
                        $range['end'],
                        $range['start'] === $range['end'] ? 0 : 1,
                        $range['label'],
                        $scope,
                        $action,
                        $source,
                        '',
                        $now,
                        $expires,
                    ], fn ($value) => $value !== null)
                );
            }

            if ($wpdb->query("INSERT INTO {$table} (ip_start, ip_end, is_range, label, scope, action, source, reason, created_at, expires_at) VALUES ".implode(',', $values)) === false) {
                $wpdb->query('ROLLBACK');
                $this->memo = [];

                return 0;
            }
        }

        $wpdb->query('COMMIT');
        $this->memo = [];

        return count($rows);
    }

    public function remove(int $id): bool
    {
        global $wpdb;

        $this->memo = [];

        return (bool) $wpdb->delete($this->table(), ['id' => $id]);
    }

    public function find(int $id): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id)) ?: null;
    }

    public function release(string $ip): int
    {
        global $wpdb;

        $range = Ip::range($ip);

        if ($range === null) {
            return 0;
        }

        $this->memo = [];

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE (ip_start = %s AND ip_end = %s AND source IN ('manual', 'lockout', 'firewall')) OR (ip_start <= %s AND ip_end >= %s AND source IN ('lockout', 'firewall'))",
            $range['start'],
            $range['end'],
            $range['start'],
            $range['end']
        ));
    }

    public function active(array $sources, int $limit = 200, int $offset = 0): array
    {
        global $wpdb;

        if ($sources === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sources), '%s'));

        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE source IN ({$placeholders}) AND (expires_at IS NULL OR expires_at > %s) ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...[...$sources, gmdate('Y-m-d H:i:s'), $limit, $offset]
        ));
    }

    public function removeSources(string $prefix): int
    {
        global $wpdb;

        $this->memo = [];

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE source LIKE %s",
            $wpdb->esc_like($prefix).'%'
        ));
    }

    public function counts(): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT source, action, COUNT(*) AS total FROM {$this->table()} WHERE expires_at IS NULL OR expires_at > %s GROUP BY source, action",
            gmdate('Y-m-d H:i:s')
        ));

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->source][$row->action] = (int) $row->total;
        }

        return $counts;
    }

    public function prune(): int
    {
        global $wpdb;

        $this->memo = [];

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE expires_at IS NOT NULL AND expires_at <= %s",
            gmdate('Y-m-d H:i:s')
        ));
    }

    private function rank(object $row): int
    {
        return match ($row->action) {
            'allow' => 0,
            'block' => $row->scope === 'site' ? 1 : 2,
            default => 3,
        };
    }
}
