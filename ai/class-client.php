<?php
/**
 * WordPress AI Client (Connectors) wrapper.
 *
 * @package ForWP\AI
 */

namespace ForWP\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate text or JSON. Does not save.
 */
class Client {
	/**
	 * @return bool
	 */
	public static function is_available() {
		if ( ! function_exists( 'wp_ai_client_prompt' )
			|| ! function_exists( 'wp_supports_ai' )
			|| ! wp_supports_ai() ) {
			return false;
		}

		return true === wp_ai_client_prompt()->is_supported_for_text_generation();
	}

	/**
	 * @param string $prompt      User prompt.
	 * @param string $system      System instruction.
	 * @param int    $max_tokens  Max tokens.
	 * @return string|\WP_Error
	 */
	public static function generate( $prompt, $system = '', $max_tokens = 180 ) {
		$raw = self::request_raw( $prompt, $system, (int) $max_tokens, false, [] );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$text = self::sanitize_text( $raw );
		if ( '' === $text ) {
			return new \WP_Error(
				'forwp_ai_empty',
				__( 'The model returned empty text.', '4wp-ai' ),
				[ 'status' => 502 ]
			);
		}

		return $text;
	}

	/**
	 * @param string   $prompt      User prompt.
	 * @param string   $system      System instruction.
	 * @param int      $max_tokens  Max tokens.
	 * @param string[] $keys        Expected object keys (strings). Empty = keep all keys.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function generate_json( $prompt, $system = '', $max_tokens = 400, $keys = [] ) {
		$raw = self::request_raw( $prompt, $system, (int) $max_tokens, true, $keys );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$data = self::parse_json( $raw );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return self::map_json( $data, $keys );
	}

	/**
	 * @param string $raw Model output.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function parse_json( $raw ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '/```(?:json)?\s*(.*?)\s*```/s', $raw, $match ) ) {
			$raw = trim( (string) $match[1] );
		}

		$start = strpos( $raw, '{' );
		$end   = strrpos( $raw, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return new \WP_Error(
				'forwp_ai_json_invalid',
				__( 'The model did not return JSON.', '4wp-ai' ),
				[ 'status' => 502 ]
			);
		}

		$decoded = json_decode( substr( $raw, $start, $end - $start + 1 ), true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'forwp_ai_json_invalid',
				__( 'The model did not return JSON.', '4wp-ai' ),
				[ 'status' => 502 ]
			);
		}

		return $decoded;
	}

	/**
	 * @param string $text Raw model output.
	 * @return string
	 */
	public static function sanitize_text( $text ) {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = trim( $text );

		return trim( $text, "\"'`" );
	}

	/**
	 * @param string   $prompt      User prompt.
	 * @param string   $system      System instruction.
	 * @param int      $max_tokens  Max tokens.
	 * @param bool     $as_json     Request JSON MIME when supported.
	 * @param string[] $keys        JSON object keys for an output schema.
	 * @return string|\WP_Error
	 */
	private static function request_raw( $prompt, $system, $max_tokens, $as_json, $keys ) {
		if ( ! self::is_available() ) {
			return new \WP_Error(
				'forwp_ai_unavailable',
				__( 'AI is not available. Configure a connector under Settings → Connectors.', '4wp-ai' ),
				[ 'status' => 400 ]
			);
		}

		$prompt = trim( (string) $prompt );
		if ( '' === $prompt ) {
			return new \WP_Error(
				'forwp_ai_prompt_empty',
				__( 'The prompt is empty.', '4wp-ai' ),
				[ 'status' => 400 ]
			);
		}

		$result = self::execute_prompt( $prompt, $system, $max_tokens, $as_json, $keys );
		if ( is_wp_error( $result ) && $as_json ) {
			$result = self::execute_prompt( $prompt, $system, $max_tokens, false, [] );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$raw = trim( (string) $result );
		if ( '' === $raw ) {
			return new \WP_Error(
				'forwp_ai_empty',
				__( 'The model returned empty text.', '4wp-ai' ),
				[ 'status' => 502 ]
			);
		}

		return $raw;
	}

	/**
	 * @param string   $prompt      User prompt.
	 * @param string   $system      System instruction.
	 * @param int      $max_tokens  Max tokens.
	 * @param bool     $as_json     Request JSON MIME when supported.
	 * @param string[] $keys        JSON object keys for an output schema.
	 * @return string|\WP_Error
	 */
	private static function execute_prompt( $prompt, $system, $max_tokens, $as_json, $keys ) {
		$request = wp_ai_client_prompt( $prompt );
		if ( is_string( $system ) && '' !== $system ) {
			$request = $request->using_system_instruction( $system );
		}

		$request = $request->using_max_tokens( max( 1, (int) $max_tokens ) );

		if ( $as_json ) {
			$schema  = self::json_schema( $keys );
			$request = $schema
				? $request->as_json_response( $schema )
				: $request->as_json_response();
		}

		return $request->generate_text();
	}

	/**
	 * @param string[] $keys Expected keys.
	 * @return array<string, mixed>|null
	 */
	private static function json_schema( $keys ) {
		$properties = [];
		$required   = [];

		foreach ( $keys as $key ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			$properties[ $key ] = [ 'type' => 'string' ];
			$required[]         = $key;
		}

		if ( empty( $properties ) ) {
			return null;
		}

		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		];
	}

	/**
	 * Keep declared keys; sanitize strings; arrays become lists of strings.
	 *
	 * @param array<string, mixed> $data Decoded object.
	 * @param string[]             $keys Expected keys. Empty = all string/array values.
	 * @return array<string, mixed>
	 */
	private static function map_json( array $data, $keys ) {
		$wanted = [];
		foreach ( $keys as $key ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key ) {
				$wanted[] = $key;
			}
		}

		$source = empty( $wanted ) ? $data : [];
		if ( ! empty( $wanted ) ) {
			foreach ( $wanted as $key ) {
				if ( array_key_exists( $key, $data ) ) {
					$source[ $key ] = $data[ $key ];
				}
			}
		}

		$mapped = [];
		foreach ( $source as $key => $value ) {
			if ( is_array( $value ) ) {
				$list = [];
				foreach ( $value as $item ) {
					if ( is_scalar( $item ) ) {
						$clean = self::sanitize_text( (string) $item );
						if ( '' !== $clean ) {
							$list[] = $clean;
						}
					}
				}
				$mapped[ (string) $key ] = $list;
				continue;
			}

			if ( is_scalar( $value ) ) {
				$mapped[ (string) $key ] = self::sanitize_text( (string) $value );
			}
		}

		return $mapped;
	}
}
