<form method="get" class="snap-panel snap-filters">
  <input type="hidden" name="page" value="snapshot-security">
  <input type="hidden" name="tab" value="events">

  <label>
    <span>{{ __('Severity', 'snapshot-core') }}</span>
    <select name="severity">
      <option value="">{{ __('All severities', 'snapshot-core') }}</option>
      @foreach (array_keys(snap_security_severities()) as $severity)
        <option value="{{ $severity }}" @selected($filters['severity'] === $severity)>{{ ucfirst($severity) }}</option>
      @endforeach
    </select>
  </label>

  <label>
    <span>{{ __('Event type', 'snapshot-core') }}</span>
    <select name="event">
      <option value="">{{ __('All event types', 'snapshot-core') }}</option>
      @foreach ($types as $key => $type)
        <option value="{{ $key }}" @selected($filters['event'] === $key)>{{ $type['label'] }}</option>
      @endforeach
    </select>
  </label>

  <label>
    <span>{{ __('IP address', 'snapshot-core') }}</span>
    <input type="text" name="ip" value="{{ $filters['ip'] }}" size="18">
  </label>

  <label>
    <span>{{ __('Username', 'snapshot-core') }}</span>
    <input type="text" name="user" value="{{ $filters['user'] }}" size="14">
  </label>

  <button type="submit" class="button">{{ __('Apply filters', 'snapshot-core') }}</button>
  <a class="button-link" href="{!! esc_url(admin_url('admin.php?page=snapshot-security&tab=events')) !!}">{{ __('Reset', 'snapshot-core') }}</a>
</form>

<p class="snap-muted">{{ sprintf(_n('%s matching event', '%s matching events', $total, 'snapshot-core'), number_format_i18n($total)) }}</p>

@include('snapshot::security.admin.events-table', ['rows' => $rows, 'types' => $types, 'base' => admin_url('admin.php?page=snapshot-security')])

@if ($pages > 1)
  <div class="tablenav"><div class="tablenav-pages">
    {!! paginate_links([
      'base' => add_query_arg('paged', '%#%'),
      'format' => '',
      'current' => $page,
      'total' => $pages,
    ]) !!}
  </div></div>
@endif
