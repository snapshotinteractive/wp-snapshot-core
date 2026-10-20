<?php

namespace Snapshot\Mcp;

use Snapshot\Core;
use stdClass;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Server
{
    public const PROTOCOLS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    private const MAX_BODY_BYTES = 12 * MB_IN_BYTES;

    private const MAX_BATCH = 10;

    public function __construct(private Auth $auth, private Abilities $abilities) {}

    public function boot(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route(Mcp::NAMESPACE, Mcp::ROUTE, [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'handle'],
                    'permission_callback' => [$this, 'allowed'],
                ],
                [
                    'methods' => 'GET, DELETE',
                    'callback' => fn () => new WP_REST_Response(['error' => 'This server answers POST requests only.'], 405, ['Allow' => 'POST']),
                    'permission_callback' => [$this, 'allowed'],
                ],
            ]);
        });

        add_filter('rest_post_dispatch', [$this, 'headers'], 20, 3);

        if (Mcp::alias()) {
            add_action('parse_request', [$this, 'alias'], -1);
        }
        add_filter('rest_pre_serve_request', [$this, 'serveEmpty'], 10, 2);
    }

    public function allowed(WP_REST_Request $request)
    {
        $origin = trim((string) $request->get_header('origin'));

        if ($origin !== '' && ! $this->sameSite($origin)) {
            return new WP_Error('snapshot_mcp_origin', __('Requests from other sites are refused.', 'snapshot-core'), ['status' => 403]);
        }

        if (! $this->auth->authenticated()) {
            $message = match ($this->auth->failure()) {
                'throttled' => __('Too many refused tokens from this address. Try again in an hour.', 'snapshot-core'),
                'missing' => __('Sign in to connect: add this address to an MCP client that supports OAuth, or send a token from Snapshot, AI access as a Bearer token.', 'snapshot-core'),
                'expired' => __('The access token expired. Refresh it.', 'snapshot-core'),
                'twofactor' => __('This connection was made before two factor sign in was switched on for this site. Connect again.', 'snapshot-core'),
                default => __('The MCP token is unknown, expired or revoked.', 'snapshot-core'),
            };

            return new WP_Error('snapshot_mcp_unauthorized', $message, ['status' => match ($this->auth->failure()) {
                'throttled' => 429, default => 401
            }]);
        }

        if (! current_user_can(Mcp::capability())) {
            return new WP_Error('snapshot_mcp_forbidden', __('This account cannot use MCP.', 'snapshot-core'), ['status' => 403]);
        }

        return true;
    }

    public function headers($response, $server, $request)
    {
        if (! $response instanceof WP_REST_Response || $request->get_route() !== '/'.Mcp::NAMESPACE.Mcp::ROUTE) {
            return $response;
        }

        $response->header('Allow', 'POST');

        if ($response->get_status() === 401) {
            $response->header('WWW-Authenticate', $this->challenge());
        }

        return $response;
    }

    public function alias($wp): void
    {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $home = untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));

        if (untrailingslashit($path) === $home.'/mcp') {
            $wp->query_vars = ['rest_route' => Auth::route()];
        }
    }

    private function challenge(): string
    {
        $parts = ['Bearer realm="'.esc_attr((string) wp_parse_url(home_url(), PHP_URL_HOST)).'"'];

        if (Mcp::oauth()) {
            $path = untrailingslashit((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
            $resource = Mcp::alias() && $path === untrailingslashit((string) wp_parse_url(home_url('/mcp'), PHP_URL_PATH)) ? Mcp::endpoint() : Mcp::restEndpoint();
            $parts[] = 'resource_metadata="'.OAuth\Endpoints::metadataUrl($resource).'"';
            $parts[] = 'scope="mcp:'.Mcp::siteAccess().'"';
        }

        if (in_array($this->auth->failure(), ['invalid', 'twofactor', 'expired'], true)) {
            $parts[] = 'error="invalid_token"';
        }

        return implode(', ', $parts);
    }

    public function serveEmpty($served, $result)
    {
        if (! $served && $result instanceof WP_REST_Response && $result->get_status() === 202 && $result->get_data() === null && $this->auth->authenticated()) {
            return true;
        }

        return $served;
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_body();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->send($this->error(null, -32600, 'Request too large.'));
        }

        if ($body === '' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            return $this->send($this->error(null, -32600, sprintf('Request larger than this server accepts (post_max_size is %s). Upload large files with upload-media url instead of data.', ini_get('post_max_size'))));
        }

        $message = json_decode($body, true, 64);

        if (! is_array($message)) {
            return $this->send($this->error(null, -32700, 'Parse error: the body is not JSON.'));
        }

        if (! array_is_list($message)) {
            $reply = $this->message($message);

            return $reply === null ? new WP_REST_Response(null, 202) : $this->send($reply);
        }

        if (count($message) > self::MAX_BATCH) {
            return $this->send($this->error(null, -32600, sprintf('Send at most %d messages in one batch.', self::MAX_BATCH)));
        }

        $replies = array_values(array_filter(array_map(fn ($item) => is_array($item) ? $this->message($item) : $this->error(null, -32600, 'Invalid request.'), $message)));

        return $replies === [] ? new WP_REST_Response(null, 202) : $this->send($replies);
    }

    public function message(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;

        if (($message['jsonrpc'] ?? null) !== '2.0') {
            return $this->error($id, -32600, 'Invalid request: jsonrpc must be "2.0".');
        }

        if (! is_string($method)) {
            return array_key_exists('result', $message) || array_key_exists('error', $message) ? null : $this->error($id, -32600, 'Invalid request: method is missing.');
        }

        if (! array_key_exists('id', $message)) {
            return null;
        }

        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($method) {
            'initialize' => $this->result($id, $this->initialize($params)),
            'ping' => $this->result($id, new stdClass),
            'tools/list' => $this->result($id, ['tools' => $this->abilities->tools()]),
            'tools/call' => $this->call($id, $params),
            'resources/list' => $this->result($id, ['resources' => []]),
            'resources/templates/list' => $this->result($id, ['resourceTemplates' => []]),
            'prompts/list' => $this->result($id, ['prompts' => []]),
            default => $this->error($id, -32601, "Method not found: {$method}"),
        };
    }

    private function initialize(array $params): array
    {
        $asked = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($asked, self::PROTOCOLS, true) ? $asked : self::PROTOCOLS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => 'snapshot-'.sanitize_title((string) wp_parse_url(home_url(), PHP_URL_HOST)),
                'title' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
                'version' => Core::version(),
            ],
            'instructions' => snap_mcp_instructions(),
        ];
    }

    private function call($id, array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = $params['arguments'] ?? [];

        if (! $this->abilities->has($name)) {
            return $this->error($id, -32602, "Unknown tool: {$name}");
        }

        if (! is_array($arguments)) {
            return $this->error($id, -32602, 'Tool arguments must be an object.');
        }

        $result = $this->abilities->run($name, $arguments);

        if (is_wp_error($result)) {
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => $this->explain($result)]],
                'isError' => true,
            ]);
        }

        $payload = ['content' => [['type' => 'text', 'text' => (string) wp_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]];

        if (is_array($result) && $result !== [] && ! array_is_list($result)) {
            $payload['structuredContent'] = $result;
        }

        return $this->result($id, $payload);
    }

    private function explain(WP_Error $error): string
    {
        $messages = [];

        foreach ($error->get_error_codes() as $code) {
            foreach ($error->get_error_messages($code) as $message) {
                $messages[] = wp_strip_all_tags($message);
            }
        }

        return implode("\n", array_unique($messages)) ?: __('The tool failed.', 'snapshot-core');
    }

    private function sameSite(string $origin): bool
    {
        $host = strtolower((string) wp_parse_url($origin, PHP_URL_HOST));

        return $host !== '' && in_array($host, array_map('strtolower', array_filter([
            (string) wp_parse_url(home_url(), PHP_URL_HOST),
            (string) wp_parse_url(site_url(), PHP_URL_HOST),
        ])), true);
    }

    private function result($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function send(array $payload): WP_REST_Response
    {
        $response = new WP_REST_Response($payload, 200);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
