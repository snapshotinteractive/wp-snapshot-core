<?php

namespace Snapshot\Security;

class Alerts
{
    public const DIGEST_OPTION = 'snapshot_security_digest_at';

    private const ALERT_WINDOW = 15 * MINUTE_IN_SECONDS;

    private const ALERTS_PER_HOUR = 12;

    private const VOLUME_EVENTS = ['firewall.auto_block', 'login.ip_blocked'];

    private const DIGEST_LIMIT = 500;

    private bool $sending = false;

    public function __construct(
        private Settings $settings,
        private Throttle $throttle,
        private EventLog $log,
    ) {}

    public function boot(): void
    {
        add_action('snap/security/event', [$this, 'onEvent']);
    }

    public function recipients(): array
    {
        $list = array_values(array_filter(
            array_map('trim', explode(',', $this->settings->string('alert_recipients'))),
            'is_email'
        ));

        return $list !== [] ? $list : array_filter([get_option('admin_email')], 'is_email');
    }

    public function onEvent(object $event): void
    {
        if ($this->sending || $event->severity !== 'critical') {
            return;
        }

        $key = $event->event.':'.Ip::bucket((string) $event->ip).':'.md5((string) $event->message);

        if (! $this->throttle->once("alert:{$key}", self::ALERT_WINDOW)) {
            return;
        }

        if (in_array($event->event, self::VOLUME_EVENTS, true) && $this->throttle->hit('alert:hourly', HOUR_IN_SECONDS) > self::ALERTS_PER_HOUR) {
            return;
        }

        $label = snap_security_event_types()[$event->event]['label'] ?? $event->event;

        $this->send(
            sprintf('Security alert: %s', $label),
            implode("\n", array_filter([
                $label,
                '',
                $event->message,
                '',
                'When: '.wp_date('j F Y, H:i T', strtotime($event->created_at.' UTC')),
                $event->ip !== '' ? "Address: {$event->ip}" : null,
                $event->username !== '' ? "Account: {$event->username}" : null,
                '',
                'Review: '.admin_url('admin.php?page=snapshot-security&tab=events&severity=critical'),
            ], fn ($line) => $line !== null))
        );
    }

    public function digest(bool $force = false): bool
    {
        if (! $force && ! $this->settings->bool('alert_digest')) {
            return false;
        }

        $since = (string) get_option(self::DIGEST_OPTION, gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS));
        $now = gmdate('Y-m-d H:i:s');
        $events = [...$this->log->since($since, ['critical'], self::DIGEST_LIMIT), ...$this->log->since($since, ['warning'], self::DIGEST_LIMIT)];
        $stats = $this->log->stats($since);

        if ($events === [] && ! $force) {
            update_option(self::DIGEST_OPTION, $now, false);

            return false;
        }

        $types = snap_security_event_types();
        $grouped = [];

        foreach ($events as $event) {
            $grouped[$event->event][] = $event;
        }

        $lines = [
            sprintf('Security summary for %s since %s.', home_url(), wp_date('j F Y, H:i T', strtotime($since.' UTC'))),
            '',
            sprintf('Critical: %d   Warning: %d   Notice: %d   Info: %d', $stats['critical'], $stats['warning'], $stats['notice'], $stats['info']),
            '',
        ];

        foreach ($grouped as $key => $items) {
            $lines[] = sprintf('%s (%d)', $types[$key]['label'] ?? $key, count($items));

            foreach (array_slice($items, 0, 5) as $item) {
                $lines[] = sprintf('  %s  %s  %s', wp_date('j M H:i', strtotime($item->created_at.' UTC')), $item->ip, $item->message);
            }

            if (count($items) > 5) {
                $lines[] = sprintf('  and %d more', count($items) - 5);
            }

            $lines[] = '';
        }

        $lines[] = 'Review: '.admin_url('admin.php?page=snapshot-security&tab=events');

        if (! $this->send('Daily security summary', implode("\n", $lines))) {
            return false;
        }

        update_option(self::DIGEST_OPTION, $now, false);

        return true;
    }

    private function send(string $subject, string $body): bool
    {
        $recipients = $this->recipients();

        if ($recipients === []) {
            return false;
        }

        $this->sending = true;
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $sent = (bool) wp_mail($recipients, "[{$site}] {$subject}", $body."\n\n".$site."\n");
        $this->sending = false;

        return $sent;
    }
}
