<?php
defined( 'ABSPATH' ) || die;
$f = '/home/REDACTED_SSH_USER/govdata.ca/wp-content/themes/govdata/single-member-parliament.php';
if ( function_exists( 'opcache_invalidate' ) ) {
    opcache_invalidate( $f, true );
    WP_CLI::log( 'OPcache invalidated.' );
} else {
    WP_CLI::log( 'opcache_invalidate not available.' );
}
$lines = file( $f );
WP_CLI::log( 'Line  34: ' . trim( $lines[33]  ?? '(missing)' ) );
WP_CLI::log( 'Line  35: ' . trim( $lines[34]  ?? '(missing)' ) );
WP_CLI::log( 'Line 111: ' . trim( $lines[110] ?? '(missing)' ) );
WP_CLI::log( 'Total lines: ' . count( $lines ) );
