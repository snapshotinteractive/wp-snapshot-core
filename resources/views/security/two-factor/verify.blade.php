<div class="snapshot-2fa" data-snapshot-2fa>
  <p class="snap-eyebrow">{{ __('Set up two factor sign in', 'snapshot-core') }}</p>
  <p class="snap-2fa-lead">{{ __('Every account on this site now uses two factor sign in. First, confirm this is your account.', 'snapshot-core') }}</p>

  <form class="snap-2fa-panel" method="post" action="{!! esc_url($action) !!}">
    {!! $nonce !!}
    <input type="hidden" name="snapshot_2fa_method" value="email">
    <label for="snap-2fa-verify">{{ sprintf(__('Six digit code sent to %s', 'snapshot-core'), $maskedEmail) }}</label>
    <input id="snap-2fa-verify" class="input snap-2fa-code" type="text" name="snapshot_2fa_code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus>
    <button type="submit" class="button button-primary button-large snap-2fa-wide">{{ __('Confirm', 'snapshot-core') }}</button>
    <button type="submit" name="snapshot_2fa_send" value="1" class="button-link snap-2fa-resend" formnovalidate>{{ __('Send a new code', 'snapshot-core') }}</button>
  </form>

  <p class="snap-2fa-cancel"><a href="{!! esc_url($cancelUrl) !!}">{{ __('Cancel and start again', 'snapshot-core') }}</a></p>
</div>
