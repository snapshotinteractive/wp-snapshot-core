<dialog class="snap-help-dialog" id="snap-seo-help" aria-labelledby="snap-seo-help-title">
  <form method="dialog" class="snap-help-dialog-inner">
    <header class="snap-help-dialog-head">
      <h2 id="snap-seo-help-title" data-snap-help-title></h2>
      <button type="submit" class="snap-help-close" value="close">
        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
        <span class="screen-reader-text">{{ __('Close', 'snapshot-core') }}</span>
      </button>
    </header>
    <div class="snap-help-dialog-body" data-snap-help-body></div>
    <footer class="snap-help-dialog-foot">
      <button type="submit" class="button button-primary" value="close">{{ __('Got it', 'snapshot-core') }}</button>
    </footer>
  </form>
</dialog>
