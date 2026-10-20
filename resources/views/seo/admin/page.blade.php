@php
  $statusIcon = ['ok' => 'yes-alt', 'warning' => 'warning', 'critical' => 'dismiss'];
@endphp

<div class="wrap snapshot-security snapshot-seo">
  <header class="snap-head">
    <div class="snap-head-title">
      <span class="snap-head-mark" aria-hidden="true">{!! snap_logomark_svg() !!}</span>
      <div>
        <h1><span class="snap-head-brand">Snapshot</span> <span class="snap-head-module">{{ __('SEO', 'snapshot-core') }}</span></h1>
        <ul class="snap-head-meta">
          <li>{{ __('Search, sharing and AI visibility, built into the theme.', 'snapshot-core') }}</li>
          <li>
            <button type="button" class="snap-help-inline" data-snap-help="master">{{ __('How this works', 'snapshot-core') }}</button>
          </li>
        </ul>
      </div>
    </div>

    <a class="snap-status is-{{ $status['level'] }}" href="{!! esc_url($base) !!}">
      <span class="dashicons dashicons-{{ $statusIcon[$status['level']] }}" aria-hidden="true"></span>
      <span>
        <strong>{{ sprintf(__('Health %d%%', 'snapshot-core'), $status['score']) }}</strong>
        <span class="snap-status-detail">{{ $status['label'] }}</span>
      </span>
    </a>
  </header>

  <hr class="wp-header-end">

  @if ($notice)
    <div class="notice notice-{{ $notice['type'] === 'error' ? 'error' : 'success' }} is-dismissible"><p>{{ $notice['message'] }}</p></div>
  @endif

  @if (! $master && $tab !== 'modules')
    <div class="notice notice-error inline"><p>{{ __('Built-in SEO is switched off. Pages have no descriptions, structured data or sitemap tuning.', 'snapshot-core') }} <a href="{!! esc_url(add_query_arg('tab', 'modules', $base)) !!}">{{ __('Open the control panel', 'snapshot-core') }}</a></p></div>
  @endif

  <nav class="snap-tabs" aria-label="{{ __('SEO sections', 'snapshot-core') }}">
    @foreach ($tabs as $key => $label)
      <a @class(['snap-tab', 'is-active' => $key === $tab]) href="{!! esc_url($key === 'overview' ? $base : add_query_arg('tab', $key, $base)) !!}" @if ($key === $tab) aria-current="page" @endif>{{ __($label, 'snapshot-core') }}</a>
    @endforeach
  </nav>

  <div class="snap-security-body">
    @include('snapshot::seo.admin.tab-'.$tab, $data)
  </div>
</div>
