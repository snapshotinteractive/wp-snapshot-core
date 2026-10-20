<h2 id="two-factor">{{ __('Two-factor authentication', 'snapshot-core') }}</h2>

<input type="hidden" name="snapshot_2fa_op" value="" data-snap-op-field>
{!! $nonce !!}

<table class="form-table snap-profile" role="presentation" data-snap-profile data-ajax="{{ $ajaxUrl }}" data-nonce="{{ $passkeyNonce }}">
  <tbody>
    <tr>
      <th scope="row">{{ __('Status', 'snapshot-core') }}</th>
      <td>
        @if ($emergency)
          <span class="snap-pill is-fail">{{ __('Disabled in wp-config.php', 'snapshot-core') }}</span>
        @elseif ($bypassed)
          <span class="snap-pill is-fail">{{ __('Disabled in the Security control panel', 'snapshot-core') }}</span>
        @elseif ($hasTotp || $passkeys !== [])
          <span class="snap-pill is-ok">{{ __('Enabled', 'snapshot-core') }}</span>
        @else
          <span class="snap-pill is-warn">{{ __('Not configured. Setup is required at next login.', 'snapshot-core') }}</span>
        @endif
        @unless ($bypassed)
          <p class="description">{{ __('Two-factor authentication is required for all accounts.', 'snapshot-core') }}</p>
        @endunless
      </td>
    </tr>

    @if ($self)
      <tr>
        <th scope="row">{{ __('Passkeys', 'snapshot-core') }}</th>
        <td>
          @if ($passkeys !== [])
            <ul class="snap-passkeys">
              @foreach ($passkeys as $key)
                <li>
                  <strong>{{ $key['name'] }}</strong>
                  <span class="description">
                    {{ sprintf(__('Added %s', 'snapshot-core'), wp_date(get_option('date_format'), $key['created'])) }}@if ($key['used']), {{ sprintf(__('last used %s ago', 'snapshot-core'), human_time_diff($key['used'])) }}@endif
                  </span>
                  <button type="button" class="button-link button-link-delete" data-snap-op="passkey-remove:{{ $key['id'] }}" data-confirm="{{ __('Remove this passkey?', 'snapshot-core') }}">{{ __('Remove', 'snapshot-core') }}<span class="screen-reader-text"> {{ sprintf(__('passkey %s', 'snapshot-core'), $key['name']) }}</span></button>
                </li>
              @endforeach
            </ul>
          @endif

          <p>
            <label for="snap-passkey-name" class="screen-reader-text">{{ __('Passkey name', 'snapshot-core') }}</label>
            <input type="text" id="snap-passkey-name" class="regular-text" maxlength="60" placeholder="{{ __('Passkey name, e.g. Work laptop', 'snapshot-core') }}" data-snap-passkey-name>
            <button type="button" class="button" data-snap-passkey-add>{{ __('Add a passkey', 'snapshot-core') }}</button>
          </p>
          <p class="description" data-snap-passkey-status role="status" hidden></p>
        </td>
      </tr>

      <tr>
        <th scope="row">{{ __('Authenticator app', 'snapshot-core') }}</th>
        <td>
          @if ($hasTotp)
            <p><span class="snap-pill is-ok">{{ __('Enabled', 'snapshot-core') }}</span></p>
            <button type="button" class="button" data-snap-op="totp-remove" data-confirm="{{ __('Remove the authenticator app from this account?', 'snapshot-core') }}">{{ __('Remove authenticator app', 'snapshot-core') }}</button>
          @else
            <div class="snap-qr">{!! $qr !!}</div>
            <p class="description">{{ __('Scan the QR code with an authenticator app, or enter the setup key:', 'snapshot-core') }} <code>{{ $secret }}</code></p>
            <p>
              <label for="snap-2fa-profile-code">{{ __('Verification code', 'snapshot-core') }}</label>
              <input type="text" id="snap-2fa-profile-code" name="snapshot_2fa_code" class="small-text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" data-snap-enter="totp-enable">
              <button type="button" class="button" data-snap-op="totp-enable">{{ __('Verify and enable', 'snapshot-core') }}</button>
            </p>
          @endif
        </td>
      </tr>

      <tr>
        <th scope="row">{{ __('Recovery codes', 'snapshot-core') }}</th>
        <td>
          @if ($freshCodes !== [])
            <div class="notice notice-warning inline">
              <p><strong>{{ __('Store these codes securely. They are shown only once.', 'snapshot-core') }}</strong></p>
              <ol class="snap-codes" data-snap-codes>
                @foreach ($freshCodes as $code)
                  <li><code>{{ $code }}</code></li>
                @endforeach
              </ol>
              <p>
                <button type="button" class="button" data-snap-copy-codes>{{ __('Copy', 'snapshot-core') }}</button>
                <button type="button" class="button" data-snap-download-codes data-filename="{{ sanitize_file_name(wp_parse_url(home_url(), PHP_URL_HOST).'-recovery-codes.txt') }}">{{ __('Download', 'snapshot-core') }}</button>
              </p>
            </div>
          @endif
          <p>{{ sprintf(_n('%d recovery code remaining.', '%d recovery codes remaining.', $recoveryLeft, 'snapshot-core'), $recoveryLeft) }}</p>
          <button type="button" class="button" data-snap-op="recovery-regenerate" data-confirm="{{ __('Generate new recovery codes? Existing codes are invalidated immediately.', 'snapshot-core') }}">{{ __('Regenerate codes', 'snapshot-core') }}</button>
        </td>
      </tr>
    @elseif ($canReset)
      <tr>
        <th scope="row">{{ __('Reset', 'snapshot-core') }}</th>
        <td>
          <button type="button" class="button button-secondary" data-snap-op="reset" data-confirm="{{ __('Remove all two-factor methods and end all sessions for this account? The user must enroll again at next login. Verify the user\'s identity before continuing.', 'snapshot-core') }}">{{ __('Reset two-factor authentication', 'snapshot-core') }}</button>
          <p class="description">{{ __('Use when the user has lost access to all devices and recovery codes.', 'snapshot-core') }}</p>
        </td>
      </tr>
    @endif
  </tbody>
</table>
