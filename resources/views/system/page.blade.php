@php
  $level = $summary['fail'] ? 'critical' : ($summary['warn'] ? 'warning' : 'ok');
  $statusIcon = ['ok' => 'yes-alt', 'warning' => 'warning', 'critical' => 'dismiss'];
@endphp

<div class="wrap snapshot-security snapshot-system">
  <header class="snap-head">
    <div class="snap-head-title">
      <span class="snap-head-mark" aria-hidden="true">{!! snap_logomark_svg() !!}</span>
      <div>
        <h1><span class="snap-head-brand">Snapshot</span> <span class="snap-head-module">{{ __('System', 'snapshot-core') }}</span></h1>
        <ul class="snap-head-meta">
          <li>{{ __('Server and theme diagnostics, rebuild tools, the admin experience and the activity log.', 'snapshot-core') }}</li>
        </ul>
      </div>
    </div>

    <a class="snap-status is-{{ $level }}" href="{!! esc_url($base) !!}">
      <span class="dashicons dashicons-{{ $statusIcon[$level] }}" aria-hidden="true"></span>
      <span>
        <strong>{{ $level === 'ok' ? __('All checks pass', 'snapshot-core') : sprintf(__('%1$d failing, %2$d to review', 'snapshot-core'), $summary['fail'], $summary['warn']) }}</strong>
        <span class="snap-status-detail">{{ sprintf(__('%d checks passed', 'snapshot-core'), $summary['ok']) }}</span>
      </span>
    </a>
  </header>

  <hr class="wp-header-end">

  @if ($notice)
    <div class="notice notice-{{ $notice['type'] === 'error' ? 'error' : 'success' }} snap-notice is-dismissible"><p>{{ $notice['message'] }}</p></div>
  @endif

  <nav class="snap-tabs" aria-label="{{ __('System sections', 'snapshot-core') }}">
    @foreach ($tabs as $key => $label)
      <a @class(['snap-tab', 'is-active' => $key === $tab]) href="{!! esc_url($key === 'status' ? $base : add_query_arg('tab', $key, $base)) !!}" @if ($key === $tab) aria-current="page" @endif>{{ __($label, 'snapshot-core') }}</a>
    @endforeach
  </nav>

  <div class="snap-security-body">
    @include('snapshot::system.tab-'.$tab, $data)
  </div>
</div>
