<?php
/** Offline protocol and privilege regression checks; no WordPress database or network. */
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__, 2 ) . '/publictech-core/inc/class-longplay-access.php';
require_once dirname( __DIR__, 2 ) . '/publictech-core/inc/class-access-tiers.php';

function lp_expect( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ): int { return $r['response']['code']; }
function wp_remote_retrieve_header( $r, $key ) { return $r['headers'][ $key ] ?? ''; }
function wp_remote_retrieve_body( $r ): string { return $r['body']; }
function wp_remote_post( $url, $options ) { return ( $GLOBALS['lp_transport'] )( $url, $options ); }
function current_user_can( $cap, ...$args ): bool { return 'manage_options' !== $cap || $GLOBALS['lp_manager']; }
function check_admin_referer( ...$args ): void {}
function get_user_meta( $id, $key, $single ) { return $GLOBALS['lp_meta'][ $id ][ $key ] ?? ''; }
function update_user_meta( $id, $key, $value ): void { $GLOBALS['lp_meta'][ $id ][ $key ] = $value; }

$feature = '11111111-1111-4111-8111-111111111111';
$customer = '22222222-2222-4222-8222-222222222222';
$config = [ 'BASE_URL' => 'https://billing.invalid', 'APP_ID' => 'app', 'SECRET_KEY' => 'gg_sk_' . str_repeat( 'x', 43 ),
	'ENVIRONMENT' => 'sandbox', 'ANALYST_FEATURE_ID' => $feature ];
$allowed = true;
$kind = 'access';
$scope = 'app';
$calls = [];
$post = static function ( $url, $options ) use ( &$calls, &$allowed, &$kind, &$scope, $feature, $customer ) {
	$body = json_decode( $options['body'], true );
	$calls[] = [ $url, $body ];
	lp_expect( 0 === $options['redirection'] && 3 === $options['timeout'], 'Bounded request required' );
	$data = [ 'requestId' => $customer, 'scope' => [ 'projectId' => $scope, 'environment' => 'sandbox' ] ];
	if ( str_ends_with( $url, '/customers' ) ) {
		lp_expect( [ 'externalUserId' => 'publictech:user:7' ] === $body, 'Only trusted WP identity goes to billing' );
		$data['customer'] = [ 'id' => $customer ];
	} else {
		lp_expect( $body['customerId'] === $customer && $body['requirement']['internal_feature_id'] === $feature, 'Bound Customer and Feature required' );
		$data['check'] = [ 'kind' => $kind, 'allowed' => $allowed, 'internal_feature_id' => $feature ];
	}
	return [ 'response' => [ 'code' => 200 ], 'headers' => [ 'x-indiana-protocol' => '0.10' ], 'body' => json_encode( $data ) ];
};

use PublicTech\Core\Longplay_Access;
lp_expect( ! Longplay_Access::check( 0, $config, $post ), 'Anonymous cannot buy access' );
lp_expect( ! Longplay_Access::check( 7, [], $post ) && [] === $calls, 'Disabled configuration makes no calls' );
lp_expect( Longplay_Access::check( 7, $config, $post ), 'Allowed access grants Analyst eligibility' );
$allowed = false;
lp_expect( ! Longplay_Access::check( 7, $config, $post ), 'Revocation is checked again' );
$allowed = true; $kind = 'credit';
lp_expect( ! Longplay_Access::check( 7, $config, $post ), 'Credit balance is not an access grant' );
$kind = 'access'; $scope = 'foreign';
lp_expect( ! Longplay_Access::check( 7, $config, $post ), 'Foreign App response must fail closed' );
lp_expect( ! Longplay_Access::check( 7, $config, static function () { throw new RuntimeException( 'PRIVATE' ); } ), 'Failure must not grant access' );
lp_expect( ! Longplay_Access::check( 7, array_merge( $config, [ 'BASE_URL' => 'https://user@billing.invalid' ] ), $post ), 'Reject URL credentials' );

$GLOBALS['lp_manager'] = false;
$GLOBALS['lp_meta'] = [];
$_POST = [ 'pt_tier' => 'institutional' ];
$tiers = PublicTech\Core\Access_Tiers::instance();
$tiers->maybe_rotate_token( 7 );
lp_expect( empty( $GLOBALS['lp_meta'] ), 'Editing a profile must not self-grant a paid tier' );
$GLOBALS['lp_manager'] = true;
$tiers->maybe_rotate_token( 7 );
lp_expect( 'institutional' === $GLOBALS['lp_meta'][7]['pt_tier'], 'Administrator grants remain available' );
lp_expect( 'anonymous' === $tiers->user_tier( null ), 'Anonymous default preserved' );
lp_expect( 'free' === $tiers->user_tier( 8 ), 'Free default preserved' );
$scope = 'app';
$GLOBALS['lp_transport'] = $post;
$GLOBALS['lp_meta'][7]['pt_tier'] = 'free';
foreach ( $config as $key => $value ) { putenv( 'LONGPLAY_' . $key . '=' . $value ); }
lp_expect( 'analyst' === $tiers->user_tier( 7 ), 'The existing tier reader must use the online grant' );
lp_expect( 'free' === $GLOBALS['lp_meta'][7]['pt_tier'], 'An online grant must not persist a stale paid tier' );
foreach ( $config as $key => $value ) { putenv( 'LONGPLAY_' . $key ); }
echo "Longplay protocol, revocation, fail-closed and profile privilege checks passed.\n";
