@php
  $checkIcon = ['ok' => 'yes-alt', 'warn' => 'warning', 'fail' => 'dismiss'];
  $checkText = ['ok' => __('Pass', 'snapshot-core'), 'warn' => __('Warning', 'snapshot-core'), 'fail' => __('Fail', 'snapshot-core')];
  $passed = count(array_filter($checks, fn ($check) => $check['status'] === 'ok'));
  $coverage = $enrollment['users'] > 0 ? (int) round($enrollment['enrolled'] / $enrollment['users'] * 100) : 100;
  $scanCritical = (int) ($scan['counts']['critical'] ?? 0);
  $tasks = [
      'scan' => __('Run file integrity scan', 'snapshot-core'),
      'vulns' => __('Check for vulnerabilities', 'snapshot-core'),
      'feeds' => __('Update threat intelligence', 'snapshot-core'),
      'digest' => __('Send security digest', 'snapshot-core'),
  ];
@endphp

<div class="snap-grid">
  <section class="snap-panel snap-panel-wide" aria-labelledby="snap-issues">
    <h2 id="snap-issues">{{ __('Open issues', 'snapshot-core') }}</h2>
    @if ($status['issues'] === [])
      <p class="snap-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> {{ __('No open issues. All checks passed.', 'snapshot-core') }}</p>
    @else
      <ul class="snap-issues">
        @foreach ($status['issues'] as $issue)
          <li class="is-{{ $issue['level'] }}">
            <span class="dashicons dashicons-{{ $issue['level'] === 'critical' ? 'dismiss' : 'warning' }}" aria-hidden="true"></span>
            <span class="screen-reader-text">{{ $issue['level'] === 'critical' ? __('Critical:', 'snapshot-core') : __('Warning:', 'snapshot-core') }}</span>
            <a href="{!! esc_url($issue['url']) !!}">{{ $issue['text'] }}</a>
          </li>
        @endforeach
      </ul>
    @endif
  </section>

  <section class="snap-panel" aria-labelledby="snap-actions">
    <h2 id="snap-actions">{{ __('Actions', 'snapshot-core') }}</h2>
    <div class="snap-action-list">
      @foreach ($tasks as $task => $label)
        <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
          {!! $runNonce !!}
          <input type="hidden" name="action" value="snapshot_security_run">
          <input type="hidden" name="task" value="{{ $task }}">
          <button type="submit" @class(['button', 'button-primary' => $loop->first])>{{ $label }}</button>
        </form>
      @endforeach
    </div>
  </section>
</div>

<div class="snap-kpis">
  <a class="snap-kpi" href="{!! esc_url(admin_url('users.php')) !!}">
    <span class="snap-kpi-label">{{ __('Two-factor coverage', 'snapshot-core') }}</span>
    <span class="snap-kpi-value">{{ $coverage }}%</span>
    <span class="snap-kpi-note">{{ sprintf(_n('%1$d of %2$d account enrolled', '%1$d of %2$d accounts enrolled', $enrollment['users'], 'snapshot-core'), $enrollment['enrolled'], $enrollment['users']) }}</span>
    <span class="snap-meter" aria-hidden="true"><span style="width: {{ $coverage }}%"></span></span>
  </a>

  <a class="snap-kpi" href="{!! esc_url(add_query_arg('tab', 'events', $base)) !!}">
    <span class="snap-kpi-label">{{ __('Events, last 24 hours', 'snapshot-core') }}</span>
    <span class="snap-kpi-value">{{ number_format_i18n($day['critical'] + $day['warning'] + $day['notice']) }}</span>
    <span class="snap-kpi-note">{{ sprintf(__('%1$d critical, %2$d warning, %3$d notice', 'snapshot-core'), $day['critical'], $day['warning'], $day['notice']) }}</span>
  </a>

  <a class="snap-kpi" href="{!! esc_url(add_query_arg('tab', 'blocks', $base)) !!}">
    <span class="snap-kpi-label">{{ __('Active blocks', 'snapshot-core') }}</span>
    <span class="snap-kpi-value">{{ number_format_i18n($lockouts + $manual) }}</span>
    <span class="snap-kpi-note">{{ sprintf(__('%1$d lockouts, %2$s feed entries', 'snapshot-core'), $lockouts, number_format_i18n($feedEntries)) }}</span>
  </a>

  <a @class(['snap-kpi', 'is-critical' => $scanCritical > 0]) href="{!! esc_url(add_query_arg('tab', 'scans', $base)) !!}">
    <span class="snap-kpi-label">{{ __('File integrity', 'snapshot-core') }}</span>
    @if (! empty($scan['at']))
      <span class="snap-kpi-value">{{ $scanCritical }}</span>
      <span class="snap-kpi-note">{{ sprintf(__('Critical findings. Scanned %s ago.', 'snapshot-core'), human_time_diff($scan['at'])) }}</span>
    @else
      <span class="snap-kpi-value">{{ __('Pending', 'snapshot-core') }}</span>
      <span class="snap-kpi-note">{{ __('First scan runs automatically.', 'snapshot-core') }}</span>
    @endif
  </a>

  <a @class(['snap-kpi', 'is-critical' => $vulnerabilities > 0]) href="{!! esc_url(add_query_arg('tab', 'scans', $base)) !!}">
    <span class="snap-kpi-label">{{ __('Known vulnerabilities', 'snapshot-core') }}</span>
    <span class="snap-kpi-value">{{ $vulnerabilities }}</span>
    <span class="snap-kpi-note">{{ __('In installed core, plugins and themes', 'snapshot-core') }}</span>
  </a>
</div>

<section class="snap-panel" aria-labelledby="snap-posture">
  <div class="snap-panel-head">
    <h2 id="snap-posture">{{ __('Configuration checks', 'snapshot-core') }}</h2>
    <span class="snap-muted">{{ sprintf(__('%1$d of %2$d passed', 'snapshot-core'), $passed, count($checks)) }}</span>
  </div>
  <ul class="snap-checks">
    @foreach ($checks as $check)
      <li class="is-{{ $check['status'] }}">
        <span class="dashicons dashicons-{{ $checkIcon[$check['status']] ?? 'marker' }}" aria-hidden="true"></span>
        <div>
          <strong>{{ $check['label'] }}</strong>
          <span class="screen-reader-text">{{ $checkText[$check['status']] ?? $check['status'] }}.</span>
          <p>{{ $check['detail'] }}</p>
        </div>
      </li>
    @endforeach
  </ul>
</section>

<section class="snap-panel" aria-labelledby="snap-recent">
  <div class="snap-panel-head">
    <h2 id="snap-recent">{{ __('Recent critical events', 'snapshot-core') }}</h2>
    <a href="{!! esc_url(add_query_arg(['tab' => 'events', 'severity' => 'critical'], $base)) !!}">{{ __('View all events', 'snapshot-core') }}</a>
  </div>
  @if ($recent === [])
    <p class="snap-empty">{{ __('No critical events recorded.', 'snapshot-core') }}</p>
  @else
    @include('snapshot::security.admin.events-table', ['rows' => $recent, 'types' => snap_security_event_types(), 'base' => $base])
  @endif
</section>
