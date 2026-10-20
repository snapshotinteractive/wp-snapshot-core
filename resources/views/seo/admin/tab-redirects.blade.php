@php
  $base = admin_url('admin.php?page=snapshot-seo&tab=redirects');
  $origins = ['manual' => __('Added by hand', 'snapshot-core'), 'auto' => __('Automatic', 'snapshot-core'), 'trash' => __('Deleted entry', 'snapshot-core'), 'import:redirection' => __('Redirection plugin', 'snapshot-core'), 'import:rankmath' => __('Rank Math', 'snapshot-core'), 'import:yoast' => __('Yoast', 'snapshot-core'), 'import:csv' => __('CSV import', 'snapshot-core')];
  $pageUrl = fn (int $page) => add_query_arg(array_filter(['view' => $view === '404' ? '404' : null, 's' => $search, 'paged' => $page > 1 ? $page : null, 'bots' => $bots ? 1 : null]), $base);
@endphp

<nav class="snap-subtabs" aria-label="{{ __('Redirect views', 'snapshot-core') }}">
  <a @class(['is-active' => $view === 'redirects']) href="{!! esc_url($base) !!}" @if ($view === 'redirects') aria-current="page" @endif>{{ sprintf(__('Redirects (%d)', 'snapshot-core'), $counts['redirects']) }}</a>
  <a @class(['is-active' => $view === '404']) href="{!! esc_url($base.'&view=404') !!}" @if ($view === '404') aria-current="page" @endif>{{ sprintf(__('404 log (%d this week)', 'snapshot-core'), $counts['missing']) }}</a>
</nav>

@if (! $ready)
  <div class="notice notice-error inline"><p>{{ __('The redirect tables could not be created. Give the database user CREATE rights, then run wp snap seo install.', 'snapshot-core') }}</p></div>
@elseif ($view === 'redirects')
  @if (! $enabled)
    <div class="notice notice-warning inline"><p>{{ __('The Redirects module is off or paused, so these rules are not applied.', 'snapshot-core') }}</p></div>
  @endif

  <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-panel snap-redirect-form">
    {!! $nonces['add'] !!}
    <input type="hidden" name="action" value="snapshot_seo_add_redirect">
    <h2>{{ __('Add a redirect', 'snapshot-core') }} @include('snapshot::seo.admin.help', ['topic' => 'module_redirects', 'label' => __('Redirects', 'snapshot-core')])</h2>
    <div class="snap-redirect-grid">
      <label>
        <span>{{ __('Old address', 'snapshot-core') }}</span>
        <input type="text" name="source" class="regular-text code" placeholder="/old-page/" required>
      </label>
      <label>
        <span>{{ __('New address', 'snapshot-core') }}</span>
        <input type="text" name="target" class="regular-text code" placeholder="/new-page/ or https://">
      </label>
      <label>
        <span>{{ __('Type', 'snapshot-core') }}</span>
        <select name="code">
          @foreach ($codes as $code => $label)
            <option value="{{ $code }}">{{ $label }}</option>
          @endforeach
        </select>
      </label>
      <label>
        <span>{{ __('Match', 'snapshot-core') }}</span>
        <select name="match_type">
          @foreach ($matches as $key => $label)
            <option value="{{ $key }}">{{ $label }}</option>
          @endforeach
        </select>
      </label>
      <label>
        <span>{{ __('Note', 'snapshot-core') }}</span>
        <input type="text" name="note" class="regular-text">
      </label>
    </div>
    <p class="snap-muted">{{ __('301 for a page that moved for good. 410 for a page removed on purpose, with no replacement. Starts with also moves everything under the old address, for example /old-services/ to /services/. Regular expressions can use $1 in the new address.', 'snapshot-core') }}</p>
    <div class="snap-form-foot"><button type="submit" class="button button-primary">{{ __('Add redirect', 'snapshot-core') }}</button></div>
  </form>

  <section class="snap-panel">
    <form method="get" action="{!! esc_url(admin_url('admin.php')) !!}" class="snap-filters">
      <input type="hidden" name="page" value="snapshot-seo">
      <input type="hidden" name="tab" value="redirects">
      <label><span>{{ __('Search', 'snapshot-core') }}</span><input type="search" name="s" value="{{ $search }}"></label>
      <button type="submit" class="button">{{ __('Search', 'snapshot-core') }}</button>
    </form>

    @if ($rows === [])
      <p class="snap-empty"><span class="dashicons dashicons-info" aria-hidden="true"></span> {{ __('No redirects yet. Import the Redirection plugin map from Tools, or add one above.', 'snapshot-core') }}</p>
    @else
      <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
        {!! $nonces['bulk'] !!}
        <input type="hidden" name="action" value="snapshot_seo_redirect_action">
        <table class="widefat striped">
          <thead>
            <tr>
              <td class="check-column"><input type="checkbox" data-snap-check-all aria-label="{{ __('Select all', 'snapshot-core') }}"></td>
              <th scope="col">{{ __('Old address', 'snapshot-core') }}</th>
              <th scope="col">{{ __('New address', 'snapshot-core') }}</th>
              <th scope="col">{{ __('Type', 'snapshot-core') }}</th>
              <th scope="col">{{ __('Source', 'snapshot-core') }}</th>
              <th scope="col">{{ __('Hits', 'snapshot-core') }}</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($rows as $row)
              <tr @class(['is-disabled' => ! $row['enabled']])>
                <th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="{{ $row['id'] }}" aria-label="{{ sprintf(__('Select %s', 'snapshot-core'), $row['source']) }}"></th>
                <td><code>{{ $row['source'] }}</code> @if ($row['match_type'] !== 'exact')<span class="snap-pill is-info">{{ $matches[$row['match_type']] ?? $row['match_type'] }}</span>@endif @if (! $row['enabled'])<span class="snap-pill">{{ __('Disabled', 'snapshot-core') }}</span>@endif @if ($row['note'] !== '')<br><span class="snap-muted">{{ $row['note'] }}</span>@endif</td>
                <td>@if ($row['target'] !== '')<code>{{ $row['target'] }}</code>@else<span class="snap-muted">{{ __('None', 'snapshot-core') }}</span>@endif</td>
                <td>{{ $row['code'] }}</td>
                <td>{{ $origins[$row['origin']] ?? $row['origin'] }}</td>
                <td>{{ number_format_i18n((int) $row['hits']) }}@if ($row['last_hit'])<br><span class="snap-muted">{{ sprintf(__('%s ago', 'snapshot-core'), human_time_diff(strtotime($row['last_hit'].' UTC'))) }}</span>@endif</td>
              </tr>
            @endforeach
          </tbody>
        </table>
        <div class="snap-form-foot">
          <label class="screen-reader-text" for="snap-redirect-bulk">{{ __('Bulk action', 'snapshot-core') }}</label>
          <select id="snap-redirect-bulk" name="bulk">
            <option value="">{{ __('Bulk action', 'snapshot-core') }}</option>
            <option value="enable">{{ __('Enable', 'snapshot-core') }}</option>
            <option value="disable">{{ __('Disable', 'snapshot-core') }}</option>
            <option value="delete">{{ __('Delete', 'snapshot-core') }}</option>
          </select>
          <button type="submit" class="button" data-confirm="{{ __('Apply this action to the selected redirects?', 'snapshot-core') }}">{{ __('Apply', 'snapshot-core') }}</button>
        </div>
      </form>
    @endif
  </section>
@else
  @if (! $monitoring)
    <div class="notice notice-warning inline"><p>{{ __('The 404 monitor is off or paused, so new broken addresses are not recorded.', 'snapshot-core') }}</p></div>
  @endif

  <section class="snap-panel">
    <div class="snap-panel-head">
      <h2>{{ __('Addresses that answered page not found', 'snapshot-core') }} @include('snapshot::seo.admin.help', ['topic' => 'monitor', 'label' => __('404 monitor', 'snapshot-core')])</h2>
    </div>
    <form method="get" action="{!! esc_url(admin_url('admin.php')) !!}" class="snap-filters">
      <input type="hidden" name="page" value="snapshot-seo">
      <input type="hidden" name="tab" value="redirects">
      <input type="hidden" name="view" value="404">
      <label><span>{{ __('Search', 'snapshot-core') }}</span><input type="search" name="s" value="{{ $search }}"></label>
      <label class="snap-inline"><input type="checkbox" name="bots" value="1" @checked($bots)> {{ __('Include crawlers and scanners', 'snapshot-core') }}</label>
      <button type="submit" class="button">{{ __('Filter', 'snapshot-core') }}</button>
    </form>

    @if ($rows === [])
      <p class="snap-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> {{ __('No broken addresses recorded.', 'snapshot-core') }}</p>
    @else
      <table class="widefat striped snap-404-table">
        <thead>
          <tr>
            <th scope="col">{{ __('Address', 'snapshot-core') }}</th>
            <th scope="col">{{ __('Hits', 'snapshot-core') }}</th>
            <th scope="col">{{ __('Last seen', 'snapshot-core') }}</th>
            <th scope="col">{{ __('Redirect to', 'snapshot-core') }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($rows as $row)
            <tr>
              <td>
                <code>{{ $row['path'] }}</code>
                @if ($row['bot'])<span class="snap-pill">{{ __('Crawler', 'snapshot-core') }}</span>@endif
                @if ($row['referrer'] !== '')<br><span class="snap-muted">{{ __('Linked from', 'snapshot-core') }} {{ $row['referrer'] }}</span>@endif
              </td>
              <td>{{ number_format_i18n((int) $row['hits']) }}</td>
              <td>{{ sprintf(__('%s ago', 'snapshot-core'), human_time_diff(strtotime($row['last_seen'].' UTC'))) }}</td>
              <td>
                <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-inline-form">
                  {!! $nonces['add'] !!}
                  <input type="hidden" name="action" value="snapshot_seo_add_redirect">
                  <input type="hidden" name="source" value="{{ $row['path'] }}">
                  <input type="hidden" name="from_404" value="{{ $row['id'] }}">
                  <input type="hidden" name="code" value="301">
                  <label class="screen-reader-text" for="snap-404-{{ $row['id'] }}">{{ sprintf(__('Redirect %s to', 'snapshot-core'), $row['path']) }}</label>
                  <input type="text" id="snap-404-{{ $row['id'] }}" name="target" class="regular-text code" value="{{ $row['suggest'] }}" placeholder="/new-page/" required>
                  <button type="submit" class="button">{{ __('Redirect', 'snapshot-core') }}</button>
                </form>
                <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-inline-form">
                  {!! $nonces['forget'] !!}
                  <input type="hidden" name="action" value="snapshot_seo_forget_404">
                  <input type="hidden" name="ids[]" value="{{ $row['id'] }}">
                  <button type="submit" class="button-link">{{ __('Ignore', 'snapshot-core') }}<span class="screen-reader-text"> {{ $row['path'] }}</span></button>
                </form>
                @if ($row['suggest'] !== '')<span class="snap-muted">{{ __('Suggested from a matching entry.', 'snapshot-core') }}</span>@endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </section>
@endif

@if ($ready && $pages > 1)
  <nav class="snap-pager" aria-label="{{ __('Pages', 'snapshot-core') }}">
    @if ($page > 1)<a class="button" href="{!! esc_url($pageUrl($page - 1)) !!}">{{ __('Previous', 'snapshot-core') }}</a>@endif
    <span>{{ sprintf(__('Page %1$d of %2$d', 'snapshot-core'), $page, $pages) }}</span>
    @if ($page < $pages)<a class="button" href="{!! esc_url($pageUrl($page + 1)) !!}">{{ __('Next', 'snapshot-core') }}</a>@endif
  </nav>
@endif
