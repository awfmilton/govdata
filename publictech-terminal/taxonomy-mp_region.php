<?php
/**
 * Region archive (/region/<slug>/): the MP directory pre-filtered to this region.
 *
 * Without this template WordPress fell back to index.php, which printed each MP's name over an empty content box.
 *
 * @package PublicTechTerminal
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/archive-member-parliament.php';
