<?php
/**
 * Cross posting REST endpoints.
 */

namespace Forwp\SeoHelper\CrossPosting;

use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest {
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			'forwp-seo/v1',
			'/crosspost',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_request' ],
				'permission_callback' => [ $this, 'can_access' ],
				'args'                => [
					'post_id' => [
						'required' => true,
						'type'     => 'integer',
					],
					'fresh'   => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);

		$this->register_payload_route();
		$this->register_notify_route();
		$this->register_published_route();
		$this->register_board_route();
	}

	/**
	 * Send the Telegram share message now, without waiting for the schedule.
	 */
	private function register_notify_route(): void {
		register_rest_route(
			'forwp-seo/v1',
			'/crosspost/notify',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_notify' ],
				'permission_callback' => [ $this, 'can_access' ],
				'args'                => [
					'post_id' => [
						'required' => true,
						'type'     => 'integer',
					],
				],
			]
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array<string, bool>|\WP_Error
	 */
	public function handle_notify( WP_REST_Request $request ) {
		$result = Schedule::send( (int) $request->get_param( 'post_id' ), false );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			if ( $status < 400 ) {
				$result->add_data( [ 'status' => 502 ] );
			}
			return $result;
		}

		return [ 'sent' => true ];
	}

	/**
	 * Record that the variant was published on the platform. The slug is taken from the page open at the click.
	 */
	private function register_published_route(): void {
		register_rest_route(
			'forwp-seo/v1',
			'/crosspost/published',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_published' ],
				'permission_callback' => [ $this, 'can_access' ],
				'args'                => [
					'post_id'  => [
						'required' => true,
						'type'     => 'integer',
					],
					'platform' => [
						'required' => true,
						'type'     => 'string',
					],
					'url'      => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array<string, string>|\WP_Error
	 */
	public function handle_published( WP_REST_Request $request ) {
		if ( ! Module::get_instance()->is_enabled() ) {
			return new \WP_Error( 'crossposting_disabled', __( 'Cross posting is disabled.', '4wp-seo-helper' ), [ 'status' => 403 ] );
		}

		$post_id = (int) $request->get_param( 'post_id' );
		$key     = strtolower( sanitize_text_field( (string) $request->get_param( 'platform' ) ) );
		$url     = esc_url_raw( (string) $request->get_param( 'url' ) );

		if ( ! in_array( $key, Platforms::ACTIVE_KEYS, true ) ) {
			return new \WP_Error( 'crossposting_platform', __( 'This platform is not enabled.', '4wp-seo-helper' ), [ 'status' => 404 ] );
		}
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'crossposting_url', __( 'Open the published page first.', '4wp-seo-helper' ), [ 'status' => 400 ] );
		}

		$slug = self::slug_from_url( $url );
		if ( '' === $slug ) {
			return new \WP_Error( 'crossposting_slug', __( 'This page has no slug.', '4wp-seo-helper' ), [ 'status' => 400 ] );
		}

		$posted_at = gmdate( 'c' );
		Record::append( $post_id, $key, $url, $slug, $posted_at );
		$result    = apply_filters(
			'forwp_drive_update_package',
			null,
			$post_id,
			[
				'platforms' => [
					$key => [
						'status'    => 'posted',
						'url'       => $url,
						'slug'      => $slug,
						'posted_at' => $posted_at,
					],
				],
			]
		);

		return [
			'status'    => 'posted',
			'url'       => $url,
			'slug'      => $slug,
			'posted_at' => $posted_at,
		];
	}

	/**
	 * Last path segment of the page that was open when PUBLISHED was pressed.
	 */
	private static function slug_from_url( string $url ): string {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return '';
		}
		$parts = explode( '/', $path );
		$slug  = trim( rawurldecode( (string) end( $parts ) ) );
		if ( '' === $slug || str_contains( $slug, '..' ) || strlen( $slug ) > 200 ) {
			return '';
		}

		return $slug;
	}

	/**
	 * Register the payload route (called from register_routes).
	 */
	private function register_payload_route(): void {
		register_rest_route(
			'forwp-seo/v1',
			'/crosspost/payload',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_payload' ],
				'permission_callback' => [ $this, 'can_access' ],
				'args'                => [
					'post_id'  => [
						'required' => true,
						'type'     => 'integer',
					],
					'platform' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);
	}

	/**
	 * Everything the Chrome extension needs to post one platform variant.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle_payload( WP_REST_Request $request ) {
		if ( ! Module::get_instance()->is_enabled() ) {
			return new \WP_Error( 'crossposting_disabled', __( 'Cross posting is disabled.', '4wp-seo-helper' ), [ 'status' => 403 ] );
		}

		$post_id = (int) $request->get_param( 'post_id' );
		$key     = strtolower( sanitize_text_field( (string) $request->get_param( 'platform' ) ) );

		$platform = null;
		foreach ( Platforms::all() as $row ) {
			if ( $row['key'] === $key ) {
				$platform = $row;
				break;
			}
		}
		if ( null === $platform ) {
			return new \WP_Error( 'crossposting_platform', __( 'This platform is not enabled.', '4wp-seo-helper' ), [ 'status' => 404 ] );
		}

		$package = apply_filters( 'forwp_drive_package', null, $post_id );
		if ( is_wp_error( $package ) ) {
			return new \WP_Error( 'crossposting_package', $package->get_error_message(), [ 'status' => 502 ] );
		}
		if ( ! is_array( $package ) || empty( $package['platforms'][ $key ] ) || ! is_array( $package['platforms'][ $key ] ) ) {
			return new \WP_Error( 'crossposting_not_declared', __( 'This post has no variant for this platform.', '4wp-seo-helper' ), [ 'status' => 404 ] );
		}

		$entry   = $package['platforms'][ $key ];
		$file    = (string) ( $entry['file'] ?? '' );
		$content = '';
		$error   = '';

		$files = apply_filters( 'forwp_drive_package_files', null, $post_id );
		$state = self::variant_state( (string) ( $entry['status'] ?? 'open' ), $file, is_array( $files ) ? $files : null );
		if ( '' !== $file ) {
			$text = apply_filters( 'forwp_drive_package_file', null, $post_id, $file );
			if ( is_wp_error( $text ) ) {
				$error = $text->get_error_message();
			} elseif ( is_string( $text ) ) {
				$content = $text;
			}
		}

		$original = isset( $package['original'] ) && is_array( $package['original'] ) ? $package['original'] : [];

		// Known values for {{placeholders}} in variants.
		$replacements = [
			'{{original.url}}'   => (string) ( $original['url'] ?? '' ),
			'{{original.title}}' => get_the_title( $post_id ),
		];
		$content = strtr( $content, $replacements );

		// Front matter (--- key: value ---) holds platform fields such as title, tags, canonical_url.
		$fields = [];
		if ( preg_match( '/\A\s*---\R(.*?)\R---\s*(?:\R|\z)/su', $content, $m ) ) {
			foreach ( preg_split( '/\R/u', $m[1] ) as $line ) {
				if ( preg_match( '/^\s*([A-Za-z0-9_\-]+)\s*:\s*(.*?)\s*$/u', $line, $kv ) ) {
					$value = trim( $kv[2], "\"'" );
					// Unresolved placeholder: leave the field out rather than paste "{{...}}".
					if ( '' !== $value && false === strpos( $value, '{{' ) ) {
						$fields[ $kv[1] ] = $value;
					}
				}
			}
			$content = ltrim( (string) substr( $content, strlen( $m[0] ) ) );
		}

		// A leading "# Title" is the platform title: return it separately so it is not pasted twice.
		$variant_title = (string) ( $fields['title'] ?? '' );
		unset( $fields['title'] );
		if ( preg_match( '/\A\s*#\s+(.+?)\s*(?:\R|\z)/u', $content, $m ) ) {
			$variant_title = '' !== $variant_title ? $variant_title : trim( $m[1] );
			$content       = ltrim( (string) substr( $content, strlen( $m[0] ) ) );
		}

		return [
			'post_id'  => $post_id,
			'title'    => get_the_title( $post_id ),
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			'original' => [
				'status' => (string) ( $original['status'] ?? '' ),
				'url'    => (string) ( $original['url'] ?? '' ),
			],
			'platform' => [
				'key'      => $platform['key'],
				'label'    => $platform['label'],
				'mode'     => $platform['mode'],
				'format'   => (string) ( $entry['format'] ?? $platform['format'] ),
				'status'   => (string) ( $entry['status'] ?? 'open' ),
				'rules'    => isset( $entry['rules'] ) && is_array( $entry['rules'] ) ? array_values( $entry['rules'] ) : [],
				'media'    => isset( $entry['media'] ) && is_array( $entry['media'] ) ? array_values( $entry['media'] ) : [],
				'url'      => (string) ( $entry['url'] ?? '' ),
				'slug'     => (string) ( $entry['slug'] ?? '' ),
				'open_url' => $platform['url'],
			],
			'variant'  => [
				'file'    => $file,
				'state'   => $state,
				'title'   => $variant_title,
				'fields'  => (object) $fields,
				'content' => $content,
				'error'   => $error,
			],
			'image'    => self::post_image( $post_id, $package ),
		];
	}

	/**
	 * Featured image, or the package cover when the post has none.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $package Package data.
	 * @return array{name: string, url: string, file: string}|null
	 */
	private static function post_image( int $post_id, array $package ): ?array {
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb_id > 0 ) {
			$url = (string) wp_get_attachment_url( $thumb_id );
			if ( '' !== $url ) {
				$path = (string) get_attached_file( $thumb_id );
				return [
					'name' => '' !== $path ? basename( $path ) : 'cover.jpg',
					'url'  => $url,
					'file' => '',
				];
			}
		}

		$media = isset( $package['media'] ) && is_array( $package['media'] ) ? $package['media'] : [];
		foreach ( $media as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$file = str_replace( '\\', '/', trim( (string) ( $item['file'] ?? '' ) ) );
			if ( '' === $file || str_contains( $file, '..' ) || ! preg_match( '/\.(png|jpe?g|gif|webp)$/i', $file ) ) {
				continue;
			}
			return [
				'name' => basename( $file ),
				'url'  => '',
				'file' => $file,
			];
		}

		return null;
	}

	/**
	 * Enabled platforms, and the posts still waiting on the platform open in the extension.
	 */
	private function register_board_route(): void {
		register_rest_route(
			'forwp-seo/v1',
			'/crosspost/board',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_board' ],
				'permission_callback' => [ $this, 'can_list' ],
				'args'                => [
					'platform' => [
						'type'    => 'string',
						'default' => '',
					],
				],
			]
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function handle_board( WP_REST_Request $request ) {
		if ( ! Module::get_instance()->is_enabled() ) {
			return new \WP_Error( 'crossposting_disabled', __( 'Cross posting is disabled.', '4wp-seo-helper' ), [ 'status' => 403 ] );
		}

		$platforms = [];
		foreach ( Platforms::all() as $row ) {
			$platforms[] = [
				'key'   => $row['key'],
				'label' => $row['label'],
				'url'   => $row['url'],
			];
		}

		$key = strtolower( sanitize_text_field( (string) $request->get_param( 'platform' ) ) );
		$known = wp_list_pluck( $platforms, 'key' );
		if ( ! in_array( $key, $known, true ) ) {
			$key = '';
		}

		return [
			'platforms' => $platforms,
			'platform'  => $key,
			'materials' => '' === $key ? [] : $this->waiting_materials( $key ),
		];
	}

	/**
	 * Published package posts whose variant for this platform is not posted yet.
	 *
	 * @return list<array{post_id: int, title: string, status: string}>
	 */
	private function waiting_materials( string $key ): array {
		$ids = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => Schedule::PACKAGE_META,
			]
		);

		$materials = [];
		foreach ( $ids as $post_id ) {
			$post_id = (int) $post_id;
			$package = apply_filters( 'forwp_drive_package', null, $post_id );
			if ( ! is_array( $package ) || empty( $package['platforms'][ $key ] ) || ! is_array( $package['platforms'][ $key ] ) ) {
				continue;
			}
			$status = (string) ( $package['platforms'][ $key ]['status'] ?? 'open' );
			if ( 'posted' === $status ) {
				continue;
			}
			$materials[] = [
				'post_id' => $post_id,
				'title'   => get_the_title( $post_id ),
				'status'  => '' !== $status ? $status : 'open',
			];
		}

		return $materials;
	}

	public function can_list(): bool {
		return current_user_can( 'edit_posts' );
	}

	public function can_access( WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Platforms of the post's meta.json package that are enabled on this site, with their status.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle_request( WP_REST_Request $request ) {
		if ( ! Module::get_instance()->is_enabled() ) {
			return new \WP_Error( 'crossposting_disabled', __( 'Cross posting is disabled.', '4wp-seo-helper' ), [ 'status' => 403 ] );
		}

		$post_id = (int) $request->get_param( 'post_id' );

		/**
		 * Package meta.json, provided by 4WP Drive (null when there is no package).
		 *
		 * @param array|\WP_Error|null $package Package data.
		 * @param int                  $post_id Post ID.
		 * @param bool                 $fresh   Skip the cache.
		 */
		$package = apply_filters( 'forwp_drive_package', null, $post_id, (bool) $request->get_param( 'fresh' ) );

		if ( is_wp_error( $package ) ) {
			return new \WP_Error( 'crossposting_package', $package->get_error_message(), [ 'status' => 502 ] );
		}

		if ( ! is_array( $package ) ) {
			return [
				'package'   => false,
				'platforms' => [],
			];
		}

		$republish   = isset( $package['republish'] ) && is_array( $package['republish'] ) ? $package['republish'] : [];
		$delay_hours = isset( $republish['delay_hours'] ) ? max( 0, (int) $republish['delay_hours'] ) : Module::get_instance()->delay_hours();

		/**
		 * Files in the package folder (name => size), provided by 4WP Drive.
		 *
		 * @param array|\WP_Error|null $files   Package files.
		 * @param int                  $post_id Post ID.
		 */
		$files = apply_filters( 'forwp_drive_package_files', null, $post_id );
		$files = is_array( $files ) ? $files : null;

		$declared = isset( $package['platforms'] ) && is_array( $package['platforms'] ) ? $package['platforms'] : [];
		$original = isset( $package['original'] ) && is_array( $package['original'] ) ? $package['original'] : [];

		$platforms = [];
		foreach ( Platforms::all() as $platform ) {
			if ( ! in_array( $platform['key'], Platforms::ACTIVE_KEYS, true ) ) {
				continue;
			}
			if ( ! isset( $declared[ $platform['key'] ] ) || ! is_array( $declared[ $platform['key'] ] ) ) {
				continue;
			}
			$entry  = $declared[ $platform['key'] ];
			$status = (string) ( $entry['status'] ?? 'open' );
			$url    = (string) ( $entry['url'] ?? '' );
			$file   = (string) ( $entry['file'] ?? '' );

			$platforms[] = [
				'key'       => $platform['key'],
				'label'     => $platform['label'],
				'mode'      => $platform['mode'],
				'format'    => (string) ( $entry['format'] ?? $platform['format'] ),
				'status'    => $status,
				'posted'    => 'posted' === $status,
				'variant'   => self::variant_state( $status, $file, $files ),
				'url'       => $url,
				'open_url'  => '' !== $url ? $url : $platform['url'],
				'posted_at' => (string) ( $entry['posted_at'] ?? '' ),
				'file'      => $file,
			];
		}

		$published_ts = ! empty( $original['published_at'] ) ? strtotime( (string) $original['published_at'] ) : false;

		return [
			'package'      => true,
			'delay_hours'  => $delay_hours,
			'republish_at' => $published_ts ? gmdate( 'c', $published_ts + $delay_hours * HOUR_IN_SECONDS ) : '',
			'original'     => [
				'status'       => (string) ( $original['status'] ?? '' ),
				'url'          => (string) ( $original['url'] ?? '' ),
				'published_at' => (string) ( $original['published_at'] ?? '' ),
			],
			'sync_error'   => (string) get_post_meta( $post_id, '_forwp_drive_package_error', true ),
			'platforms'    => $platforms,
		];
	}

	/**
	 * Readiness of the platform text: none, missing, empty, draft, skipped or ready.
	 *
	 * @param string                  $status Platform status from meta.json.
	 * @param string                  $file   Variant file name from meta.json.
	 * @param array<string, int>|null $files  Package files (name => size); null when unknown.
	 */
	private static function variant_state( string $status, string $file, ?array $files ): string {
		if ( '' === $file ) {
			return 'none';
		}
		if ( null !== $files ) {
			if ( ! array_key_exists( $file, $files ) ) {
				return 'missing';
			}
			if ( 0 === $files[ $file ] ) {
				return 'empty';
			}
		}
		if ( 'skipped' === $status ) {
			return 'skipped';
		}
		return in_array( $status, [ 'ready', 'scheduled', 'posted' ], true ) ? 'ready' : 'draft';
	}
}
