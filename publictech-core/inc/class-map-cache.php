<?php
/**
 * Map static-file cache.
 *
 * Writes wp-content/uploads/pt-map/riding-totals.json containing:
 *   {
 *     "ridings":   { "10001": { spend, count, name, province }, … },
 *     "countries": { "840":   { spend, count, name }, … }   ← ISO numeric keyed
 *   }
 *
 * Riding data comes from map_riding_totals / riding_cache in pt-cache-02.
 * Country data comes from a live aggregate on grantconts.master_table (read-only).
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Map_Cache {

	public const HOOK     = 'publictech_refresh_map_cache';
	public const FILENAME = 'riding-totals.json';
	public const SUBDIR   = 'pt-map';

	public static function instance(): void {
		static $init = false;
		if ( $init ) return;
		$init = true;
		add_action( 'init',             [ __CLASS__, 'schedule' ] );
		add_action( self::HOOK,         [ __CLASS__, 'refresh'  ] );
		add_action( Riding_Cache::HOOK, [ __CLASS__, 'refresh'  ], 20 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$first = strtotime( 'next sunday 05:00 UTC' ) ?: ( time() + DAY_IN_SECONDS );
			wp_schedule_event( $first, 'weekly', self::HOOK );
		}
	}

	public static function file_path(): string {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . self::SUBDIR . '/' . self::FILENAME;
	}

	public static function file_url(): string {
		if ( ! file_exists( self::file_path() ) ) return '';
		$upload = wp_upload_dir();
		return trailingslashit( $upload['baseurl'] ) . self::SUBDIR . '/' . self::FILENAME;
	}

	/* ── Alpha-2 ISO → [ numeric_id, English name ] ────────────────────── */
	public static function alpha2_to_iso(): array {
		return [
			'AD' => [ 20,  'Andorra' ],              'AE' => [ 784, 'United Arab Emirates' ],
			'AF' => [ 4,   'Afghanistan' ],           'AG' => [ 28,  'Antigua and Barbuda' ],
			'AI' => [ 660, 'Anguilla' ],              'AL' => [ 8,   'Albania' ],
			'AM' => [ 51,  'Armenia' ],               'AO' => [ 24,  'Angola' ],
			'AQ' => [ 10,  'Antarctica' ],            'AR' => [ 32,  'Argentina' ],
			'AT' => [ 40,  'Austria' ],               'AU' => [ 36,  'Australia' ],
			'AW' => [ 533, 'Aruba' ],                 'AZ' => [ 31,  'Azerbaijan' ],
			'BA' => [ 70,  'Bosnia and Herzegovina' ],'BB' => [ 52,  'Barbados' ],
			'BD' => [ 50,  'Bangladesh' ],            'BE' => [ 56,  'Belgium' ],
			'BF' => [ 854, 'Burkina Faso' ],          'BG' => [ 100, 'Bulgaria' ],
			'BH' => [ 48,  'Bahrain' ],               'BI' => [ 108, 'Burundi' ],
			'BJ' => [ 204, 'Benin' ],                 'BL' => [ 652, 'Saint Barthélemy' ],
			'BM' => [ 60,  'Bermuda' ],               'BN' => [ 96,  'Brunei' ],
			'BO' => [ 68,  'Bolivia' ],               'BR' => [ 76,  'Brazil' ],
			'BS' => [ 44,  'Bahamas' ],               'BT' => [ 64,  'Bhutan' ],
			'BW' => [ 72,  'Botswana' ],              'BY' => [ 112, 'Belarus' ],
			'BZ' => [ 84,  'Belize' ],                'CA' => [ 124, 'Canada' ],
			'CD' => [ 180, 'DR Congo' ],              'CF' => [ 140, 'Central African Republic' ],
			'CG' => [ 178, 'Republic of Congo' ],     'CH' => [ 756, 'Switzerland' ],
			'CI' => [ 384, "Côte d'Ivoire" ],         'CK' => [ 184, 'Cook Islands' ],
			'CL' => [ 152, 'Chile' ],                 'CM' => [ 120, 'Cameroon' ],
			'CN' => [ 156, 'China' ],                 'CO' => [ 170, 'Colombia' ],
			'CR' => [ 188, 'Costa Rica' ],            'CU' => [ 192, 'Cuba' ],
			'CV' => [ 132, 'Cape Verde' ],            'CY' => [ 196, 'Cyprus' ],
			'CZ' => [ 203, 'Czechia' ],               'DE' => [ 276, 'Germany' ],
			'DJ' => [ 262, 'Djibouti' ],              'DK' => [ 208, 'Denmark' ],
			'DM' => [ 212, 'Dominica' ],              'DO' => [ 214, 'Dominican Republic' ],
			'DZ' => [ 12,  'Algeria' ],               'EC' => [ 218, 'Ecuador' ],
			'EE' => [ 233, 'Estonia' ],               'EG' => [ 818, 'Egypt' ],
			'EH' => [ 732, 'Western Sahara' ],        'ER' => [ 232, 'Eritrea' ],
			'ES' => [ 724, 'Spain' ],                 'ET' => [ 231, 'Ethiopia' ],
			'FI' => [ 246, 'Finland' ],               'FJ' => [ 242, 'Fiji' ],
			'FK' => [ 238, 'Falkland Islands' ],      'FM' => [ 583, 'Micronesia' ],
			'FO' => [ 234, 'Faroe Islands' ],         'FR' => [ 250, 'France' ],
			'GA' => [ 266, 'Gabon' ],                 'GB' => [ 826, 'United Kingdom' ],
			'GD' => [ 308, 'Grenada' ],               'GE' => [ 268, 'Georgia' ],
			'GF' => [ 254, 'French Guiana' ],         'GH' => [ 288, 'Ghana' ],
			'GL' => [ 304, 'Greenland' ],             'GM' => [ 270, 'Gambia' ],
			'GN' => [ 324, 'Guinea' ],                'GQ' => [ 226, 'Equatorial Guinea' ],
			'GR' => [ 300, 'Greece' ],                'GT' => [ 320, 'Guatemala' ],
			'GU' => [ 316, 'Guam' ],                  'GW' => [ 624, 'Guinea-Bissau' ],
			'GY' => [ 328, 'Guyana' ],                'HK' => [ 344, 'Hong Kong' ],
			'HM' => [ 334, 'Heard Island' ],          'HN' => [ 340, 'Honduras' ],
			'HR' => [ 191, 'Croatia' ],               'HT' => [ 332, 'Haiti' ],
			'HU' => [ 348, 'Hungary' ],               'ID' => [ 360, 'Indonesia' ],
			'IE' => [ 372, 'Ireland' ],               'IL' => [ 376, 'Israel' ],
			'IN' => [ 356, 'India' ],                 'IO' => [ 86,  'British Indian Ocean Territory' ],
			'IQ' => [ 368, 'Iraq' ],                  'IR' => [ 364, 'Iran' ],
			'IS' => [ 352, 'Iceland' ],               'IT' => [ 380, 'Italy' ],
			'JM' => [ 388, 'Jamaica' ],               'JO' => [ 400, 'Jordan' ],
			'JP' => [ 392, 'Japan' ],                 'KE' => [ 404, 'Kenya' ],
			'KG' => [ 417, 'Kyrgyzstan' ],            'KH' => [ 116, 'Cambodia' ],
			'KI' => [ 296, 'Kiribati' ],              'KM' => [ 174, 'Comoros' ],
			'KN' => [ 659, 'Saint Kitts and Nevis' ], 'KP' => [ 408, 'North Korea' ],
			'KR' => [ 410, 'South Korea' ],           'KW' => [ 414, 'Kuwait' ],
			'KY' => [ 136, 'Cayman Islands' ],        'KZ' => [ 398, 'Kazakhstan' ],
			'LA' => [ 418, 'Laos' ],                  'LB' => [ 422, 'Lebanon' ],
			'LC' => [ 662, 'Saint Lucia' ],           'LI' => [ 438, 'Liechtenstein' ],
			'LK' => [ 144, 'Sri Lanka' ],             'LR' => [ 430, 'Liberia' ],
			'LS' => [ 426, 'Lesotho' ],               'LT' => [ 440, 'Lithuania' ],
			'LU' => [ 442, 'Luxembourg' ],            'LV' => [ 428, 'Latvia' ],
			'LY' => [ 434, 'Libya' ],                 'MA' => [ 504, 'Morocco' ],
			'MC' => [ 492, 'Monaco' ],                'MD' => [ 498, 'Moldova' ],
			'ME' => [ 499, 'Montenegro' ],            'MG' => [ 450, 'Madagascar' ],
			'MH' => [ 584, 'Marshall Islands' ],      'MK' => [ 807, 'North Macedonia' ],
			'ML' => [ 466, 'Mali' ],                  'MM' => [ 104, 'Myanmar' ],
			'MN' => [ 496, 'Mongolia' ],              'MO' => [ 446, 'Macao' ],
			'MR' => [ 478, 'Mauritania' ],            'MT' => [ 470, 'Malta' ],
			'MU' => [ 480, 'Mauritius' ],             'MV' => [ 462, 'Maldives' ],
			'MW' => [ 454, 'Malawi' ],                'MX' => [ 484, 'Mexico' ],
			'MY' => [ 458, 'Malaysia' ],              'MZ' => [ 508, 'Mozambique' ],
			'NA' => [ 516, 'Namibia' ],               'NC' => [ 540, 'New Caledonia' ],
			'NE' => [ 562, 'Niger' ],                 'NG' => [ 566, 'Nigeria' ],
			'NI' => [ 558, 'Nicaragua' ],             'NL' => [ 528, 'Netherlands' ],
			'NO' => [ 578, 'Norway' ],                'NP' => [ 524, 'Nepal' ],
			'NR' => [ 520, 'Nauru' ],                 'NZ' => [ 554, 'New Zealand' ],
			'OM' => [ 512, 'Oman' ],                  'PA' => [ 591, 'Panama' ],
			'PE' => [ 604, 'Peru' ],                  'PF' => [ 258, 'French Polynesia' ],
			'PG' => [ 598, 'Papua New Guinea' ],      'PH' => [ 608, 'Philippines' ],
			'PK' => [ 586, 'Pakistan' ],              'PL' => [ 616, 'Poland' ],
			'PM' => [ 666, 'Saint Pierre and Miquelon' ], 'PN' => [ 612, 'Pitcairn' ],
			'PR' => [ 630, 'Puerto Rico' ],           'PS' => [ 275, 'Palestine' ],
			'PT' => [ 620, 'Portugal' ],              'PW' => [ 585, 'Palau' ],
			'PY' => [ 600, 'Paraguay' ],              'QA' => [ 634, 'Qatar' ],
			'RE' => [ 638, 'Réunion' ],               'RO' => [ 642, 'Romania' ],
			'RS' => [ 688, 'Serbia' ],                'RU' => [ 643, 'Russia' ],
			'RW' => [ 646, 'Rwanda' ],                'SA' => [ 682, 'Saudi Arabia' ],
			'SB' => [ 90,  'Solomon Islands' ],       'SC' => [ 690, 'Seychelles' ],
			'SD' => [ 729, 'Sudan' ],                 'SE' => [ 752, 'Sweden' ],
			'SG' => [ 702, 'Singapore' ],             'SH' => [ 654, 'Saint Helena' ],
			'SI' => [ 705, 'Slovenia' ],              'SJ' => [ 744, 'Svalbard and Jan Mayen' ],
			'SK' => [ 703, 'Slovakia' ],              'SL' => [ 694, 'Sierra Leone' ],
			'SM' => [ 674, 'San Marino' ],            'SN' => [ 686, 'Senegal' ],
			'SO' => [ 706, 'Somalia' ],               'SR' => [ 740, 'Suriname' ],
			'SS' => [ 728, 'South Sudan' ],           'ST' => [ 678, 'São Tomé and Príncipe' ],
			'SV' => [ 222, 'El Salvador' ],           'SX' => [ 534, 'Sint Maarten' ],
			'SY' => [ 760, 'Syria' ],                 'SZ' => [ 748, 'Eswatini' ],
			'TC' => [ 796, 'Turks and Caicos Islands' ], 'TD' => [ 148, 'Chad' ],
			'TF' => [ 260, 'French Southern Territories' ], 'TG' => [ 768, 'Togo' ],
			'TH' => [ 764, 'Thailand' ],              'TJ' => [ 762, 'Tajikistan' ],
			'TL' => [ 626, 'Timor-Leste' ],           'TM' => [ 795, 'Turkmenistan' ],
			'TN' => [ 788, 'Tunisia' ],               'TO' => [ 776, 'Tonga' ],
			'TR' => [ 792, 'Türkiye' ],               'TT' => [ 780, 'Trinidad and Tobago' ],
			'TV' => [ 798, 'Tuvalu' ],                'TW' => [ 158, 'Taiwan' ],
			'TZ' => [ 834, 'Tanzania' ],              'UA' => [ 804, 'Ukraine' ],
			'UG' => [ 800, 'Uganda' ],                'US' => [ 840, 'United States' ],
			'UY' => [ 858, 'Uruguay' ],               'UZ' => [ 860, 'Uzbekistan' ],
			'VA' => [ 336, 'Vatican City' ],          'VC' => [ 670, 'Saint Vincent and the Grenadines' ],
			'VE' => [ 862, 'Venezuela' ],             'VG' => [ 92,  'British Virgin Islands' ],
			'VI' => [ 850, 'U.S. Virgin Islands' ],  'VN' => [ 704, 'Vietnam' ],
			'VU' => [ 548, 'Vanuatu' ],               'WF' => [ 876, 'Wallis and Futuna' ],
			'WS' => [ 882, 'Samoa' ],                 'XK' => [ 383, 'Kosovo' ],
			'YE' => [ 887, 'Yemen' ],                 'YT' => [ 175, 'Mayotte' ],
			'ZA' => [ 710, 'South Africa' ],          'ZM' => [ 894, 'Zambia' ],
			'ZW' => [ 716, 'Zimbabwe' ],
		];
	}

	/* ── Main refresh ───────────────────────────────────────────────────── */

	/** @return array{written_ridings:int,written_countries:int,bytes:int,elapsed:float}|null */
	public static function refresh(): ?array {
		$t0 = microtime( true );

		/* ── Riding data (pt-cache-02) ─── */
		try {
			$riding_rows = publictech_db( Riding_Cache_Warmer::cache_alias() )->select(
				'SELECT `riding_number`, `name`, `province`, `spend`, `agreement_count`
				 FROM `map_riding_totals`'
			);
		} catch ( \Throwable $e ) {
			try {
				$riding_rows = publictech_db( Riding_Cache_Warmer::cache_alias() )->select(
					'SELECT `riding_number`, `riding_name_en` AS `name`, `province`,
					        `total_value` AS `spend`, `agreement_count`
					 FROM `riding_cache`'
				);
			} catch ( \Throwable $e2 ) {
				error_log( '[PublicTech] map-cache: riding query failed: ' . $e2->getMessage() );
				return null;
			}
		}

		$ridings = [];
		foreach ( $riding_rows as $row ) {
			$ridings[ (string) $row['riding_number'] ] = [
				'spend'    => (float) $row['spend'],
				'count'    => (int)   $row['agreement_count'],
				'name'     => (string) $row['name'],
				'province' => (string) $row['province'],
			];
		}

		/* ── Country data (grantconts — read-only aggregate) ─── */
		$countries = [];
		try {
			$country_rows = publictech_db( Riding_Cache_Warmer::source_alias() )->select(
				"SELECT UPPER(TRIM(`recipient_country`))    AS cc,
				        SUM(GREATEST(`agreement_value`, 0)) AS spend,
				        COUNT(*)                            AS cnt
				 FROM `master_table`
				 WHERE `recipient_country` IS NOT NULL
				   AND LENGTH(TRIM(`recipient_country`)) = 2
				   AND UPPER(TRIM(`recipient_country`)) != 'CA'
				 GROUP BY cc"
			);
			$iso_map = self::alpha2_to_iso();
			foreach ( $country_rows as $row ) {
				$cc = strtoupper( trim( (string) $row['cc'] ) );
				if ( ! isset( $iso_map[ $cc ] ) ) continue;
				[ $numeric, $name ] = $iso_map[ $cc ];
				$countries[ (string) $numeric ] = [
					'spend' => (float) $row['spend'],
					'count' => (int)   $row['cnt'],
					'name'  => $name,
				];
			}
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] map-cache: country query skipped: ' . $e->getMessage() );
		}

		if ( empty( $ridings ) && empty( $countries ) ) return null;

		/* ── Write JSON file ─── */
		$out  = [ 'ridings' => $ridings, 'countries' => $countries ];
		$json = wp_json_encode( $out, JSON_UNESCAPED_UNICODE );
		if ( ! $json ) return null;

		$path = self::file_path();
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );

		$tmp = $path . '.tmp';
		if ( file_put_contents( $tmp, $json, LOCK_EX ) === false ) {
			error_log( '[PublicTech] map-cache: could not write ' . $tmp );
			return null;
		}
		rename( $tmp, $path );

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess,
				"<FilesMatch \"\\.json$\">\n" .
				"    Header set Content-Type \"application/json; charset=UTF-8\"\n" .
				"    Header set Cache-Control \"public, max-age=3600\"\n" .
				"    Header set Access-Control-Allow-Origin \"*\"\n" .
				"</FilesMatch>\n"
			);
		}

		return [
			'written_ridings'   => count( $ridings ),
			'written_countries' => count( $countries ),
			'bytes'             => strlen( $json ),
			'elapsed'           => round( microtime( true ) - $t0, 3 ),
		];
	}
}

Map_Cache::instance();
