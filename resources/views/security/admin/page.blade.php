@php
  $modes = ['enforce' => __('Enforce', 'snapshot-core'), 'learning' => __('Learning', 'snapshot-core'), 'off' => __('Disabled', 'snapshot-core')];
  $statusIcon = ['ok' => 'yes-alt', 'warning' => 'warning', 'critical' => 'dismiss'];
@endphp

<div class="wrap snapshot-security">
  <header class="snap-head">
    <div class="snap-head-title">
      <span class="snap-head-mark" aria-hidden="true">{!! snap_logomark_svg() !!}</span>
      <div>
        <h1><span class="snap-head-brand">Snapshot</span> <span class="snap-head-module">{{ __('Security', 'snapshot-core') }}</span></h1>
        <ul class="snap-head-meta">
          <li>{{ __('Firewall:', 'snapshot-core') }} <strong>{{ $modes[$summary['firewallMode']] ?? $summary['firewallMode'] }}</strong></li>
          <li>{{ __('Two-factor:', 'snapshot-core') }} <strong>{{ sprintf(_n('%1$d of %2$d account', '%1$d of %2$d accounts', $summary['enrollment']['users'], 'snapshot-core'), $summary['enrollment']['enrolled'], $summary['enrollment']['users']) }}</strong></li>
          <li>{{ __('Last scan:', 'snapshot-core') }} <strong>{{ ! empty($summary['scan']['at']) ? sprintf(__('%s ago', 'snapshot-core'), human_time_diff($summary['scan']['at'])) : __('Never', 'snapshot-core') }}</strong></li>
        </ul>
      </div>
    </div>

    <a class="snap-status is-{{ $status['level'] }}" href="{!! esc_url($base) !!}">
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
    </a>
  </header>

  <hr class="wp-header-end">

  @if ($notice)
    <div class="notice notice-{{ $notice['type'] === 'error' ? 'error' : 'success' }} is-dismissible"><p>{{ $notice['message'] }}</p></div>
  @endif

  <nav class="snap-tabs" aria-label="{{ __('Security sections', 'snapshot-core') }}">
    @foreach ($tabs as $key => $label)
      <a @class(['snap-tab', 'is-active' => $key === $tab]) href="{!! esc_url($key === 'overview' ? $base : add_query_arg('tab', $key, $base)) !!}" @if ($key === $tab) aria-current="page" @endif>{{ __($label, 'snapshot-core') }}</a>
    @endforeach
  </nav>

  <div class="snap-security-body">
    @include('snapshot::security.admin.tab-'.$tab, $data)
  </div>
</div>
