<?php
/**
 * Yoast SEO meta adapter.
 */

namespace Forwp\SeoHelper\SeoMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YoastAdapter implements AdapterInterface {
	public function get_id(): string {
		return 'yoast';
	}

	public function get_label(): string {
		return 'Yoast SEO';
	}

	public function is_available(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	public function read( int $post_id ): array {
		return [
			'title'          => (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'description'    => (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
			'focus_keyword' => (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
			'canonical'      => (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true ),
			'noindex'        => '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ),
			'og_title'       => (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true ),
			'og_description' => (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true ),
			'og_image'       => (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ),
		];
	}

	/**
	 * Resolve Yoast %%variables%% / CPT templates to the values that actually go out.
	 *
	 * @param array<string, mixed> $raw read() output
	 * @return array{title: string, title_is_auto: bool, description: string, description_is_auto: bool}
	 */
	public function present( \WP_Post $post, array $raw ): array {
		$title_raw = trim( (string) ( $raw['title'] ?? '' ) );
		$desc_raw  = trim( (string) ( $raw['description'] ?? '' ) );

		return [
			'title'              => $this->resolve_template( $post, $title_raw, 'title', '%%title%% %%page%% %%sep%% %%sitename%%' ),
			'title_is_auto'      => $this->is_generated( $title_raw ),
			'description'        => $this->resolve_template( $post, $desc_raw, 'metadesc', '' ),
			'description_is_auto'=> $this->is_generated( $desc_raw ),
		];
	}

	private function is_generated( string $raw ): bool {
		return '' === $raw || false !== strpos( $raw, '%%' );
	}

	private function resolve_template( \WP_Post $post, string $raw, string $option_prefix, string $hard_fallback ): string {
		$source = $raw;
		if ( '' === $source ) {
			$source = $this->option_template( $option_prefix . '-' . $post->post_type );
		}
		if ( '' === $source ) {
			$source = $hard_fallback;
		}
		if ( '' === $source ) {
			return '';
		}

		return $this->replace_vars( $source, $post );
	}

	private function option_template( string $option ): string {
		if ( ! class_exists( '\WPSEO_Options' ) ) {
			return '';
		}

		$value = \WPSEO_Options::get( $option, '' );

		return is_string( $value ) ? $value : '';
	}

	private function replace_vars( string $text, \WP_Post $post ): string {
		if ( ! class_exists( '\WPSEO_Replace_Vars' ) ) {
			return trim( $text );
		}

		$replaced = ( new \WPSEO_Replace_Vars() )->replace( $text, $post );

		return is_string( $replaced ) ? trim( $replaced ) : '';
	}

	public function write( int $post_id, array $fields ): bool {
		$map = [
			'title'           => '_yoast_wpseo_title',
			'description'     => '_yoast_wpseo_metadesc',
			'focus_keyword'   => '_yoast_wpseo_focuskw',
			'canonical'       => '_yoast_wpseo_canonical',
			'og_title'        => '_yoast_wpseo_opengraph-title',
			'og_description'  => '_yoast_wpseo_opengraph-description',
			'og_image'        => '_yoast_wpseo_opengraph-image',
		];

		foreach ( $map as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				continue;
			}

			update_post_meta( $post_id, $meta_key, sanitize_text_field( (string) $fields[ $field ] ) );
		}

		if ( array_key_exists( 'noindex', $fields ) ) {
			update_post_meta(
				$post_id,
				'_yoast_wpseo_meta-robots-noindex',
				! empty( $fields['noindex'] ) ? '1' : '0'
			);
		}

		return true;
	}

	public function read_scores( int $post_id ): array {
		$focus     = trim( (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) );
		$linkdex   = get_post_meta( $post_id, '_yoast_wpseo_linkdex', true );
		$content   = get_post_meta( $post_id, '_yoast_wpseo_content_score', true );
		$no_focus  = '' === $focus;
		$seo       = ( '' !== (string) $linkdex && is_numeric( $linkdex ) ) ? (int) $linkdex : null;
		$read      = ( '' !== (string) $content && is_numeric( $content ) ) ? (int) $content : null;
		$label     = '';

		if ( $no_focus ) {
			$label = __( 'No focus keyphrase', '4wp-seo-helper' );
		} elseif ( null !== $seo && class_exists( 'WPSEO_Rank' ) ) {
			$label = \WPSEO_Rank::from_numeric_score( $seo )->get_label();
		}

		return [
			'seo'           => $seo,
			'readability'   => $read,
			'label'         => $label,
			'no_focus'      => $no_focus,
		];
	}

	/**
	 * Yoast fields stored on a taxonomy term (archive page).
	 *
	 * @return array{
	 *     title: string,
	 *     description: string,
	 *     focus_keyword: string,
	 *     canonical: string,
	 *     noindex: bool,
	 *     og_title: string,
	 *     og_description: string,
	 *     og_image: string
	 * }
	 */
	public function read_term( \WP_Term $term ): array {
		$meta = [];
		if ( class_exists( '\WPSEO_Taxonomy_Meta' ) ) {
			$stored = \WPSEO_Taxonomy_Meta::get_term_meta( $term, $term->taxonomy );
			if ( is_array( $stored ) ) {
				$meta = $stored;
			}
		}

		if ( [] === $meta ) {
			$all = get_option( 'wpseo_taxonomy_meta', [] );
			if ( is_array( $all ) ) {
				$meta = $all[ $term->taxonomy ][ $term->term_id ] ?? [];
			}
		}

		if ( ! is_array( $meta ) ) {
			$meta = [];
		}

		$noindex = $meta['wpseo_noindex'] ?? '';

		return [
			'title'           => (string) ( $meta['wpseo_title'] ?? '' ),
			'description'     => (string) ( $meta['wpseo_desc'] ?? '' ),
			'focus_keyword'   => (string) ( $meta['wpseo_focuskw'] ?? '' ),
			'canonical'       => (string) ( $meta['wpseo_canonical'] ?? '' ),
			'noindex'         => 'noindex' === $noindex || '1' === (string) $noindex,
			'og_title'        => (string) ( $meta['wpseo_opengraph-title'] ?? '' ),
			'og_description'  => (string) ( $meta['wpseo_opengraph-description'] ?? '' ),
			'og_image'        => (string) ( $meta['wpseo_opengraph-image'] ?? '' ),
		];
	}

	/**
	 * @return array{title: string, title_is_auto: bool, description: string, description_is_auto: bool}
	 */
	public function present_term( \WP_Term $term, array $raw ): array {
		$title_raw = trim( (string) ( $raw['title'] ?? '' ) );
		$desc_raw  = trim( (string) ( $raw['description'] ?? '' ) );

		$title = $title_raw;
		$desc  = $desc_raw;
		if ( class_exists( '\WPSEO_Replace_Vars' ) ) {
			$replacer = new \WPSEO_Replace_Vars();
			if ( '' !== $title ) {
				$replaced = $replacer->replace( $title, $term );
				$title    = is_string( $replaced ) ? trim( $replaced ) : $title;
			}
			if ( '' !== $desc ) {
				$replaced = $replacer->replace( $desc, $term );
				$desc     = is_string( $replaced ) ? trim( $replaced ) : $desc;
			}
		}

		if ( '' === $title ) {
			$title = (string) $term->name;
		}

		return [
			'title'               => $title,
			'title_is_auto'       => '' === $title_raw || false !== strpos( $title_raw, '%%' ),
			'description'         => $desc,
			'description_is_auto' => '' === $desc_raw || false !== strpos( $desc_raw, '%%' ),
		];
	}

	public static function rebuild_indexable( int $post_id ): bool {
		if ( $post_id <= 0 || ! defined( 'WPSEO_VERSION' ) || ! function_exists( 'YoastSEO' ) ) {
			return false;
		}

		try {
			$builder = YoastSEO()->classes->get( \Yoast\WP\SEO\Builders\Indexable_Builder::class );
			$builder->build_for_id_and_type( $post_id, 'post' );

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
