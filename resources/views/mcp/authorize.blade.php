<div class="snapshot-2fa">
  <p class="snap-eyebrow">{{ __('Connect an AI assistant', 'snapshot-core') }}</p>
  <p class="snap-2fa-lead">{{ sprintf(__('%1$s wants to work on %2$s as you, %3$s.', 'snapshot-core'), $client['name'], $site, $user->display_name) }}</p>

  <form class="snap-2fa-panel" method="post" action="{!! esc_url($action) !!}">
    {!! $nonce !!}

    <p class="snap-2fa-hint">{{ sprintf(__('After you allow it, you return to %s. The app named itself; only allow it if you started this connection just now.', 'snapshot-core'), $destination) }}</p>

    @if (! $known)
      <p class="snap-2fa-hint"><strong>{{ sprintf(__('%s is not an address this site recognises. If you did not start this from an app you trust, choose Deny.', 'snapshot-core'), $destination) }}</strong></p>
    @endif

    <label for="snap-mcp-level">{{ __('What it may do', 'snapshot-core') }}</label>
    <select id="snap-mcp-level" name="level" class="input">
      @foreach ($levels as $level => $label)
        <option value="{{ $level }}" @selected($level === $selected)>{{ __($label, 'snapshot-core') }}</option>
      @endforeach
    </select>

    <p class="snap-2fa-hint">{{ __('It works with your permissions, cannot post unfiltered HTML, and every change it makes is saved as a revision and recorded in the activity log. Disconnect it any time under Snapshot, AI access.', 'snapshot-core') }}</p>

    <button type="submit" name="decision" value="allow" class="button button-primary button-large snap-2fa-wide">{{ __('Allow', 'snapshot-core') }}</button>
    <button type="submit" name="decision" value="deny" class="button button-large snap-2fa-wide">{{ __('Deny', 'snapshot-core') }}</button>
  </form>
</div>
