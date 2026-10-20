<div class="snap-actions">
  @foreach (['scan' => __('Run file integrity scan', 'snapshot-core'), 'vulns' => __('Check for vulnerabilities', 'snapshot-core')] as $task => $label)
    <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
      {!! $nonces['run'] !!}
      <input type="hidden" name="action" value="snapshot_security_run">
      <input type="hidden" name="task" value="{{ $task }}">
      <button type="submit" @class(['button', 'button-primary' => $loop->first])>{{ $label }}</button>
    </form>
  @endforeach
</div>

<section class="snap-panel" aria-labelledby="snap-vulns">
<h2 id="snap-vulns">{{ __('Known vulnerabilities', 'snapshot-core') }}</h2>
@if (empty($vulns['at']))
  <p class="snap-empty">{{ __('No check has run yet.', 'snapshot-core') }}</p>
@else
  <p class="snap-muted">{{ sprintf(__('Last checked %s ago against the WPVulnerability database.', 'snapshot-core'), human_time_diff($vulns['at'])) }}
    @if (! empty($vulns['errors']))
      {{ sprintf(__('%d lookups failed and will be retried on the next run.', 'snapshot-core'), $vulns['errors']) }}
    @endif
  </p>
  <table class="widefat striped">
    <thead>
      <tr>
        <th scope="col">{{ __('Component', 'snapshot-core') }}</th>
        <th scope="col">{{ __('Installed version', 'snapshot-core') }}</th>
        <th scope="col">{{ __('Vulnerability', 'snapshot-core') }}</th>
        <th scope="col">{{ __('Fixed in', 'snapshot-core') }}</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($vulns['items'] as $item)
        <tr>
          <td>{{ $item['name'] }} <span class="snap-muted">{{ $item['type'] }}</span></td>
          <td>{{ $item['version'] }}</td>
          <td>
            {{ $item['title'] }}
            @foreach ($item['links'] as $link)
              <br><a href="{!! esc_url($link) !!}" target="_blank" rel="noopener noreferrer">{{ wp_parse_url($link, PHP_URL_HOST) }}</a>
            @endforeach
          </td>
          <td>{{ $item['fixed_in'] ?: __('No fix available', 'snapshot-core') }}</td>
        </tr>
      @empty
        <tr><td colspan="4">{{ __('No known vulnerabilities in installed versions.', 'snapshot-core') }}</td></tr>
      @endforelse
    </tbody>
  </table>
@endif
</section>

<section class="snap-panel" aria-labelledby="snap-integrity">
<h2 id="snap-integrity">{{ __('File integrity', 'snapshot-core') }}</h2>
@if (empty($scan['at']))
  <p class="snap-empty">{{ __('No scan has run yet.', 'snapshot-core') }}</p>
@else
  <p class="snap-muted">{{ sprintf(__('Last scanned %1$s ago: %2$d findings, %3$d critical, %4$d new since the previous scan.', 'snapshot-core'), human_time_diff($scan['at']), $scan['total'], $scan['counts']['critical'], $scan['new']) }}</p>
  <table class="widefat striped">
    <thead>
      <tr>
        <th scope="col">{{ __('Severity', 'snapshot-core') }}</th>
        <th scope="col">{{ __('Finding', 'snapshot-core') }}</th>
        <th scope="col">{{ __('Path', 'snapshot-core') }}</th>
        <th scope="col">{{ __('Detail', 'snapshot-core') }}</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($scan['findings'] as $finding)
        <tr>
          <td><span class="snap-pill is-{{ $finding['severity'] }}">{{ ucfirst($finding['severity']) }}</span></td>
          <td><code>{{ $finding['type'] }}</code></td>
          <td><code>{{ $finding['path'] }}</code></td>
          <td>{{ $finding['detail'] }}</td>
        </tr>
      @empty
        <tr><td colspan="4">{{ __('All files match their checksums.', 'snapshot-core') }}</td></tr>
      @endforelse
    </tbody>
  </table>
@endif
</section>

<section class="snap-panel" aria-labelledby="snap-baseline">
<h2 id="snap-baseline">{{ __('Baseline', 'snapshot-core') }}</h2>
<p>{{ __('Components without published checksums, such as premium plugins or a theme deployed without a manifest, are compared against a baseline recorded on first scan. After an authorized change, record the current files as the new baseline.', 'snapshot-core') }}</p>
<form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" data-snap-confirm="{{ __('Record the current files as trusted? Only continue if every change since the last baseline was authorized.', 'snapshot-core') }}">
  {!! $nonces['accept'] !!}
  <input type="hidden" name="action" value="snapshot_security_accept_baseline">
  <button type="submit" class="button">{{ __('Update baseline', 'snapshot-core') }}</button>
</form>
</section>
