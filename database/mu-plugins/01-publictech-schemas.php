<?php
/**
 * PublicTech column maps for the grantconts external database.
 *
 * Drop this file into wp-content/mu-plugins/ on your WordPress server.
 *
 * The alias "pt-grantconts-01" must match the key you declare in
 * PUBLICTECH_DB_CONNECTIONS inside wp-config.php:
 *
 *   define( 'PUBLICTECH_DB_CONNECTIONS', [
 *       'pt-grantconts-01' => [
 *           'host' => '35.230.186.163',
 *           'port' => 3306,
 *           'name' => 'grantconts',
 *           'user' => 'pt_read',
 *           'pass' => '...',
 *       ],
 *   ] );
 *
 * Only columns listed here are reachable by the front end and MCP clients.
 * Add or remove columns freely — the alias on the left is what queries use,
 * the backtick SQL on the right is what hits the database.
 *
 * Tables exposed:
 *   master_table       — denormalised, best for full-text search & filtering
 *   agreements         — normalised agreements (join via *_id FKs)
 *   recipients         — who received the grant
 *   programs           — grant program catalogue
 *   locations          — geographic data per agreement
 *   mps                — Members of Parliament with riding + contact info
 *   tbayrainy_view     — agreements filtered to Thunder Bay–Rainy River ridings
 */

// ── master_table ─────────────────────────────────────────────────────────────
add_filter( 'publictech_columns_pt-grantconts-01_master_table', static function () {
	return [
		'id'                     => '`master_table`.`primary_key`',
		'ref_number'             => '`master_table`.`ref_number`',
		'agreement_type'         => '`master_table`.`agreement_type`',
		'agreement_number'       => '`master_table`.`agreement_number`',
		'agreement_value'        => '`master_table`.`agreement_value`',
		'agreement_start_date'   => '`master_table`.`agreement_start_date`',
		'agreement_end_date'     => '`master_table`.`agreement_end_date`',
		'year'                   => '`master_table`.`year`',
		'fiscal_year'            => '`master_table`.`fiscal_year`',
		'agreement_title_en'     => '`master_table`.`agreement_title_en`',
		'description_eng'        => '`master_table`.`description_eng`',
		'expected_results_en'    => '`master_table`.`expected_results_en`',
		'coverage'               => '`master_table`.`coverage`',
		'recipient_type'         => '`master_table`.`recipient_type`',
		'recipient_legal_name'   => '`master_table`.`recipient_legal_name`',
		'recipient_operating_name' => '`master_table`.`recipient_operating_name`',
		'recipient_country'      => '`master_table`.`recipient_country`',
		'recipient_province'     => '`master_table`.`recipient_province`',
		'recipient_city'         => '`master_table`.`recipient_city`',
		'recipient_postal_code'  => '`master_table`.`recipient_postal_code`',
		'federal_riding_name_en' => '`master_table`.`federal_riding_name_en`',
		'federal_riding_number'  => '`master_table`.`federal_riding_number`',
		'prog_name_en'           => '`master_table`.`prog_name_en`',
		'prog_purpose_en'        => '`master_table`.`prog_purpose_en`',
		'naics_identifier'       => '`master_table`.`naics_identifier`',
		'owner_org_en'           => '`master_table`.`owner_org_en`',
		'owner_org_title'        => '`master_table`.`owner_org_title`',
	];
} );

// ── agreements ────────────────────────────────────────────────────────────────
add_filter( 'publictech_columns_pt-grantconts-01_agreements', static function () {
	return [
		'id'                   => '`agreements`.`agreement_id`',
		'ref_number'           => '`agreements`.`ref_number`',
		'agreement_number'     => '`agreements`.`agreement_number`',
		'agreement_type'       => '`agreements`.`agreement_type`',
		'agreement_value'      => '`agreements`.`agreement_value`',
		'agreement_start_date' => '`agreements`.`agreement_start_date`',
		'agreement_end_date'   => '`agreements`.`agreement_end_date`',
		'year'                 => '`agreements`.`year`',
		'agreement_title_en'   => '`agreements`.`agreement_title_en`',
		'description_eng'      => '`agreements`.`description_eng`',
		'expected_results_en'  => '`agreements`.`expected_results_en`',
		'coverage'             => '`agreements`.`coverage`',
		'recipient_id'         => '`agreements`.`recipient_id`',
		'program_id'           => '`agreements`.`program_id`',
		'location_id'          => '`agreements`.`location_id`',
		'naics_id'             => '`agreements`.`naics_id`',
	];
} );

// ── recipients ────────────────────────────────────────────────────────────────
add_filter( 'publictech_columns_pt-grantconts-01_recipients', static function () {
	return [
		'id'                        => '`recipients`.`recipient_id`',
		'recipient_legal_name'      => '`recipients`.`recipient_legal_name`',
		'recipient_operating_name'  => '`recipients`.`recipient_operating_name`',
		'recipient_type_code'       => '`recipients`.`recipient_type_code`',
		'recipient_business_number' => '`recipients`.`recipient_business_number`',
	];
} );

// ── programs ──────────────────────────────────────────────────────────────────
add_filter( 'publictech_columns_pt-grantconts-01_programs', static function () {
	return [
		'id'              => '`programs`.`program_id`',
		'prog_name_en'    => '`programs`.`prog_name_en`',
		'prog_name_fr'    => '`programs`.`prog_name_fr`',
		'prog_purpose_en' => '`programs`.`prog_purpose_en`',
	];
} );

// ── locations ─────────────────────────────────────────────────────────────────
add_filter( 'publictech_columns_pt-grantconts-01_locations', static function () {
	return [
		'id'                     => '`locations`.`location_id`',
		'recipient_country'      => '`locations`.`recipient_country`',
		'recipient_province'     => '`locations`.`recipient_province`',
		'recipient_city'         => '`locations`.`recipient_city`',
		'recipient_postal_code'  => '`locations`.`recipient_postal_code`',
		'federal_riding_name_en' => '`locations`.`federal_riding_name_en`',
		'federal_riding_number'  => '`locations`.`federal_riding_number`',
		'mp_id'                  => '`locations`.`mp_id`',
	];
} );

// ── mps ───────────────────────────────────────────────────────────────────────
// mp_id here is a varchar such as "P0001" — set external_mp_id on WP MP posts
// to the same value so mp.spending and /mp/{id}/spend can join correctly.
add_filter( 'publictech_columns_pt-grantconts-01_mps', static function () {
	return [
		'mp_id'         => '`mps`.`mp_id`',
		'first_name'    => '`mps`.`first_name`',
		'last_name'     => '`mps`.`last_name`',
		'district_name' => '`mps`.`district_name`',
		'party'         => '`mps`.`party`',
		'province'      => '`mps`.`province`',
		'postal_code'   => '`mps`.`postal_code`',
		'email'         => '`mps`.`email`',
		'photo_url'     => '`mps`.`photo_url`',
	];
} );

// ── tbayrainy_view ────────────────────────────────────────────────────────────
// Pre-filtered view: only agreements touching Thunder Bay–Rainy River ridings.
add_filter( 'publictech_columns_pt-grantconts-01_tbayrainy_view', static function () {
	return [
		'id'                     => '`tbayrainy_view`.`primary_key`',
		'ref_number'             => '`tbayrainy_view`.`ref_number`',
		'agreement_value'        => '`tbayrainy_view`.`agreement_value`',
		'agreement_start_date'   => '`tbayrainy_view`.`agreement_start_date`',
		'agreement_end_date'     => '`tbayrainy_view`.`agreement_end_date`',
		'year'                   => '`tbayrainy_view`.`year`',
		'fiscal_year'            => '`tbayrainy_view`.`fiscal_year`',
		'agreement_title_en'     => '`tbayrainy_view`.`agreement_title_en`',
		'description_eng'        => '`tbayrainy_view`.`description_eng`',
		'recipient_legal_name'   => '`tbayrainy_view`.`recipient_legal_name`',
		'recipient_province'     => '`tbayrainy_view`.`recipient_province`',
		'recipient_city'         => '`tbayrainy_view`.`recipient_city`',
		'federal_riding_name_en' => '`tbayrainy_view`.`federal_riding_name_en`',
		'federal_riding_number'  => '`tbayrainy_view`.`federal_riding_number`',
		'prog_name_en'           => '`tbayrainy_view`.`prog_name_en`',
		'owner_org_en'           => '`tbayrainy_view`.`owner_org_en`',
		'naics_identifier'       => '`tbayrainy_view`.`naics_identifier`',
	];
} );
