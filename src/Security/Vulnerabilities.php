<?php

namespace Snapshot\Security;

class Vulnerabilities
{
    public const OPTION = 'snapshot_security_vulns';

    private const API = 'https://www.wpvulnerability.net/';

    public function __construct(private EventLog $log) {}

    public function last(): array
    {
        $result = get_option(self::OPTION, []);

        return is_array($result) ? $result : [];
    }

    public function run(): array
    {
        global $wp_version;

        require_once ABSPATH.'wp-admin/includes/plugin.php';

        $items = [];
        $errors = 0;
        $previousItems = $this->last()['items'] ?? [];
        $carry = function (string $type, string $slug, string $version, int $before, int $after) use (&$items, $previousItems) {
            if ($after > $before) {
                foreach ($previousItems as $item) {
                    if ($item['type'] === $type && $item['slug'] === $slug && $item['version'] === $version) {
                        $items[] = $item;
                    }
                }
            }
        };

        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $before = $errors;
        $core = $this->query("core/{$wp_version}", $errors);
        $carry('core', 'wordpress', (string) $wp_version, $before, $errors);

        foreach ($core as $entry) {
            $items[] = $this->item('core', 'wordpress', 'WordPress', $wp_version, $entry);
        }

        foreach (get_plugins() as $file => $data) {
            $slug = dirname($file) === '.' ? basename($file, '.php') : dirname($file);
            $version = (string) ($data['Version'] ?? '');

            $before = $errors;
            $entries = $this->query("plugin/{$slug}", $errors);
            $carry('plugin', $slug, $version, $before, $errors);

            foreach ($entries as $entry) {
                if ($this->applies($entry, $version)) {
                    $items[] = $this->item('plugin', $slug, (string) $data['Name'], $version, $entry);
                }
            }
        }

        foreach (wp_get_themes() as $slug => $theme) {
            $version = (string) $theme->get('Version');

            $before = $errors;
            $entries = $this->query("theme/{$slug}", $errors);
            $carry('theme', (string) $slug, $version, $before, $errors);

            foreach ($entries as $entry) {
                if ($this->applies($entry, $version)) {
                    $items[] = $this->item('theme', (string) $slug, (string) $theme->get('Name'), $version, $entry);
                }
            }
        }

        $previous = array_column($this->last()['items'] ?? [], 'id');
        $new = array_values(array_filter($items, fn ($item) => ! in_array($item['id'], $previous, true)));

        $result = ['at' => time(), 'items' => $items, 'errors' => $errors];
        update_option(self::OPTION, $result, false);

        if ($new !== []) {
            $this->log->log('vuln.found', sprintf('%d newly listed vulnerabilities, starting with %s', count($new), $new[0]['title']), [
                'items' => array_map(fn ($item) => "{$item['name']} {$item['version']}: {$item['title']}", array_slice($new, 0, 15)),
            ]);
        }

        return $result;
    }

    public function applies(array $entry, string $version): bool
    {
        $operator = (array) ($entry['operator'] ?? []);

        if ($version === '' || $operator === []) {
            return false;
        }

        $min = (string) ($operator['min_version'] ?? '');
        $max = (string) ($operator['max_version'] ?? '');

        if ($min !== '' && ! version_compare($version, $min, $this->operator($operator['min_operator'] ?? 'ge'))) {
            return false;
        }

        if (! empty($operator['unfixed']) && (string) $operator['unfixed'] !== '0') {
            return true;
        }

        if ($max !== '' && ! version_compare($version, $max, $this->operator($operator['max_operator'] ?? 'le'))) {
            return false;
        }

        return $min !== '' || $max !== '';
    }

    private function operator(?string $operator): string
    {
        return in_array($operator, ['lt', 'le', 'gt', 'ge', 'eq', 'ne'], true) ? $operator : 'le';
    }

    private function item(string $type, string $slug, string $name, string $version, array $entry): array
    {
        $sources = array_slice((array) ($entry['source'] ?? []), 0, 3);
        $operator = (array) ($entry['operator'] ?? []);
        $fixedIn = ($operator['max_operator'] ?? '') === 'lt' && empty($operator['unfixed']) ? (string) ($operator['max_version'] ?? '') : '';
        $severity = $entry['impact']['cvss']['severity'] ?? null;

        return [
            'id' => (string) ($entry['uuid'] ?? md5(wp_json_encode($entry))),
            'type' => $type,
            'slug' => $slug,
            'name' => $name,
            'version' => $version,
            'title' => $this->title($entry),
            'fixed_in' => $fixedIn,
            'severity' => is_string($severity) ? $severity : '',
            'links' => array_values(array_filter(array_map(fn ($source) => esc_url_raw((string) ($source['link'] ?? '')), $sources))),
            'ids' => array_values(array_filter(array_map(fn ($source) => sanitize_text_field((string) ($source['id'] ?? '')), $sources))),
        ];
    }

    private function title(array $entry): string
    {
        $sources = (array) ($entry['source'] ?? []);
        $descriptive = array_filter($sources, fn ($source) => ($source['name'] ?? '') !== '' && ($source['name'] ?? '') !== ($source['id'] ?? ''));
        $source = reset($descriptive) ?: ($sources[0] ?? []);
        $title = (string) ($source['name'] ?? $entry['name'] ?? 'Vulnerability');

        if ($title === ($source['id'] ?? null) && ! empty($source['description'])) {
            $title = $source['id'].': '.preg_replace('/^\[\w+\]\s*/', '', (string) $source['description']);
        }

        return mb_strimwidth(wp_strip_all_tags(html_entity_decode($title)), 0, 180, '…');
    }

    private function query(string $path, int &$errors): array
    {
        $key = 'snapshot_sec_vuln_'.md5($path);
        $cached = get_transient($key);

        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(self::API.$path.'/', ['timeout' => 15, 'headers' => ['Accept' => 'application/json']]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $errors++;

            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $entries = is_array($body) && empty($body['error']) && is_array($body['data']['vulnerability'] ?? null)
            ? $body['data']['vulnerability']
            : [];

        set_transient($key, $entries, 12 * HOUR_IN_SECONDS);

        return $entries;
    }
}
