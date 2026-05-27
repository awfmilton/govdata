<?php
/**
 * Home page. Defers to the dashboard template so the same layout works whether
 * the user assigns a static front-page or browses to it via the menu.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

include __DIR__ . '/page-templates/template-dashboard.php';
