<div class="snap-settings">
  <nav class="snap-settings-nav" aria-label="{{ __('Admin experience sections', 'snapshot-core') }}">
    <ul>
      @foreach ($sections as $key => $item)
        <li>
          <a @class(['is-active' => $key === $section]) href="{!! esc_url(admin_url('admin.php?page=snapshot-system&tab=experience&section='.$key)) !!}" @if ($key === $section) aria-current="page" @endif>{{ __($item['label'], 'snapshot-core') }}</a>
        </li>
      @endforeach
    </ul>
  </nav>

  <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-panel snap-settings-form">
    {!! $nonce !!}
    <input type="hidden" name="action" value="snapshot_system_save">
    <input type="hidden" name="section" value="{{ $section }}">

    <h2>{{ __($sections[$section]['label'], 'snapshot-core') }}</h2>
    <p class="snap-note">{{ __($sections[$section]['intro'], 'snapshot-core') }}</p>

    @if ($section === 'login')
      <p><a href="{!! esc_url($preview) !!}" target="_blank" rel="noopener">{{ __('Preview the sign in screen in a private window', 'snapshot-core') }}</a></p>
    @endif

    <div class="snap-fields">
      @foreach ($fields as $field)
        @include('snapshot::seo.admin.field', ['field' => $field, 'prefix' => 'snapshot_system'])
      @endforeach
    </div>

    <div class="snap-form-foot">
      <button type="submit" class="button button-primary">{{ __('Save changes', 'snapshot-core') }}</button>
    </div>
  </form>
</div>
