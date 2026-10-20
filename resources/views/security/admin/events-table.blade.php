<table class="widefat striped snap-events">
  <thead>
    <tr>
      <th scope="col">{{ __('Time', 'snapshot-core') }}</th>
      <th scope="col">{{ __('Severity', 'snapshot-core') }}</th>
      <th scope="col">{{ __('Event', 'snapshot-core') }}</th>
      <th scope="col">{{ __('IP address', 'snapshot-core') }}</th>
      <th scope="col">{{ __('User', 'snapshot-core') }}</th>
      <th scope="col">{{ __('Details', 'snapshot-core') }}</th>
    </tr>
  </thead>
  <tbody>
    @forelse ($rows as $row)
      @php($context = $row->context ? json_decode($row->context, true) : null)
      <tr>
        <td><time datetime="{{ $row->created_at }}Z">{{ wp_date('j M Y H:i:s', strtotime($row->created_at.' UTC')) }}</time></td>
        <td><span class="snap-pill is-{{ $row->severity }}">{{ ucfirst($row->severity) }}</span></td>
        <td>{{ $types[$row->event]['label'] ?? $row->event }}<br><code class="snap-muted">{{ $row->event }}</code></td>
        <td>
          @if ($row->ip !== '')
            <a href="{!! esc_url(add_query_arg(['tab' => 'events', 'ip' => rawurlencode($row->ip)], $base)) !!}">{{ $row->ip }}</a>
          @endif
        </td>
        <td>
          @if ($row->username !== '')
            <a href="{!! esc_url(add_query_arg(['tab' => 'events', 'user' => rawurlencode($row->username)], $base)) !!}">{{ $row->username }}</a>
          @endif
        </td>
        <td>
          {{ $row->message }}
          @if ($context)
            <details class="snap-context">
              <summary>{{ __('Context', 'snapshot-core') }}</summary>
              <pre>{{ wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="6">{{ __('No events match the current filters.', 'snapshot-core') }}</td></tr>
    @endforelse
  </tbody>
</table>
