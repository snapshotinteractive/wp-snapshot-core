<div class="snap-settings">
  <nav class="snap-settings-nav" aria-label="{{ __('Settings sections', 'snapshot-core') }}">
    <ul>
      @foreach ($sections as $key => $label)
        <li>
          <a @class(['is-active' => $key === $section]) href="{!! esc_url(admin_url('admin.php?page=snapshot-seo&tab=settings&section='.$key)) !!}" @if ($key === $section) aria-current="page" @endif>{{ __($label, 'snapshot-core') }}</a>
        </li>
      @endforeach
    </ul>
  </nav>

  <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-panel snap-settings-form">
    {!! $nonce !!}
    <input type="hidden" name="action" value="snapshot_seo_save_settings">
    <input type="hidden" name="section" value="{{ $section }}">

    <h2>{{ __($sections[$section], 'snapshot-core') }}</h2>

    @if ($section === 'identity')
      <p class="snap-note">{{ __('Blank fields are filled from Site Options and Settings, General, shown under each field as In use now. Fill one in only to say something different to search engines.', 'snapshot-core') }}</p>
    @endif

    @if ($sectionModules !== [])
      <p class="snap-section-modules">
        @foreach ($sectionModules as $module)
          <span @class(['snap-pill', 'is-ok' => $module['on'], 'is-warning' => ! $module['on']])>{{ sprintf($module['on'] ? __('%s: running', 'snapshot-core') : __('%s: off', 'snapshot-core'), __($module['label'], 'snapshot-core')) }}</span>
        @endforeach
        <a href="{!! esc_url(admin_url('admin.php?page=snapshot-seo&tab=modules')) !!}">{{ __('Switch modules on or off in the control panel', 'snapshot-core') }}</a>
      </p>
    @endif

    <div class="snap-fields">
      @foreach ($fields as $field)
        @include('snapshot::seo.admin.field', ['field' => $field])
      @endforeach
    </div>

    <div class="snap-form-foot">
      <button type="submit" class="button button-primary">{{ __('Save changes', 'snapshot-core') }}</button>
    </div>
  </form>
</div>
