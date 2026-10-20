<div class="snap-grid">
  <section class="snap-panel">
    <div class="snap-panel-head">
      <h2>{{ __('Tools', 'snapshot-core') }}</h2>
    </div>
    <p class="snap-muted">{{ __('Each tool runs once, now, and is recorded in the activity log. The same tools run from the command line with wp snap system run <tool>.', 'snapshot-core') }}</p>

    <ul class="snap-system-tools">
      @foreach ($tools as $key => $tool)
        @php($available = $tool['available'] ?? true)
        <li>
          <div>
            <strong>{{ $tool['label'] }}</strong>
            <p class="snap-muted">{{ $tool['detail'] }}</p>
          </div>
          <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
            {!! $nonce !!}
            <input type="hidden" name="action" value="snapshot_system_tool">
            <input type="hidden" name="tool" value="{{ $key }}">
            <button type="submit" @class(['button', 'button-primary' => ! empty($tool['primary'])]) @disabled(! $available) @if (! empty($tool['confirm'])) data-confirm="{{ $tool['confirm'] }}" @endif>{{ $available ? __('Run', 'snapshot-core') : __('Not available', 'snapshot-core') }}<span class="screen-reader-text"> {{ $tool['label'] }}</span></button>
          </form>
        </li>
      @endforeach
    </ul>
  </section>

  <div class="snap-system-side">
    <section class="snap-panel">
      <div class="snap-panel-head">
        <h2>{{ __('Blade view cache', 'snapshot-core') }}</h2>
      </div>
      <dl class="snap-system-facts">
        <dt>{{ __('Templates', 'snapshot-core') }}</dt><dd>{{ $views['sources'] }}</dd>
        <dt>{{ __('Compiled files', 'snapshot-core') }}</dt><dd>{{ $views['compiled'] }}</dd>
        <dt>{{ __('Not compiled yet', 'snapshot-core') }}</dt><dd>{{ $views['missing'] }}</dd>
        <dt>{{ __('Out of date', 'snapshot-core') }}</dt><dd>{{ $views['stale'] }}</dd>
        <dt>{{ __('Last compiled', 'snapshot-core') }}</dt><dd>{{ $views['newest'] ? sprintf(__('%s ago', 'snapshot-core'), human_time_diff($views['newest'])) : __('Never', 'snapshot-core') }}</dd>
        <dt>{{ __('Folder', 'snapshot-core') }}</dt><dd><code>{{ $views['path'] }}</code> @if (! $views['writable'])<span class="snap-pill is-critical">{{ __('Not writable', 'snapshot-core') }}</span>@endif</dd>
      </dl>
    </section>

    <section class="snap-panel">
      <div class="snap-panel-head">
        <h2>{{ __('Acorn caches', 'snapshot-core') }}</h2>
      </div>
      <dl class="snap-system-facts">
        @foreach ($caches as $label => $cache)
          <dt>{{ $label }}</dt>
          <dd>{{ $cache['exists'] ? sprintf(__('Cached %s ago', 'snapshot-core'), human_time_diff($cache['modified'])) : __('Not cached', 'snapshot-core') }}</dd>
        @endforeach
      </dl>
    </section>
  </div>
</div>

<section class="snap-panel">
  <div class="snap-panel-head">
    <h2>{{ __('Debug log', 'snapshot-core') }}</h2>
    <span class="snap-muted">{{ __('The last 60 lines of wp-content/debug.log', 'snapshot-core') }}</span>
  </div>
  @if ($debug === '')
    <p class="snap-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> {{ __('The debug log is empty or absent.', 'snapshot-core') }}</p>
  @else
    <pre class="snap-system-log" tabindex="0">{{ $debug }}</pre>
  @endif
</section>
