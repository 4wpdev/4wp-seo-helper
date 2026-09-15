<?php
/**
 * Admin prompt panel assets.
 *
 * @package ForWP\AI
 */

namespace ForWP\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the shared field panel.
 */
class Panel {
	/**
	 * @param array $config {
	 *     @type array  $slots           List of { id, field, label }. Mapping from JSON keys to form fields.
	 *     @type string $field           Legacy single CSS selector (becomes one slot).
	 *     @type string $prompt_path     REST GET path that returns { prompt }. May include {id}.
	 *     @type string $generate_path   REST POST path that accepts { prompt } and returns { fields } or { text }.
	 *     @type bool   $lazy            Resolve fields later via window.forwpAiPanel.bind.
	 *     @type array  $context_modes   Optional list of { id, label } radios in the panel.
	 *     @type string $default_context Default context mode id.
	 *     @type array  $i18n            UI strings.
	 *     @type string $storage_key     sessionStorage key for panel position.
	 * }
	 * @return bool
	 */
	public static function enqueue( $config ) {
		if ( ! defined( 'FORWP_AI_LAYER_DIR' ) || ! defined( 'FORWP_AI_LAYER_URL' ) || '' === FORWP_AI_LAYER_URL ) {
			return false;
		}

		$script = FORWP_AI_LAYER_DIR . 'assets/panel.js';
		$style  = FORWP_AI_LAYER_DIR . 'assets/panel.css';
		if ( ! is_readable( $script ) ) {
			return false;
		}

		$slots         = self::normalize_slots( $config );
		$prompt_path   = isset( $config['prompt_path'] ) ? (string) $config['prompt_path'] : '';
		$generate_path = isset( $config['generate_path'] ) ? (string) $config['generate_path'] : '';
		if ( empty( $slots ) || '' === $prompt_path || '' === $generate_path ) {
			return false;
		}

		if ( is_readable( $style ) ) {
			wp_enqueue_style(
				'forwp-ai-field-panel',
				FORWP_AI_LAYER_URL . 'assets/panel.css',
				[ 'dashicons' ],
				(string) filemtime( $style )
			);
		}

		wp_enqueue_script(
			'forwp-ai-field-panel',
			FORWP_AI_LAYER_URL . 'assets/panel.js',
			[ 'wp-api-fetch' ],
			(string) filemtime( $script ),
			true
		);

		wp_localize_script(
			'forwp-ai-field-panel',
			'forwpAiField',
			[
				'slots'          => $slots,
				'promptPath'     => $prompt_path,
				'generatePath'   => $generate_path,
				'lazy'           => ! empty( $config['lazy'] ),
				'contextModes'   => self::normalize_context_modes( $config ),
				'defaultContext' => isset( $config['default_context'] ) ? sanitize_key( (string) $config['default_context'] ) : 'content',
				'storageKey'     => isset( $config['storage_key'] ) ? (string) $config['storage_key'] : 'forwpAiPanelPos',
				'i18n'           => isset( $config['i18n'] ) && is_array( $config['i18n'] ) ? $config['i18n'] : [],
			]
		);

		return true;
	}

	/**
	 * @param array $config Panel config.
	 * @return list<array{id: string, field: string, label: string}>
	 */
	private static function normalize_slots( $config ) {
		$raw = [];
		if ( isset( $config['slots'] ) && is_array( $config['slots'] ) ) {
			$raw = $config['slots'];
		} elseif ( ! empty( $config['field'] ) ) {
			$raw = [
				[
					'id'    => 'text',
					'field' => (string) $config['field'],
					'label' => '',
				],
			];
		}

		$slots = [];
		foreach ( $raw as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$id    = isset( $slot['id'] ) ? sanitize_key( (string) $slot['id'] ) : '';
			$field = isset( $slot['field'] ) ? trim( (string) $slot['field'] ) : '';
			if ( '' === $id || '' === $field ) {
				continue;
			}

			$slots[] = [
				'id'    => $id,
				'field' => $field,
				'label' => isset( $slot['label'] ) ? (string) $slot['label'] : '',
			];
		}

		return $slots;
	}

	/**
	 * @param array $config Panel config.
	 * @return list<array{id: string, label: string}>
	 */
	private static function normalize_context_modes( $config ) {
		$raw = isset( $config['context_modes'] ) && is_array( $config['context_modes'] )
			? $config['context_modes']
			: [];

		$modes = [];
		foreach ( $raw as $mode ) {
			if ( ! is_array( $mode ) ) {
				continue;
			}

			$id = isset( $mode['id'] ) ? sanitize_key( (string) $mode['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}

			$modes[] = [
				'id'    => $id,
				'label' => isset( $mode['label'] ) ? (string) $mode['label'] : $id,
			];
		}

		return $modes;
	}
}
