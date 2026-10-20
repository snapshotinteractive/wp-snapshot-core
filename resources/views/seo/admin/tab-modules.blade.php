@php
  $settingsUrl = admin_url('admin.php?page=snapshot-seo&tab=settings');
  $appearanceUrl = admin_url('admin.php?page=snapshot-seo&tab=appearance');
  $labels = array_map(fn ($item) => $item['label'], $items);
@endphp

<form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-modules-form" data-snap-modules>
  {!! $nonce !!}
  <input type="hidden" name="action" value="snapshot_seo_save_modules">

  <section @class(['snap-panel', 'snap-master', 'is-off' => ! $master['value']]) aria-labelledby="snap-seo-master-title">
    <div class="snap-master-text">
      <h2 id="snap-seo-master-title">{{ __('Built-in SEO', 'snapshot-core') }} @include('snapshot::seo.admin.help', ['topic' => 'master', 'label' => __('Built-in SEO', 'snapshot-core')])</h2>
      <p id="snap-seo-master-help">{{ __('Master switch for every module below. Turn it off only when another SEO plugin takes over everything. This page and the command line stay available so it can be turned back on.', 'snapshot-core') }}</p>
      @if ($master['locked'])
        <p class="snap-locked"><span class="dashicons dashicons-lock" aria-hidden="true"></span> {{ sprintf(__('Defined by %s in wp-config.php.', 'snapshot-core'), $master['constant']) }}</p>
      @endif
    </div>
    <div class="snap-master-control">
      <span @class(['snap-pill', 'is-ok' => $master['value'], 'is-critical' => ! $master['value']])>{{ $master['value'] ? __('On', 'snapshot-core') : __('Off', 'snapshot-core') }}</span>
      <label class="screen-reader-text" for="snap-seo-module-master">{{ __('Built-in SEO', 'snapshot-core') }}</label>
      <input type="checkbox" role="switch" class="snap-switch is-large" id="snap-seo-module-master" name="snapshot_seo[seo_enabled]" value="1" aria-describedby="snap-seo-master-help" data-snap-master data-confirm-off="{{ __('Turn off all built-in SEO? Titles fall back to WordPress defaults, and descriptions, structured data, redirects and sitemap tuning stop. Only continue if another plugin replaces them.', 'snapshot-core') }}" @checked($master['value']) @disabled($master['locked'])>
    </div>
  </section>

  @foreach ($overlaps as $plugin)
    <div class="snap-overlap">
      <span class="dashicons dashicons-info" aria-hidden="true"></span>
      <p>
        <strong>{{ sprintf(__('%s is active.', 'snapshot-core'), $plugin['label']) }}</strong>
        @if ($defer)
          {{ sprintf(__('These modules are paused so pages never carry duplicate tags: %s. Import its data under Tools, deactivate it, and they start on their own.', 'snapshot-core'), implode(', ', array_map(fn ($key) => $labels[$key] ?? $key, $plugin['modules']))) }}
        @else
          {{ __('Pausing is off under Settings, Advanced, so both are running. Expect duplicate titles and canonicals until it is deactivated.', 'snapshot-core') }}
        @endif
        @include('snapshot::seo.admin.help', ['topic' => 'defer', 'label' => __('Pausing for SEO plugins', 'snapshot-core')])
      </p>
    </div>
  @endforeach

  <div @class(['snap-module-grid', 'is-master-off' => ! $master['value']]) data-snap-module-grid>
    @foreach ($items as $key => $item)
      @php($id = 'snap-seo-module-'.$key)
      <section @class(['snap-module', 'is-off' => ! $item['value']]) aria-labelledby="{{ $id }}-title">
        <div class="snap-module-head">
          <h3 id="{{ $id }}-title"><label for="{{ $id }}">{{ __($item['label'], 'snapshot-core') }}</label></h3>
          <input type="checkbox" role="switch" class="snap-switch" id="{{ $id }}" name="snapshot_seo[{{ $item['setting'] }}]" value="1" aria-describedby="{{ $id }}-summary {{ $id }}-state" @checked($item['value']) @disabled($item['locked'])>
        </div>
        <p id="{{ $id }}-summary">{{ __($item['summary'], 'snapshot-core') }}</p>
        <p class="snap-module-foot">
          <span id="{{ $id }}-state" @class(['snap-pill', 'is-ok' => $item['on'], 'is-warning' => ! $item['on']])>
            @if ($item['on'])
              {{ __('Running', 'snapshot-core') }}
            @elseif ($item['paused'])
              {{ sprintf(__('Paused: %s is active', 'snapshot-core'), implode(', ', $item['paused'])) }}
            @elseif ($item['value'])
              {{ __('Stopped by master switch', 'snapshot-core') }}
            @else
              {{ __('Off', 'snapshot-core') }}
            @endif
          </span>
          @include('snapshot::seo.admin.help', ['topic' => $item['topic'], 'label' => __($item['label'], 'snapshot-core')])
          @if ($item['section'] === 'sitemaps')
            <a href="{!! esc_url(admin_url('admin.php?page=snapshot-seo&tab=sitemaps')) !!}">{{ __('Configure', 'snapshot-core') }}<span class="screen-reader-text"> {{ __($item['label'], 'snapshot-core') }}</span></a>
          @elseif ($item['section'] === 'appearance')
            <a href="{!! esc_url($appearanceUrl) !!}">{{ __('Configure', 'snapshot-core') }}<span class="screen-reader-text"> {{ __($item['label'], 'snapshot-core') }}</span></a>
          @elseif ($item['section'])
            <a href="{!! esc_url($settingsUrl.'&section='.$item['section']) !!}">{{ __('Configure', 'snapshot-core') }}<span class="screen-reader-text"> {{ __($item['label'], 'snapshot-core') }}</span></a>
          @endif
        </p>
        @if ($item['locked'])
          <p class="snap-locked"><span class="dashicons dashicons-lock" aria-hidden="true"></span> {{ sprintf(__('Defined by %s in wp-config.php.', 'snapshot-core'), $item['constant']) }}</p>
        @endif
      </section>
    @endforeach
  </div>

  <div class="snap-panel snap-modules-foot">
    <button type="submit" class="button button-primary">{{ __('Save changes', 'snapshot-core') }}</button>
    <p class="snap-muted">{{ __('Every change is recorded in the security activity log. Any module can also be switched with wp snap seo enable or disable, or pinned with SNAPSHOT_SEO_MODULE_<KEY> in wp-config.php.', 'snapshot-core') }}</p>
  </div>
</form>
