@if ($issuedToken)
  <div class="notice notice-warning">
    <p><strong>{{ __('Copy this status token now. It is shown only once.', 'snapshot-core') }}</strong></p>
    <p><code>{{ $issuedToken }}</code></p>
    <p>{{ sprintf(__('Send it in the X-Snapshot-Security-Token header to %s.', 'snapshot-core'), $statusUrl) }}</p>
  </div>
@endif

<div class="snap-settings">
  <nav class="snap-settings-nav" aria-label="{{ __('Settings sections', 'snapshot-core') }}">
    <ul>
      @foreach ($sections as $key => $label)
        <li>
          <a @class(['is-active' => $key === $section]) href="{!! esc_url(admin_url('admin.php?page=snapshot-security&tab=settings&section='.$key)) !!}" @if ($key === $section) aria-current="page" @endif>{{ __($label, 'snapshot-core') }}</a>
        </li>
      @endforeach
    </ul>
  </nav>

  <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-panel snap-settings-form">
    {!! $nonce !!}
    <input type="hidden" name="action" value="snapshot_security_save_settings">
    <input type="hidden" name="section" value="{{ $section }}">

    <h2>{{ __($sections[$section], 'snapshot-core') }}</h2>

    @if ($sectionModules !== [])
      <p class="snap-section-modules">
        @foreach ($sectionModules as $module)
          <span @class(['snap-pill', 'is-ok' => $module['on'], 'is-warning' => ! $module['on']])>{{ sprintf($module['on'] ? __('%s: running', 'snapshot-core') : __('%s: off', 'snapshot-core'), __($module['label'], 'snapshot-core')) }}</span>
        @endforeach
        <a href="{!! esc_url($modulesUrl) !!}">{{ __('Switch modules on or off in the control panel', 'snapshot-core') }}</a>
      </p>
    @endif

    <div class="snap-fields">
      @foreach ($fields[$section] ?? [] as $key => $field)
        @php
          $id = 'snap-security-'.$key;
          $name = 'snapshot_security['.$key.']';
          $help = $id.'-help';
        @endphp
        <div @class(['snap-field', 'is-locked' => $field['locked'], 'is-toggle' => $field['type'] === 'bool'])>
          @if ($field['type'] === 'bool')
            <div class="snap-field-label">
              <label for="{{ $id }}">{{ __($field['label'], 'snapshot-core') }}</label>
              <p class="description" id="{{ $help }}">{{ __($field['help'], 'snapshot-core') }}</p>
            </div>
            <div class="snap-field-control">
              <input type="checkbox" role="switch" class="snap-switch" id="{{ $id }}" name="{{ $name }}" value="1" aria-describedby="{{ $help }}" @checked($field['value']) @disabled($field['locked'])>
            </div>
          @else
            <div class="snap-field-label">
              @if ($field['type'] === 'token')
                <span class="snap-field-title">{{ __($field['label'], 'snapshot-core') }}</span>
              @else
                <label for="{{ $id }}">{{ __($field['label'], 'snapshot-core') }}</label>
              @endif
              <p class="description" id="{{ $help }}">{{ __($field['help'], 'snapshot-core') }}</p>
            </div>
            <div class="snap-field-control">
              @if ($field['type'] === 'select')
                <select id="{{ $id }}" name="{{ $name }}" aria-describedby="{{ $help }}" @disabled($field['locked'])>
                  @foreach ($field['choices'] as $value => $choice)
                    <option value="{{ $value }}" @selected($field['value'] === $value)>{{ __($choice, 'snapshot-core') }}</option>
                  @endforeach
                </select>
              @elseif ($field['type'] === 'int')
                <input type="number" id="{{ $id }}" name="{{ $name }}" value="{{ $field['value'] }}" class="small-text" aria-describedby="{{ $help }}" @if (isset($field['min'])) min="{{ $field['min'] }}" @endif @if (isset($field['max'])) max="{{ $field['max'] }}" @endif @disabled($field['locked'])>
              @elseif ($field['type'] === 'lines')
                <textarea id="{{ $id }}" name="{{ $name }}" rows="4" class="large-text code" aria-describedby="{{ $help }}" @disabled($field['locked'])>{{ $field['value'] }}</textarea>
              @elseif ($field['type'] === 'secret')
                <input type="password" id="{{ $id }}" name="{{ $name }}" value="" class="regular-text" autocomplete="new-password" aria-describedby="{{ $help }}" placeholder="{{ $field['isSet'] ? __('Saved. Leave blank to keep the current value.', 'snapshot-core') : '' }}" @disabled($field['locked'])>
                @if ($field['isSet'] && ! $field['locked'])
                  <label class="snap-inline"><input type="checkbox" name="snapshot_security[{{ $key }}__clear]" value="1"> {{ __('Clear saved value', 'snapshot-core') }}</label>
                @endif
              @elseif ($field['type'] === 'token')
                <p>
                  @if ($field['isSet'])
                    <span class="snap-pill is-ok">{{ __('Configured', 'snapshot-core') }}</span>
                  @else
                    <span class="snap-pill">{{ __('Not configured', 'snapshot-core') }}</span>
                    <span class="snap-muted">{{ __('Administrators only.', 'snapshot-core') }}</span>
                  @endif
                </p>
                @if (! $field['locked'])
                  <label class="snap-inline"><input type="checkbox" name="snapshot_security[{{ $key }}__generate]" value="1"> {{ __('Generate new token', 'snapshot-core') }}</label>
                @endif
                @if ($field['isSet'] && ! $field['locked'])
                  <label class="snap-inline"><input type="checkbox" name="snapshot_security[{{ $key }}__clear]" value="1"> {{ __('Revoke token', 'snapshot-core') }}</label>
                @endif
              @else
                <input type="text" id="{{ $id }}" name="{{ $name }}" value="{{ $field['value'] }}" class="regular-text" aria-describedby="{{ $help }}" @disabled($field['locked'])>
              @endif
            </div>
          @endif

          @if ($field['locked'])
            <p class="snap-locked"><span class="dashicons dashicons-lock" aria-hidden="true"></span> {{ sprintf(__('Defined by %s in wp-config.php.', 'snapshot-core'), $field['constant']) }}</p>
          @endif
        </div>
      @endforeach
    </div>

    @if ($section === 'cloudflare')
      <p class="snap-note">{{ __('To enforce pushed blocks at the edge, create a Cloudflare WAF custom rule with the expression ip.src in $your_list_name and the action Block.', 'snapshot-core') }}</p>
    @endif

    <div class="snap-form-foot">
      <button type="submit" class="button button-primary">{{ __('Save changes', 'snapshot-core') }}</button>
    </div>
  </form>
</div>
