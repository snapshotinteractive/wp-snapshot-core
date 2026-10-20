<div class="snapshot-2fa" data-snapshot-2fa>
  <p class="snap-eyebrow">{{ __('Set up two factor sign in', 'snapshot-core') }}</p>
  <p class="snap-2fa-lead">{{ __('Choose how you will confirm it is you each time you sign in. You can add the other later from your profile.', 'snapshot-core') }}</p>

  <form class="snap-2fa-panel" method="post" action="{!! esc_url($action) !!}" data-snap-passkey-form data-snap-passkey-only>
    {!! $nonce !!}
    <input type="hidden" name="snapshot_2fa_method" value="passkey">
    <input type="hidden" name="snapshot_2fa_credential" value="" data-snap-credential>
    <h2 class="snap-2fa-heading">{{ __('Passkey', 'snapshot-core') }} <span class="snap-badge">{{ __('Recommended', 'snapshot-core') }}</span></h2>
    <p class="snap-2fa-hint">{{ __('Uses Touch ID, Face ID, Windows Hello or a security key. Nothing to type.', 'snapshot-core') }}</p>
    <label for="snap-2fa-name">{{ __('Name this passkey', 'snapshot-core') }}</label>
    <input id="snap-2fa-name" class="input" type="text" name="snapshot_2fa_name" maxlength="60" placeholder="{{ __('Work laptop', 'snapshot-core') }}">
    <button type="button" class="button button-primary button-large snap-2fa-wide" data-snap-passkey-enroll data-options="{{ $passkeyOptions }}">{{ __('Create a passkey', 'snapshot-core') }}</button>
    <p class="snap-2fa-hint" data-snap-passkey-status role="status" hidden></p>
  </form>

  <form class="snap-2fa-panel" method="post" action="{!! esc_url($action) !!}">
    {!! $nonce !!}
    <input type="hidden" name="snapshot_2fa_method" value="totp">
    <h2 class="snap-2fa-heading">{{ __('Authenticator app', 'snapshot-core') }}</h2>
    <p class="snap-2fa-hint">{{ __('Scan with 1Password, Google Authenticator, Microsoft Authenticator or any TOTP app.', 'snapshot-core') }}</p>
    <div class="snap-qr">{!! $qr !!}</div>
    <p class="snap-2fa-hint">
      {{ __('Cannot scan? Enter this key:', 'snapshot-core') }}
      <code class="snap-secret">{{ $secret }}</code>
      <a class="snap-2fa-applink" href="{!! esc_url($otpauth, ['otpauth']) !!}">{{ __('Open in an app on this device', 'snapshot-core') }}</a>
    </p>
    <label for="snap-2fa-setup">{{ __('Six digit code the app shows', 'snapshot-core') }}</label>
    <input id="snap-2fa-setup" class="input snap-2fa-code" type="text" name="snapshot_2fa_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required>
    <button type="submit" class="button button-large snap-2fa-wide">{{ __('Turn on', 'snapshot-core') }}</button>
  </form>

  <p class="snap-2fa-cancel"><a href="{!! esc_url($cancelUrl) !!}">{{ __('Cancel and start again', 'snapshot-core') }}</a></p>
</div>
