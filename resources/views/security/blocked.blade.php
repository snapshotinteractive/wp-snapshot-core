<!doctype html>
<html lang="{{ str_replace('_', '-', get_locale()) }}">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · {{ $siteName }}</title>
    @if (function_exists('App\font_stylesheets'))
      @foreach (\App\font_stylesheets() as $stylesheet)
        <link rel="stylesheet" href="{!! esc_url($stylesheet) !!}">
      @endforeach
    @endif
    {!! \Snapshot\Core::assets(['resources/css/login.css']) !!}
  </head>

  <body class="snap-screen">
    <main class="snap-screen-card" aria-labelledby="snap-screen-title">
      @if ($logo = snap_site_logo_url())
        <img class="snap-screen-logo" src="{{ $logo }}" alt="{{ $siteName }}">
      @endif

      <p class="snap-eyebrow">{{ $status === 429 ? __('Paused', 'snapshot-core') : __('Blocked', 'snapshot-core') }} · {{ $status }}</p>

      <h1 id="snap-screen-title" class="snap-screen-title">{{ $title }}</h1>

      <p class="snap-screen-copy">{{ $message }}</p>

      @if ($retryAt)
        <p class="snap-screen-copy">{{ __('Try again after', 'snapshot-core') }} <strong>{{ $retryAt }}</strong>.</p>
      @endif

      <dl class="snap-screen-meta">
        <dt>{{ __('Reference', 'snapshot-core') }}</dt>
        <dd><code>{{ $reference }}</code></dd>
      </dl>

      @if ($status === 429)
        <a class="snap-screen-link" href="{!! esc_url($homeUrl) !!}">{{ sprintf(__('Back to %s', 'snapshot-core'), $siteName) }}</a>
      @endif
    </main>
  </body>
</html>
