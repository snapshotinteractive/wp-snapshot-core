<button type="button" class="snap-help" data-snap-help="{{ $topic }}">
  <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
  <span class="screen-reader-text">{{ sprintf(__('Why this matters: %s', 'snapshot-core'), $label ?? '') }}</span>
</button>
