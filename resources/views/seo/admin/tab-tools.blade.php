<div class="snap-grid">
  <section class="snap-panel snap-panel-wide" aria-labelledby="snap-seo-import">
    <h2 id="snap-seo-import">{{ __('Import data', 'snapshot-core') }} @include('snapshot::seo.admin.help', ['topic' => 'import', 'label' => __('Import data', 'snapshot-core')])</h2>
    <p class="snap-muted">{{ __('Copies SEO data saved by other plugins into this module, so the plugins and their data can be removed. Until then their values are read live, so nothing is lost by waiting. Nothing is deleted from the other plugins, and running it again only fills what is still empty.', 'snapshot-core') }}</p>

    <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
      {!! $nonces['import'] !!}
      <input type="hidden" name="action" value="snapshot_seo_import">

      <table class="widefat striped snap-import-table">
        <thead>
          <tr>
            <td class="check-column"><span class="screen-reader-text">{{ __('Import', 'snapshot-core') }}</span></td>
            <th scope="col">{{ __('Data', 'snapshot-core') }}</th>
            <th scope="col">{{ __('Found', 'snapshot-core') }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($imports as $key => $item)
            <tr @class(['is-disabled' => $item['count'] === 0])>
              <th scope="row" class="check-column">
                <input type="checkbox" id="snap-import-{{ $key }}" name="sources[]" value="{{ $key }}" @checked($item['count'] > 0) @disabled($item['count'] === 0)>
              </th>
              <td>
                <label for="snap-import-{{ $key }}"><strong>{{ $item['label'] }}</strong></label>
                <br><span class="snap-muted">{{ $item['detail'] }}</span>
              </td>
              <td>{{ $item['count'] > 0 ? $item['found'] : __('Nothing found', 'snapshot-core') }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>

      <div class="snap-form-foot">
        <button type="submit" class="button button-primary" @disabled(collect($imports)->sum('count') === 0)>{{ __('Import data', 'snapshot-core') }}</button>
        <label class="snap-inline"><input type="checkbox" name="overwrite" value="1"> {{ __('Replace titles and descriptions already set in this module', 'snapshot-core') }}</label>
      </div>
    </form>

    @if ($lastImport)
      <p class="snap-muted">{{ sprintf(__('Last import %1$s ago: %2$s', 'snapshot-core'), human_time_diff((int) $lastImport['at']), $lastImport['message']) }}</p>
    @endif
  </section>

  <section class="snap-panel" aria-labelledby="snap-seo-csv">
    <h2 id="snap-seo-csv">{{ __('Redirects as CSV', 'snapshot-core') }}</h2>
    <p class="snap-muted">{{ __('Columns: source, target, code, match_type, note. Only source and target are required.', 'snapshot-core') }}</p>
    <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" enctype="multipart/form-data">
      {!! $nonces['csv'] !!}
      <input type="hidden" name="action" value="snapshot_seo_import_csv">
      <label for="snap-seo-csv-file" class="screen-reader-text">{{ __('CSV file', 'snapshot-core') }}</label>
      <input type="file" id="snap-seo-csv-file" name="csv" accept=".csv,text/csv" required>
      <p><button type="submit" class="button">{{ __('Import CSV', 'snapshot-core') }}</button></p>
    </form>
    <p><a class="button" href="{!! esc_url($nonces['export']) !!}">{{ __('Download all redirects', 'snapshot-core') }}</a></p>
  </section>
</div>

<div class="snap-grid">
  <section class="snap-panel snap-panel-wide" aria-labelledby="snap-seo-robots">
    <h2 id="snap-seo-robots">{{ __('robots.txt', 'snapshot-core') }} @include('snapshot::seo.admin.help', ['topic' => 'robots_txt', 'label' => __('robots.txt', 'snapshot-core')])</h2>
    @if (in_array('robots.txt', $files, true))
      <div class="notice notice-warning inline"><p>{{ __('A physical robots.txt file exists at the site root and is served instead of this one.', 'snapshot-core') }}</p></div>
    @endif
    <p class="snap-muted">{{ $blocked ? sprintf(__('Blocking %d AI crawlers by policy.', 'snapshot-core'), count($blocked)) : __('Every AI crawler is allowed.', 'snapshot-core') }} <a href="{!! esc_url(admin_url('admin.php?page=snapshot-seo&tab=settings&section=crawlers')) !!}">{{ __('Change the policy', 'snapshot-core') }}</a></p>
    <pre class="snap-code">{{ $robots }}</pre>
  </section>

  <section class="snap-panel" aria-labelledby="snap-seo-indexnow">
    <h2 id="snap-seo-indexnow">{{ __('IndexNow', 'snapshot-core') }} @include('snapshot::seo.admin.help', ['topic' => 'indexnow', 'label' => __('IndexNow', 'snapshot-core')])</h2>
    @if (! $indexnow['on'])
      <p class="snap-muted">{{ __('The IndexNow module is off.', 'snapshot-core') }}</p>
    @else
      <p class="snap-muted">
        @if ($indexnow['allowed'])
          {{ __('Changes are sent automatically.', 'snapshot-core') }}
        @else
          {{ __('Automatic pings are off on this environment. They run only on production with search engines allowed.', 'snapshot-core') }}
        @endif
      </p>
      <p class="snap-muted">{{ __('Key file:', 'snapshot-core') }} <a href="{!! esc_url($indexnow['key']) !!}" target="_blank" rel="noopener">{{ basename($indexnow['key']) }}</a></p>
      <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
        {!! $nonces['run'] !!}
        <input type="hidden" name="action" value="snapshot_seo_run">
        <input type="hidden" name="task" value="indexnow">
        <input type="hidden" name="return" value="tools">
        <label for="snap-seo-indexnow-urls">{{ __('Submit addresses now, one per line', 'snapshot-core') }}</label>
        <textarea id="snap-seo-indexnow-urls" name="urls" rows="3" class="large-text code"></textarea>
        <p><button type="submit" class="button">{{ __('Submit', 'snapshot-core') }}</button></p>
      </form>
      @if ($indexnow['log'])
        <h3 class="snap-subhead">{{ __('Recent submissions', 'snapshot-core') }}</h3>
        <ul class="snap-link-list">
          @foreach (array_slice($indexnow['log'], 0, 5) as $entry)
            <li>{{ sprintf(__('%1$s ago: %2$d addresses', 'snapshot-core'), human_time_diff((int) $entry['at']), (int) $entry['count']) }}@if ($entry['code'] !== null), {{ $entry['code'] }}@endif @if ($entry['error'] !== '')<span class="snap-muted">{{ $entry['error'] }}</span>@endif</li>
          @endforeach
        </ul>
      @endif
    @endif
  </section>
</div>

@if ($llms !== '')
  <section class="snap-panel" aria-labelledby="snap-seo-llms">
    <div class="snap-panel-head">
      <h2 id="snap-seo-llms">{{ __('llms.txt preview', 'snapshot-core') }} @include('snapshot::seo.admin.help', ['topic' => 'llms', 'label' => __('llms.txt', 'snapshot-core')])</h2>
      <a href="{!! esc_url(home_url('/llms.txt')) !!}" target="_blank" rel="noopener">{{ __('Open llms.txt', 'snapshot-core') }}</a>
    </div>
    @if (in_array('llms.txt', $files, true))
      <div class="notice notice-warning inline"><p>{{ __('A physical llms.txt file exists at the site root and is served instead of this one.', 'snapshot-core') }}</p></div>
    @endif
    <pre class="snap-code snap-code-tall">{{ $llms }}</pre>
  </section>
@endif

<section class="snap-panel" aria-labelledby="snap-seo-maintenance">
  <h2 id="snap-seo-maintenance">{{ __('Maintenance', 'snapshot-core') }}</h2>
  <div class="snap-action-list">
    @foreach (['report' => __('Rebuild the content report', 'snapshot-core'), 'caches' => __('Clear llms.txt and redirect caches', 'snapshot-core'), 'install' => __('Repair the redirect tables', 'snapshot-core')] as $task => $label)
      <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
        {!! $nonces['run'] !!}
        <input type="hidden" name="action" value="snapshot_seo_run">
        <input type="hidden" name="task" value="{{ $task }}">
        <input type="hidden" name="return" value="tools">
        <button type="submit" class="button">{{ $label }}</button>
      </form>
    @endforeach
  </div>
</section>
