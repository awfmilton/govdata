<?php
/**
 * MCP JSON-RPC server.
 *
 * Implements the subset of the Model Context Protocol that AI clients need
 * for read-only data access: initialize, tools/list, tools/call,
 * resources/list, resources/read. Each request is authenticated by bearer
 * token (mapped to a WP user via Access_Tiers::resolve_token) and rate
 * limited by Throttle::check() before any DB work is done.
 *
 * @package PublicTech\MCP
 */

namespace PublicTech\MCP;

defined( 'ABSPATH' ) || exit;

use PublicTech\Core\Access_Tiers;
use PublicTech\Core\Throttle;

final class Server {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	public function register_routes(): void {
		register_rest_route( PUBLICTECH_MCP_NS, '/rpc', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'rpc' ],
		] );
		register_rest_route( PUBLICTECH_MCP_NS, '/manifest', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'manifest' ],
		] );
	}

	public function manifest(): \WP_REST_Response {
		return rest_ensure_response( [
			'name'        => 'publictech',
			'version'     => PUBLICTECH_MCP_VERSION,
			'protocol'    => '2024-11-05',
			'description' => 'PublicTech Audit Terminal — read-only access to Canadian government spending, grants and contracts.',
			'transports'  => [ 'streamable-http' ],
			'auth'        => [ 'type' => 'bearer', 'header' => 'Authorization' ],
		] );
	}

	public function rpc( \WP_REST_Request $req ): \WP_REST_Response {
		$raw = json_decode( $req->get_body(), true );
		if ( ! is_array( $raw ) ) {
			return $this->err( null, -32700, 'Parse error' );
		}

		// Authentication.
		$user_id = $this->authenticate( $req );
		if ( ! $user_id && ! $this->is_introspection( $raw['method'] ?? '' ) ) {
			return $this->err( $raw['id'] ?? null, -32001, 'Unauthorized — missing or invalid bearer token', 401 );
		}

		// Throttle.
		$state = Throttle::instance()->check( $user_id, $this->ip() );
		if ( ! $state['allowed'] ) {
			$resp = $this->err( $raw['id'] ?? null, -32002, 'Rate limit exceeded for tier ' . $state['quota']['label'], 429 );
			$resp->header( 'Retry-After', (string) $state['retry_after'] );
			return $resp;
		}

		// MCP gate.
		if ( $user_id ) {
			$quota = Access_Tiers::instance()->quota( $user_id );
			if ( ! $quota['mcp'] && ! $this->is_introspection( $raw['method'] ?? '' ) ) {
				return $this->err( $raw['id'] ?? null, -32003, 'MCP requires a paid tier.', 402 );
			}
		}

		$id     = $raw['id']     ?? null;
		$method = $raw['method'] ?? '';
		$params = $raw['params'] ?? [];

		try {
			$result = match ( $method ) {
				'initialize'      => $this->initialize( $params ),
				'tools/list'      => Tools::instance()->list( $user_id ),
				'tools/call'      => Tools::instance()->call( $user_id, (string) ( $params['name'] ?? '' ), (array) ( $params['arguments'] ?? [] ), (int) $state['quota']['max_rows'] ),
				'resources/list'  => Resources::instance()->list( $user_id ),
				'resources/read'  => Resources::instance()->read( $user_id, (string) ( $params['uri'] ?? '' ), (int) $state['quota']['max_rows'] ),
				'ping'            => [ 'ok' => true ],
				default           => throw new \RuntimeException( 'Method not found: ' . $method, -32601 ),
			};
		} catch ( \Throwable $e ) {
			$code = (int) $e->getCode();
			return $this->err( $id, $code ?: -32603, $e->getMessage() );
		}

		$resp = rest_ensure_response( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ] );
		$resp->header( 'X-PT-Tier-RPM-Used',    (string) $state['minute_used'] );
		$resp->header( 'X-PT-Tier-Daily-Used',  (string) $state['day_used'] );
		$resp->header( 'X-PT-Tier',             (string) $state['quota']['label'] );
		return $resp;
	}

	private function initialize( array $params ): array {
		return [
			'protocolVersion' => '2024-11-05',
			'capabilities'    => [ 'tools' => [ 'listChanged' => false ], 'resources' => [ 'subscribe' => false ] ],
			'serverInfo'      => [ 'name' => 'publictech', 'version' => PUBLICTECH_MCP_VERSION ],
		];
	}

	private function authenticate( \WP_REST_Request $req ): ?int {
		$auth = $req->get_header( 'authorization' );
		if ( ! $auth || stripos( $auth, 'Bearer ' ) !== 0 ) {
			return null;
		}
		return Access_Tiers::instance()->resolve_token( substr( $auth, 7 ) );
	}

	private function is_introspection( string $method ): bool {
		return in_array( $method, [ 'initialize', 'ping' ], true );
	}

	private function err( $id, int $code, string $msg, int $http = 200 ): \WP_REST_Response {
		$resp = rest_ensure_response( [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => [ 'code' => $code, 'message' => $msg ],
		] );
		$resp->set_status( $http );
		return $resp;
	}

	private function ip(): string {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$raw = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
				$ip  = trim( explode( ',', $raw )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}
