<div class="snapshot-2fa">
  <p class="snap-eyebrow">{{ __('Connect an AI assistant', 'snapshot-core') }}</p>
  <p class="snap-2fa-lead">{{ $message }}</p>

  @if (! empty($cancel))
    <p class="snap-2fa-cancel"><a href="{!! esc_url($cancel, [(string) wp_parse_url($cancel, PHP_URL_SCHEME)]) !!}">{{ __('Return to the app', 'snapshot-core') }}</a></p>
  @endif
</div>
