@php
  $pageUrl = fn (int $page) => add_query_arg(array_filter(['page' => 'snapshot-system', 'tab' => 'activity', 'user' => $raw['user'] ?: null, 'category' => $raw['category'], 'from' => $raw['from'], 'to' => $raw['to'], 's' => $raw['s'], 'people' => $raw['people'] ? 1 : null, 'paged' => $page > 1 ? $page : null]), admin_url('admin.php'));
  $severityPill = ['info' => 'is-quiet', 'notice' => 'is-info', 'warning' => 'is-warning', 'critical' => 'is-critical'];
@endphp

@if (! $ready)
  <div class="notice notice-warning inline"><p>{{ __('The activity log needs the security tables. Open Security to see why they are missing, or run wp snap security install.', 'snapshot-core') }}</p></div>
@else
  <section class="snap-panel">
    <div class="snap-panel-head">
      <h2>{{ __('Activity log', 'snapshot-core') }}</h2>
      <span class="snap-muted">{{ sprintf(_n('%s event', '%s events', $total, 'snapshot-core'), number_format_i18n($total)) }} · {{ sprintf(__('Kept for %d days', 'snapshot-core'), $retention) }}</span>
    </div>
    <p class="snap-muted">{{ __('Every change people make: content, media, menus, users, settings, plugins and themes, sign ins, and everything Security and SEO record. Times are in the site timezone.', 'snapshot-core') }}</p>

    <form method="get" action="{!! esc_url(admin_url('admin.php')) !!}" class="snap-filters">
      <input type="hidden" name="page" value="snapshot-system">
      <input type="hidden" name="tab" value="activity">
      <label>
        <span>{{ __('Person', 'snapshot-core') }}</span>
        <select name="user">
          <option value="">{{ __('Anyone', 'snapshot-core') }}</option>
          @foreach ($people as $person)
            <option value="{{ $person->user_id }}" @selected($raw['user'] === (int) $person->user_id)>{{ $person->username }} ({{ number_format_i18n((int) $person->total) }})</option>
          @endforeach
        </select>
      </label>
      <label>
        <span>{{ __('Area', 'snapshot-core') }}</span>
        <select name="category">
          <option value="">{{ __('Everything', 'snapshot-core') }}</option>
          @foreach ($categories as $key => $label)
            <option value="{{ $key }}" @selected($raw['category'] === $key)>{{ __($label, 'snapshot-core') }}</option>
          @endforeach
        </select>
      </label>
      <label>
        <span>{{ __('From', 'snapshot-core') }}</span>
        <input type="date" name="from" value="{{ $raw['from'] }}">
      </label>
      <label>
        <span>{{ __('To', 'snapshot-core') }}</span>
        <input type="date" name="to" value="{{ $raw['to'] }}">
      </label>
      <label>
        <span>{{ __('Search', 'snapshot-core') }}</span>
        <input type="search" name="s" value="{{ $raw['s'] }}">
      </label>
      <label class="snap-inline"><input type="checkbox" name="people" value="1" @checked($raw['people'])> {{ __('Only actions by signed in people', 'snapshot-core') }}</label>
      <button type="submit" class="button">{{ __('Filter', 'snapshot-core') }}</button>
    </form>
  </section>

  <section class="snap-panel">
    @if ($rows === [])
      <p class="snap-empty"><span class="dashicons dashicons-info" aria-hidden="true"></span> {{ __('Nothing recorded matches these filters.', 'snapshot-core') }}</p>
    @else
      <table class="widefat striped snap-activity-table">
        <thead>
          <tr>
            <th scope="col">{{ __('When', 'snapshot-core') }}</th>
            <th scope="col">{{ __('Who', 'snapshot-core') }}</th>
            <th scope="col">{{ __('Action', 'snapshot-core') }}</th>
            <th scope="col">{{ __('What happened', 'snapshot-core') }}</th>
            <th scope="col">{{ __('Address', 'snapshot-core') }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($rows as $row)
            @php
              $context = $row->context ? (json_decode($row->context, true) ?: []) : [];
              $time = strtotime($row->created_at.' UTC');
              $link = match ($context['object'] ?? '') {
                'post', 'attachment' => ! empty($context['id']) ? get_edit_post_link((int) $context['id'], 'raw') : '',
                'term' => ! empty($context['id']) && ! empty($context['taxonomy']) ? get_edit_term_link((int) $context['id'], (string) $context['taxonomy']) : '',
                'user' => ! empty($context['id']) ? get_edit_user_link((int) $context['id']) : '',
                'menu' => admin_url('nav-menus.php'),
                default => '',
              };
            @endphp
            <tr>
              <td><time datetime="{{ gmdate('c', $time) }}">{{ wp_date(get_option('date_format').' '.get_option('time_format'), $time) }}</time><br><span class="snap-muted">{{ sprintf(__('%s ago', 'snapshot-core'), human_time_diff($time)) }}</span></td>
              <td>
                @if ((int) $row->user_id)
                  <a href="{!! esc_url(add_query_arg('user', (int) $row->user_id, $pageUrl(1))) !!}">{{ $row->username ?: '#'.$row->user_id }}</a>
                @else
                  <span class="snap-muted">{{ $row->username ?: __('System or visitor', 'snapshot-core') }}</span>
                @endif
              </td>
              <td><span class="snap-pill {{ $severityPill[$row->severity] ?? '' }}">{{ $types[$row->event]['label'] ?? $row->event }}</span></td>
              <td>
                @if ($link)
                  <a href="{!! esc_url($link) !!}">{{ $row->message }}</a>
                @else
                  {{ $row->message }}
                @endif
                @if (! empty($context['changed']) && is_array($context['changed']))
                  <br><span class="snap-muted">{{ sprintf(__('Changed: %s', 'snapshot-core'), implode(', ', $context['changed'])) }}</span>
                @endif
                @if (! empty($context['changed_keys']) && is_array($context['changed_keys']))
                  <br><span class="snap-muted">{{ sprintf(__('Changed keys: %s', 'snapshot-core'), implode(', ', array_slice(array_map('strval', $context['changed_keys']), 0, 12))) }}</span>
                @endif
                @if (! empty($context['redacted']))
                  <br><span class="snap-muted">{{ __('Value not recorded because the setting looks like a secret.', 'snapshot-core') }}</span>
                @endif
                @if (! empty($context['fields']) && is_array($context['fields']))
                  <br><span class="snap-muted">{{ sprintf(__('Fields: %s', 'snapshot-core'), implode(', ', array_slice($context['fields'], 0, 12))) }}</span>
                @endif
                @if (isset($context['from'], $context['to']) && is_scalar($context['from']) && is_scalar($context['to']) && ($context['object'] ?? '') === 'option')
                  <br><span class="snap-muted">{{ sprintf(__('From %1$s to %2$s', 'snapshot-core'), wp_html_excerpt((string) $context['from'], 60, '…') ?: __('empty', 'snapshot-core'), wp_html_excerpt((string) $context['to'], 60, '…') ?: __('empty', 'snapshot-core')) }}</span>
                @endif
              </td>
              <td>@if ($row->ip !== '')<code>{{ $row->ip }}</code>@endif</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    <div class="snap-form-foot">
      <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
        {!! $nonce !!}
        <input type="hidden" name="action" value="snapshot_system_export_activity">
        <input type="hidden" name="user" value="{{ $raw['user'] ?: '' }}">
        <input type="hidden" name="category" value="{{ $raw['category'] }}">
        <input type="hidden" name="from" value="{{ $raw['from'] }}">
        <input type="hidden" name="to" value="{{ $raw['to'] }}">
        <input type="hidden" name="s" value="{{ $raw['s'] }}">
        <input type="hidden" name="people" value="{{ $raw['people'] ? 1 : '' }}">
        <button type="submit" class="button">{{ __('Download as CSV', 'snapshot-core') }}</button>
      </form>
      @if ($pages > 1)
        <nav class="snap-pager" aria-label="{{ __('Activity pages', 'snapshot-core') }}">
          @if ($page > 1)
            <a class="button" href="{!! esc_url($pageUrl($page - 1)) !!}">{{ __('Newer', 'snapshot-core') }}</a>
          @endif
          <span>{{ sprintf(__('Page %1$d of %2$d', 'snapshot-core'), $page, $pages) }}</span>
          @if ($page < $pages)
            <a class="button" href="{!! esc_url($pageUrl($page + 1)) !!}">{{ __('Older', 'snapshot-core') }}</a>
          @endif
        </nav>
      @endif
    </div>
  </section>
@endif
