<?php

use Snapshot\Core;
use Snapshot\Mcp\Mcp;
use Snapshot\ModuleSwitch;
use Snapshot\Remote;
use Snapshot\Security\Admin\Cli;
use Snapshot\Security\Schema;
use Snapshot\Security\Shield;
use Snapshot\Security\TwoFactor\Profile;
use Snapshot\Seo\Engine;

PHP_SAPI === 'cli' || exit(1);

$root = dirname(__DIR__);

define('ABSPATH', sys_get_temp_dir().'/snapshot-remote-check/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('YEAR_IN_SECONDS', 31536000);
define('ARRAY_A', 'ARRAY_A');
define('KB_IN_BYTES', 1024);
define('MB_IN_BYTES', 1048576);
define('GB_IN_BYTES', 1073741824);
define('SNAPSHOT_CORE_VERSION', '0.0.0-check');
define('SNAPSHOT_SECURITY_MODULE_FIREWALL', true);

spl_autoload_register(function (string $class) use ($root) {
    if (str_starts_with($class, 'Snapshot\\') && ! str_starts_with($class, 'Snapshot\\Vendor\\')) {
        $path = $root.'/src/'.str_replace('\\', '/', substr($class, 9)).'.php';

        if (is_file($path)) {
            require $path;
        }
    }
});

$state = ['options' => [], 'transients' => [], 'filters' => [], 'events' => [], 'cli' => [], 'mail' => [], 'meta' => [], 'session' => [], 'actions' => [], 'user' => 0, 'destroyed' => [], 'cleared' => false];

function get_option(string $name, $default = false)
{
    global $state;

    return array_key_exists($name, $state['options']) ? $state['options'][$name] : $default;
}

function update_option(string $name, $value, $autoload = null): bool
{
    global $state;
    $state['options'][$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    global $state;
    unset($state['options'][$name]);

    return true;
}

function get_transient(string $name)
{
    global $state;

    return $state['transients'][$name] ?? false;
}

function set_transient(string $name, $value, int $expiry = 0): bool
{
    global $state;
    $state['transients'][$name] = $value;

    return true;
}

function delete_transient(string $name): bool
{
    global $state;
    unset($state['transients'][$name]);

    return true;
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): void
{
    global $state;
    $state['filters'][$hook][] = [$callback, $args];
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): void
{
    global $state;
    $state['actions'][$hook][] = $callback;
}

function apply_filters(string $hook, $value, ...$extra)
{
    global $state;

    foreach ($state['filters'][$hook] ?? [] as [$callback, $args]) {
        $value = $callback(...array_slice([$value, ...$extra], 0, $args));
    }

    return $value;
}

function do_action(string $hook, ...$args): void
{
    global $state;

    if ($hook === 'snap/security/event') {
        $state['events'][] = $args[0];
    }
}

function did_action(string $hook): int
{
    return 0;
}

class Redirected extends RuntimeException {}

function wp_safe_redirect(string $url): never
{
    throw new Redirected($url);
}

function add_query_arg(array $args, string $url): string
{
    return $url.'?'.http_build_query($args);
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/'.$path;
}

function current_user_can(string $capability): bool
{
    return true;
}

function check_admin_referer(string $action): bool
{
    return true;
}

function wp_unslash($value)
{
    return $value;
}

function wp_get_current_user(): object
{
    return (object) ['ID' => 7, 'user_login' => 'ada'];
}

function is_email($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
}

function wp_mail($to, string $subject, string $body): bool
{
    global $state;
    $state['mail'][] = ['to' => (array) $to, 'subject' => $subject, 'body' => $body];

    return true;
}

function wp_date(string $format, ?int $time = null): string
{
    return gmdate($format, $time ?? time());
}

function get_bloginfo(string $show = ''): string
{
    return 'Example';
}

function wp_specialchars_decode(string $text, $quotes = ENT_NOQUOTES): string
{
    return htmlspecialchars_decode($text, $quotes);
}

function wp_parse_url(string $url, int $component = -1)
{
    return parse_url($url, $component);
}

function esc_url_raw(string $url): string
{
    return $url;
}

function current_time(string $type, bool $gmt = false): string
{
    return gmdate('Y-m-d H:i:s');
}

class WP_Post
{
    public int $ID = 0;

    public string $post_type = 'page';

    public string $post_status = 'publish';

    public function __construct(array $fields = [])
    {
        foreach ($fields as $key => $value) {
            $this->{$key} = $value;
        }
    }
}

function get_post(int $id)
{
    return $id === 11 ? new WP_Post(['ID' => 11]) : null;
}

function post_password_required($post = null): bool
{
    return false;
}

function metadata_exists(string $type, int $id, string $key): bool
{
    global $state;

    return array_key_exists($key, $state['meta'][$id] ?? []);
}

function get_post_meta(int $id, string $key, bool $single = false)
{
    global $state;

    return $state['meta'][$id][$key] ?? '';
}

function wp_get_session_token(): string
{
    return 'token';
}

class WP_Session_Tokens
{
    public static function get_instance(int $userId): self
    {
        return new self;
    }

    public function get(string $token): ?array
    {
        global $state;

        return $state['session'];
    }

    public function destroy(string $token): void
    {
        global $state;
        $state['destroyed'][] = $token;
    }
}

function is_ssl(): bool
{
    return false;
}

function is_user_logged_in(): bool
{
    global $state;

    return ($state['user'] ?? 0) > 0;
}

function wp_parse_auth_cookie($cookie = '', $scheme = '')
{
    global $state;

    return ($state['user'] ?? 0) > 0 ? ['token' => 'token'] : false;
}

function wp_validate_auth_cookie($cookie = '', $scheme = '')
{
    global $state;

    return ($state['user'] ?? 0) > 0 ? $state['user'] : false;
}

function wp_doing_cron(): bool
{
    return false;
}

function wp_doing_ajax(): bool
{
    return true;
}

function wp_is_json_request(): bool
{
    return false;
}

function wp_clear_auth_cookie(): void
{
    global $state;
    $state['cleared'] = true;
}

function wp_set_current_user(int $id): void
{
    global $state;
    $state['user'] = $id;
}

function wp_verify_nonce($nonce, $action = -1)
{
    return 1;
}

function check_ajax_referer($action = -1, $query = false): bool
{
    return true;
}

function wp_send_json_error($data = null, ?int $status = null): never
{
    throw new RuntimeException('json_error:'.$status.':'.json_encode($data));
}

function __($text, $domain = 'default')
{
    return $text;
}

function _n($single, $plural, $number, $domain = 'default')
{
    return $number === 1 ? $single : $plural;
}

function is_multisite(): bool
{
    return false;
}

function get_site_option(string $name, $default = false)
{
    return $default;
}

function sanitize_text_field($value): string
{
    $value = strip_tags((string) $value);

    while (preg_match('/%[a-f0-9]{2}/i', $value)) {
        $value = (string) preg_replace('/%[a-f0-9]{2}/i', '', $value);
    }

    return trim(preg_replace('/[\r\n\t ]+/', ' ', $value));
}

function wp_json_encode($value)
{
    return json_encode($value);
}

function home_url(string $path = ''): string
{
    return 'https://example.test'.$path;
}

function snap_security(): Shield
{
    return Shield::instance();
}

function get_userdata(int $id)
{
    return $id === 7 ? (object) ['ID' => 7, 'user_login' => 'staff'] : false;
}

function get_current_user_id(): int
{
    global $state;

    return (int) ($state['user'] ?? 0);
}

function snap_post_types(): array
{
    return [];
}

function snap_taxonomies(): array
{
    return [];
}

function count_users(): array
{
    return ['total_users' => 3];
}

class WP_User_Query
{
    public function __construct(array $query) {}

    public function get_total(): int
    {
        return 1;
    }
}

class WP_CLI
{
    public static function success(string $message): void
    {
        global $state;
        $state['cli'][] = "success: {$message}";
    }

    public static function error(string $message): never
    {
        throw new RuntimeException($message);
    }
}

$wpdb = new class
{
    public string $prefix = 'wp_';

    public int $insert_id = 0;

    public array $deleted = [];

    public array $tables = [];

    public array $hits = [];

    public function insert(string $table, array $row): int
    {
        $this->insert_id++;
        $this->tables[$table][$this->insert_id] = ['id' => $this->insert_id] + $row + ['enabled' => 1, 'hits' => 0, 'last_hit' => null, 'origin' => '', 'note' => '', 'created_at' => ''];

        return 1;
    }

    public function update(string $table, array $fields, array $where): int
    {
        $count = 0;

        foreach ($this->tables[$table] ?? [] as $id => $row) {
            if (array_intersect_assoc(array_map('strval', $where), array_map('strval', $row)) === array_map('strval', $where)) {
                $this->tables[$table][$id] = array_merge($row, $fields);
                $count++;
            }
        }

        return $count;
    }

    public function delete(string $table, array $where): int
    {
        $count = 0;

        foreach ($this->tables[$table] ?? [] as $id => $row) {
            if (array_intersect_assoc(array_map('strval', $where), array_map('strval', $row)) === array_map('strval', $where)) {
                unset($this->tables[$table][$id]);
                $count++;
            }
        }

        return $count;
    }

    public function prepare(string $query, ...$args): string
    {
        return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $args);
    }

    public function esc_like(string $value): string
    {
        return addcslashes($value, '_%\\');
    }

    public function query(string $sql): int
    {
        if (preg_match("/INSERT INTO \S+attempts .*VALUES \('([0-9a-f]{64})'/s", $sql, $match)) {
            $this->hits[$match[1]] = ($this->hits[$match[1]] ?? 0) + 1;

            return 1;
        }

        $this->deleted[] = $sql;

        return 0;
    }

    public function get_var(string $sql)
    {
        if (preg_match("/SELECT hits FROM \S+ WHERE bucket = '([0-9a-f]{64})'/", $sql, $match)) {
            return $this->hits[$match[1]] ?? 0;
        }

        if (preg_match('/^SELECT (id|COUNT\(\*\)) FROM (\S+)(?: WHERE (.*))?$/s', trim($sql), $match)) {
            $rows = $this->select($match[2], $match[3] ?? '');

            return $match[1] === 'id' ? ($rows[0]['id'] ?? null) : count($rows);
        }

        return 0;
    }

    public function get_results(string $sql, $output = null): array
    {
        return [];
    }

    public function get_row(string $sql, $output = null)
    {
        if (preg_match('/^SELECT \* FROM (\S+) WHERE (.*)$/s', trim($sql), $match)) {
            return $this->select($match[1], $match[2])[0] ?? null;
        }

        return null;
    }

    private function select(string $table, string $where): array
    {
        preg_match_all("/(\w+) = (?:'((?:[^']|'(?! AND |$))*)'|(\d+))/", $where, $clauses, PREG_SET_ORDER);

        return array_values(array_filter($this->tables[$table] ?? [], function ($row) use ($clauses) {
            foreach ($clauses as $clause) {
                if ((string) ($row[$clause[1]] ?? '') !== (string) (($clause[3] ?? '') !== '' ? $clause[3] : $clause[2])) {
                    return false;
                }
            }

            return true;
        }));
    }
};

require $root.'/includes/helpers.php';
require $root.'/includes/security-defaults.php';
require $root.'/includes/seo-defaults.php';

(new ReflectionProperty(Core::class, 'loaded'))->setValue(null, true);
(new ReflectionProperty(Schema::class, 'ready'))->setValue(null, true);
(new ReflectionProperty(Shield::instance(), 'ready'))->setValue(Shield::instance(), true);
(new ReflectionProperty(Shield::instance(), 'booted'))->setValue(Shield::instance(), true);
(new ReflectionProperty(Snapshot\Seo\Schema::class, 'ready'))->setValue(null, true);
(new ReflectionProperty(Engine::instance(), 'booted'))->setValue(Engine::instance(), true);

$failures = [];
$passed = 0;

$check = function (string $label, bool $ok) use (&$failures, &$passed) {
    $ok ? $passed++ : $failures[] = $label;
    echo ($ok ? '  ok    ' : '  FAIL  ').$label."\n";
};

$fresh = function (array $security, array $seo = []) use (&$state, $wpdb) {
    $state['options'] = ['snapshot_security_settings' => $security, 'snapshot_seo_settings' => $seo, Snapshot\Seo\Schema::OPTION => Snapshot\Seo\Schema::VERSION];
    $state['events'] = [];
    $state['mail'] = [];
    $state['meta'] = [];
    $state['session'] = [];
    $state['user'] = 0;
    $state['destroyed'] = [];
    $state['cleared'] = false;
    $state['transients'] = [];
    $wpdb->hits = [];
    $wpdb->tables = [];

    foreach ([Shield::instance(), Engine::instance()] as $instance) {
        (new ReflectionProperty($instance, 'services'))->setValue($instance, []);
    }
};

$context = fn (?object $event) => $event && is_string($event->context) ? (array) json_decode($event->context, true) : [];

$loaded = function (string $layer): array {
    $loaded = [];

    foreach (['all', ...array_keys(ModuleSwitch::registry($layer))] as $module) {
        $setting = ModuleSwitch::setting($layer, $module);
        $settings = $layer === 'security' ? Shield::instance()->settings() : Engine::instance()->settings();

        if (! $settings->locked($setting)) {
            $loaded[$setting] = $settings->bool($setting) ? '1' : '0';
        }
    }

    return $loaded;
};

$refused = function (callable $run): string {
    try {
        $run();

        return '';
    } catch (Redirected) {
        return 'redirected';
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }
};

$stored = [
    'security_enabled' => true,
    'module_login' => false,
    'module_headers' => true,
    'module_alerts' => false,
    'cloudflare_enabled' => true,
    'scan_enabled' => false,
    'twofactor_email_fallback' => false,
    'alert_recipients' => 'ops@example.test',
];

echo "setModules\n";

$fresh($stored);
$result = Remote::setModules('security', ['headers' => false], 'Ada at Snapshot');
$after = get_option('snapshot_security_settings');
$check('a listed key changes', $result['changed'] === ['headers'] && $after['module_headers'] === false);
$check('only the listed key changes', array_diff_key($after, ['module_headers' => 0]) === array_diff_key($stored, ['module_headers' => 0]));
$check('keys that were never stored stay unstored', ! array_key_exists('module_passwords', $after) && ! array_key_exists('module_twofactor', $after));
$check('the answer carries the layer section', ($result['modules']['modules']['headers']['on'] ?? null) === false && ($result['modules']['modules']['cloudflare']['on'] ?? null) === true);
$event = $state['events'][0] ?? null;
$check('the change is logged as security.module_changed from snapshot-os with the actor', $event && $event->event === 'security.module_changed' && $event->username === 'snapshot-os' && str_contains($event->message, 'from Snapshot OS, by Ada at Snapshot') && $event->severity === 'warning');

$fresh($stored);
$result = Remote::setModules('security', ['firewall' => false, 'nope' => true, 'passwords' => 'off'], 'Ada');
$check('a key pinned in wp-config.php is refused as locked', ($result['refused']['firewall'] ?? '') === 'locked');
$check('an unknown key is refused as unknown_module', ($result['refused']['nope'] ?? '') === 'unknown_module');
$check('a value that is not a bool is refused as invalid_value', ($result['refused']['passwords'] ?? '') === 'invalid_value');
$check('refused keys change nothing', $result['changed'] === [] && get_option('snapshot_security_settings') === $stored && $state['events'] === []);
$check('the locked module reports locked in the status', Remote::status()['security']['modules']['firewall']['locked'] === true);

$fresh($stored);
$result = Remote::setModules('security', ['all' => false], 'Ada');
$status = Remote::status();
$check('all switches the master off', $result['changed'] === ['all'] && get_option('snapshot_security_settings')['security_enabled'] === false);
$check('with the master off no module is on, and each keeps its own switch', $status['security']['enabled'] === false && array_filter(array_column($status['security']['modules'], 'on')) === [] && $status['security']['modules']['headers']['enabled'] === true);
$check('turning the master off is logged as critical', ($state['events'][0]->severity ?? '') === 'critical');
Remote::setModules('security', ['all' => true], 'Ada');
$check('all switches the master on again', get_option('snapshot_security_settings')['security_enabled'] === true && Remote::status()['security']['modules']['headers']['on'] === true);

$fresh($stored);
$result = Remote::setModules('security', ['headers' => true], 'Ada');
$check('a change to the current value is not a change and is not logged', $result['changed'] === [] && $result['refused'] === [] && $state['events'] === []);

$fresh($stored);
$result = Remote::setModules('seo', ['llms' => false], 'Ada');
$check('the seo layer changes only its listed key', $result['changed'] === ['llms'] && get_option('snapshot_seo_settings') === ['module_llms' => false]);
$check('seo.module_changed is a registered warning', (snap_security_event_types()['seo.module_changed']['severity'] ?? '') === 'warning' && ($state['events'][0]->severity ?? '') === 'warning');
$check('an unknown layer is refused', (Remote::setModules('mail', ['x' => true], 'Ada')['refused']['x'] ?? '') === 'unknown_layer');

echo "booted layers\n";

$fresh($stored);
$shieldReady = new ReflectionProperty(Shield::instance(), 'ready');
$shieldReady->setValue(Shield::instance(), false);
$status = Remote::status();
$check('a security layer that did not boot reports booted false', $status['security']['booted'] === false && $status['seo']['booted'] === true);
$check('the modules that boot before the schema check still report booted and on', $status['security']['modules']['headers']['booted'] === true && $status['security']['modules']['headers']['on'] === true && $status['security']['modules']['hardening']['booted'] === true);
$check('the modules that need the security tables report booted false and on false, with the switch kept', $status['security']['modules']['login']['booted'] === false && $status['security']['modules']['firewall']['on'] === false && $status['security']['modules']['cloudflare']['booted'] === false && $status['security']['modules']['cloudflare']['enabled'] === true);
$check('every module is booted false or the on flag follows it', array_filter($status['security']['modules'], fn ($module) => $module['on'] && ! $module['booted']) === []);
$shieldBooted = new ReflectionProperty(Shield::instance(), 'booted');
$shieldBooted->setValue(Shield::instance(), false);
$status = Remote::status();
$check('when the layer never booted, every module reports booted false and on false', array_filter(array_column($status['security']['modules'], 'on')) === [] && array_filter(array_column($status['security']['modules'], 'booted')) === []);
$shieldBooted->setValue(Shield::instance(), true);
$shieldReady->setValue(Shield::instance(), true);
$check('with the tables ready every security module reports booted true', array_filter(array_column(Remote::status()['security']['modules'], 'booted')) === array_column(Remote::status()['security']['modules'], 'booted'));
$fresh($stored + ['module_twofactor' => true]);
$check('two factor reports booted true without the emergency constant', Remote::status()['security']['modules']['twofactor']['booted'] === true && Remote::status()['security']['modules']['twofactor']['on'] === true);
$seoReady = new ReflectionProperty(Snapshot\Seo\Schema::class, 'ready');
$seoReady->setValue(null, false);
$status = Remote::status();
$check('SEO redirects and the 404 monitor report booted false without the SEO tables', $status['seo']['modules']['redirects']['booted'] === false && $status['seo']['modules']['monitor']['on'] === false && $status['seo']['modules']['head']['booted'] === true && $status['seo']['modules']['head']['on'] === true);
$seoReady->setValue(null, true);
$check('and booted true with them', Remote::status()['seo']['modules']['redirects']['booted'] === true);
$engineBooted = new ReflectionProperty(Engine::instance(), 'booted');
$engineBooted->setValue(Engine::instance(), false);
$status = Remote::status();
$check('an seo layer that did not boot reports booted false and no module on', $status['seo']['booted'] === false && array_filter(array_column($status['seo']['modules'], 'on')) === [] && $status['security']['booted'] === true);
$engineBooted->setValue(Engine::instance(), true);

$fresh($stored + ['headers_hsts' => false]);
$details = Remote::status()['security']['modules']['headers']['details'] ?? null;
$check('the headers module reports whether a CSP and HSTS are sent', $details === ['csp' => true, 'hsts' => false]);
$fresh(['module_headers' => false] + $stored);
$check('with the headers module off neither is sent', (Remote::status()['security']['modules']['headers']['details'] ?? null) === ['csp' => false, 'hsts' => false]);

echo "alerts for remote switches\n";

$fresh($stored);
Remote::setModules('security', ['headers' => false], 'Ada');
$mail = $state['mail'][0] ?? null;
$check('a module switched off from Snapshot OS emails the alert recipients while alerts are off', count($state['mail']) === 1 && $mail['to'] === ['ops@example.test'] && str_contains($mail['body'], 'Security headers switched off from Snapshot OS, by Ada'));
Remote::setModules('security', ['headers' => true], 'Ada');
$check('switching it on again sends nothing', count($state['mail']) === 1);
Remote::setModules('security', ['headers' => false], 'Ada');
$check('the same alert inside the alert window is throttled', count($state['mail']) === 1);
Remote::setModules('security', ['status' => false], 'Ada');
$check('the status endpoint off is critical and emailed', count($state['mail']) === 2 && ($state['events'][count($state['events']) - 1]->severity ?? '') === 'critical');
$check('alerts and status count as critical, audit no longer does', ModuleSwitch::critical('security', 'alerts', false) && ModuleSwitch::critical('security', 'status', false) && ! ModuleSwitch::critical('security', 'audit', false) && ! ModuleSwitch::critical('security', 'alerts', true) && ['all', 'twofactor', 'firewall', 'login', 'alerts', 'status'] === ModuleSwitch::CRITICAL);
$check('critical() reads criticalKeys(), so the SEO master switch off is critical and SEO modules are not', ModuleSwitch::critical('seo', 'all', false) && ! ModuleSwitch::critical('seo', 'all', true) && ! ModuleSwitch::critical('seo', 'robots', false));

$fresh($stored);
Remote::setModules('security', ['headers' => false, 'status' => false, 'feeds' => false, 'login' => true], 'Ada');
$check('one apply() call sends one summary alert, not one per module', count($state['mail']) === 1 && str_contains($state['mail'][0]['body'], 'Security headers, Status endpoint, Threat intelligence switched off from Snapshot OS, by Ada. Switched on: Login protection'));
$check('each module still logs its own line', count(array_filter($state['events'], fn ($event) => $event->event === 'security.module_changed')) === 4);

$fresh($stored);
Remote::setModules('seo', ['all' => false], 'Ada');
$check('the SEO master switch off from Snapshot OS is critical and emailed', ($state['events'][0]->severity ?? '') === 'critical' && count($state['mail']) === 1 && str_contains($state['mail'][0]['body'], 'Built-in SEO switched off from Snapshot OS, by Ada'));
$fresh($stored);
Remote::setModules('seo', ['robots' => false], 'Ada');
$check('an SEO module off from Snapshot OS is a warning and is not emailed', ($state['events'][0]->severity ?? '') === 'warning' && $state['mail'] === []);

$fresh($stored);
(new ReflectionMethod(Snapshot\Seo\Admin\Cli::class, 'toggle'))->invoke(null, 'all', false);
$check('the SEO master off from WP-CLI is not emailed while notifications are off', ($state['events'][0]->severity ?? '') === 'critical' && $state['mail'] === []);
$alertsBooted = new ReflectionProperty(Shield::instance()->alerts(), 'booted');
$alertsBooted->setValue(Shield::instance()->alerts(), true);
(new ReflectionMethod(Snapshot\Seo\Admin\Cli::class, 'toggle'))->invoke(null, 'all', true);
(new ReflectionMethod(Cli::class, 'toggle'))->invoke(null, 'status', false);
$check('with notifications on, a critical switch-off from WP-CLI is emailed once as a summary', count($state['mail']) === 1 && str_contains($state['mail'][0]['body'], 'Status endpoint switched off from WP-CLI'));
$alerts = Shield::instance()->alerts();
$critical = (object) ['created_at' => gmdate('Y-m-d H:i:s'), 'event' => 'login.lockout', 'severity' => 'critical', 'ip' => '', 'username' => '', 'message' => 'held'];
$alerts->hold(true);
$alerts->onEvent($critical);
$held = count($state['mail']);
$alerts->hold(false);
$alerts->onEvent($critical);
$check('while apply() holds the alerts, a per event critical alert is not sent', $held === 1 && count($state['mail']) === 2);
$alertsBooted->setValue(Shield::instance()->alerts(), false);

$fresh($stored);
(new ReflectionMethod(Cli::class, 'toggle'))->invoke(null, 'headers', false);
$check('WP-CLI switching a module off sends no email', $state['mail'] === []);

echo "WP-CLI shares the path\n";

$fresh($stored);
$toggle = new ReflectionMethod(Cli::class, 'toggle');
$toggle->invoke(null, 'feeds', false);
$after = get_option('snapshot_security_settings');
$check('wp snap security disable feeds changes only module_feeds', array_diff_key($after, ['module_feeds' => 0]) === $stored && $after['module_feeds'] === false);
$check('the WP-CLI line is unchanged', ($state['events'][0]->message ?? '') === 'Threat intelligence switched off from WP-CLI' && $state['events'][0]->username === 'wp-cli');
$check('switching feeds off removes the feed rows', str_contains(end($wpdb->deleted) ?: '', "LIKE 'feed:"));

$locked = $refused(fn () => $toggle->invoke(null, 'firewall', false));
$check('WP-CLI refuses a locked module with the constant name', $locked === 'SNAPSHOT_SECURITY_MODULE_FIREWALL is defined in wp-config.php and cannot be changed here.');
$check('WP-CLI refuses an unknown module as an error', str_starts_with($refused(fn () => $toggle->invoke(null, 'nope', true)), 'Unknown module nope.'));
$check('every other refusal is an error too, never a success', ModuleSwitch::refusal('security', 'headers', 'not_loaded') === 'Security headers was not changed: this layer is not loaded on this site.' && ModuleSwitch::refusal('seo', 'llms', 'invalid_value') !== null && ModuleSwitch::refusal('seo', 'llms', null) === null);
$check('no refusal reason is left without words', array_filter(['not_loaded', 'unknown_layer', 'unknown_module', 'invalid_value', 'locked'], fn ($reason) => ModuleSwitch::refusal('security', 'headers', $reason) === null) === []);

(new ReflectionMethod(Snapshot\Seo\Admin\Cli::class, 'toggle'))->invoke(null, 'schema', false);
$check('wp snap seo disable schema changes only module_schema', get_option('snapshot_seo_settings') === ['module_schema' => false] && ($state['events'][1]->message ?? '') === 'Structured data switched off from WP-CLI');

echo "last change per module\n";

$fresh($stored);
Remote::setModules('security', ['headers' => false], 'Ada at Snapshot');
(new ReflectionMethod(Cli::class, 'toggle'))->invoke(null, 'feeds', false);
$status = Remote::status();
$change = $status['security']['modules']['headers']['changed'] ?? null;
$check('a change from Snapshot OS is recorded with its actor and time', is_array($change) && $change['source'] === 'snapshot-os' && $change['actor'] === 'Ada at Snapshot' && $change['on'] === false && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $change['at']) === 1);
$check('a change from WP-CLI is recorded as wp-cli', ($status['security']['modules']['feeds']['changed']['source'] ?? '') === 'wp-cli');
$check('a module nobody changed reports changed null', array_key_exists('changed', $status['security']['modules']['cloudflare']) && $status['security']['modules']['cloudflare']['changed'] === null);
$check('the record is stored in snapshot_module_changes', isset(get_option('snapshot_module_changes')['security']['headers']));

$fresh($stored);
$form = $loaded('security');
$posted = array_filter($form, fn ($value) => $value === '1');
$posted['module_login'] = '1';
unset($posted['module_passwords']);
$_POST = ['snapshot_security' => $posted, 'snapshot_security_loaded' => $form];
$state['user'] = 7;
$check('the wp-admin module save redirects back', $refused(fn () => Shield::instance()->admin()->saveModules()) === 'redirected');
$changes = ModuleSwitch::changes('security');
$check('the wp-admin module save records each switch it changed as wp-admin with the person', ($changes['login']['source'] ?? '') === 'wp-admin' && ($changes['login']['actor'] ?? '') === 'ada' && ($changes['login']['on'] ?? null) === true && ! isset($changes['headers']) && ! isset($changes['all']));
$check('and records the switches it turned off', ($changes['passwords']['on'] ?? null) === false && ! isset($changes['twofactor']));
$check('it applies only the changed keys, through ModuleSwitch', get_option('snapshot_security_settings') == ['module_login' => true, 'module_passwords' => false] + $stored);
$event = $state['events'][0] ?? null;
$check('its log line names wp-admin and the person, with source wp-admin in the context', $event && $event->message === 'Login protection switched on from wp-admin, by ada' && $event->username === 'ada' && $event->user_id === 7 && ($context($event)['source'] ?? '') === 'wp-admin');

$fresh($stored);
$form = $loaded('security');
$posted = array_filter($form, fn ($value) => $value === '1');
unset($posted['module_feeds']);
Remote::setModules('security', ['headers' => false, 'cloudflare' => false], 'Ada at Snapshot');
$_POST = ['snapshot_security' => $posted, 'snapshot_security_loaded' => $form];
$state['user'] = 7;
$refused(fn () => Shield::instance()->admin()->saveModules());
$after = get_option('snapshot_security_settings');
$flash = $state['transients']['snapshot_sec_flash_7'] ?? [];
$check('a key changed elsewhere after the form loaded is left alone', $after['module_headers'] === false && $after['cloudflare_enabled'] === false && $after['module_feeds'] === false);
$check('and the admin sees a warning that names it', ($flash['type'] ?? '') === 'warning' && str_contains($flash['message'] ?? '', 'Left as it is: Security headers, Cloudflare.') && str_contains($flash['message'], 'Saved: Threat intelligence off.'));
$check('the change record of that key still names Snapshot OS', (ModuleSwitch::changes('security')['headers']['source'] ?? '') === 'snapshot-os');

$fresh($stored);
$_POST = ['snapshot_security' => ['security_enabled' => '1'], 'snapshot_security_loaded' => []];
$refused(fn () => Shield::instance()->admin()->saveModules());
$check('a post without the loaded values changes nothing', get_option('snapshot_security_settings') === $stored && $state['events'] === []);

$fresh($stored, ['module_llms' => true]);
$form = $loaded('seo');
$posted = array_filter($form, fn ($value) => $value === '1');
unset($posted['module_llms']);
$_POST = ['snapshot_seo' => $posted, 'snapshot_seo_loaded' => $form];
$state['user'] = 7;
$refused(fn () => Engine::instance()->admin()->saveModules());
$changes = ModuleSwitch::changes('seo');
$check('the SEO wp-admin module save records its changes too', ($changes['llms']['source'] ?? '') === 'wp-admin' && ($changes['llms']['on'] ?? null) === false && ! isset($changes['head']));
$check('and logs them through ModuleSwitch with source wp-admin', ($state['events'][0]->event ?? '') === 'seo.module_changed' && ($context($state['events'][0])['source'] ?? '') === 'wp-admin' && get_option('snapshot_seo_settings') === ['module_llms' => false]);

$fresh($stored);
$form = $loaded('seo');
Remote::setModules('seo', ['robots' => false], 'Ada');
$_POST = ['snapshot_seo' => array_filter($form, fn ($value) => $value === '1'), 'snapshot_seo_loaded' => $form];
$state['user'] = 7;
$refused(fn () => Engine::instance()->admin()->saveModules());
$check('the SEO form leaves a key changed elsewhere alone too, and names it', get_option('snapshot_seo_settings')['module_robots'] === false && str_contains($state['transients']['snapshot_seo_flash_7']['message'] ?? '', 'Left as it is: robots.txt and AI crawlers.'));
$_POST = [];

echo "status hash\n";

$fresh($stored);
$first = Remote::status();
$check('the status document has every section', array_keys($first) === ['api', 'version', 'loaded', 'features', 'security', 'seo', 'two_factor', 'scan', 'vulnerabilities', 'seo_report', 'redirects', 'hash']);
$check('each layer reports booted', $first['security']['booted'] === true && $first['seo']['booted'] === true);
$check('the hash is stable', $first['hash'] === Remote::status()['hash'] && preg_match('/^[0-9a-f]{64}$/', $first['hash']) === 1);
Remote::setModules('seo', ['robots' => false], 'Ada');
$changed = Remote::status()['hash'];
$check('the hash changes when a module changes', $changed !== $first['hash']);
Remote::setModules('seo', ['robots' => true], 'Ada');
$back = Remote::status();
$check('the hash changes again when the module comes back, since the change record moved', $back['hash'] !== $changed && $back['hash'] !== $first['hash'] && $back['hash'] === Remote::status()['hash']);
$check('the hash ignores key order', Remote::hash(['modules' => array_reverse($back['security']['modules'], true)], $back['seo'], $back['two_factor']) === $back['hash']);
$check('the hash covers two_factor.active and trust_remote', Remote::hash($back['security'], $back['seo'], ['active' => true] + $back['two_factor']) !== $back['hash'] && Remote::hash($back['security'], $back['seo'], ['trust_remote' => true] + $back['two_factor']) !== $back['hash']);
$fresh($stored + ['twofactor_trust_remote' => true]);
$check('turning trust_remote on changes the live hash', Remote::status()['hash'] !== $first['hash']);

echo "trustSignIn\n";

$fresh($stored + ['module_twofactor' => true]);
$twoFactor = Shield::instance()->twoFactor();
$check('two factor reports active, with enrollment', Remote::twoFactorActive() === true && array_intersect_key(Remote::status()['two_factor'], array_flip(['active', 'users', 'enrolled', 'pending', 'bypassed'])) === ['active' => true, 'users' => 3, 'enrolled' => 1, 'pending' => 2, 'bypassed' => false]);
$check('trustSignIn is false while the setting is off', Remote::trustSignIn(7, 'snapshot-os-sso') === false);
$check('and the next session carries no mark', ! isset($twoFactor->markSession([], 7)['snapshot_2fa']) && $state['events'] === []);

$fresh($stored + ['twofactor_trust_remote' => true]);
$check('trustSignIn is false while two factor is off', Remote::trustSignIn(7, 'snapshot-os-sso') === false && $state['events'] === []);
$check('and status reports trust_remote without two factor active', Remote::status()['two_factor']['active'] === false && Remote::status()['two_factor']['trust_remote'] === true);

$fresh($stored + ['module_twofactor' => true, 'twofactor_trust_remote' => true]);
$schemaReady = new ReflectionProperty(Schema::class, 'ready');
$schemaReady->setValue(null, false);
$check('trustSignIn is false while the security tables are not ready', Remote::trustSignIn(7, 'snapshot-os-sso') === false && ! isset(Shield::instance()->twoFactor()->markSession([], 7)['snapshot_2fa']));
$schemaReady->setValue(null, true);

$fresh($stored + ['module_twofactor' => true, 'twofactor_trust_remote' => true]);
$twoFactor = Shield::instance()->twoFactor();
$check('trustSignIn is false for an unknown user', Remote::trustSignIn(99, 'snapshot-os-sso') === false);
$check('trustSignIn is true with the setting on and two factor active', Remote::trustSignIn(7, 'snapshot-os-sso') === true);
$check('the trust is logged as twofactor.remote_trusted, severity warning', ($state['events'][0]->event ?? '') === 'twofactor.remote_trusted' && $state['events'][0]->severity === 'warning');
$check('a session for another user carries no mark', ! isset($twoFactor->markSession([], 8)['snapshot_2fa']));
$session = $twoFactor->markSession([], 7);
$check('the next session for that user carries the mark, tagged as remote', isset($session['snapshot_2fa'], $session['snapshot_2fa_remote']));
$check('the mark is used once', ! isset($twoFactor->markSession([], 7)['snapshot_2fa']));

$fresh($stored + ['module_twofactor' => true, 'twofactor_trust_remote' => true]);
$twoFactor = Shield::instance()->twoFactor();
Remote::trustSignIn(7, 'snapshot-os-sso');
foreach ($state['actions']['shutdown'] ?? [] as $callback) {
    $callback();
}
$check('the trust is cleared at shutdown, so a persistent worker cannot carry it on', ! isset($twoFactor->markSession([], 7)['snapshot_2fa']));

$fresh($stored + ['module_twofactor' => true]);
$twoFactor = Shield::instance()->twoFactor();
(new ReflectionProperty($twoFactor, 'verifiedUser'))->setValue($twoFactor, 7);
(new ReflectionProperty($twoFactor, 'verifiedRemote'))->setValue($twoFactor, true);
$session = $twoFactor->markSession([], 7);
$check('a new session made from a remote trusted session stays tagged as remote', isset($session['snapshot_2fa'], $session['snapshot_2fa_remote']));
(new ReflectionProperty($twoFactor, 'verifiedRemote'))->setValue($twoFactor, false);
$session = $twoFactor->markSession(['snapshot_2fa_remote' => 1], 7);
$check('a new session made from a locally verified session is not tagged as remote', isset($session['snapshot_2fa']) && ! isset($session['snapshot_2fa_remote']));

echo "MCP needs a session verified on this site\n";

$fresh($stored + ['module_twofactor' => true]);
$state['session'] = ['snapshot_2fa' => time()];
$check('a locally verified session can mint tokens and grant consent', Mcp::sessionVerified(7) === true && Mcp::remoteSession(7) === false);
$state['session'] = ['snapshot_2fa' => time(), 'snapshot_2fa_remote' => time()];
$check('a session marked only by remote trust cannot', Mcp::sessionVerified(7) === false && Mcp::remoteSession(7) === true);

echo "a remote-only session cannot change two factor\n";

$fresh($stored + ['module_twofactor' => true]);
$profile = Shield::instance()->profile();
$profileError = new ReflectionProperty($profile, 'error');
$state['user'] = 7;
$state['session'] = ['snapshot_2fa' => time(), 'snapshot_2fa_remote' => time()];
$check('the current session reads as remote-only', Shield::instance()->twoFactor()->remoteSession() === true);

foreach (['totp-enable', 'totp-remove', 'passkey-remove:abc', 'recovery-regenerate', 'reset'] as $op) {
    $profileError->setValue($profile, null);
    $_POST = ['snapshot_2fa_op' => $op, 'snapshot_2fa_nonce' => 'n', 'snapshot_2fa_code' => '123456'];
    $profile->handle(7);
    $check("profile {$op} is refused with the plain message", $profileError->getValue($profile) === Profile::remoteMessage());
}

$check('the refusal is logged as twofactor.remote_refused', ($state['events'][0]->event ?? '') === 'twofactor.remote_refused' && $state['events'][0]->severity === 'warning');
$check('passkey options are refused with 403', str_starts_with($refused(fn () => $profile->passkeyOptions()), 'json_error:403:'));
$check('passkey register is refused with 403', str_starts_with($refused(fn () => $profile->passkeyRegister()), 'json_error:403:'));
$check('the message says to sign in on this site with your own second factor', str_contains(Profile::remoteMessage(), 'Sign in on this site with your own second factor to change it.'));

$state['session'] = ['snapshot_2fa' => time()];
$profileError->setValue($profile, null);
$_POST = ['snapshot_2fa_op' => 'reset', 'snapshot_2fa_nonce' => 'n'];
$profile->handle(7);
$check('a locally verified session passes the remote gate', Shield::instance()->twoFactor()->remoteSession() === false && $profileError->getValue($profile) === 'You cannot reset two factor sign in for this account.');
$_POST = [];

echo "enforceSession and remote-only sessions\n";

$fresh($stored + ['module_twofactor' => true]);
$twoFactor = Shield::instance()->twoFactor();
$state['user'] = 7;
$state['session'] = ['snapshot_2fa' => time(), 'snapshot_2fa_remote' => time()];
$twoFactor->enforceSession();
$check('with trust off, a remote-only session is not verified: it is destroyed and signed out', $state['destroyed'] === ['token'] && $state['cleared'] === true && $state['user'] === 0 && ($state['events'][0]->event ?? '') === 'login.session_revoked');

$fresh($stored + ['module_twofactor' => true, 'twofactor_trust_remote' => true]);
$twoFactor = Shield::instance()->twoFactor();
$state['user'] = 7;
$state['session'] = ['snapshot_2fa' => time(), 'snapshot_2fa_remote' => time()];
$twoFactor->enforceSession();
$check('with trust on, a remote-only session stays verified and remote', $state['destroyed'] === [] && $state['user'] === 7 && (new ReflectionProperty($twoFactor, 'verifiedRemote'))->getValue($twoFactor) === true);

$fresh($stored + ['module_twofactor' => true]);
$twoFactor = Shield::instance()->twoFactor();
$state['user'] = 7;
$state['session'] = ['snapshot_2fa' => time()];
$twoFactor->enforceSession();
$check('a locally verified session is unchanged by the trust setting', $state['destroyed'] === [] && $state['user'] === 7 && (new ReflectionProperty($twoFactor, 'verifiedRemote'))->getValue($twoFactor) === false);

$fresh($stored + ['module_twofactor' => true]);
$twoFactor = Shield::instance()->twoFactor();
$state['user'] = 7;
$state['session'] = [];
$twoFactor->enforceSession();
$check('a session without the mark is still signed out', $state['destroyed'] === ['token'] && $state['user'] === 0);

echo "source in every change event\n";

$fresh($stored);
Remote::setModules('security', ['headers' => false], 'Ada');
(new ReflectionMethod(Cli::class, 'toggle'))->invoke(null, 'feeds', false);
(new ReflectionMethod(Snapshot\Seo\Admin\Cli::class, 'toggle'))->invoke(null, 'llms', false);
Remote::setModules('seo', ['robots' => false], 'Ada');
$check('module change events carry source snapshot-os and wp-cli in the context', array_map(fn ($event) => $context($event)['source'] ?? null, $state['events']) === ['snapshot-os', 'wp-cli', 'wp-cli', 'snapshot-os']);
$fresh($stored + ['module_twofactor' => true]);
Shield::instance()->settings()->save(['twofactor_trust_remote' => '1'], 'twofactor');
$check('the trust setting change carries source wp-admin', ($context($state['events'][0] ?? null)['source'] ?? '') === 'wp-admin');

echo "trust setting changes\n";

$fresh($stored + ['module_twofactor' => true]);
Shield::instance()->settings()->save(['twofactor_trust_remote' => '1'], 'twofactor');
$event = $state['events'][0] ?? null;
$check('turning twofactor_trust_remote on in wp-admin is logged as critical', $event && $event->event === 'twofactor.remote_trust_on' && $event->severity === 'critical');
Shield::instance()->settings()->save([], 'twofactor');
$check('turning it off is logged as a notice', ($state['events'][1]->event ?? '') === 'twofactor.remote_trust_off' && $state['events'][1]->severity === 'notice');
$count = count($state['events']);
Shield::instance()->settings()->save([], 'twofactor');
$check('a save that leaves it alone logs nothing for it', count($state['events']) === $count);

echo "postMeta\n";

$fresh($stored);
$state['meta'][11] = ['seo_title' => 'Own title', '_yoast_wpseo_metadesc' => 'From Yoast', '_yoast_wpseo_title' => 'Old Yoast title', 'seo_nofollow' => '1'];
$meta = Remote::postMeta(11);
$check('postMeta covers every metaKeys field, then robots_effective', array_keys($meta) === [...array_keys(Remote::metaKeys()), 'robots_effective']);
$check('an own value reports source own', $meta['title'] === ['value' => 'Own title', 'source' => 'own'] && $meta['nofollow'] === ['value' => true, 'source' => 'own']);
$check('a Yoast value reports source legacy, as Core renders it', $meta['description'] === ['value' => 'From Yoast', 'source' => 'legacy']);
$check('an empty field reports source default', $meta['canonical'] === ['value' => '', 'source' => 'default'] && $meta['key_page'] === ['value' => false, 'source' => 'default']);
$check('an unknown post gives no fields', Remote::postMeta(12) === []);
$fresh($stored, ['pt_page_index' => true]);
$state['meta'][11] = ['seo_robots' => 'noindex', 'seo_nofollow' => '1'];
$check('robots_effective reports index false and follow false for noindex and nofollow', Remote::postMeta(11)['robots_effective'] === ['index' => false, 'follow' => false]);
$fresh($stored, ['pt_page_index' => true]);
$check('robots_effective reports index and follow for an entry with no override', Remote::postMeta(11)['robots_effective'] === ['index' => true, 'follow' => true]);
$fresh($stored, ['pt_page_index' => false]);
$check('robots_effective applies the type default: a hidden content type is noindex', Remote::postMeta(11)['robots_effective'] === ['index' => false, 'follow' => true]);
$state['meta'][11] = ['seo_robots' => 'index'];
$check('an entry set to index overrides the type default', Remote::postMeta(11)['robots_effective'] === ['index' => true, 'follow' => true]);

echo "addRedirect\n";

$fresh($stored);
$table = 'wp_snapshot_seo_redirects';
$added = Remote::addRedirect('^/old/(.*)%20x$', 'https://example.test/new/?a=%20b', 301, 'regex', 'moved', 'Ada');
$check('a regex source is stored exactly, not stripped of percent octets', $added['success'] === true && ($added['redirect']['source'] ?? '') === '^/old/(.*)%20x$');
$check('the target URL keeps its percent octets', ($added['redirect']['target'] ?? '') === '/new/?a=%20b');
$check('a new redirect reports updated false and previous null', $added['updated'] === false && $added['previous'] === null);
$again = Remote::addRedirect('^/old/(.*)%20x$', '/other', 302, 'regex', '', 'Ada');
$check('the same source again reports updated true with the previous row', $again['success'] === true && $again['updated'] === true && ($again['previous']['code'] ?? 0) === 301 && ($again['redirect']['code'] ?? 0) === 302 && count($wpdb->tables[$table]) === 1);
$check('the update is logged as updated', str_contains(end($state['events'])->message ?? '', 'updated from Snapshot OS, by Ada'));
$check('a source over 191 characters is refused, not truncated', Remote::addRedirect('/'.str_repeat('a', 191), '/x', 301, 'exact', '', 'Ada') === ['success' => false, 'error' => 'source_too_long']);
$check('a source with a control character is refused', Remote::addRedirect("/a\x07b", '/x', 301, 'exact', '', 'Ada')['error'] === 'invalid_source');
$check('a javascript: target is refused', Remote::addRedirect('/a', 'javascript:alert(1)', 301, 'exact', '', 'Ada')['error'] === 'invalid_target');
$check('a protocol relative target is refused', Remote::addRedirect('/a', '//evil.test/x', 301, 'exact', '', 'Ada')['error'] === 'invalid_target');
$check('a target with a line break is refused', Remote::addRedirect('/a', "/x\nLocation: /y", 301, 'exact', '', 'Ada')['error'] === 'invalid_target');
$check('a target with user info is refused', Remote::addRedirect('/a', 'https://example.test@evil.test/', 301, 'exact', '', 'Ada')['error'] === 'invalid_target');
$check('a 410 needs no target', Remote::addRedirect('/gone', '', 410, 'exact', '', 'Ada')['success'] === true);
$spaced = Remote::addRedirect('/Old%20Page/', '/new', 301, 'exact', '', 'Ada');
$check('a non-regex source is decoded and normalized, not stripped of its percent octets', $spaced['success'] === true && ($spaced['redirect']['source'] ?? '') === '/old page');
$check('the same address given decoded finds the same redirect', Remote::addRedirect('/old page', '/newer', 301, 'exact', '', 'Ada')['updated'] === true);
$check('an encoded control character in a non-regex source is refused', Remote::addRedirect('/a%0Ab', '/x', 301, 'exact', '', 'Ada')['error'] === 'invalid_source');
$check('a prefix source is normalized the same way', (Remote::addRedirect('/Shop%2Dold/', '/shop', 301, 'prefix', '', 'Ada')['redirect']['source'] ?? '') === '/shop-old');

echo "record and overrides\n";

$fresh($stored);
$check('record logs a remote.* event as snapshot-os', Remote::record('remote.seo_meta_updated', 'SEO fields updated on 2 pages', ['post_ids' => [1, 2], 'severity' => 'critical'], 'Ada') === true && ($state['events'][0]->event ?? '') === 'remote.seo_meta_updated' && $state['events'][0]->severity === 'notice' && $state['events'][0]->username === 'snapshot-os' && str_ends_with($state['events'][0]->message, ', by Ada'));
$check('record refuses an event outside remote.*', Remote::record('login.success', 'x', [], 'Ada') === false && Remote::record('remote.', 'x', [], 'Ada') === false && count($state['events']) === 1);
$check('the remote event types are registered as notices', array_map(fn ($type) => $type['severity'], array_intersect_key(snap_security_event_types(), array_flip(['remote.seo_meta_updated', 'remote.robots_served', 'remote.llms_served']))) === ['remote.seo_meta_updated' => 'notice', 'remote.robots_served' => 'notice', 'remote.llms_served' => 'notice']);
add_filter('snap/remote/overrides', fn ($overrides) => $overrides + ['robots' => 'Snapshot OS sets robots.txt on this site.', 'nope' => 'x', 'llms' => '']);
$check('overrides keep only known areas with words', Remote::overrides() === ['robots' => 'Snapshot OS sets robots.txt on this site.'] && Remote::override('llms') === '');

echo "layer changed, critical and emergency off\n";

$fresh($stored);
$status = Remote::status();
$check('a layer nobody switched reports changed null', array_key_exists('changed', $status['security']) && $status['security']['changed'] === null && $status['seo']['changed'] === null);
$check('each layer names its critical keys', $status['security']['critical'] === ModuleSwitch::CRITICAL && $status['seo']['critical'] === ['all'] && Remote::modules('seo')['critical'] === ['all']);
$check('a layer that is not loaded still names its critical keys', (new ReflectionMethod(Remote::class, 'emptyLayer'))->invoke(null, 'security')['critical'] === ModuleSwitch::CRITICAL && (new ReflectionMethod(Remote::class, 'emptyLayer'))->invoke(null, 'security')['changed'] === null);
$before = $status['hash'];
Remote::setModules('seo', ['all' => false], 'Ada at Snapshot');
$status = Remote::status();
$master = $status['seo']['changed'];
$check('the master switch change is the layer changed record', is_array($master) && $master['source'] === 'snapshot-os' && $master['actor'] === 'Ada at Snapshot' && $master['on'] === false && ! isset($status['seo']['modules']['all']));
$check('setModules answers the layer with its changed record', (Remote::setModules('seo', ['all' => true], 'Bo')['modules']['changed']['actor'] ?? '') === 'Bo');
$status = Remote::status();
$check('the hash covers the layer changed record', Remote::hash(['changed' => null] + $status['security'], ['changed' => null] + $status['seo'], $status['two_factor']) !== $status['hash'] && $status['hash'] !== $before);
$check('two factor reports emergency_off false without the constant', $status['two_factor']['emergency_off'] === false && Remote::status()['two_factor']['bypassed'] === true);
define('SNAPSHOT_SECURITY_2FA_DISABLED', true);
$fresh($stored + ['module_twofactor' => true]);
$twoFactor = Remote::status()['two_factor'];
$check('SNAPSHOT_SECURITY_2FA_DISABLED reports emergency_off true, two factor inactive and bypassed', $twoFactor['emergency_off'] === true && $twoFactor['active'] === false && $twoFactor['bypassed'] === true);
$check('the not loaded two factor shape reports the constant too', (new ReflectionMethod(Remote::class, 'emptyTwoFactor'))->invoke(null)['emergency_off'] === true);
$check('with the emergency constant the twofactor module reports booted false and on false', Remote::status()['security']['modules']['twofactor']['booted'] === false && Remote::status()['security']['modules']['twofactor']['on'] === false);

echo "\n";

if ($failures !== []) {
    echo count($failures).' of '.($passed + count($failures))." remote checks failed.\n";

    exit(1);
}

echo "All {$passed} remote checks pass: setModules changes only the listed keys and records who changed them, the wp-admin forms apply only what the person changed and leave keys changed elsewhere alone, each module reports booted, switch-offs send one summary alert per call and the SEO master off is critical, WP-CLI shares its path and errors on every refusal, each layer reports its master switch change and critical keys, the status hash covers modules, layer changes and two factor, emergency off is reported, trustSignIn is gated and marks its sessions as remote, a remote-only session cannot change two factor and is signed out while trust is off, every change event carries its source, postMeta reports robots_effective and addRedirect answers as specified.\n";
