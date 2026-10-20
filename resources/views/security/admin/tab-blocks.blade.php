<section class="snap-panel" aria-labelledby="snap-add-rule">
<h2 id="snap-add-rule">{{ __('Add IP rule', 'snapshot-core') }}</h2>
<p class="snap-muted">{{ sprintf(__('Your IP address: %s. Rules that would block it are rejected.', 'snapshot-core'), $clientIp) }}</p>

<form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-filters">
  {!! $nonces['add'] !!}
  <input type="hidden" name="action" value="snapshot_security_add_block">

  <label>
    <span>{{ __('IP address or CIDR range', 'snapshot-core') }}</span>
    <input type="text" name="target" required size="22" placeholder="203.0.113.7">
  </label>

  <label>
    <span>{{ __('Action', 'snapshot-core') }}</span>
    <select name="block_action">
      <option value="block">{{ __('Block site access', 'snapshot-core') }}</option>
      <option value="allow">{{ __('Allow (bypass blocks)', 'snapshot-core') }}</option>
    </select>
  </label>

  <label>
    <span>{{ __('Duration in minutes (blank: permanent)', 'snapshot-core') }}</span>
    <input type="number" name="minutes" min="0" step="1" size="8">
  </label>

  <label>
    <span>{{ __('Reason', 'snapshot-core') }}</span>
    <input type="text" name="reason" maxlength="200" size="28">
  </label>

  <button type="submit" class="button button-primary">{{ __('Add rule', 'snapshot-core') }}</button>
</form>
</section>

<section class="snap-panel" aria-labelledby="snap-active">
<h2 id="snap-active">{{ __('Active rules and lockouts', 'snapshot-core') }}</h2>
<table class="widefat striped">
  <thead>
    <tr>
      <th scope="col">{{ __('IP address or range', 'snapshot-core') }}</th>
      <th scope="col">{{ __('Action', 'snapshot-core') }}</th>
      <th scope="col">{{ __('Scope', 'snapshot-core') }}</th>
      <th scope="col">{{ __('Source', 'snapshot-core') }}</th>
      <th scope="col">{{ __('Reason', 'snapshot-core') }}</th>
      <th scope="col">{{ __('Expires', 'snapshot-core') }}</th>
      <th scope="col"><span class="screen-reader-text">{{ __('Remove', 'snapshot-core') }}</span></th>
    </tr>
  </thead>
  <tbody>
    @forelse ($rows as $row)
      <tr>
        <td><code>{{ $row->label }}</code></td>
        <td>{{ $row->action === 'allow' ? __('Allow', 'snapshot-core') : __('Block', 'snapshot-core') }}</td>
        <td>{{ $row->scope === 'login' ? __('Login only', 'snapshot-core') : __('Whole site', 'snapshot-core') }}</td>
        <td>{{ ['manual' => __('Manual', 'snapshot-core'), 'lockout' => __('Login lockout', 'snapshot-core'), 'firewall' => __('Firewall', 'snapshot-core')][$row->source] ?? $row->source }}</td>
        <td>{{ $row->reason }}</td>
        <td>{{ $row->expires_at ? wp_date('j M H:i', strtotime($row->expires_at.' UTC')) : __('Never', 'snapshot-core') }}</td>
        <td>
          <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
            <input type="hidden" name="_wpnonce" value="{{ $nonces['remove'] }}">
            <input type="hidden" name="action" value="snapshot_security_remove_block">
            <input type="hidden" name="id" value="{{ $row->id }}">
            <button type="submit" class="button-link button-link-delete">{{ __('Remove', 'snapshot-core') }}<span class="screen-reader-text"> {{ sprintf(__('rule for %s', 'snapshot-core'), $row->label) }}</span></button>
          </form>
        </td>
      </tr>
    @empty
      <tr><td colspan="7">{{ __('No active manual rules, lockouts or firewall blocks.', 'snapshot-core') }}</td></tr>
    @endforelse
  </tbody>
</table>
</section>

<section class="snap-panel" aria-labelledby="snap-feeds">
<h2 id="snap-feeds">{{ __('Threat intelligence feeds', 'snapshot-core') }}</h2>
<table class="widefat striped">
  <thead>
    <tr>
      <th scope="col">{{ __('Feed', 'snapshot-core') }}</th>
      <th scope="col">{{ __('Action', 'snapshot-core') }}</th>
      <th scope="col">{{ __('Entries', 'snapshot-core') }}</th>
      <th scope="col">{{ __('Last updated', 'snapshot-core') }}</th>
      <th scope="col">{{ __('Source', 'snapshot-core') }}</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($feeds as $key => $feed)
      @php($state = $status[$key] ?? null)
      <tr>
        <td>{{ $feed['label'] }}</td>
        <td>{{ $feed['action'] === 'block' ? __('Block site access', 'snapshot-core') : __('Stricter login limits', 'snapshot-core') }}</td>
        <td>{{ number_format_i18n($counts['feed:'.$key][$feed['action']] ?? 0) }}</td>
        <td>
          @if ($state && $state['synced_at'])
            {{ sprintf(__('%s ago', 'snapshot-core'), human_time_diff($state['synced_at'])) }}
          @elseif ($state === null)
            {{ __('Disabled', 'snapshot-core') }}
          @endif
          @if ($state && $state['error'])
            <br><span class="snap-pill is-warn">{{ $state['error'] }}</span>
          @endif
        </td>
        <td class="snap-muted">{{ $feed['credit'] }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-actions">
  {!! $nonces['run'] !!}
  <input type="hidden" name="action" value="snapshot_security_run">
  <input type="hidden" name="task" value="feeds">
  <button type="submit" class="button">{{ __('Update feeds now', 'snapshot-core') }}</button>
</form>
</section>
