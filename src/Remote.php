<?php

namespace Snapshot;

use Snapshot\Security\Schema;
use Snapshot\Security\Shield;
use Snapshot\Security\TwoFactor\TwoFactor;
use Snapshot\Seo\Context;
use Snapshot\Seo\Engine;
use Snapshot\Seo\Meta;
use Snapshot\Seo\Redirects;
use Snapshot\System\Diagnostics;
use Throwable;
use WP_Post;

class Remote
{
    public const API = 1;

    public const SOURCE = 'snapshot-os';

    public const REDIRECT_CODES = [301, 302, 307, 308, 410];

    public const OVERRIDE_AREAS = ['robots', 'llms', 'schema', 'headers', 'seo_meta'];

    public const SOURCE_MAX = 191;

    public static function available(): bool
    {
        return Core::loaded();
    }

    public static function status(): array
    {
        $loaded = self::available();
        $security = $loaded ? self::guard(fn () => self::modules('security'), self::emptyLayer('security')) : self::emptyLayer('security');
        $seo = $loaded ? self::guard(fn () => self::modules('seo'), self::emptyLayer('seo')) : self::emptyLayer('seo');
        $twoFactor = $loaded ? self::guard(fn () => self::twoFactor(), self::emptyTwoFactor()) : self::emptyTwoFactor();

        return [
            'api' => self::API,
            'version' => Core::version(),
            'loaded' => $loaded,
            'features' => $loaded ? array_values(array_filter(Core::FEATURES, [Core::class, 'enabled'])) : [],
            'security' => $security,
            'seo' => $seo,
            'two_factor' => $twoFactor,
            'scan' => $loaded ? self::guard(fn () => self::scan(), self::emptyScan()) : self::emptyScan(),
            'vulnerabilities' => $loaded ? self::guard(fn () => Shield::instance()->status()->vulnerabilities(['name', 'version', 'title', 'fixed_in', 'severity']), []) : [],
            'seo_report' => $loaded ? self::guard(fn () => self::seoReport(), self::emptySeoReport()) : self::emptySeoReport(),
            'redirects' => $loaded ? self::guard(fn () => self::redirectCounts(), ['total' => 0, 'not_found' => 0]) : ['total' => 0, 'not_found' => 0],
            'hash' => self::hash($security, $seo, $twoFactor),
        ];
    }

    public static function modules(string $layer): array
    {
        if (! ModuleSwitch::loaded($layer)) {
            return self::emptyLayer($layer);
        }

        $instance = $layer === 'security' ? Shield::instance() : Engine::instance();
        $settings = $instance->settings();
        $modules = $instance->modules();
        $booted = $instance->booted();
        $changes = ModuleSwitch::changes($layer);
        $map = [];

        foreach ($modules->registry() as $key => $module) {
            $moduleBooted = $instance->moduleBooted((string) $key);
            $map[$key] = [
                'label' => (string) $module['label'],
                'on' => $moduleBooted && $modules->on($key),
                'booted' => $moduleBooted,
                'enabled' => $settings->bool($module['setting']),
                'locked' => $settings->locked($module['setting']),
            ];

            if ($layer === 'seo') {
                $map[$key]['paused_by'] = $map[$key]['enabled'] ? $modules->pausedBy($key) : [];
            }

            if ($layer === 'security' && $key === 'headers') {
                $map[$key]['details'] = [
                    'csp' => $map[$key]['on'] && $settings->string('headers_csp') !== '',
                    'hsts' => $map[$key]['on'] && $settings->bool('headers_hsts'),
                ];
            }

            $map[$key]['changed'] = $changes[$key] ?? null;
        }

        return [
            'enabled' => $modules->master(),
            'locked' => $settings->locked($modules::MASTER),
            'booted' => $booted,
            'changed' => $changes['all'] ?? null,
            'critical' => ModuleSwitch::criticalKeys($layer),
            'modules' => $map,
        ];
    }

    public static function hash(array $security, array $seo, array $twoFactor = []): string
    {
        $maps = [
            'security' => self::sorted((array) ($security['modules'] ?? [])),
            'seo' => self::sorted((array) ($seo['modules'] ?? [])),
            'changed' => [
                'security' => is_array($security['changed'] ?? null) ? self::sorted($security['changed']) : null,
                'seo' => is_array($seo['changed'] ?? null) ? self::sorted($seo['changed']) : null,
            ],
            'two_factor' => ['active' => (bool) ($twoFactor['active'] ?? false), 'trust_remote' => (bool) ($twoFactor['trust_remote'] ?? false)],
        ];

        return hash('sha256', (string) json_encode($maps, JSON_UNESCAPED_SLASHES));
    }

    public static function setModules(string $layer, array $changes, string $actor): array
    {
        if (! self::available()) {
            return ['changed' => [], 'refused' => array_fill_keys(array_map('strval', array_keys($changes)), 'not_loaded'), 'modules' => self::emptyLayer($layer)];
        }

        $result = ModuleSwitch::apply($layer, $changes, self::SOURCE, self::actor($actor));

        return $result + ['modules' => self::modules($layer)];
    }

    public static function twoFactorActive(): bool
    {
        return self::available() && ModuleSwitch::loaded('security') && ! TwoFactor::bypassed();
    }

    public static function trustSignIn(int $userId, string $source): bool
    {
        if ($userId <= 0 || ! self::twoFactorActive() || ! Schema::ready()) {
            return false;
        }

        $shield = Shield::instance();

        if (! $shield->settings()->bool('twofactor_trust_remote')) {
            return false;
        }

        $user = get_userdata($userId);

        if (! $user) {
            return false;
        }

        $source = substr((string) preg_replace('/[^a-z0-9_-]/', '', strtolower($source)), 0, 40) ?: self::SOURCE;
        $shield->twoFactor()->trust($userId);
        $shield->log()->log('twofactor.remote_trusted', sprintf('Two factor sign in for %s accepted from Snapshot OS (%s)', $user->user_login, $source), ['source' => $source, 'target' => $userId], $userId);

        return true;
    }

    public static function metaKeys(): array
    {
        return class_exists(Meta::class) ? Meta::KEYS : [];
    }

    public static function postMeta(int $postId): array
    {
        $post = $postId > 0 && self::available() && ModuleSwitch::loaded('seo') ? get_post($postId) : null;

        if (! $post) {
            return [];
        }

        $engine = Engine::instance();
        $meta = $engine->meta();
        $fields = [];

        foreach (array_keys(self::metaKeys()) as $field) {
            $fields[$field] = $meta->resolvePost($postId, $field);
        }

        if ($post instanceof WP_Post) {
            try {
                $robots = $engine->resolver()->robots(Context::forPost($post));
                $fields['robots_effective'] = ['index' => (bool) $robots['index'], 'follow' => (bool) $robots['follow']];
            } catch (Throwable) {
            }
        }

        return $fields;
    }

    public static function record(string $event, string $message, array $context, string $actor): bool
    {
        if (! self::available() || ! ModuleSwitch::loaded('security') || ! Schema::ready() || preg_match('/^remote\.[a-z0-9_]{1,57}$/', $event) !== 1) {
            return false;
        }

        $actor = self::actor($actor);
        $message = mb_substr(trim(sanitize_text_field($message)), 0, 200);
        unset($context['severity']);

        return Shield::instance()->log()->log(
            $event,
            ($message !== '' ? $message : $event).($actor !== '' ? ", by {$actor}" : ''),
            $context + ['source' => self::SOURCE, 'actor' => $actor],
            0,
            self::SOURCE
        ) > 0;
    }

    public static function overrides(): array
    {
        $given = apply_filters('snap/remote/overrides', []);
        $overrides = [];

        foreach (is_array($given) ? $given : [] as $area => $sentence) {
            if (in_array($area, self::OVERRIDE_AREAS, true) && is_string($sentence) && trim($sentence) !== '') {
                $overrides[$area] = mb_substr(trim(sanitize_text_field($sentence)), 0, 300);
            }
        }

        return $overrides;
    }

    public static function override(string $area): string
    {
        return self::overrides()[$area] ?? '';
    }

    public static function redirects(int $page = 1, int $perPage = 50, string $search = ''): array
    {
        $empty = ['items' => [], 'total' => 0, 'counts' => ['redirects' => 0, 'missing' => 0]];

        if (! self::seoTables()) {
            return $empty + ['error' => 'seo_not_loaded'];
        }

        $redirects = Engine::instance()->redirects();
        $result = $redirects->query(['search' => sanitize_text_field($search)], max(1, $page), self::perPage($perPage));

        return [
            'items' => array_map([self::class, 'redirectRow'], (array) $result['rows']),
            'total' => (int) $result['total'],
            'counts' => $redirects->counts(),
        ];
    }

    public static function addRedirect(string $source, string $target, int $code, string $match, string $note, string $actor): array
    {
        if (! self::seoTables()) {
            return ['success' => false, 'error' => 'seo_not_loaded'];
        }

        if (! in_array($code, self::REDIRECT_CODES, true)) {
            return ['success' => false, 'error' => 'invalid_code'];
        }

        if (! array_key_exists($match, Redirects::MATCH)) {
            return ['success' => false, 'error' => 'invalid_match'];
        }

        $source = $match === 'regex' ? trim($source) : rawurldecode($source);

        if (trim($source) === '' || self::control($source)) {
            return ['success' => false, 'error' => 'invalid_source'];
        }

        $source = $match === 'regex' ? $source : Redirects::normalize($source);

        if (mb_strlen(Redirects::sourceKey($source, $match)) > self::SOURCE_MAX) {
            return ['success' => false, 'error' => 'source_too_long'];
        }

        $target = trim($target);

        if ($code !== 410 && $target !== '' && ! self::validTarget($target)) {
            return ['success' => false, 'error' => 'invalid_target'];
        }

        $engine = Engine::instance();
        $redirects = $engine->redirects();
        $previous = $redirects->existing($source, $match);
        $result = $redirects->add($source, $code === 410 ? '' : $target, $code, $match, self::SOURCE, 0, sanitize_text_field($note));

        if (! $result['ok']) {
            return ['success' => false, 'error' => (string) $result['message']];
        }

        $actor = self::actor($actor);
        self::log('seo.redirect_changed', sprintf('Redirect %s %s from Snapshot OS', $source, $previous ? 'updated' : 'added').($actor !== '' ? ", by {$actor}" : ''), ['id' => (int) $result['id'], 'actor' => $actor]);
        $engine->admin()->forgetStatus();
        $row = $redirects->find((int) $result['id']);

        return [
            'success' => true,
            'updated' => $previous !== null,
            'previous' => $previous ? self::redirectRow($previous) : null,
            'redirect' => $row ? self::redirectRow($row) : ['id' => (int) $result['id']],
        ];
    }

    public static function removeRedirects(array $ids, string $actor): int
    {
        if (! self::seoTables()) {
            return 0;
        }

        $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));

        if ($ids === []) {
            return 0;
        }

        $engine = Engine::instance();
        $removed = $engine->redirects()->remove($ids);
        $actor = self::actor($actor);

        if ($removed > 0) {
            self::log('seo.redirect_changed', sprintf('%d redirects deleted from Snapshot OS', $removed).($actor !== '' ? ", by {$actor}" : ''), ['ids' => $ids, 'actor' => $actor]);
            $engine->admin()->forgetStatus();
        }

        return $removed;
    }

    public static function notFound(int $page = 1, int $perPage = 50, bool $suggest = true): array
    {
        if (! self::seoTables()) {
            return ['items' => [], 'total' => 0, 'error' => 'seo_not_loaded'];
        }

        $redirects = Engine::instance()->redirects();
        $result = $redirects->missing([], max(1, $page), self::perPage($perPage));

        return [
            'items' => array_map(fn ($row) => [
                'id' => (int) $row['id'],
                'path' => (string) $row['path'],
                'hits' => (int) $row['hits'],
                'referrer' => (string) $row['referrer'],
                'bot' => (bool) $row['bot'],
                'first_seen' => (string) $row['first_seen'],
                'last_seen' => (string) $row['last_seen'],
                'suggest' => $suggest ? $redirects->suggest((string) $row['path']) : '',
            ], (array) $result['rows']),
            'total' => (int) $result['total'],
        ];
    }

    public static function submitIndexNow(array $urls): array
    {
        if (! self::available() || ! ModuleSwitch::loaded('seo')) {
            return ['queued' => 0, 'skipped_reason' => 'seo_not_loaded'];
        }

        $engine = Engine::instance();

        if (! $engine->modules()->on('indexnow')) {
            return ['queued' => 0, 'skipped_reason' => 'module_off'];
        }

        if (! $engine->indexNow()->allowed()) {
            return ['queued' => 0, 'skipped_reason' => 'not_production'];
        }

        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $same = array_values(array_unique(array_filter(
            array_map(fn ($url) => is_string($url) ? trim($url) : '', $urls),
            fn ($url) => $url !== '' && strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === $host && str_starts_with($url, home_url())
        )));

        if ($same === []) {
            return ['queued' => 0, 'skipped_reason' => 'no_site_urls'];
        }

        $engine->indexNow()->queue($same);

        return ['queued' => count($same)];
    }

    public static function diagnostics(): array
    {
        if (! self::available() || ! class_exists(Diagnostics::class)) {
            return ['groups' => []];
        }

        return function_exists('snap_system') ? snap_system()->diagnostics()->structured() : (new Diagnostics)->structured();
    }

    private static function twoFactor(): array
    {
        if (! ModuleSwitch::loaded('security')) {
            return self::emptyTwoFactor();
        }

        $shield = Shield::instance();
        $enrollment = $shield->status()->twoFactor();

        return [
            'active' => self::twoFactorActive(),
            'emergency_off' => TwoFactor::emergencyOff(),
            'trust_remote' => $shield->settings()->bool('twofactor_trust_remote'),
            'users' => (int) ($enrollment['users'] ?? 0),
            'enrolled' => (int) ($enrollment['enrolled'] ?? 0),
            'pending' => (int) ($enrollment['pending'] ?? 0),
            'bypassed' => (bool) ($enrollment['bypassed'] ?? true),
        ];
    }

    private static function scan(): array
    {
        $scan = Shield::instance()->status()->scan();

        return ['at' => $scan['at'], 'counts' => (array) ($scan['counts'] ?? []), 'new' => (int) ($scan['new'] ?? 0)];
    }

    private static function seoReport(): array
    {
        if (! ModuleSwitch::loaded('seo')) {
            return self::emptySeoReport();
        }

        $engine = Engine::instance();
        $status = $engine->admin()->status();
        $report = $engine->audit()->report();

        return [
            'at' => isset($report['at']) ? gmdate('c', (int) $report['at']) : null,
            'score' => isset($status['score']) ? (int) $status['score'] : null,
            'failing' => array_values(array_map(
                fn ($check) => ['key' => (string) $check['key'], 'label' => (string) $check['label']],
                array_filter((array) ($status['checks'] ?? []), fn ($check) => ($check['status'] ?? '') === 'fail')
            )),
        ];
    }

    private static function redirectCounts(): array
    {
        if (! self::seoTables()) {
            return ['total' => 0, 'not_found' => 0];
        }

        $counts = Engine::instance()->redirects()->counts();

        return ['total' => (int) $counts['redirects'], 'not_found' => (int) $counts['missing']];
    }

    private static function redirectRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'source' => (string) $row['source'],
            'match' => (string) $row['match_type'],
            'target' => (string) $row['target'],
            'code' => (int) $row['code'],
            'enabled' => (bool) $row['enabled'],
            'origin' => (string) $row['origin'],
            'note' => (string) $row['note'],
            'hits' => (int) $row['hits'],
            'last_hit' => $row['last_hit'] ?: null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    private static function control(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private static function validTarget(string $target): bool
    {
        if (self::control($target) || preg_match('/\s/', $target) === 1) {
            return false;
        }

        if (str_starts_with($target, '/')) {
            return ! str_starts_with($target, '//');
        }

        return preg_match('#^https?://[^/?\#@]+(?:[/?\#]|$)#i', $target) === 1 && (string) wp_parse_url($target, PHP_URL_HOST) !== '';
    }

    private static function seoTables(): bool
    {
        return self::available() && ModuleSwitch::loaded('seo') && Engine::instance()->schema()->ensure();
    }

    private static function log(string $event, string $message, array $context): void
    {
        if (ModuleSwitch::loaded('security')) {
            Shield::instance()->log()->log($event, $message, $context + ['source' => self::SOURCE], 0, self::SOURCE);
        }
    }

    private static function actor(string $actor): string
    {
        return mb_substr(trim(sanitize_text_field($actor)), 0, 100);
    }

    private static function perPage(int $perPage): int
    {
        return min(100, max(1, $perPage));
    }

    private static function sorted(array $map): array
    {
        ksort($map);

        return array_map(fn ($value) => is_array($value) ? self::sorted($value) : $value, $map);
    }

    private static function guard(callable $build, array $fallback): array
    {
        try {
            return $build();
        } catch (Throwable) {
            return $fallback;
        }
    }

    private static function emptyLayer(string $layer): array
    {
        return ['enabled' => false, 'locked' => false, 'booted' => false, 'changed' => null, 'critical' => ModuleSwitch::criticalKeys($layer), 'modules' => []];
    }

    private static function emptyTwoFactor(): array
    {
        return ['active' => false, 'emergency_off' => defined('SNAPSHOT_SECURITY_2FA_DISABLED') && SNAPSHOT_SECURITY_2FA_DISABLED, 'trust_remote' => false, 'users' => 0, 'enrolled' => 0, 'pending' => 0, 'bypassed' => true];
    }

    private static function emptyScan(): array
    {
        return ['at' => null, 'counts' => [], 'new' => 0];
    }

    private static function emptySeoReport(): array
    {
        return ['at' => null, 'score' => null, 'failing' => []];
    }
}
