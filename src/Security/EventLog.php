<?php

namespace Snapshot\Security;

class EventLog
{
    public function __construct(private Ip $ip) {}

    public function table(): string
    {
        return Schema::table('events');
    }

    public function log(string $event, string $message = '', array $context = [], ?int $userId = null, ?string $username = null, ?string $ip = null): int
    {
        global $wpdb;

        if (! Schema::ready()) {
            return 0;
        }

        $types = snap_security_event_types();
        $severity = $context['severity'] ?? ($types[$event]['severity'] ?? 'notice');
        unset($context['severity']);

        if ($userId === null && function_exists('get_current_user_id') && did_action('set_current_user')) {
            $userId = get_current_user_id();
        }

        if ($username === null && $userId) {
            $user = get_userdata($userId);
            $username = $user ? $user->user_login : '';
        }

        $row = [
            'created_at' => gmdate('Y-m-d H:i:s'),
            'event' => mb_substr($event, 0, 64),
            'severity' => $severity,
            'ip' => $ip ?? $this->ip->client(),
            'user_id' => (int) $userId,
            'username' => mb_substr((string) $username, 0, 60),
            'message' => mb_substr($message !== '' ? $message : ($types[$event]['label'] ?? $event), 0, 255),
            'context' => $context === [] ? null : wp_json_encode($this->trim($context)),
        ];

        $wpdb->insert($this->table(), $row);
        $row['id'] = (int) $wpdb->insert_id;

        do_action('snap/security/event', (object) $row);

        return $row['id'];
    }

    public function query(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        global $wpdb;

        [$where, $params] = $this->where($filters);
        $table = $this->table();
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $wpdb->get_var($params === []
            ? "SELECT COUNT(*) FROM {$table} {$where}"
            : $wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where}", ...$params));

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            ...[...$params, $perPage, $offset]
        ));

        return ['rows' => $rows, 'total' => $total, 'pages' => (int) max(1, ceil($total / $perPage))];
    }

    public function people(): array
    {
        global $wpdb;

        return (array) $wpdb->get_results("SELECT user_id, username, COUNT(*) AS total FROM {$this->table()} WHERE user_id > 0 GROUP BY user_id, username ORDER BY total DESC LIMIT 200");
    }

    public function since(string $gmt, array $severities = [], int $limit = 500): array
    {
        global $wpdb;

        $params = [$gmt];
        $severityClause = '';

        if ($severities !== []) {
            $severityClause = ' AND severity IN ('.implode(',', array_fill(0, count($severities), '%s')).')';
            $params = [...$params, ...$severities];
        }

        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE created_at > %s{$severityClause} ORDER BY id ASC LIMIT %d",
            ...[...$params, $limit]
        ));
    }

    public function stats(string $gmt): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT severity, COUNT(*) AS total FROM {$this->table()} WHERE created_at > %s GROUP BY severity",
            $gmt
        ));

        $stats = array_fill_keys(array_keys(snap_security_severities()), 0);

        foreach ($rows as $row) {
            $stats[$row->severity] = (int) $row->total;
        }

        return $stats;
    }

    public function prune(int $days): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE created_at < %s",
            gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS))
        ));
    }

    private function where(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (! empty($filters['severity']) && array_key_exists($filters['severity'], snap_security_severities())) {
            $clauses[] = 'severity = %s';
            $params[] = $filters['severity'];
        }

        if (! empty($filters['event'])) {
            $clauses[] = 'event = %s';
            $params[] = (string) $filters['event'];
        }

        if (! empty($filters['ip'])) {
            $clauses[] = 'ip = %s';
            $params[] = (string) $filters['ip'];
        }

        if (! empty($filters['user'])) {
            $clauses[] = 'username = %s';
            $params[] = (string) $filters['user'];
        }

        if (! empty($filters['user_id'])) {
            $clauses[] = 'user_id = %d';
            $params[] = (int) $filters['user_id'];
        }

        if (! empty($filters['category'])) {
            $clauses[] = 'event LIKE %s';
            $params[] = $GLOBALS['wpdb']->esc_like((string) $filters['category']).'.%';
        }

        if (! empty($filters['people'])) {
            $clauses[] = 'user_id > 0';
        }

        if (! empty($filters['from'])) {
            $clauses[] = 'created_at >= %s';
            $params[] = (string) $filters['from'];
        }

        if (! empty($filters['to'])) {
            $clauses[] = 'created_at <= %s';
            $params[] = (string) $filters['to'];
        }

        if (! empty($filters['search'])) {
            $like = '%'.$GLOBALS['wpdb']->esc_like((string) $filters['search']).'%';
            $clauses[] = '(message LIKE %s OR event LIKE %s OR username LIKE %s)';
            array_push($params, $like, $like, $like);
        }

        return [$clauses === [] ? '' : 'WHERE '.implode(' AND ', $clauses), $params];
    }

    private function trim(array $context): array
    {
        array_walk_recursive($context, function (&$value) {
            if (is_string($value) && mb_strlen($value) > 500) {
                $value = mb_substr($value, 0, 500).'…';
            }
        });

        return $context;
    }
}
