<?php

namespace Snapshot\Security;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Snapshot\Core;
use UnexpectedValueException;

class Scanner
{
    public const RESULT_OPTION = 'snapshot_security_scan';

    public const MANIFEST = 'security-manifest.json';

    private const MAX_FILES = 150000;

    private const MAX_HASH_BYTES = 25 * MB_IN_BYTES;

    private const MAX_SIGNATURES = 20000;

    private const CONFIG_FILES = ['.user.ini', 'php.ini', '.htaccess'];

    private const SKIP_DIRS = ['node_modules', '.git', '.svn'];

    private array $findings = [];

    public function __construct(private EventLog $log) {}

    public function last(): array
    {
        $result = get_option(self::RESULT_OPTION, []);

        return is_array($result) ? $result : [];
    }

    public function run(): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(900);
        }

        $this->findings = [];
        $last = $this->last();
        $previous = $last['signatures'] ?? array_column($last['findings'] ?? [], 'signature');

        $this->core();
        $this->plugins();
        $this->theme();
        $this->uploads();
        $this->contentRoot();
        $this->configFiles();

        $severities = snap_security_severities();
        usort($this->findings, fn ($a, $b) => $severities[$b['severity']] <=> $severities[$a['severity']] ?: strcmp($a['path'], $b['path']));

        $counts = array_fill_keys(array_keys($severities), 0);

        foreach ($this->findings as $finding) {
            $counts[$finding['severity']]++;
        }

        $new = array_values(array_filter(
            $this->findings,
            fn ($finding) => $severities[$finding['severity']] >= $severities['warning'] && ! in_array($finding['signature'], $previous, true)
        ));

        $result = [
            'at' => time(),
            'counts' => $counts,
            'total' => count($this->findings),
            'new' => count($new),
            'findings' => array_slice($this->findings, 0, 500),
            'signatures' => array_slice(array_column($this->findings, 'signature'), 0, self::MAX_SIGNATURES),
        ];

        update_option(self::RESULT_OPTION, $result, false);

        $this->log->log('scan.completed', sprintf('%d findings, %d critical, %d new', count($this->findings), $counts['critical'], count($new)));

        if ($new !== []) {
            $this->log->log('scan.findings', sprintf('%d new problems, starting with %s', count($new), $new[0]['detail'].' '.$new[0]['path']), [
                'findings' => array_map(fn ($f) => "{$f['type']} {$f['path']}", array_slice($new, 0, 15)),
            ]);
        }

        return $result;
    }

    public function acceptBaseline(?string $origin = null): int
    {
        global $wpdb;

        $table = Schema::table('files');

        return (int) ($origin === null
            ? $wpdb->query("DELETE FROM {$table}")
            : $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE origin = %s", $origin)));
    }

    private function core(): void
    {
        global $wp_version, $wp_local_package;

        require_once ABSPATH.'wp-admin/includes/update.php';

        $checksums = get_core_checksums($wp_version, $wp_local_package ?? 'en_US');

        if (! is_array($checksums) || $checksums === []) {
            $checksums = get_core_checksums($wp_version, 'en_US');
        }

        if (! is_array($checksums) || $checksums === []) {
            $this->add('core.unverified', 'warning', '', "Could not download the checksums for WordPress {$wp_version}");

            return;
        }

        foreach ($checksums as $file => $md5) {
            if (str_starts_with($file, 'wp-content/')) {
                continue;
            }

            $path = ABSPATH.$file;

            if (! is_file($path)) {
                $this->add('core.missing', 'notice', $file, 'Core file is missing');

                continue;
            }

            if (! in_array(md5_file($path), (array) $md5, true)) {
                $this->add('core.modified', 'critical', $file, 'Core file differs from the official release', $this->hash($path));
            }
        }

        foreach (['wp-admin', WPINC] as $dir) {
            foreach ($this->walk(ABSPATH.$dir) as $path) {
                $relative = $this->relative($path, ABSPATH);

                if (! isset($checksums[$relative]) && ($original = $this->truncatedCoreFile($relative, $checksums)) !== null) {
                    $this->add('core.truncated', 'notice', $relative, "Cut short copy of {$original} left by an archive extraction that limits names to 100 characters. Safe to delete, or reinstall core with wp core download --force --skip-content", $this->hash($path));
                } elseif (! isset($checksums[$relative])) {
                    $this->add('core.unknown', $this->executable($path) ? 'critical' : 'warning', $relative, 'File is not part of WordPress core', $this->hash($path));
                }
            }
        }

        foreach (new FilesystemIterator(ABSPATH, FilesystemIterator::SKIP_DOTS) as $file) {
            if (! $file->isFile() || ! $this->executable($file->getPathname())) {
                continue;
            }

            $name = $file->getFilename();

            if (! isset($checksums[$name]) && ! in_array($name, ['wp-config.php', 'wp-config-local.php'], true)) {
                $this->add('core.unknown', 'warning', $name, 'Unexpected executable file in the WordPress root');
            }
        }
    }

    private function plugins(): void
    {
        require_once ABSPATH.'wp-admin/includes/plugin.php';

        foreach (get_plugins() as $file => $data) {
            $slug = dirname($file);
            $version = (string) ($data['Version'] ?? '');

            if ($slug === '.') {
                $this->baseline('plugin:'.$file.'@'.$version, WP_PLUGIN_DIR.'/'.$file, 'plugin');

                continue;
            }

            $dir = WP_PLUGIN_DIR.'/'.$slug;
            $own = $this->ownManifest($dir);
            $remote = $own ?? ($this->hostedElsewhere($data) ? null : $this->pluginChecksums($slug, $version));

            if ($remote === null) {
                $this->baseline("plugin:{$slug}@{$version}", $dir, 'plugin', "plugin:{$slug}@");

                continue;
            }

            foreach ($remote as $relative => $hashes) {
                $path = $dir.'/'.$relative;

                if (! is_file($path)) {
                    continue;
                }

                $expected = (array) ($hashes['sha256'] ?? []);

                $actual = hash_file('sha256', $path);

                if ($expected !== [] && ! in_array($actual, $expected, true)) {
                    $this->add('plugin.modified', 'critical', "wp-content/plugins/{$slug}/{$relative}", $own !== null ? "File differs from {$data['Name']} {$version} as released" : "File differs from {$data['Name']} {$version} on WordPress.org", $actual);
                }
            }

            foreach ($this->walk($dir) as $path) {
                $relative = $this->relative($path, $dir.'/');

                if (! isset($remote[$relative]) && $this->executable($path)) {
                    $this->add('plugin.unknown', 'critical', "wp-content/plugins/{$slug}/{$relative}", "Executable file is not part of {$data['Name']} {$version}", $this->hash($path));
                }
            }
        }
    }

    private function theme(): void
    {
        $dir = get_stylesheet_directory();
        $manifest = $this->manifest($dir);

        if ($manifest === null) {
            $this->baseline('theme:'.get_stylesheet(), $dir, 'theme');

            return;
        }

        $root = 'wp-content/themes/'.get_stylesheet().'/';
        $seen = [];

        foreach ($this->walk($dir) as $path) {
            $relative = $this->relative($path, $dir.'/');
            $seen[$relative] = true;

            if ($relative === self::MANIFEST) {
                continue;
            }

            if (! isset($manifest[$relative])) {
                if ($this->executable($path)) {
                    $this->add('theme.unknown', 'critical', $root.$relative, 'Executable file was not in the deployed theme', $this->hash($path));
                }

                continue;
            }

            $hash = $this->hash($path);

            if ($hash !== $manifest[$relative]) {
                $this->add('theme.modified', 'critical', $root.$relative, 'File differs from the deployed theme', $hash);
            }
        }

        foreach (array_diff_key($manifest, $seen) as $relative => $hash) {
            $this->add('theme.missing', 'warning', $root.$relative, 'File from the deployed theme is missing');
        }
    }

    private function uploads(): void
    {
        $base = wp_get_upload_dir()['basedir'] ?? '';

        if (! is_dir($base)) {
            return;
        }

        foreach ($this->walk($base, ['.acorn']) as $path) {
            $name = basename($path);

            if ($this->executable($path) && ! $this->silentIndex($path)) {
                $this->add('uploads.executable', 'critical', $this->relative($path, ABSPATH), 'Executable file in uploads', $this->hash($path));
            } elseif ($name === '.htaccess' || $name === '.user.ini') {
                $this->add('uploads.config', 'warning', $this->relative($path, ABSPATH), 'Server configuration file in uploads', $this->hash($path));
            }
        }

        foreach ($this->walk($base.'/.acorn') as $path) {
            $relative = $this->relative($path, $base.'/.acorn/');

            if ($this->executable($path) && ! preg_match('#^framework/(?:views/[a-f0-9]{32,64}(?:-loader)?|cache/(?:services|packages|config|events|routes-v7))\.php$#', $relative)) {
                $this->add('uploads.acorn', 'critical', $this->relative($path, ABSPATH), 'Executable file in the Acorn storage folder that is not a compiled view', $this->hash($path));
            } elseif (str_ends_with($path, '.log') && ! $this->denied($base.'/.acorn')) {
                $this->add('uploads.log', 'warning', $this->relative($path, ABSPATH), 'Log file inside the web root. Deny web access to uploads/.acorn on the server.', $this->hash($path));
            }
        }
    }

    private function configFiles(): void
    {
        foreach ([ABSPATH, WP_CONTENT_DIR.'/', get_stylesheet_directory().'/'] as $dir) {
            foreach (self::CONFIG_FILES as $name) {
                $path = $dir.$name;

                if (! is_file($path) || ! is_readable($path)) {
                    continue;
                }

                $contents = (string) file_get_contents($path, false, null, 0, MB_IN_BYTES);

                if (preg_match('/\b(?:auto_prepend_file|auto_append_file)\b/i', $contents)) {
                    $this->add('config.prepend', 'critical', $this->relative($path, ABSPATH), 'Server configuration loads a PHP file into every request. Confirm this is intended.', $this->hash($path));
                }
            }
        }
    }

    private function silentIndex(string $path): bool
    {
        if (basename($path) !== 'index.php' || (int) @filesize($path) > 200) {
            return false;
        }

        $closed = false;

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            $id = is_array($token) ? $token[0] : null;

            if ($closed || ! in_array($id, [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_CLOSE_TAG], true)) {
                if ($id === T_INLINE_HTML && trim($token[1]) === '') {
                    continue;
                }

                return false;
            }

            $closed = $id === T_CLOSE_TAG;
        }

        return true;
    }

    private function contentRoot(): void
    {
        foreach (new FilesystemIterator(WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isFile() && $this->executable($file->getPathname()) && ! in_array($file->getFilename(), snap_security_known_dropins(), true)) {
                $this->add('content.unknown', 'warning', $this->relative($file->getPathname(), ABSPATH), 'Unexpected executable file in wp-content');
            }

            if ($file->isFile() && preg_match('/(?:debug|error)[._]log$/i', $file->getFilename())) {
                $this->add('content.log', 'warning', $this->relative($file->getPathname(), ABSPATH), 'Log file in a web readable folder');
            }
        }

        $mu = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR.'/mu-plugins';

        if (is_dir($mu)) {
            foreach ($this->walk($mu) as $path) {
                if ($this->executable($path) && ! $this->silentIndex($path)) {
                    $this->add('content.mu_plugin', 'warning', $this->relative($path, ABSPATH), 'Must use plugin present, this repository ships none', $this->hash($path));
                }
            }
        }
    }

    private function baseline(string $origin, string $target, string $kind, ?string $family = null): void
    {
        global $wpdb;

        $table = Schema::table('files');
        $root = is_dir($target) ? rtrim($target, '/').'/' : dirname($target).'/';
        $paths = is_dir($target) ? $this->walk($target) : (is_file($target) ? [$target] : []);
        $current = [];

        foreach ($paths as $path) {
            $current[$this->relative($path, $root)] = ['sha256' => $this->hash($path), 'size' => (int) @filesize($path), 'path' => $path];
        }

        $replaced = false;

        if ($family !== null) {
            $replaced = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE origin LIKE %s AND origin <> %s",
                $wpdb->esc_like($family).'%',
                $origin
            )) > 0;

            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE origin LIKE %s AND origin <> %s",
                $wpdb->esc_like($family).'%',
                $origin
            ));
        }

        $stored = [];

        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT path, sha256 FROM {$table} WHERE origin = %s", $origin)) as $row) {
            $stored[$row->path] = $row->sha256;
        }

        $display = $this->relative($root, ABSPATH);

        if ($stored === []) {
            $now = gmdate('Y-m-d H:i:s');

            foreach (array_chunk($current, 300, true) as $chunk) {
                $values = [];

                foreach ($chunk as $relative => $file) {
                    $values[] = $wpdb->prepare('(%s, %s, %s, %s, %d, %s)', hash('sha256', $origin.'|'.$relative), $origin, $relative, $file['sha256'], $file['size'], $now);
                }

                $wpdb->query("INSERT INTO {$table} (path_hash, origin, path, sha256, size, checked_at) VALUES ".implode(',', $values));
            }

            if ($replaced) {
                $this->add('baseline.replaced', 'warning', $display, sprintf('Version changed to one without published checksums. Recorded %d current files as the new baseline for %s; confirm the update was deliberate.', count($current), $origin));
            } else {
                $this->add('baseline.created', 'info', $display, sprintf('Recorded %d files as the trusted baseline for %s', count($current), $origin));
            }

            return;
        }

        foreach ($current as $relative => $file) {
            if (! isset($stored[$relative])) {
                if ($this->executable($file['path'])) {
                    $this->add("{$kind}.unknown", 'critical', $display.$relative, "Executable file added since the baseline for {$origin}", $file['sha256']);
                }

                continue;
            }

            if (! hash_equals($stored[$relative], $file['sha256'])) {
                $this->add("{$kind}.modified", 'critical', $display.$relative, "File changed since the baseline for {$origin}", $file['sha256']);
            }
        }
    }

    private function pluginChecksums(string $slug, string $version): ?array
    {
        if ($version === '' || ! preg_match('/^[a-z0-9._-]+$/i', $slug)) {
            return null;
        }

        $key = 'snapshot_sec_pc_'.md5($slug.'@'.$version);
        $cached = get_transient($key);

        if ($cached !== false) {
            return $cached === 'none' ? null : $cached;
        }

        $response = wp_remote_get(sprintf('https://downloads.wordpress.org/plugin-checksums/%s/%s.json', rawurlencode($slug), rawurlencode($version)), ['timeout' => 20]);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $body = $code === 200 ? json_decode(wp_remote_retrieve_body($response), true) : null;
        $files = is_array($body) && isset($body['files']) && is_array($body['files']) ? $body['files'] : null;

        if ($files === null && $code !== 404) {
            return null;
        }

        set_transient($key, $files ?? 'none', WEEK_IN_SECONDS);

        return $files;
    }

    private function ownManifest(string $dir): ?array
    {
        if (realpath($dir) !== realpath(Core::root()) || ($files = $this->manifest(Core::root())) === null) {
            return null;
        }

        return array_map(fn ($hash) => str_starts_with((string) $hash, 'size:') ? [] : ['sha256' => [(string) $hash]], $files);
    }

    private function hostedElsewhere(array $data): bool
    {
        $host = (string) wp_parse_url((string) ($data['UpdateURI'] ?? ''), PHP_URL_HOST);

        return $host !== '' && ! in_array($host, ['w.org', 'wordpress.org'], true);
    }

    private function manifest(string $dir): ?array
    {
        $path = $dir.'/'.self::MANIFEST;

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && isset($data['files']) && is_array($data['files']) && $data['files'] !== [] ? $data['files'] : null;
    }

    private function truncatedCoreFile(string $relative, array $checksums): ?string
    {
        if (strlen('wordpress/'.$relative) !== 100 || $this->executable(ABSPATH.$relative)) {
            return null;
        }

        foreach (array_keys($checksums) as $file) {
            if (strlen($file) > strlen($relative) && str_starts_with($file, $relative)) {
                return $file;
            }
        }

        return null;
    }

    private function denied(string $dir): bool
    {
        $rules = is_readable($dir.'/.htaccess') ? (string) file_get_contents($dir.'/.htaccess') : '';

        return str_contains($rules, 'Require all denied') || stripos($rules, 'Deny from all') !== false;
    }

    private function walk(string $dir, array $skip = []): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $paths = [];
        $skip = [...self::SKIP_DIRS, ...$skip];
        $root = rtrim(wp_normalize_path($dir), '/');

        try {
            $iterator = new RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    fn ($file) => ! $file->isLink() && ! ($file->isDir() && in_array($file->getFilename(), $skip, true) && rtrim(wp_normalize_path($file->getPath()), '/') === $root)
                )
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $paths[] = $file->getPathname();
                }

                if (count($paths) >= self::MAX_FILES) {
                    $this->add('scan.incomplete', 'warning', $this->relative($dir, ABSPATH), sprintf('Scan stopped after %d files in this folder; the remaining files were not checked.', self::MAX_FILES));

                    break;
                }
            }
        } catch (UnexpectedValueException) {
            return $paths;
        }

        sort($paths);

        return $paths;
    }

    private function hash(string $path): string
    {
        $size = @filesize($path);

        return $size !== false && $size > self::MAX_HASH_BYTES ? 'size:'.$size : (string) hash_file('sha256', $path);
    }

    private function executable(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), snap_security_executable_extensions(), true);
    }

    private function relative(string $path, string $root): string
    {
        $root = rtrim(wp_normalize_path($root), '/').'/';
        $path = wp_normalize_path($path);

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function add(string $type, string $severity, string $path, string $detail, string $hash = ''): void
    {
        $this->findings[] = [
            'type' => $type,
            'severity' => $severity,
            'path' => $path,
            'detail' => $detail,
            'signature' => md5($type.'|'.$path.'|'.$hash),
        ];
    }
}
