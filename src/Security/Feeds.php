<?php

namespace Snapshot\Security;

class Feeds
{
    public const STATUS_OPTION = 'snapshot_security_feeds_status';

    private const KEEP_FOR = 3 * DAY_IN_SECONDS;

    public function __construct(
        private Settings $settings,
        private Blocklist $blocklist,
        private EventLog $log,
    ) {}

    public function enabled(): array
    {
        return array_filter(snap_security_feeds(), function ($feed) {
            if (! $this->settings->bool($feed['setting'])) {
                return false;
            }

            return empty($feed['key_setting']) || $this->settings->has($feed['key_setting']);
        });
    }

    public function status(): array
    {
        $status = get_option(self::STATUS_OPTION, []);

        return is_array($status) ? $status : [];
    }

    public function sync(): array
    {
        $status = $this->status();
        $enabled = $this->enabled();

        foreach (snap_security_feeds() as $key => $feed) {
            if (! isset($enabled[$key])) {
                $this->blocklist->replaceSource("feed:{$key}", [], $feed['action'], 'site', self::KEEP_FOR);
                unset($status[$key]);

                continue;
            }

            $result = $this->fetch($feed);

            if (is_string($result)) {
                $status[$key] = ['synced_at' => $status[$key]['synced_at'] ?? null, 'count' => $status[$key]['count'] ?? 0, 'error' => $result, 'checked_at' => time()];
                $this->log->log('feeds.failed', "{$feed['label']}: {$result}", ['feed' => $key]);

                continue;
            }

            $count = $this->blocklist->replaceSource("feed:{$key}", $result, $feed['action'], 'site', self::KEEP_FOR);

            if ($count === 0) {
                $status[$key] = ['synced_at' => $status[$key]['synced_at'] ?? null, 'count' => $status[$key]['count'] ?? 0, 'error' => 'the entries could not be saved', 'checked_at' => time()];
                $this->log->log('feeds.failed', "{$feed['label']}: the entries could not be saved", ['feed' => $key]);

                continue;
            }

            $status[$key] = ['synced_at' => time(), 'count' => $count, 'error' => null, 'checked_at' => time()];
            $this->log->log('feeds.synced', sprintf('%s: %d entries', $feed['label'], $count), ['feed' => $key]);
        }

        update_option(self::STATUS_OPTION, $status, false);

        return $status;
    }

    public function parse(string $body, string $format): array
    {
        $entries = [];

        foreach (preg_split('/\r?\n/', $body) as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            if ($format === 'ndjson-cidr') {
                $row = json_decode($line, true);
                $candidate = is_array($row) ? (string) ($row['cidr'] ?? '') : '';
            } else {
                $candidate = trim(strtok($line, " \t;#"));
            }

            if ($candidate !== '' && Ip::range($candidate) !== null && ! Ip::tooWide($candidate)) {
                $entries[] = $candidate;
            }

            if (count($entries) >= snap_security_feed_max_entries()) {
                break;
            }
        }

        return array_values(array_unique($entries));
    }

    private function fetch(array $feed): array|string
    {
        $url = str_replace('{confidence}', (string) $this->settings->int('abuseipdb_min_confidence'), $feed['url']);
        $headers = ['User-Agent' => 'Snapshot-Security/1.0 (+'.home_url('/').')'];

        if (! empty($feed['key_setting'])) {
            $headers['Key'] = $this->settings->string($feed['key_setting']);
            $headers['Accept'] = 'text/plain';
        }

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => $headers,
            'limit_response_size' => 16 * MB_IN_BYTES,
        ]);

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return "HTTP {$code}";
        }

        $entries = $this->parse((string) wp_remote_retrieve_body($response), $feed['format']);

        return $entries === [] ? 'the feed was empty' : $entries;
    }
}
