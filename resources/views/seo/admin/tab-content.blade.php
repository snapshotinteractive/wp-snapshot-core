@php
  $robots = ['' => __('Default', 'snapshot-core'), 'noindex' => __('Hide', 'snapshot-core'), 'index' => __('Always show', 'snapshot-core')];
  $pageUrl = fn (int $page) => add_query_arg(array_filter(['page' => 'snapshot-seo', 'tab' => 'content', 'type' => $type, 'issue' => $issue, 's' => $search, 'paged' => $page > 1 ? $page : null]), admin_url('admin.php'));
@endphp

<section class="snap-panel">
  <div class="snap-panel-head">
    <h2>{{ __('Bulk editor', 'snapshot-core') }} @include('snapshot::seo.admin.help', ['topic' => 'check_descriptions', 'label' => __('Bulk editor', 'snapshot-core')])</h2>
    <span class="snap-muted">{{ sprintf(_n('%d entry', '%d entries', $total, 'snapshot-core'), $total) }}</span>
  </div>
  <p class="snap-muted">{{ __('Leave a field blank to use the template, shown in gray. Only rows that change are saved. Use the issue filter to work through what the content report found.', 'snapshot-core') }}</p>

  <form method="get" action="{!! esc_url(admin_url('admin.php')) !!}" class="snap-filters">
    <input type="hidden" name="page" value="snapshot-seo">
    <input type="hidden" name="tab" value="content">
    <label>
      <span>{{ __('Content type', 'snapshot-core') }}</span>
      <select name="type">
        <option value="">{{ __('All', 'snapshot-core') }}</option>
        @foreach ($types as $key => $config)
          <option value="{{ $key }}" @selected($type === $key)>{{ $config['plural'] }}</option>
        @endforeach
      </select>
    </label>
    <label>
      <span>{{ __('Issue', 'snapshot-core') }}</span>
      <select name="issue">
        <option value="">{{ __('Any', 'snapshot-core') }}</option>
        @foreach ($issues as $key => $label)
          <option value="{{ $key }}" @selected($issue === $key)>{{ $label }}{{ $report ? ' ('.($report['issues'][$key] ?? 0).')' : '' }}</option>
        @endforeach
      </select>
    </label>
    <label>
      <span>{{ __('Search', 'snapshot-core') }}</span>
      <input type="search" name="s" value="{{ $search }}">
    </label>
    <button type="submit" class="button">{{ __('Filter', 'snapshot-core') }}</button>
  </form>

  @if ($issue !== '' && ! $report)
    <p class="notice notice-warning inline">{{ __('The issue filter needs the content report. Run it from the overview.', 'snapshot-core') }}</p>
  @endif
</section>

@if ($rows === [])
  <section class="snap-panel"><p class="snap-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> {{ __('Nothing matches.', 'snapshot-core') }}</p></section>
@else
  <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-panel snap-bulk">
    {!! $nonce !!}
    <input type="hidden" name="action" value="snapshot_seo_save_content">
    <input type="hidden" name="type" value="{{ $type }}">
    <input type="hidden" name="issue" value="{{ $issue }}">
    <input type="hidden" name="s" value="{{ $search }}">
    <input type="hidden" name="paged" value="{{ $paged }}">

    <table class="widefat striped snap-bulk-table">
      <thead>
        <tr>
          <th scope="col">{{ __('Entry', 'snapshot-core') }}</th>
          <th scope="col">{{ __('Search title and description', 'snapshot-core') }}</th>
          <th scope="col">{{ __('Visibility', 'snapshot-core') }}</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($rows as $row)
          @php($prefix = 'rows['.$row['id'].']')
          <tr>
            <th scope="row">
              <a href="{!! esc_url($row['edit']) !!}"><strong>{{ $row['title'] }}</strong></a>
              <span class="snap-muted">{{ $row['type'] }}</span>
              <span class="snap-row-flags">
                @foreach ($row['flags'] as $flag)
                  <span @class(['snap-pill', 'is-warning' => $flag !== 'no_image', 'is-quiet' => $flag === 'no_image'])>{{ $issues[$flag] ?? $flag }}</span>
                @endforeach
                @if (! $row['resolved']['robots']['index'])
                  <span class="snap-pill">{{ sprintf(__('Hidden: %s', 'snapshot-core'), $row['resolved']['robots']['reason']) }}</span>
                @endif
              </span>
              <a class="snap-muted" href="{!! esc_url($row['view']) !!}" target="_blank" rel="noopener">{{ __('View', 'snapshot-core') }}<span class="screen-reader-text"> {{ $row['title'] }}</span></a>
            </th>
            <td>
              <label class="screen-reader-text" for="snap-bulk-title-{{ $row['id'] }}">{{ sprintf(__('Search title for %s', 'snapshot-core'), $row['title']) }}</label>
              <input type="text" id="snap-bulk-title-{{ $row['id'] }}" class="large-text" name="{{ $prefix }}[seo_title]" value="{{ $row['seo_title'] }}" placeholder="{{ $row['resolved']['title_source'] === 'template' ? $row['resolved']['title'] : $row['title_template'] }}" data-snap-count="60">
              <input type="hidden" name="{{ $prefix }}[seo_title__was]" value="{{ $row['seo_title'] }}">
              <label class="screen-reader-text" for="snap-bulk-description-{{ $row['id'] }}">{{ sprintf(__('Search description for %s', 'snapshot-core'), $row['title']) }}</label>
              <textarea id="snap-bulk-description-{{ $row['id'] }}" class="large-text" rows="2" name="{{ $prefix }}[seo_description]" placeholder="{{ $row['resolved']['description_source'] === 'template' ? ($row['resolved']['description'] ?: __('No description: the template found nothing to use. Write one here.', 'snapshot-core')) : '' }}" data-snap-count="155">{{ $row['seo_description'] }}</textarea>
              <input type="hidden" name="{{ $prefix }}[seo_description__was]" value="{{ $row['seo_description'] }}">
            </td>
            <td>
              <label class="screen-reader-text" for="snap-bulk-robots-{{ $row['id'] }}">{{ sprintf(__('Search visibility for %s', 'snapshot-core'), $row['title']) }}</label>
              <select id="snap-bulk-robots-{{ $row['id'] }}" name="{{ $prefix }}[seo_robots]">
                @foreach ($robots as $value => $label)
                  <option value="{{ $value }}" @selected($row['seo_robots'] === $value)>{{ $label }}</option>
                @endforeach
              </select>
              <input type="hidden" name="{{ $prefix }}[seo_robots__was]" value="{{ $row['seo_robots'] }}">
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <div class="snap-form-foot">
      <button type="submit" class="button button-primary">{{ __('Save changes', 'snapshot-core') }}</button>
      @if ($pages > 1)
        <nav class="snap-pager" aria-label="{{ __('Bulk editor pages', 'snapshot-core') }}">
          @if ($paged > 1)
            <a class="button" href="{!! esc_url($pageUrl($paged - 1)) !!}">{{ __('Previous', 'snapshot-core') }}</a>
          @endif
          <span>{{ sprintf(__('Page %1$d of %2$d', 'snapshot-core'), $paged, $pages) }}</span>
          @if ($paged < $pages)
            <a class="button" href="{!! esc_url($pageUrl($paged + 1)) !!}">{{ __('Next', 'snapshot-core') }}</a>
          @endif
        </nav>
      @endif
    </div>
  </form>
@endif
