<?php

namespace Snapshot\Security;

class Throttle
{
    public function table(): string
    {
        return Schema::table('attempts');
    }

    public function hit(string $key, int $window): int
    {
        global $wpdb;

        if (! Schema::ready()) {
            return 0;
        }

        $bucket = $this->bucket($key);
        $now = time();
        $stale = $now - $window;
        $table = $this->table();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (bucket, hits, window_start, expires_at) VALUES (%s, 1, %d, %d)
            ON DUPLICATE KEY UPDATE
                hits = IF(window_start <= %d, 1, hits + 1),
                window_start = IF(window_start <= %d, %d, window_start),
                expires_at = %d",
            $bucket,
            $now,
            $now + $window,
            $stale,
            $stale,
            $now,
            $now + $window
        ));

        return (int) $wpdb->get_var($wpdb->prepare("SELECT hits FROM {$table} WHERE bucket = %s", $bucket));
    }

    public function count(string $key, int $window): int
    {
        global $wpdb;

        if (! Schema::ready()) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT hits FROM {$this->table()} WHERE bucket = %s AND window_start > %d",
            $this->bucket($key),
            time() - $window
        ));
    }

    public function once(string $key, int $window): bool
    {
        return $this->hit($key, $window) === 1;
    }

    public function clear(string $key): void
    {
        global $wpdb;

        if (! Schema::ready()) {
            return;
        }

        $wpdb->delete($this->table(), ['bucket' => $this->bucket($key)]);
    }

    public function prune(): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$this->table()} WHERE expires_at < %d", time()));
    }

    private function bucket(string $key): string
    {
        return hash('sha256', $key);
    }
}
