@php
  $statusPill = ['in' => 'is-ok', 'excluded' => 'is-warning', 'rule' => 'is-warning', 'hidden' => 'is-quiet', 'type' => 'is-quiet', 'empty' => 'is-quiet'];
  $levelPill = ['fail' => 'is-critical', 'warn' => 'is-warning', 'info' => 'is-info'];
  $issueLabels = ['extra' => __('Extra address', 'snapshot-core'), 'rule' => __('Rule', 'snapshot-core'), 'redirect' => __('Redirects', 'snapshot-core'), 'canonical' => __('Canonical', 'snapshot-core'), 'duplicate' => __('Duplicate', 'snapshot-core'), 'offsite' => __('Other site', 'snapshot-core'), 'private' => __('Not public', 'snapshot-core'), 'module' => __('Module off', 'snapshot-core')];
  $sectionUrl = fn (string $key, array $args = []) => add_query_arg(array_filter(['page' => 'snapshot-seo', 'tab' => 'sitemaps', 'section' => $key] + $args), admin_url('admin.php'));
  $pageUrl = fn (int $page) => $sectionUrl($section, ['status' => $status, 's' => $search, 'paged' => $page > 1 ? $page : null]);
  $current = $sections[$section] ?? null;
  $hasAdvanced = $rules !== [] || $extra !== [] || $advanced['sitemap_lastmod']['value'] !== 'modified';
@endphp

@if (! $public)
  <div class="notice notice-error inline"><p>{{ __('Search engines are discouraged under Settings, Reading, so WordPress serves no sitemap at all. Untick that setting when the site launches.', 'snapshot-core') }} <a href="{!! esc_url(admin_url('options-reading.php')) !!}">{{ __('Open Reading settings', 'snapshot-core') }}</a></p></div>
@elseif (! $module)
  <div class="notice notice-warning inline"><p>{{ __('The XML sitemaps module is off or paused, so the untuned WordPress sitemap is served and nothing below applies until it runs.', 'snapshot-core') }} <a href="{!! esc_url(admin_url('admin.php?page=snapshot-seo&tab=modules')) !!}">{{ __('Open the control panel', 'snapshot-core') }}</a></p></div>
@endif

<form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-panel snap-sitemap-sections">
  {!! $nonces['save'] !!}
  <input type="hidden" name="action" value="snapshot_seo_save_sitemap">
  <input type="hidden" name="scope" value="basic">

  <div class="snap-panel-head">
    <h2>{{ __('What goes in the sitemap', 'snapshot-core') }} @include('snapshot::seo.admin.help', ['topic' => 'sitemap_sections', 'label' => __('What goes in the sitemap', 'snapshot-core')])</h2>
    <a class="snap-muted" href="{!! esc_url($index) !!}" target="_blank" rel="noopener">{{ __('Open the sitemap', 'snapshot-core') }}</a>
  </div>
  <p class="snap-muted">{{ __('Switch a content type or taxonomy off to leave every address of that kind out. The pages stay live and searchable. Types hidden from search under Search appearance are always left out.', 'snapshot-core') }}</p>

  <table class="widefat striped snap-sitemap-table">
    <thead>
      <tr>
        <th scope="col">{{ __('Include', 'snapshot-core') }}</th>
        <th scope="col">{{ __('Content', 'snapshot-core') }}</th>
        <th scope="col">{{ __('Addresses listed', 'snapshot-core') }}</th>
        <th scope="col">{{ __('Left out by hand', 'snapshot-core') }}</th>
        <th scope="col"><span class="screen-reader-text">{{ __('Links', 'snapshot-core') }}</span></th>
      </tr>
    </thead>
    <tbody>
      @foreach ($sections as $key => $item)
        @php($id = 'snap-sitemap-'.sanitize_html_class(str_replace(':', '-', $key)))
        <tr>
          <td>
            <label class="screen-reader-text" for="{{ $id }}">{{ sprintf(__('Include %s in the sitemap', 'snapshot-core'), $item['label']) }}</label>
            <input type="hidden" name="snapshot_seo[{{ $item['setting'] }}]" value="{{ ! $item['searchable'] && $item['included'] ? 1 : 0 }}">
            <input type="checkbox" role="switch" class="snap-switch" id="{{ $id }}" name="snapshot_seo[{{ $item['setting'] }}]" value="1" @checked($item['included'] && $item['searchable']) @disabled($item['locked'] || ! $item['searchable'])>
          </td>
          <th scope="row">
            <strong>{{ $item['label'] }}</strong>
            <span class="snap-muted">{{ $item['kind'] === 'pt' ? __('Content type', 'snapshot-core') : __('Taxonomy archives', 'snapshot-core') }}</span>
            @if (! $item['searchable'])
              <span class="snap-pill is-quiet">{{ __('Hidden from search', 'snapshot-core') }}</span>
            @endif
          </th>
          <td>{{ number_format_i18n($item['count']) }}</td>
          <td>{{ $item['excluded'] ? number_format_i18n($item['excluded']) : '' }}</td>
          <td class="snap-sitemap-links">
            <a href="{!! esc_url($sectionUrl($key)) !!}#snap-sitemap-entries">{{ __('Curate', 'snapshot-core') }}<span class="screen-reader-text"> {{ $item['label'] }}</span></a>
            @if ($item['count'] && $item['xml'])
              <a href="{!! esc_url($item['xml']) !!}" target="_blank" rel="noopener">{{ __('XML', 'snapshot-core') }}<span class="screen-reader-text"> {{ $item['label'] }}</span></a>
            @endif
          </td>
        </tr>
      @endforeach
      @if ($extra !== [])
        <tr>
          <td><span class="snap-pill is-ok">{{ __('On', 'snapshot-core') }}</span></td>
          <th scope="row"><strong>{{ __('Extra addresses', 'snapshot-core') }}</strong> <span class="snap-muted">{{ __('From the advanced options', 'snapshot-core') }}</span></th>
          <td>{{ number_format_i18n(count($extra)) }}</td>
          <td></td>
          <td class="snap-sitemap-links">@if ($extraXml)<a href="{!! esc_url($extraXml) !!}" target="_blank" rel="noopener">{{ __('XML', 'snapshot-core') }}</a>@endif</td>
        </tr>
      @endif
    </tbody>
  </table>

  <div class="snap-form-foot">
    <button type="submit" class="button button-primary">{{ __('Save sitemap contents', 'snapshot-core') }}</button>
  </div>
</form>

<details class="snap-panel snap-advanced" @if ($hasAdvanced) open @endif>
  <summary><h2>{{ __('Advanced options', 'snapshot-core') }}</h2> <span class="snap-muted">{{ __('Address rules, extra addresses, dates and paging', 'snapshot-core') }}</span></summary>

  <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
    {!! $nonces['save'] !!}
    <input type="hidden" name="action" value="snapshot_seo_save_sitemap">
    <input type="hidden" name="scope" value="advanced">

    <div class="snap-fields">
      @foreach (['sitemap_exclude_rules', 'sitemap_extra_urls', 'sitemap_lastmod', 'sitemap_max_urls', 'sitemap_legacy_urls'] as $key)
        @include('snapshot::seo.admin.field', ['field' => $advanced[$key]])
      @endforeach
    </div>

    <div class="snap-form-foot">
      <button type="submit" class="button button-primary">{{ __('Save advanced options', 'snapshot-core') }}</button>
    </div>
  </form>
</details>

<section class="snap-panel" id="snap-sitemap-entries">
  <div class="snap-panel-head">
    <h2>{{ __('Curate entries', 'snapshot-core') }} @include('snapshot::seo.admin.help', ['topic' => 'sitemap_entry', 'label' => __('Curate entries', 'snapshot-core')])</h2>
    @if ($current)
      <span class="snap-muted">{{ sprintf(_n('%d entry', '%d entries', $total, 'snapshot-core'), $total) }}</span>
    @endif
  </div>

  <form method="get" action="{!! esc_url(admin_url('admin.php')) !!}" class="snap-filters">
    <input type="hidden" name="page" value="snapshot-seo">
    <input type="hidden" name="tab" value="sitemaps">
    <label>
      <span>{{ __('Sitemap', 'snapshot-core') }}</span>
      <select name="section">
        @foreach ($sections as $key => $item)
          <option value="{{ $key }}" @selected($section === $key)>{{ $item['label'] }}</option>
        @endforeach
      </select>
    </label>
    <label>
      <span>{{ __('Show', 'snapshot-core') }}</span>
      <select name="status">
        <option value="">{{ __('Every entry', 'snapshot-core') }}</option>
        <option value="excluded" @selected($status === 'excluded')>{{ __('Left out by hand', 'snapshot-core') }}</option>
      </select>
    </label>
    <label>
      <span>{{ __('Search', 'snapshot-core') }}</span>
      <input type="search" name="s" value="{{ $search }}">
    </label>
    <button type="submit" class="button">{{ __('Filter', 'snapshot-core') }}</button>
  </form>

  @if ($current && ! ($current['included'] && $current['searchable']))
    <p class="notice notice-warning inline">{{ sprintf(__('%s is left out of the sitemap as a whole, so no entry below is listed whatever its own setting.', 'snapshot-core'), $current['label']) }}</p>
  @endif

  @if ($rows === [])
    <p class="snap-empty"><span class="dashicons dashicons-info" aria-hidden="true"></span> {{ __('Nothing matches.', 'snapshot-core') }}</p>
  @else
    <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
      {!! $nonces['entries'] !!}
      <input type="hidden" name="action" value="snapshot_seo_sitemap_entries">
      <input type="hidden" name="section" value="{{ $section }}">
      <input type="hidden" name="status" value="{{ $status }}">
      <input type="hidden" name="s" value="{{ $search }}">
      <input type="hidden" name="paged" value="{{ $paged }}">

      <table class="widefat striped">
        <thead>
          <tr>
            <td class="check-column"><input type="checkbox" data-snap-check-all aria-label="{{ __('Select all', 'snapshot-core') }}"></td>
            <th scope="col">{{ __('Entry', 'snapshot-core') }}</th>
            <th scope="col">{{ __('Sitemap', 'snapshot-core') }}</th>
            <th scope="col">{{ ($current['kind'] ?? 'pt') === 'pt' ? __('Last updated', 'snapshot-core') : __('Entries', 'snapshot-core') }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($rows as $row)
            <tr>
              <th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="{{ $row['id'] }}" aria-label="{{ sprintf(__('Select %s', 'snapshot-core'), $row['title']) }}"></th>
              <td>
                <a href="{!! esc_url($row['edit']) !!}"><strong>{{ $row['title'] }}</strong></a><br>
                <a class="snap-muted" href="{!! esc_url($row['url']) !!}" target="_blank" rel="noopener">{{ wp_make_link_relative($row['url']) }}</a>
              </td>
              <td><span class="snap-pill {{ $statusPill[$row['status']['code']] ?? '' }}">{{ $row['status']['label'] }}</span></td>
              <td>{{ $row['lastmod'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>

      <div class="snap-form-foot">
        <label class="screen-reader-text" for="snap-sitemap-bulk">{{ __('Bulk action', 'snapshot-core') }}</label>
        <select id="snap-sitemap-bulk" name="bulk">
          <option value="exclude">{{ __('Leave out of the sitemap', 'snapshot-core') }}</option>
          <option value="include">{{ __('Put back in the sitemap', 'snapshot-core') }}</option>
        </select>
        <button type="submit" class="button">{{ __('Apply to selected', 'snapshot-core') }}</button>
        @if ($pages > 1)
          <nav class="snap-pager" aria-label="{{ __('Entry pages', 'snapshot-core') }}">
            @if ($paged > 1)
              <a class="button" href="{!! esc_url($pageUrl($paged - 1)) !!}#snap-sitemap-entries">{{ __('Previous', 'snapshot-core') }}</a>
            @endif
            <span>{{ sprintf(__('Page %1$d of %2$d', 'snapshot-core'), $paged, $pages) }}</span>
            @if ($paged < $pages)
              <a class="button" href="{!! esc_url($pageUrl($paged + 1)) !!}#snap-sitemap-entries">{{ __('Next', 'snapshot-core') }}</a>
            @endif
          </nav>
        @endif
      </div>
    </form>
  @endif
</section>

<div class="snap-grid">
  <section class="snap-panel">
    <div class="snap-panel-head">
      <h2>{{ __('Check the sitemap', 'snapshot-core') }} @include('snapshot::seo.admin.help', ['topic' => 'sitemap_check', 'label' => __('Check the sitemap', 'snapshot-core')])</h2>
      @if ($check)
        <span class="snap-muted">{{ sprintf(__('Last run %s ago', 'snapshot-core'), human_time_diff($check['at'])) }}</span>
      @endif
    </div>

    @if (! $check)
      <p class="snap-muted">{{ __('Not run yet. It walks every address in the sitemap and reports redirects, canonicals that point elsewhere, duplicates, broken extra addresses and rules that match nothing.', 'snapshot-core') }}</p>
    @else
      <p>
        <strong>{{ sprintf(_n('%s address', '%s addresses', $check['total'], 'snapshot-core'), number_format_i18n($check['total'])) }}</strong>
        @if ($check['issues'] === [])
          <span class="snap-pill is-ok">{{ __('No problems found', 'snapshot-core') }}</span>
        @else
          @foreach ($check['counts'] as $level => $count)
            <span class="snap-pill {{ $levelPill[$level] ?? '' }}">{{ sprintf(_n('%d issue', '%d issues', $count, 'snapshot-core'), $count) }}</span>
          @endforeach
        @endif
      </p>
      @if ($check['issues'] !== [])
        <ul class="snap-sitemap-issues">
          @foreach (array_slice($check['issues'], 0, 50) as $issue)
            <li>
              <span class="snap-pill {{ $levelPill[$issue['level']] ?? '' }}">{{ $issueLabels[$issue['type']] ?? $issue['type'] }}</span>
              <code>{{ wp_make_link_relative($issue['url']) ?: $issue['url'] }}</code>
              <span>{{ $issue['detail'] }}</span>
            </li>
          @endforeach
        </ul>
        @if (count($check['issues']) > 50)
          <p class="snap-muted">{{ sprintf(__('And %d more. Run wp snap seo sitemap check for the full list.', 'snapshot-core'), count($check['issues']) - 50) }}</p>
        @endif
      @endif
    @endif

    <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-form-foot">
      {!! $nonces['check'] !!}
      <input type="hidden" name="action" value="snapshot_seo_sitemap_check">
      <button type="submit" class="button button-primary">{{ __('Check the sitemap now', 'snapshot-core') }}</button>
    </form>
  </section>

  <section class="snap-panel">
    <div class="snap-panel-head">
      <h2>{{ __('Submit', 'snapshot-core') }} @include('snapshot::seo.admin.help', ['topic' => 'sitemaps', 'label' => __('Submit', 'snapshot-core')])</h2>
    </div>
    <p class="snap-muted">{{ __('Submit the sitemap address once in each search engine. They read it again on their own after that.', 'snapshot-core') }}</p>
    <p><code>{{ $index }}</code></p>
    <ul class="snap-link-list">
      <li><a href="{!! esc_url($consoles['google']) !!}" target="_blank" rel="noopener">{{ __('Google Search Console, Sitemaps', 'snapshot-core') }}</a></li>
      <li><a href="{!! esc_url($consoles['bing']) !!}" target="_blank" rel="noopener">{{ __('Bing Webmaster Tools, Sitemaps', 'snapshot-core') }}</a></li>
    </ul>

    <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-form-foot">
      {!! $nonces['indexnow'] !!}
      <input type="hidden" name="action" value="snapshot_seo_sitemap_indexnow">
      <button type="submit" class="button" @disabled(! $indexnow) data-confirm="{{ __('Send every address in the sitemap to IndexNow? Use it after a launch or a large change, not routinely.', 'snapshot-core') }}">{{ __('Send every address to IndexNow', 'snapshot-core') }}</button>
      @if (! $indexnow)
        <span class="snap-muted">{{ __('Available on a public production site with the IndexNow module on.', 'snapshot-core') }}</span>
      @endif
    </form>
  </section>
</div>
