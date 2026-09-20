<?php
/**
 * Serper gl / hl option lists (Google country + interface language).
 */

namespace Forwp\SeoHelper\Positions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SerperLocales {
	/**
	 * @return array<string, string> ISO code => English name.
	 */
	public static function countries(): array {
		return [
			'us' => 'United States',
			'uk' => 'United Kingdom',
			'ua' => 'Ukraine',
			'pl' => 'Poland',
			'il' => 'Israel',
			'de' => 'Germany',
			'fr' => 'France',
			'es' => 'Spain',
			'it' => 'Italy',
			'nl' => 'Netherlands',
			'be' => 'Belgium',
			'at' => 'Austria',
			'ch' => 'Switzerland',
			'cz' => 'Czechia',
			'sk' => 'Slovakia',
			'hu' => 'Hungary',
			'ro' => 'Romania',
			'bg' => 'Bulgaria',
			'gr' => 'Greece',
			'tr' => 'Turkey',
			'pt' => 'Portugal',
			'ie' => 'Ireland',
			'dk' => 'Denmark',
			'se' => 'Sweden',
			'no' => 'Norway',
			'fi' => 'Finland',
			'ee' => 'Estonia',
			'lv' => 'Latvia',
			'lt' => 'Lithuania',
			'ru' => 'Russia',
			'by' => 'Belarus',
			'kz' => 'Kazakhstan',
			'ca' => 'Canada',
			'mx' => 'Mexico',
			'br' => 'Brazil',
			'ar' => 'Argentina',
			'cl' => 'Chile',
			'co' => 'Colombia',
			'pe' => 'Peru',
			'au' => 'Australia',
			'nz' => 'New Zealand',
			'in' => 'India',
			'jp' => 'Japan',
			'kr' => 'South Korea',
			'cn' => 'China',
			'tw' => 'Taiwan',
			'hk' => 'Hong Kong',
			'sg' => 'Singapore',
			'my' => 'Malaysia',
			'th' => 'Thailand',
			'vn' => 'Vietnam',
			'id' => 'Indonesia',
			'ph' => 'Philippines',
			'za' => 'South Africa',
			'ng' => 'Nigeria',
			'eg' => 'Egypt',
			'sa' => 'Saudi Arabia',
			'ae' => 'United Arab Emirates',
			'qa' => 'Qatar',
			'kw' => 'Kuwait',
		];
	}

	/**
	 * @return array<string, string> hl code => English name.
	 */
	public static function languages(): array {
		return [
			'en'    => 'English',
			'uk'    => 'Ukrainian',
			'pl'    => 'Polish',
			'he'    => 'Hebrew',
			'de'    => 'German',
			'fr'    => 'French',
			'es'    => 'Spanish',
			'it'    => 'Italian',
			'nl'    => 'Dutch',
			'pt'    => 'Portuguese',
			'cs'    => 'Czech',
			'sk'    => 'Slovak',
			'hu'    => 'Hungarian',
			'ro'    => 'Romanian',
			'bg'    => 'Bulgarian',
			'el'    => 'Greek',
			'tr'    => 'Turkish',
			'ru'    => 'Russian',
			'be'    => 'Belarusian',
			'lt'    => 'Lithuanian',
			'lv'    => 'Latvian',
			'et'    => 'Estonian',
			'fi'    => 'Finnish',
			'sv'    => 'Swedish',
			'no'    => 'Norwegian',
			'da'    => 'Danish',
			'ar'    => 'Arabic',
			'hi'    => 'Hindi',
			'ja'    => 'Japanese',
			'ko'    => 'Korean',
			'zh-cn' => 'Chinese (Simplified)',
			'zh-tw' => 'Chinese (Traditional)',
			'th'    => 'Thai',
			'vi'    => 'Vietnamese',
			'id'    => 'Indonesian',
		];
	}

	public static function country_label( string $code ): string {
		$code = self::normalize_gl( $code );
		$name = self::countries()[ $code ] ?? strtoupper( $code );

		return self::flag( $code ) . ' ' . $name . ' (' . $code . ')';
	}

	public static function language_label( string $code ): string {
		$code = self::normalize_hl( $code );
		$name = self::languages()[ $code ] ?? $code;

		return $name . ' (' . $code . ')';
	}

	public static function normalize_gl( string $code ): string {
		$code = strtolower( sanitize_key( $code ) );
		if ( isset( self::countries()[ $code ] ) ) {
			return $code;
		}

		return 'us';
	}

	public static function normalize_hl( string $code ): string {
		$code = strtolower( str_replace( '_', '-', $code ) );
		$code = preg_replace( '/[^a-z-]/', '', $code ) ?? '';
		if ( isset( self::languages()[ $code ] ) ) {
			return $code;
		}

		return 'en';
	}

	public static function flag( string $code ): string {
		$map = [
			'uk' => 'GB',
		];
		$iso = strtoupper( $map[ $code ] ?? $code );
		if ( 2 !== strlen( $iso ) || ! ctype_alpha( $iso ) ) {
			return '';
		}

		$chars = str_split( $iso );
		$out   = '';
		foreach ( $chars as $char ) {
			$out .= mb_chr( 0x1F1E6 + ( ord( $char ) - ord( 'A' ) ), 'UTF-8' );
		}

		return $out;
	}
}
