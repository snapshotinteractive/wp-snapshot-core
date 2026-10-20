@php
  $id = 'snap-seo-'.$field['key'];
  $name = ($prefix ?? 'snapshot_seo').'['.$field['key'].']';
  $help = $id.'-help';
  $type = $field['type'];
  $value = $field['value'];
  $scope = $scope ?? '';
@endphp

<div @class(['snap-field', 'is-locked' => $field['locked'], 'is-toggle' => $type === 'bool', 'is-template' => $type === 'template'])>
  <div class="snap-field-label">
    @if ($type === 'key' || $type === 'image')
      <span class="snap-field-title">{{ __($field['label'], 'snapshot-core') }}</span>
    @else
      <label for="{{ $id }}">{{ __($field['label'], 'snapshot-core') }}</label>
    @endif
    @if (! empty($field['topic']))
      @include('snapshot::seo.admin.help', ['topic' => $field['topic'], 'label' => __($field['label'], 'snapshot-core')])
    @endif
    <p class="description" id="{{ $help }}">{{ __($field['help'], 'snapshot-core') }}</p>
    @if (($field['inherited'] ?? '') !== '' && ($type !== 'image'))
      <p class="snap-inherited"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> {{ __('In use now:', 'snapshot-core') }} <span>{{ wp_html_excerpt($field['inherited'], 140, '…') }}</span></p>
    @endif
  </div>

  <div class="snap-field-control">
    @if ($type === 'bool')
      <input type="checkbox" role="switch" class="snap-switch" id="{{ $id }}" name="{{ $name }}" value="1" aria-describedby="{{ $help }}" @checked($value) @disabled($field['locked'])>
    @elseif ($type === 'select')
      <select id="{{ $id }}" name="{{ $name }}" aria-describedby="{{ $help }}" @disabled($field['locked'])>
        @foreach ($field['choices'] as $choice => $label)
          <option value="{{ $choice }}" @selected((string) $value === (string) $choice)>{{ __($label, 'snapshot-core') }}</option>
        @endforeach
      </select>
    @elseif ($type === 'color')
      <span class="snap-color" data-snap-color>
        <input type="color" value="{{ $value }}" aria-label="{{ sprintf(__('Pick %s', 'snapshot-core'), __($field['label'], 'snapshot-core')) }}" data-snap-color-picker @disabled($field['locked'])>
        <input type="text" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" class="code" size="9" maxlength="7" placeholder="{{ __('Default', 'snapshot-core') }}" aria-describedby="{{ $help }}" data-snap-color-value @disabled($field['locked'])>
        <button type="button" class="button-link" data-snap-color-clear>{{ __('Use default', 'snapshot-core') }}</button>
      </span>
    @elseif ($type === 'int')
      <input type="number" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" class="small-text" aria-describedby="{{ $help }}" @if (isset($field['min'])) min="{{ $field['min'] }}" @endif @if (isset($field['max'])) max="{{ $field['max'] }}" @endif @disabled($field['locked'])>
    @elseif ($type === 'textarea' || $type === 'lines')
      <textarea id="{{ $id }}" name="{{ $name }}" rows="4" @class(['large-text', 'code' => $type === 'lines']) aria-describedby="{{ $help }}" placeholder="{{ $field['inherited'] ?? '' }}" @disabled($field['locked'])>{{ $value }}</textarea>
    @elseif ($type === 'template')
      <div class="snap-template" data-snap-template data-scope="{{ $scope }}" data-field="{{ $field['key'] }}">
        <input type="text" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" class="large-text code" aria-describedby="{{ $help }} {{ $id }}-sample" data-default="{{ $field['default'] }}" @disabled($field['locked'])>
        <p class="snap-template-sample" id="{{ $id }}-sample" aria-live="polite" data-snap-sample>@if (! empty($sample['output']))<span class="snap-template-sample-label">{{ $sample['name'] !== '' ? sprintf(__('Example with %s:', 'snapshot-core'), $sample['name']) : __('Example:', 'snapshot-core') }}</span><span>{{ $sample['output'] }}</span>@endif</p>
      </div>
    @elseif ($type === 'image')
      <div class="snap-image" data-snap-image>
        <input type="hidden" id="{{ $id }}" name="{{ $name }}" value="{{ $value ?: '' }}" data-snap-image-input>
        <div class="snap-image-preview" data-snap-image-preview>
          @if ($value)
            {!! wp_get_attachment_image((int) $value, 'medium', false, ['alt' => '']) !!}
          @elseif (($field['inherited'] ?? '') !== '' && str_starts_with($field['inherited'], 'http'))
            <img src="{!! esc_url($field['inherited']) !!}" alt="">
            <span class="snap-muted">{{ __('In use now, from Site Options', 'snapshot-core') }}</span>
          @endif
        </div>
        <p>
          <button type="button" class="button" data-snap-image-choose @disabled($field['locked'])>{{ __('Choose image', 'snapshot-core') }}</button>
          <button type="button" class="button-link" data-snap-image-clear @if (! $value) hidden @endif>{{ __('Use the inherited image', 'snapshot-core') }}</button>
        </p>
      </div>
    @elseif ($type === 'key')
      <p><code>{{ $value ?: __('Created on first use', 'snapshot-core') }}</code></p>
      @if (! $field['locked'])
        <label class="snap-inline"><input type="checkbox" name="snapshot_seo[{{ $field['key'] }}__generate]" value="1"> {{ __('Generate a new key', 'snapshot-core') }}</label>
      @endif
    @else
      <input type="text" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" class="regular-text" aria-describedby="{{ $help }}" placeholder="{{ $field['inherited'] ?? '' }}" @disabled($field['locked'])>
    @endif
  </div>

  @if ($field['locked'])
    <p class="snap-locked"><span class="dashicons dashicons-lock" aria-hidden="true"></span> {{ sprintf(__('Defined by %s in wp-config.php.', 'snapshot-core'), $field['constant']) }}</p>
  @endif
</div>
