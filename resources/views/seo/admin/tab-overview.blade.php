@php
  $checkIcon = ['ok' => 'yes-alt', 'warn' => 'warning', 'fail' => 'dismiss', 'info' => 'info-outline'];
  $checkText = ['ok' => __('Pass', 'snapshot-core'), 'warn' => __('Warning', 'snapshot-core'), 'fail' => __('Fail', 'snapshot-core'), 'info' => __('Note', 'snapshot-core')];
  $doneSteps = count(array_filter($steps, fn ($step) => $step['done']));
  $order = ['fail' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
  $checks = $status['checks'];
  usort($checks, fn ($a, $b) => $order[$a['status']] <=> $order[$b['status']]);
@endphp

<div class="snap-grid">
  <section class="snap-panel snap-panel-wide" aria-labelledby="snap-seo-setup">
    <div class="snap-panel-head">
      <h2 id="snap-seo-setup">{{ __('Getting started', 'snapshot-core') }}</h2>
      <span @class(['snap-pill', 'is-ok' => $doneSteps === count($steps)])>{{ sprintf(__('%1$d of %2$d done', 'snapshot-core'), $doneSteps, count($steps)) }}</span>
    </div>
    <p class="snap-muted">{{ __('Most of the site describes itself from the content model. These steps are the few things only a person can confirm. Each one is ticked automatically when it is detected.', 'snapshot-core') }}</p>
    <ol class="snap-steps">
      @foreach ($steps as $key => $step)
        <li @class(['is-done' => $step['done']])>
          <span class="snap-step-mark dashicons dashicons-{{ $step['done'] ? 'yes-alt' : 'marker' }}" aria-hidden="true"></span>
          <div class="snap-step-text">
            <strong>{{ $step['label'] }}</strong>
            <span class="screen-reader-text">{{ $step['done'] ? __('Done.', 'snapshot-core') : __('To do.', 'snapshot-core') }}</span>
            @include('snapshot::seo.admin.help', ['topic' => $step['topic'], 'label' => $step['label']])
            <p>{{ $step['detail'] }}</p>
          </div>
          <div class="snap-step-actions">
            <a class="button" href="{!! esc_url($step['url']) !!}">{{ $step['action'] }}</a>
            @if (! empty($step['manual']))
              <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
                {!! $nonces['setup'] !!}
                <input type="hidden" name="action" value="snapshot_seo_setup">
                <input type="hidden" name="step" value="{{ $key }}">
                @if ($step['done'])
                  <input type="hidden" name="undo" value="1">
                  <button type="submit" class="button-link">{{ __('Mark as not done', 'snapshot-core') }}</button>
                @else
                  <button type="submit" class="button-link">{{ __('Mark as done', 'snapshot-core') }}</button>
                @endif
              </form>
            @endif
          </div>
        </li>
      @endforeach
    </ol>
  </section>

  <section class="snap-panel" aria-labelledby="snap-seo-actions">
    <h2 id="snap-seo-actions">{{ __('Check the output', 'snapshot-core') }}</h2>
    <ul class="snap-link-list">
      <li><a href="{!! esc_url($links['sitemap']) !!}" target="_blank" rel="noopener">{{ __('XML sitemap', 'snapshot-core') }}</a></li>
      <li><a href="{!! esc_url($links['robots']) !!}" target="_blank" rel="noopener">{{ __('robots.txt', 'snapshot-core') }}</a></li>
      <li><a href="{!! esc_url($links['llms']) !!}" target="_blank" rel="noopener">{{ __('llms.txt', 'snapshot-core') }}</a></li>
      <li><a href="{!! esc_url($links['rich']) !!}" target="_blank" rel="noopener">{{ __('Google rich results test, home page', 'snapshot-core') }}</a></li>
      <li><a href="{!! esc_url($links['schema']) !!}" target="_blank" rel="noopener">{{ __('Schema.org validator, home page', 'snapshot-core') }}</a></li>
    </ul>
    <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
      {!! $nonces['run'] !!}
      <input type="hidden" name="action" value="snapshot_seo_run">
      <input type="hidden" name="task" value="report">
      <button type="submit" class="button button-primary">{{ $report ? __('Refresh the content report', 'snapshot-core') : __('Run the content report', 'snapshot-core') }}</button>
    </form>
    <p class="snap-muted">{{ $report ? sprintf(__('Last run %s ago. Runs nightly.', 'snapshot-core'), human_time_diff($report['at'])) : __('Checks every published entry for missing descriptions and duplicate titles. Runs nightly.', 'snapshot-core') }}</p>
  </section>
</div>

<div class="snap-kpis">
  <a class="snap-kpi" href="{!! esc_url(add_query_arg('tab', 'content', $base)) !!}">
    <span class="snap-kpi-label">{{ __('Indexable entries', 'snapshot-core') }}</span>
    <span class="snap-kpi-value">{{ $report ? number_format_i18n($report['total']) : __('Pending', 'snapshot-core') }}</span>
    <span class="snap-kpi-note">{{ __('Published and shown in search', 'snapshot-core') }}</span>
  </a>
  <a @class(['snap-kpi', 'is-critical' => $report && $report['issues']['no_description'] > 0]) href="{!! esc_url(add_query_arg(['tab' => 'content', 'issue' => 'no_description'], $base)) !!}">
    <span class="snap-kpi-label">{{ __('Missing descriptions', 'snapshot-core') }}</span>
    <span class="snap-kpi-value">{{ $report ? number_format_i18n($report['issues']['no_description']) : '?' }}</span>
    <span class="snap-kpi-note">{{ __('Open in the bulk editor', 'snapshot-core') }}</span>
  </a>
  <a class="snap-kpi" href="{!! esc_url(add_query_arg(['tab' => 'content', 'issue' => 'duplicate_title'], $base)) !!}">
    <span class="snap-kpi-label">{{ __('Duplicate titles', 'snapshot-core') }}</span>
    <span class="snap-kpi-value">{{ $report ? number_format_i18n($report['issues']['duplicate_title']) : '?' }}</span>
    <span class="snap-kpi-note">{{ __('Entries sharing a title', 'snapshot-core') }}</span>
  </a>
  <a class="snap-kpi" href="{!! esc_url(add_query_arg('tab', 'redirects', $base)) !!}">
    <span class="snap-kpi-label">{{ __('Redirects', 'snapshot-core') }}</span>
    <span class="snap-kpi-value">{{ number_format_i18n($redirects['redirects']) }}</span>
    <span class="snap-kpi-note">{{ __('Old addresses kept working', 'snapshot-core') }}</span>
  </a>
  <a @class(['snap-kpi', 'is-critical' => $redirects['missing'] > 0]) href="{!! esc_url(add_query_arg(['tab' => 'redirects', 'view' => '404'], $base)) !!}">
    <span class="snap-kpi-label">{{ __('404s this week', 'snapshot-core') }}</span>
    <span class="snap-kpi-value">{{ number_format_i18n($redirects['missing']) }}</span>
    <span class="snap-kpi-note">{{ __('Addresses visitors could not reach', 'snapshot-core') }}</span>
  </a>
</div>

<section class="snap-panel" aria-labelledby="snap-seo-checks">
  <div class="snap-panel-head">
    <h2 id="snap-seo-checks">{{ __('Site health', 'snapshot-core') }}</h2>
    <span class="snap-muted">{{ sprintf(__('%1$d%% from %2$d checks. Notes do not count toward the score.', 'snapshot-core'), $status['score'], count(array_filter($checks, fn ($check) => $check['status'] !== 'info'))) }}</span>
  </div>
  <ul class="snap-checks">
    @foreach ($checks as $check)
      <li @class(['is-warn' => $check['status'] === 'warn', 'is-fail' => $check['status'] === 'fail', 'is-info' => $check['status'] === 'info'])>
        <span class="dashicons dashicons-{{ $checkIcon[$check['status']] }}" aria-hidden="true"></span>
        <div>
          <strong><span class="screen-reader-text">{{ $checkText[$check['status']] }}: </span>{{ $check['label'] }}</strong>
          @include('snapshot::seo.admin.help', ['topic' => $check['topic'], 'label' => $check['label']])
          <p>{{ $check['detail'] }} @if ($check['status'] !== 'ok')<a href="{!! esc_url($check['url']) !!}">{{ __('Fix', 'snapshot-core') }}<span class="screen-reader-text"> {{ $check['label'] }}</span></a>@endif</p>
        </div>
      </li>
    @endforeach
  </ul>
</section>
