<?php
/**
 * Plugin bootstrap.
 */

namespace Forwp\SeoHelper\Core;

use Forwp\SeoHelper\Admin\AdminBar;
use Forwp\SeoHelper\Admin\Editor;
use Forwp\SeoHelper\Admin\Menu;
use Forwp\SeoHelper\Admin\Notices;
use Forwp\SeoHelper\Blocks\TechArticleWrappers;
use Forwp\SeoHelper\CrossPosting\Module as CrossPostingModule;
use Forwp\SeoHelper\Drive\Capabilities as DriveCapabilities;
use Forwp\SeoHelper\Gsc\Admin as GscAdmin;
use Forwp\SeoHelper\Gsc\Indexing;
use Forwp\SeoHelper\Gsc\Module as GscModule;
use Forwp\SeoHelper\Llms\Generator;
use Forwp\SeoHelper\FocusKeywords\Module as FocusKeywordsModule;
use Forwp\SeoHelper\Positions\Module as PositionsModule;
use Forwp\SeoHelper\Schema\TechArticle;
use Forwp\SeoHelper\Schema\TechArticleRest;
use Forwp\SeoHelper\Schema\ExternalEntities;
use Forwp\SeoHelper\Inventory\Module as InventoryModule;
use Forwp\SeoHelper\Trends\Module as TrendsModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Extension {
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init();
	}

	private function init(): void {
		add_action( 'init', [ $this, 'register_post_meta' ] );
		add_action( 'add_meta_boxes', [ $this, 'maybe_register_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'maybe_save_post_meta' ] );

		TechArticle::get_instance();
		TechArticleRest::get_instance();
		ExternalEntities::get_instance();
		TechArticleWrappers::get_instance();
		DriveCapabilities::init();
		Generator::get_instance();
		CrossPostingModule::get_instance();
		InventoryModule::get_instance();
		PositionsModule::get_instance();
		FocusKeywordsModule::get_instance();
		TrendsModule::get_instance();
		GscModule::get_instance();
		GscAdmin::get_instance(); // Must be initialized for REST API callback
		Indexing::get_instance();
		AdminBar::boot();

		if ( is_admin() ) {
			Menu::get_instance();
			add_action( 'init', [ $this, 'maybe_boot_editor' ], 20 );
			Notices::get_instance();
		}
	}

	/**
	 * Register TechArticle meta box only when the module is public.
	 */
	public function maybe_register_meta_boxes(): void {
		if ( ! Release::is_module_public( Release::MODULE_TECHARTICLE ) ) {
			return;
		}
		$this->register_meta_boxes();
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public function maybe_save_post_meta( int $post_id ): void {
		if ( ! Release::is_module_public( Release::MODULE_TECHARTICLE ) ) {
			return;
		}
		$this->save_post_meta( $post_id );
	}

	/**
	 * Boot editor sidebar when a gated module is public (checked after theme filters).
	 */
	public function maybe_boot_editor(): void {
		if (
			Release::is_module_public( Release::MODULE_TECHARTICLE )
			|| Release::is_module_public( Release::MODULE_CROSSPOSTING )
			|| Release::is_module_public( Release::MODULE_GSC )
		) {
			Editor::get_instance();
		}
	}

	public function register_post_meta(): void {
		register_post_meta(
			'',
			TechArticle::META_KEY,
			[
				'single'       => true,
				'type'         => 'boolean',
				'show_in_rest' => true,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	public function register_meta_boxes(): void {
		$post_types = apply_filters( 'forwp_seo_supported_post_types', [ 'post' ] );
		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'forwp-seo-techarticle',
				'4wp SEO',
				[ $this, 'render_meta_box' ],
				$post_type,
				'side',
				'default'
			);
		}
	}

	public function render_meta_box( \WP_Post $post ): void {
		$enabled = TechArticle::get_instance()->is_enabled_for_post( $post->ID );
		wp_nonce_field( 'forwp_seo_meta_box', 'forwp_seo_meta_nonce' );
		?>
		<p>
			<label>
				<input type="checkbox" name="forwp_seo_techarticle_enabled" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Enable TechArticle schema for this post', '4wp-seo-helper' ); ?>
			</label>
		</p>
		<?php
	}

	public function save_post_meta( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$nonce = isset( $_POST['forwp_seo_meta_nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['forwp_seo_meta_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'forwp_seo_meta_box' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$enabled = isset( $_POST['forwp_seo_techarticle_enabled'] ) ? '1' : '0';
		update_post_meta( $post_id, TechArticle::META_KEY, $enabled );
		delete_post_meta( $post_id, '_4wp_seo_techarticle_enabled' );
	}
}

