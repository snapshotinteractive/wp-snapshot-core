@php
  $schemaLabels = snap_seo_schema_types();
  $valueOf = fn (array $fields, string $key) => collect($fields)->firstWhere('key', $key)['value'] ?? null;
@endphp

<form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-appearance">
  {!! $nonce !!}
  <input type="hidden" name="action" value="snapshot_seo_save_appearance">

  <div class="snap-appearance-main">
    <section class="snap-panel">
      <h2>{{ __('How templates work', 'snapshot-core') }} @include('snapshot::seo.admin.help', ['topic' => 'title_template', 'label' => __('Title templates', 'snapshot-core')])</h2>
      <ol class="snap-explain">
        <li>{{ __('Every content type has a title and a description template. Entries without their own title or description use them.', 'snapshot-core') }}</li>
        <li>{{ __('Variables in double percent signs are replaced with real values. Click one in the list to insert it where the cursor is.', 'snapshot-core') }}</li>
        <li>{{ __('Square brackets make a part optional: [ at %%field:company%%] disappears completely when the company is empty.', 'snapshot-core') }}</li>
        <li>{{ __('Each template shows an example built from a real entry. Clear a template to restore its default.', 'snapshot-core') }}</li>
      </ol>
    </section>

    <section class="snap-panel" aria-labelledby="snap-seo-special">
      <h2 id="snap-seo-special">{{ __('Home, search and not found', 'snapshot-core') }}</h2>
      @foreach ($special as $key => $group)
        <h3 class="snap-subhead">{{ $group['label'] }}</h3>
        <div class="snap-fields">
          @foreach ($group['fields'] as $field)
            @include('snapshot::seo.admin.field', ['field' => $field, 'scope' => $group['scope'], 'sample' => $group['samples'][$field['key']] ?? null])
          @endforeach
        </div>
      @endforeach
    </section>

    <section aria-labelledby="snap-seo-types">
      <h2 id="snap-seo-types" class="snap-section-title">{{ __('Content types', 'snapshot-core') }}</h2>
      @foreach ($types as $type => $config)
        @php
          $indexed = (bool) $valueOf($config['fields'], "pt_{$type}_index");
          $schema = (string) $valueOf($config['fields'], "pt_{$type}_schema");
        @endphp
        <details class="snap-type snap-panel" @if ($loop->first) open @endif>
          <summary>
            <span class="snap-type-name">{{ $config['plural'] }}</span>
            <span class="snap-muted">{{ sprintf(_n('%d published', '%d published', $config['count'], 'snapshot-core'), $config['count']) }}</span>
            <span @class(['snap-pill', 'is-ok' => $indexed, 'is-warning' => ! $indexed])>{{ $indexed ? __('In search', 'snapshot-core') : __('Hidden', 'snapshot-core') }}</span>
            <span class="snap-pill is-info">{{ $schemaLabels[$schema] ?? $schema }}</span>
          </summary>
          @if ($config['variables'] !== [])
            <p class="snap-type-variables">
              <span class="snap-muted">{{ sprintf(__('%s fields you can use:', 'snapshot-core'), $config['singular']) }}</span>
              @foreach ($config['variables'] as $variable)
                <button type="button" class="snap-variable is-compact" data-snap-variable="field:{{ $variable['name'] }}" title="{{ $variable['label'] }}"><code>%%field:{{ $variable['name'] }}%%</code></button>
              @endforeach
            </p>
          @endif
          <div class="snap-fields">
            @foreach ($config['fields'] as $field)
              @include('snapshot::seo.admin.field', [
                  'field' => $field,
                  'scope' => (str_starts_with($field['key'], 'pta_') ? 'pta:' : 'pt:').$type,
                  'sample' => $config['samples'][$field['key']] ?? null,
              ])
            @endforeach
          </div>
        </details>
      @endforeach
    </section>

    <section aria-labelledby="snap-seo-taxonomies">
      <h2 id="snap-seo-taxonomies" class="snap-section-title">{{ __('Categories, tags and other groupings', 'snapshot-core') }}</h2>
      @foreach ($taxonomies as $taxonomy => $config)
        @php($indexed = (bool) $valueOf($config['fields'], "tax_{$taxonomy}_index"))
        <details class="snap-type snap-panel">
          <summary>
            <span class="snap-type-name">{{ $config['plural'] }}</span>
            <span class="snap-muted">{{ sprintf(_n('%d term', '%d terms', $config['count'], 'snapshot-core'), $config['count']) }}</span>
            <span @class(['snap-pill', 'is-ok' => $indexed, 'is-warning' => ! $indexed])>{{ $indexed ? __('In search', 'snapshot-core') : __('Hidden', 'snapshot-core') }}</span>
          </summary>
          <div class="snap-fields">
            @foreach ($config['fields'] as $field)
              @include('snapshot::seo.admin.field', ['field' => $field, 'scope' => 'tax:'.$taxonomy, 'sample' => null])
            @endforeach
          </div>
        </details>
      @endforeach
    </section>

    <div class="snap-panel snap-form-foot snap-sticky-foot">
      <button type="submit" class="button button-primary">{{ __('Save search appearance', 'snapshot-core') }}</button>
    </div>
  </div>

  <aside class="snap-appearance-side" aria-labelledby="snap-seo-variables">
    <div class="snap-panel snap-variables" data-snap-variables>
      <h2 id="snap-seo-variables">{{ __('Variables', 'snapshot-core') }}</h2>
      <p class="snap-muted">{{ __('Click to insert into the template that last had focus.', 'snapshot-core') }}</p>
      @foreach ($variables as $group => $items)
        <h3 class="snap-subhead">{{ __($group, 'snapshot-core') }}</h3>
        <ul>
          @foreach ($items as $name => $variable)
            <li>
              <button type="button" class="snap-variable" data-snap-variable="{{ str_replace('NAME', '', $name) }}" title="{{ __($variable['help'], 'snapshot-core') }}">
                <code>%%{{ $name }}%%</code>
                <span>{{ __($variable['label'], 'snapshot-core') }}</span>
              </button>
            </li>
          @endforeach
        </ul>
      @endforeach
    </div>
  </aside>
</form>
