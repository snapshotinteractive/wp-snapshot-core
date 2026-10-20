@php
  $bearer = $new !== '' ? $new : 'YOUR_TOKEN';
  $command = $oauth ? "claude mcp add --transport http {$host} '{$endpoint}'" : "claude mcp add --transport http {$host} '{$endpoint}' --header 'Authorization: Bearer {$bearer}'";
  $tokenCommand = "claude mcp add --transport http {$host} '{$endpoint}' --header 'Authorization: Bearer {$bearer}'";
@endphp

<div class="snap-grid">
  <section class="snap-panel">
    <div class="snap-panel-head">
      <h2>{{ __('AI access over MCP', 'snapshot-core') }}</h2>
      @if ($enabled)
        <span class="snap-pill is-ok">{{ __('On', 'snapshot-core') }}</span>
      @else
        <span class="snap-pill is-quiet">{{ __('Off', 'snapshot-core') }}</span>
      @endif
    </div>
    <p class="snap-muted">{{ __('Lets an AI assistant such as Claude read and edit this site through the Model Context Protocol, signed in as the administrator who connected it. It works with content, fields, media, terms, menus and Site Options. It never touches code, plugins, themes, users, or Security and SEO settings. Every change it makes is in the activity log.', 'snapshot-core') }}</p>

    @if (! $enabled)
      <p>{{ __('MCP is switched off. Nothing answers at the address below and every connection is refused. To switch it on, add this to wp-config.php:', 'snapshot-core') }}</p>
      <pre class="snap-system-log" tabindex="0">define('SNAPSHOT_MCP_ENABLED', true);
define('SNAPSHOT_MCP_ACCESS', 'edit');</pre>
    @endif

    <dl class="snap-system-facts">
      <dt>{{ __('Switch', 'snapshot-core') }}</dt>
      <dd><code>SNAPSHOT_MCP_ENABLED</code> {{ $enabled ? __('is true', 'snapshot-core') : __('is not set to true', 'snapshot-core') }}</dd>
      <dt>{{ __('Most a connection can do', 'snapshot-core') }}</dt>
      <dd><code>SNAPSHOT_MCP_ACCESS</code> {{ $access }}: {{ __($levels[$access] ?? '', 'snapshot-core') }}</dd>
      <dt>{{ __('Address', 'snapshot-core') }}</dt>
      <dd><code>{{ $endpoint }}</code></dd>
      <dt>{{ __('Sign in from the app', 'snapshot-core') }}</dt>
      <dd>{{ $oauth ? __('Yes, with OAuth', 'snapshot-core') : ($pretty ? __('Off (SNAPSHOT_MCP_OAUTH is false); use a token', 'snapshot-core') : __('Needs pretty permalinks; use a token', 'snapshot-core')) }}</dd>
      <dt>{{ __('Two factor sign in', 'snapshot-core') }}</dt>
      <dd>{{ $twoFactor ? __('On: only connections made in a two factor session work', 'snapshot-core') : __('Off', 'snapshot-core') }}</dd>
    </dl>

    <ul class="snap-muted">
      @foreach ($levels as $level => $label)
        <li><strong>{{ $level }}</strong>: {{ __($label, 'snapshot-core') }}</li>
      @endforeach
    </ul>
  </section>

  <div class="snap-system-side">
    <section class="snap-panel">
      <div class="snap-panel-head">
        <h2>{{ __('Connect', 'snapshot-core') }}</h2>
      </div>
      @if ($oauth)
        <p class="snap-muted">{{ __('Add the address to the app. It opens this site in your browser, you sign in (with two factor if it is on) and choose what it may do. No token to copy.', 'snapshot-core') }}</p>
        <p class="snap-muted">{{ __('Claude Code, from a terminal:', 'snapshot-core') }}</p>
        <pre class="snap-system-log" tabindex="0">{{ $command }}</pre>
        <p class="snap-muted">{{ __('Claude on the web or desktop: Settings, Connectors, Add custom connector, then paste the address. Cursor and other clients: add the address as a remote MCP server.', 'snapshot-core') }}</p>
      @else
        <p class="snap-muted">{{ __('Create a token below, then in Claude Code:', 'snapshot-core') }}</p>
        <pre class="snap-system-log" tabindex="0">{{ $command }}</pre>
      @endif
    </section>

    <section class="snap-panel">
      <div class="snap-panel-head">
        <h2>{{ __('Create a token', 'snapshot-core') }}</h2>
      </div>

      @if ($new !== '')
        <p><strong>{{ __('Your new token. Copy it now: it is shown once and stored only as a hash.', 'snapshot-core') }}</strong></p>
        <pre class="snap-system-log" tabindex="0">{{ $new }}</pre>
        <pre class="snap-system-log" tabindex="0">{{ $tokenCommand }}</pre>
      @endif

      @if ($enabled && $can)
        <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}" class="snap-filters">
          {!! $createNonce !!}
          <input type="hidden" name="action" value="snapshot_system_mcp_token">
          <label>
            <span>{{ __('Name', 'snapshot-core') }}</span>
            <input type="text" name="name" maxlength="60" placeholder="{{ __('Deploy script', 'snapshot-core') }}" required>
          </label>
          <label>
            <span>{{ __('Expires after', 'snapshot-core') }}</span>
            <select name="days">
              @foreach ($lifetimes as $days => $label)
                <option value="{{ $days }}" @selected($days === 30)>{{ __($label, 'snapshot-core') }}</option>
              @endforeach
            </select>
          </label>
          <button type="submit" class="button">{{ __('Create token', 'snapshot-core') }}</button>
        </form>
        <p class="snap-muted">{{ __('For scripts and clients that cannot sign in through a browser. A token acts as you, with your permissions, until it expires or is revoked. It cannot post unfiltered HTML or upload HTML files, whatever your role. Changing your password revokes all of yours. If the host strips the Authorization header, send it as X-Snapshot-Mcp-Token.', 'snapshot-core') }}</p>
      @else
        <p class="snap-muted">{{ __('Tokens can be created once MCP is switched on.', 'snapshot-core') }}</p>
      @endif
    </section>
  </div>
</div>

<section class="snap-panel">
  <div class="snap-panel-head">
    <h2>{{ __('Connected apps', 'snapshot-core') }}</h2>
    <span class="snap-muted">{{ sprintf(_n('%d connection', '%d connections', count($connections), 'snapshot-core'), count($connections)) }}</span>
  </div>

  @if ($connections === [])
    <p class="snap-empty"><span class="dashicons dashicons-info" aria-hidden="true"></span> {{ __('No apps connected.', 'snapshot-core') }}</p>
  @else
    <table class="widefat striped">
      <thead>
        <tr>
          <th scope="col">{{ __('App', 'snapshot-core') }}</th>
          <th scope="col">{{ __('Person', 'snapshot-core') }}</th>
          <th scope="col">{{ __('Access', 'snapshot-core') }}</th>
          <th scope="col">{{ __('Connected', 'snapshot-core') }}</th>
          <th scope="col">{{ __('Last used', 'snapshot-core') }}</th>
          <th scope="col"><span class="screen-reader-text">{{ __('Actions', 'snapshot-core') }}</span></th>
        </tr>
      </thead>
      <tbody>
        @foreach ($connections as $id => $grant)
          <tr>
            <td><strong>{{ $grant['name'] }}</strong><br><span class="snap-muted">{{ $grant['redirect'] }}</span>@if ($twoFactor && empty($grant['verified'])) <span class="snap-pill is-warn">{{ __('Reconnect', 'snapshot-core') }}</span>@endif</td>
            <td>{{ $grant['person'] }}@if ((int) $grant['user'] === $mine) <span class="snap-pill is-info">{{ __('Yours', 'snapshot-core') }}</span>@endif</td>
            <td>{{ $grant['level'] }}</td>
            <td>{{ wp_date(get_option('date_format'), (int) $grant['created']) }}</td>
            <td>{{ $grant['used'] ? sprintf(__('%1$s ago from %2$s', 'snapshot-core'), human_time_diff((int) $grant['used']), $grant['ip']) : __('Never', 'snapshot-core') }}</td>
            <td>
              <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
                {!! $disconnectNonce !!}
                <input type="hidden" name="action" value="snapshot_system_mcp_disconnect">
                <input type="hidden" name="grant" value="{{ $id }}">
                <button type="submit" class="button" data-confirm="{{ __('Disconnect this app? It stops working at once and has to sign in again.', 'snapshot-core') }}">{{ __('Disconnect', 'snapshot-core') }}<span class="screen-reader-text"> {{ $grant['name'] }}</span></button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</section>

<section class="snap-panel">
  <div class="snap-panel-head">
    <h2>{{ __('Tokens for scripts', 'snapshot-core') }}</h2>
    <span class="snap-muted">{{ sprintf(_n('%d token', '%d tokens', count($tokens), 'snapshot-core'), count($tokens)) }}</span>
  </div>

  @if ($tokens === [])
    <p class="snap-empty"><span class="dashicons dashicons-info" aria-hidden="true"></span> {{ __('No tokens. Apps that sign in appear under Connected apps instead; tokens are for scripts and clients that cannot open a browser.', 'snapshot-core') }}</p>
  @else
    <table class="widefat striped">
      <thead>
        <tr>
          <th scope="col">{{ __('Name', 'snapshot-core') }}</th>
          <th scope="col">{{ __('Person', 'snapshot-core') }}</th>
          <th scope="col">{{ __('Created', 'snapshot-core') }}</th>
          <th scope="col">{{ __('Expires', 'snapshot-core') }}</th>
          <th scope="col">{{ __('Last used', 'snapshot-core') }}</th>
          <th scope="col"><span class="screen-reader-text">{{ __('Actions', 'snapshot-core') }}</span></th>
        </tr>
      </thead>
      <tbody>
        @foreach ($tokens as $id => $token)
          @php($expired = (int) $token['expires'] < time())
          <tr>
            <td><strong>{{ $token['name'] }}</strong>@if ((int) $token['user'] === $mine) <span class="snap-pill is-info">{{ __('Yours', 'snapshot-core') }}</span>@endif</td>
            <td>{{ $token['person'] }}</td>
            <td>{{ wp_date(get_option('date_format'), (int) $token['created']) }}</td>
            <td>
              @if ($expired)
                <span class="snap-pill is-fail">{{ __('Expired', 'snapshot-core') }}</span>
              @else
                {{ wp_date(get_option('date_format'), (int) $token['expires']) }}
              @endif
            </td>
            <td>{{ $token['used'] ? sprintf(__('%1$s ago from %2$s', 'snapshot-core'), human_time_diff((int) $token['used']), $token['ip']) : __('Never', 'snapshot-core') }}</td>
            <td>
              <form method="post" action="{!! esc_url(admin_url('admin-post.php')) !!}">
                {!! $revokeNonce !!}
                <input type="hidden" name="action" value="snapshot_system_mcp_revoke">
                <input type="hidden" name="token" value="{{ $id }}">
                <button type="submit" class="button" data-confirm="{{ __('Revoke this token? Clients using it stop working at once.', 'snapshot-core') }}">{{ __('Revoke', 'snapshot-core') }}<span class="screen-reader-text"> {{ $token['name'] }}</span></button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</section>

@if ($tools !== [])
  <section class="snap-panel">
    <div class="snap-panel-head">
      <h2>{{ __('What a client can do at this access level', 'snapshot-core') }}</h2>
      <span class="snap-muted">{{ sprintf(_n('%d tool', '%d tools', count($tools), 'snapshot-core'), count($tools)) }}</span>
    </div>
    <table class="widefat striped">
      <tbody>
        @foreach ($tools as $tool)
          <tr>
            <td><code>{{ $tool['name'] }}</code></td>
            <td>{{ $tool['description'] }}</td>
            <td>
              @if ($tool['annotations']['readOnlyHint'])
                <span class="snap-pill is-ok">{{ __('Read', 'snapshot-core') }}</span>
              @elseif ($tool['annotations']['destructiveHint'])
                <span class="snap-pill is-warn">{{ __('Removes', 'snapshot-core') }}</span>
              @else
                <span class="snap-pill is-info">{{ __('Writes', 'snapshot-core') }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </section>
@endif
