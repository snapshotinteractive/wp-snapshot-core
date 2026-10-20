@php
  $pill = ['ok' => 'is-ok', 'warn' => 'is-warning', 'fail' => 'is-critical', 'info' => 'is-info'];
  $word = ['ok' => __('OK', 'snapshot-core'), 'warn' => __('Review', 'snapshot-core'), 'fail' => __('Fix', 'snapshot-core'), 'info' => __('Info', 'snapshot-core')];
@endphp

<div class="snap-system-groups">
  @foreach ($groups as $key => $group)
    <section class="snap-panel" aria-labelledby="snap-system-{{ $key }}">
      <div class="snap-panel-head">
        <h2 id="snap-system-{{ $key }}">{{ $group['label'] }}</h2>
      </div>
      <table class="widefat striped snap-system-table">
        <tbody>
          @foreach ($group['checks'] as $check)
            <tr>
              <th scope="row">{{ $check['label'] }}</th>
              <td>
                <span class="snap-system-value">{{ $check['value'] }}</span>
                @if ($check['detail'] !== '')
                  <span class="snap-muted">{{ $check['detail'] }}</span>
                @endif
              </td>
              <td class="snap-system-state"><span class="snap-pill {{ $pill[$check['status']] ?? '' }}">{{ $word[$check['status']] ?? $check['status'] }}</span></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </section>
  @endforeach
</div>

<section class="snap-panel">
  <div class="snap-panel-head">
    <h2>{{ __('Share with support', 'snapshot-core') }}</h2>
  </div>
  <p class="snap-muted">{{ __('Downloads every check above as plain text for a support request. It holds versions and settings, never passwords or keys.', 'snapshot-core') }}</p>
  <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-form-foot">
    {!! $nonce !!}
    <input type="hidden" name="action" value="snapshot_system_report">
    <button type="submit" class="button button-primary">{{ __('Download the diagnostics report', 'snapshot-core') }}</button>
    @foreach ($links as $name => $url)
      <a class="button" href="{!! esc_url($url) !!}">{{ ['security' => __('Security', 'snapshot-core'), 'seo' => __('SEO', 'snapshot-core'), 'health' => __('Site Health', 'snapshot-core')][$name] }}</a>
    @endforeach
  </form>
</section>
