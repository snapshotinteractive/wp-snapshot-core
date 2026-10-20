<div class="snapshot-2fa" data-snapshot-2fa>
  <p class="snap-eyebrow">{{ __('Almost done', 'snapshot-core') }}</p>
  <p class="snap-2fa-lead">{{ __('Save these recovery codes somewhere safe, such as a password manager. Each one signs you in once if you lose your device. They will not be shown again.', 'snapshot-core') }}</p>

  <form class="snap-2fa-panel" method="post" action="{!! esc_url($action) !!}">
    {!! $nonce !!}
    <input type="hidden" name="snapshot_2fa_method" value="codes">

    <ol class="snap-codes" data-snap-codes>
      @foreach ($codes as $code)
        <li><code>{{ $code }}</code></li>
      @endforeach
    </ol>

    <p class="snap-codes-actions">
      <button type="button" class="button" data-snap-copy-codes>{{ __('Copy', 'snapshot-core') }}</button>
      <button type="button" class="button" data-snap-download-codes data-filename="{{ sanitize_file_name(wp_parse_url(home_url(), PHP_URL_HOST).'-recovery-codes.txt') }}">{{ __('Download', 'snapshot-core') }}</button>
    </p>

    <label class="snap-check">
      <input type="checkbox" name="snapshot_2fa_saved" value="1" required>
      {{ __('I have saved my recovery codes', 'snapshot-core') }}
    </label>

    <button type="submit" class="button button-primary button-large snap-2fa-wide">{{ __('Finish signing in', 'snapshot-core') }}</button>
  </form>
</div>
