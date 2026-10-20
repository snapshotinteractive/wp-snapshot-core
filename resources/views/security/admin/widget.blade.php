@php
  $modes = ['enforce' => __('Enforce', 'snapshot-core'), 'learning' => __('Learning', 'snapshot-core'), 'off' => __('Disabled', 'snapshot-core')];
  $statusIcon = ['ok' => 'yes-alt', 'warning' => 'warning', 'critical' => 'dismiss'];
  $types = snap_security_event_types();
@endphp

<div class="snap-widget">
  <div class="snap-status is-{{ $status['level'] }}">
    <span class="dashicons dashicons-{{ $statusIcon[$status['level']] }}" aria-hidden="true"></span>
    <span>
      <strong>{{ $status['label'] }}</strong>
      <span class="snap-status-detail">
        @if ($status['level'] === 'ok')
          {{ __('No open issues', 'snapshot-core') }}
        @else
          {{ sprintf(__('%1$d critical, %2$d warnings', 'snapshot-core'), $status['critical'], $status['warning']) }}
        @endif
      </span>
    </span>
  </div>

  <dl class="snap-widget-stats">
    <div>
      <dt>{{ __('Two-factor', 'snapshot-core') }}</dt>
      <dd>{{ $enrollment['enrolled'] }}<span>/{{ $enrollment['users'] }}</span></dd>
    </div>
    <div>
      <dt>{{ __('Critical, 24h', 'snapshot-core') }}</dt>
      <dd>{{ number_format_i18n($day['critical']) }}</dd>
    </div>
    <div>
      <dt>{{ __('Active blocks', 'snapshot-core') }}</dt>
      <dd>{{ number_format_i18n($lockouts + $manual) }}</dd>
    </div>
    <div>
      <dt>{{ __('Vulnerabilities', 'snapshot-core') }}</dt>
      <dd>{{ $vulnerabilities }}</dd>
    </div>
  </dl>

  @if ($status['issues'] !== [])
    <h3>{{ __('Open issues', 'snapshot-core') }}</h3>
    <ul class="snap-issues">
      @foreach (array_slice($status['issues'], 0, 4) as $issue)
        <li class="is-{{ $issue['level'] }}">
          <span class="dashicons dashicons-{{ $issue['level'] === 'critical' ? 'dismiss' : 'warning' }}" aria-hidden="true"></span>
          <span class="screen-reader-text">{{ $issue['level'] === 'critical' ? __('Critical:', 'snapshot-core') : __('Warning:', 'snapshot-core') }}</span>
          <a href="{!! esc_url($issue['url']) !!}">{{ $issue['text'] }}</a>
        </li>
      @endforeach
    </ul>
  @endif

  @if ($recent !== [])
    <h3>{{ __('Recent critical events', 'snapshot-core') }}</h3>
    <ul class="snap-widget-events">
      @foreach ($recent as $row)
        <li>
          <span>{{ $types[$row->event]['label'] ?? $row->event }}</span>
          <time datetime="{{ $row->created_at }}Z">{{ sprintf(__('%s ago', 'snapshot-core'), human_time_diff(strtotime($row->created_at.' UTC'))) }}</time>
        </li>
      @endforeach
    </ul>
  @endif

  <ul class="snap-widget-meta">
    <li>{{ __('Firewall:', 'snapshot-core') }} <strong>{{ $modes[$firewallMode] ?? $firewallMode }}</strong></li>
    <li>{{ __('Last scan:', 'snapshot-core') }} <strong>{{ ! empty($scan['at']) ? sprintf(__('%s ago', 'snapshot-core'), human_time_diff($scan['at'])) : __('Never', 'snapshot-core') }}</strong></li>
  </ul>

  <div class="snap-widget-foot">
    <a class="button button-primary" href="{!! esc_url($base) !!}">{{ __('Open Security', 'snapshot-core') }}</a>
    <a class="button" href="{!! esc_url(wp_nonce_url(admin_url('admin-post.php?action=snapshot_security_run&task=scan'), 'snapshot_security_run')) !!}">{{ __('Run file scan', 'snapshot-core') }}</a>
  </div>
</div>
