@php
  $time = is_array($change ?? null) ? strtotime((string) $change['at']) : false;
@endphp

@if ($time)
  @php
    $when = wp_date(get_option('date_format').' '.get_option('time_format'), $time);
    $actor = (string) $change['actor'];
  @endphp
  <p class="snap-muted">
    @if ($change['source'] === 'snapshot-os')
      {{ $actor !== '' ? sprintf(__('Last changed by Snapshot OS (%1$s), %2$s.', 'snapshot-core'), $actor, $when) : sprintf(__('Last changed by Snapshot OS, %s.', 'snapshot-core'), $when) }}
    @elseif ($change['source'] === 'wp-cli')
      {{ sprintf(__('Last changed with WP-CLI, %s.', 'snapshot-core'), $when) }}
    @elseif ($actor === '' || $actor === wp_get_current_user()->user_login)
      {{ sprintf(__('Last changed by you in wp-admin, %s.', 'snapshot-core'), $when) }}
    @else
      {{ sprintf(__('Last changed by %1$s in wp-admin, %2$s.', 'snapshot-core'), $actor, $when) }}
    @endif
  </p>
@endif
