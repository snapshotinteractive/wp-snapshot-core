@php
  $settingsUrl = admin_url('admin.php?page=snapshot-security&tab=settings');
  $labels = array_map(fn ($item) => $item['label'], $items);
@endphp

<form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-modules-form" data-snap-modules>
  {!! $nonce !!}
  <input type="hidden" name="action" value="snapshot_security_save_modules">

  <section @class(['snap-panel', 'snap-master', 'is-off' => ! $master['value']]) aria-labelledby="snap-master-title">
    <div class="snap-master-text">
      <h2 id="snap-master-title">{{ __('Built-in security', 'snapshot-core') }}</h2>
      <p id="snap-master-help">{{ __('Master switch for every module below. Turn it off to hand security to another plugin entirely. This page, the command line and the audit trail stay available so it can be turned back on.', 'snapshot-core') }}</p>
      @if ($master['locked'])
        <p class="snap-locked"><span class="dashicons dashicons-lock" aria-hidden="true"></span> {{ sprintf(__('Defined by %s in wp-config.php.', 'snapshot-core'), $master['constant']) }}</p>
      @endif
    </div>
    <div class="snap-master-control">
      <span @class(['snap-pill', 'is-ok' => $master['value'], 'is-critical' => ! $master['value']])>{{ $master['value'] ? __('On', 'snapshot-core') : __('Off', 'snapshot-core') }}</span>
      <label class="screen-reader-text" for="snap-module-master">{{ __('Built-in security', 'snapshot-core') }}</label>
      <input type="checkbox" role="switch" class="snap-switch is-large" id="snap-module-master" name="snapshot_security[security_enabled]" value="1" aria-describedby="snap-master-help" data-snap-master data-confirm-off="{{ __('Turn off all built-in security? The firewall, lockouts, two-factor authentication, scanning and alerts all stop. Only continue if another plugin replaces them.', 'snapshot-core') }}" @checked($master['value']) @disabled($master['locked'])>
    </div>
  </section>

  @if ($emergency2fa)
    <div class="notice notice-error inline"><p>{{ __('SNAPSHOT_SECURITY_2FA_DISABLED is set in wp-config.php, so two-factor authentication is off whatever its switch says.', 'snapshot-core') }}</p></div>
  @endif

  @foreach ($overlaps as $plugin)
    <div class="snap-overlap">
      <span class="dashicons dashicons-info" aria-hidden="true"></span>
      <p>
        <strong>{{ sprintf(__('%s is active.', 'snapshot-core'), $plugin['label']) }}</strong>
        @if ($plugin['active'] !== [])
          {{ sprintf(__('These modules overlap with it and are still on: %s. Running both can double-enforce or conflict.', 'snapshot-core'), implode(', ', array_map(fn ($key) => $labels[$key] ?? $key, $plugin['active']))) }}
        @else
          {{ __('Every overlapping module is already off.', 'snapshot-core') }}
        @endif
      </p>
    </div>
  @endforeach

  <div @class(['snap-module-grid', 'is-master-off' => ! $master['value']]) data-snap-module-grid>
    @foreach ($items as $key => $item)
      @php($id = 'snap-module-'.$key)
      <section @class(['snap-module', 'is-off' => ! $item['value']]) aria-labelledby="{{ $id }}-title">
        <div class="snap-module-head">
          <h3 id="{{ $id }}-title"><label for="{{ $id }}">{{ __($item['label'], 'snapshot-core') }}</label></h3>
          <input type="checkbox" role="switch" class="snap-switch" id="{{ $id }}" name="snapshot_security[{{ $item['setting'] }}]" value="1" aria-describedby="{{ $id }}-summary {{ $id }}-state" @if (! empty($item['confirm'])) data-confirm-off="{{ __($item['confirm'], 'snapshot-core') }}" @endif @checked($item['value']) @disabled($item['locked'])>
        </div>
        <p id="{{ $id }}-summary">{{ __($item['summary'], 'snapshot-core') }}</p>
        <p class="snap-module-foot">
          <span id="{{ $id }}-state" @class(['snap-pill', 'is-ok' => $item['on'], 'is-warning' => ! $item['on']])>
            @if ($item['on'])
              {{ __('Running', 'snapshot-core') }}
            @elseif ($item['value'])
              {{ __('Stopped by master switch', 'snapshot-core') }}
            @else
              {{ __('Off', 'snapshot-core') }}
            @endif
          </span>
          @if ($item['section'])
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
    @if (collect($overlaps)->pluck('active')->flatten()->isNotEmpty())
      <button type="submit" class="button" name="preset" value="overlaps" data-confirm="{{ __('Turn off every module that overlaps with an active security plugin?', 'snapshot-core') }}">{{ __('Turn off overlapping modules', 'snapshot-core') }}</button>
    @endif
    <p class="snap-muted">{{ __('Every change is recorded in the event log. Switching off the master switch, two-factor authentication, the firewall or login protection is logged as critical and emailed.', 'snapshot-core') }}</p>
  </div>
</form>
