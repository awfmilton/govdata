<?php
/**
 * Forum search box — styled to match the terminal's `.search-bar`.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

if ( ! bbp_allow_search() ) {
	return;
}
?>
<form role="search" method="get" id="bbp-search-form" class="search-bar corner" action="<?php bbp_search_url(); ?>">
	<div class="icon" aria-hidden="true">⌕</div>
	<label class="pt-sr-only" for="bbp_search"><?php esc_html_e( 'Search forums', 'publictech-terminal' ); ?></label>
	<input id="bbp_search" name="bbp_search" type="search"
		placeholder="<?php esc_attr_e( 'Search forums…', 'publictech-terminal' ); ?>"
		value="<?php echo esc_attr( bbp_get_search_terms() ); ?>" />
	<button type="submit" class="cta" style="margin:6px"><?php esc_html_e( 'Search', 'publictech-terminal' ); ?></button>
</form>
