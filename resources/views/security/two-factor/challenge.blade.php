<div class="snapshot-2fa" data-snapshot-2fa>
  <p class="snap-eyebrow">{{ __('Step 2 of 2', 'snapshot-core') }}</p>
  <p class="snap-2fa-lead">{{ sprintf(__('Hello %s. Confirm it is you to finish signing in.', 'snapshot-core'), $user->display_name) }}</p>

  @if ($hasPasskey)
    <form class="snap-2fa-panel" method="post" action="{!! esc_url($action) !!}" data-snap-passkey-form>
      {!! $nonce !!}
      <input type="hidden" name="snapshot_2fa_method" value="passkey">
      <input type="hidden" name="snapshot_2fa_credential" value="" data-snap-credential>
      <button type="button" class="button button-primary button-large snap-2fa-wide" data-snap-passkey-auth data-options="{{ $passkeyOptions }}">
        {{ __('Use your passkey', 'snapshot-core') }}
      </button>
      <p class="snap-2fa-hint" data-snap-passkey-status role="status" hidden></p>
    </form>
  @endif

  @if ($hasTotp)
    <form class="snap-2fa-panel" method="post" action="{!! esc_url($action) !!}">
      {!! $nonce !!}
      <input type="hidden" name="snapshot_2fa_method" value="totp">
      <label for="snap-2fa-totp">{{ __('Six digit code from your authenticator app', 'snapshot-core') }}</label>
      <input id="snap-2fa-totp" class="input snap-2fa-code" type="text" name="snapshot_2fa_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required @if (! $hasPasskey) autofocus @endif>
      <button type="submit" class="button button-primary button-large snap-2fa-wide">{{ __('Verify', 'snapshot-core') }}</button>
    </form>
  @endif

  <details class="snap-2fa-alt">
    <summary>{{ __('Other ways to sign in', 'snapshot-core') }}</summary>

    @if ($recoveryLeft > 0)
      <form class="snap-2fa-panel" method="post" action="{!! esc_url($action) !!}">
        {!! $nonce !!}
        <input type="hidden" name="snapshot_2fa_method" value="recovery">
        <label for="snap-2fa-recovery">{{ __('Recovery code', 'snapshot-core') }}</label>
        <input id="snap-2fa-recovery" class="input snap-2fa-code" type="text" name="snapshot_2fa_code" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="xxxxx-xxxxx" maxlength="16" required>
        <p class="snap-2fa-hint">{{ sprintf(_n('%d recovery code left. Each works once.', '%d recovery codes left. Each works once.', $recoveryLeft, 'snapshot-core'), $recoveryLeft) }}</p>
        <button type="submit" class="button button-large snap-2fa-wide">{{ __('Use recovery code', 'snapshot-core') }}</button>
      </form>
    @endif

    @if ($emailFallback)
      <form class="snap-2fa-panel" method="post" action="{!! esc_url($action) !!}">
        {!! $nonce !!}
        <input type="hidden" name="snapshot_2fa_method" value="email">
        <p class="snap-2fa-hint">{{ sprintf(__('We can email a single use code to %s.', 'snapshot-core'), $maskedEmail) }}</p>
        <button type="submit" name="snapshot_2fa_send" value="1" class="button button-large snap-2fa-wide" formnovalidate>{{ __('Email me a code', 'snapshot-core') }}</button>
        <label for="snap-2fa-email">{{ __('Emailed code', 'snapshot-core') }}</label>
        <input id="snap-2fa-email" class="input snap-2fa-code" type="text" name="snapshot_2fa_code" inputmode="numeric" autocomplete="one-time-code" maxlength="7">
        <button type="submit" class="button button-primary button-large snap-2fa-wide">{{ __('Verify emailed code', 'snapshot-core') }}</button>
      </form>
    @endif

    <p class="snap-2fa-hint">{{ __('Lost every method? An administrator can reset two factor sign in for your account.', 'snapshot-core') }}</p>
  </details>

  <p class="snap-2fa-cancel"><a href="{!! esc_url($cancelUrl) !!}">{{ __('Cancel and start again', 'snapshot-core') }}</a></p>
</div>
