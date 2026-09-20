<?php
/**
 * Admin page output.
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\Core\Release;
use Forwp\SeoHelper\CrossPosting\Module as CrossPostingModule;
use Forwp\SeoHelper\Gsc\Admin as GscAdmin;
use Forwp\SeoHelper\Gsc\Module as GscModule;
use Forwp\SeoHelper\Gsc\Sync as GscSync;
use Forwp\SeoHelper\Inventory\ExcludedPostTypes;
use Forwp\SeoHelper\Inventory\Module as InventoryModule;
use Forwp\SeoHelper\Inventory\PostTypeDiscovery;
use Forwp\SeoHelper\Inventory\PriorityLabels;
use Forwp\SeoHelper\Inventory\Repository;
use Forwp\SeoHelper\Multilingual\Registry as MultilingualRegistry;
use Forwp\SeoHelper\Positions\Settings as PositionsSettings;
use Forwp\SeoHelper\SeoMeta\Registry as SeoMetaRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Page {
	private const TAB_OVERVIEW  = 'overview';
	private const TAB_SETTINGS  = 'settings';
	private const TAB_API       = 'api';
	private const TAB_GSC       = 'gsc';
	private const TAB_POSITIONS = 'positions';

	/**
	 * @return list<string>
	 */
	private static function get_tabs(): array {
		$tabs = [
			self::TAB_OVERVIEW,
			self::TAB_SETTINGS,
			self::TAB_POSITIONS,
		];

		if ( Release::is_module_public( Release::MODULE_INVENTORY_API ) ) {
			$tabs[] = self::TAB_API;
		}

		if ( Release::is_module_public( Release::MODULE_GSC ) && GscModule::get_instance()->is_enabled() ) {
			$tabs[] = self::TAB_GSC;
		}

		return $tabs;
	}

	/**
	 * Handle Settings POST and redirects before any admin HTML is sent.
	 */
	public static function handle_load(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$gsc_redirect = GscAdmin::get_instance()->handle_connect_post();
		if ( is_string( $gsc_redirect ) && '' !== $gsc_redirect ) {
			wp_safe_redirect( $gsc_redirect );
			exit;
		}

		$redirect = self::process_settings_post();
		if ( is_string( $redirect ) && '' !== $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = self::get_active_tab();

		$is_connected         = GscAdmin::get_instance()->is_connected();
		$gsc_enabled          = GscModule::get_instance()->is_enabled();
		$crossposting_enabled = CrossPostingModule::get_instance()->is_enabled();
		$inventory_enabled    = InventoryModule::get_instance()->is_enabled();
		$seo_adapter          = SeoMetaRegistry::get_active();
		$multilingual         = MultilingualRegistry::get_active();
		$inventory_stats      = $inventory_enabled ? ( new Repository() )->get_stats() : null;

		$heading_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="none" focusable="false" aria-hidden="true"><path d="M3 3v18h18" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="m7 14 4-4 3 3 5-6" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		?>
		<div class="wrap forwp-seo-admin-shell">
			<?php self::render_notices(); ?>

			<h1 class="forwp-seo-admin-heading">
				<span class="forwp-seo-admin-heading__icon" aria-hidden="true">
					<?php echo wp_kses( $heading_svg, self::svg_allowed_html() ); ?>
				</span>
				<span class="forwp-seo-admin-heading__text">
					<?php esc_html_e( '4WP SEO Helper', '4wp-seo-helper' ); ?>
					<span class="forwp-seo-admin-heading__version"><?php echo esc_html( self::get_version_label() ); ?></span>
				</span>
			</h1>
			<p class="forwp-seo-admin-lead">
				<?php esc_html_e( 'SEO Inventory for WordPress — audit titles, meta descriptions, and SEO completeness across your site. More modules are on the roadmap.', '4wp-seo-helper' ); ?>
			</p>

			<div class="forwp-seo-admin-app">
				<div class="forwp-seo-tab-panel components-tab-panel">
					<div class="components-tab-panel__tabs" role="tablist" aria-label="<?php esc_attr_e( '4WP SEO Helper', '4wp-seo-helper' ); ?>">
						<?php self::render_tab_button( self::TAB_OVERVIEW, __( 'Overview', '4wp-seo-helper' ), $tab ); ?>
						<?php self::render_tab_button( self::TAB_SETTINGS, __( 'Settings', '4wp-seo-helper' ), $tab ); ?>
						<?php self::render_tab_button( self::TAB_POSITIONS, __( 'Position providers', '4wp-seo-helper' ), $tab ); ?>
						<?php if ( Release::is_module_public( Release::MODULE_INVENTORY_API ) ) : ?>
							<?php self::render_tab_button( self::TAB_API, __( 'Inventory API', '4wp-seo-helper' ), $tab ); ?>
						<?php endif; ?>
						<?php if ( Release::is_module_public( Release::MODULE_GSC ) && $gsc_enabled ) : ?>
							<?php self::render_tab_button( self::TAB_GSC, __( 'Search Console', '4wp-seo-helper' ), $tab ); ?>
						<?php endif; ?>
					</div>

					<div id="forwp-seo-panel-overview" role="tabpanel" class="components-tab-panel__tab-content" aria-labelledby="forwp-seo-tab-overview" <?php echo self::TAB_OVERVIEW !== $tab ? 'hidden' : ''; ?>>
						<?php
						self::render_overview_tab(
							$is_connected,
							$crossposting_enabled,
							$inventory_enabled,
							$inventory_stats,
							$seo_adapter,
							$multilingual
						);
						?>
					</div>

					<div id="forwp-seo-panel-settings" role="tabpanel" class="components-tab-panel__tab-content" aria-labelledby="forwp-seo-tab-settings" <?php echo self::TAB_SETTINGS !== $tab ? 'hidden' : ''; ?>>
						<?php self::render_settings_tab( $crossposting_enabled, $inventory_enabled, $gsc_enabled ); ?>
					</div>

					<div id="forwp-seo-panel-positions" role="tabpanel" class="components-tab-panel__tab-content" aria-labelledby="forwp-seo-tab-positions" <?php echo self::TAB_POSITIONS !== $tab ? 'hidden' : ''; ?>>
						<?php self::render_positions_tab(); ?>
					</div>

					<?php if ( Release::is_module_public( Release::MODULE_INVENTORY_API ) ) : ?>
					<div id="forwp-seo-panel-api" role="tabpanel" class="components-tab-panel__tab-content" aria-labelledby="forwp-seo-tab-api" <?php echo self::TAB_API !== $tab ? 'hidden' : ''; ?>>
						<?php self::render_api_tab( $inventory_enabled ); ?>
					</div>
					<?php endif; ?>

					<?php if ( Release::is_module_public( Release::MODULE_GSC ) && $gsc_enabled ) : ?>
					<div id="forwp-seo-panel-gsc" role="tabpanel" class="components-tab-panel__tab-content" aria-labelledby="forwp-seo-tab-gsc" <?php echo self::TAB_GSC !== $tab ? 'hidden' : ''; ?>>
						<?php self::render_gsc_tab( $gsc_enabled ); ?>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	private static function get_active_tab(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::TAB_SETTINGS;

		if ( ! in_array( $tab, self::get_tabs(), true ) ) {
			if ( Release::is_module_public( Release::MODULE_GSC ) && ( ! empty( $_GET['gsc_connected'] ) || ! empty( $_GET['gsc_disconnected'] ) || ! empty( $_GET['gsc_error'] ) || isset( $_GET['gsc_saved'] ) ) ) {
				return GscModule::get_instance()->is_enabled() ? self::TAB_GSC : self::TAB_SETTINGS;
			}
			return self::TAB_SETTINGS;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $tab;
	}

	private static function render_tab_button( string $id, string $label, string $active_tab ): void {
		$active = $id === $active_tab;
		printf(
			'<button type="button" role="tab" id="forwp-seo-tab-%1$s" class="components-button components-tab-panel__tabs-item forwp-seo-tab%2$s" data-tab="%1$s" aria-selected="%3$s" aria-controls="forwp-seo-panel-%1$s" tabindex="%4$d">%5$s</button>',
			esc_attr( $id ),
			$active ? ' is-active' : '',
			$active ? 'true' : 'false',
			$active ? 0 : -1,
			esc_html( $label )
		);
	}

	/**
	 * @param array<string, mixed>|null $inventory_stats
	 */
	private static function render_overview_tab(
		bool $is_connected,
		bool $crossposting_enabled,
		bool $inventory_enabled,
		?array $inventory_stats,
		$seo_adapter,
		$multilingual
	): void {
		?>
		<div class="forwp-seo-intro-card">
			<h2 class="forwp-seo-intro-card__title"><?php esc_html_e( 'What this plugin does', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-intro-card__text">
				<?php esc_html_e( '4WP SEO Helper gives you a site-wide SEO Inventory: see missing titles and meta fields, set business priorities (P1–P3), and export or sync via REST. Built for Yoast SEO and All in One SEO.', '4wp-seo-helper' ); ?>
			</p>
			<?php if ( $inventory_enabled ) : ?>
				<div class="forwp-seo-intro-card__actions">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::INVENTORY_PAGE_SLUG ) ); ?>">
						<?php esc_html_e( 'Open SEO Inventory', '4wp-seo-helper' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::INVENTORY_PAGE_SLUG . '&missing=any' ) ); ?>">
						<?php esc_html_e( 'Show missing fields', '4wp-seo-helper' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>

		<h2 class="forwp-seo-admin-section-title"><?php esc_html_e( 'Module status', '4wp-seo-helper' ); ?></h2>
		<div class="forwp-seo-status-grid">
			<?php
			self::render_status_card(
				__( 'SEO inventory', '4wp-seo-helper' ),
				$inventory_enabled
					? __( 'Admin table + REST API for bulk SEO ops.', '4wp-seo-helper' )
					: __( 'Disabled — enable in Settings.', '4wp-seo-helper' ),
				$inventory_enabled ? 'live' : 'off',
				$inventory_enabled && is_array( $inventory_stats )
					? sprintf(
						/* translators: 1: post count, 2: average completeness percent */
						__( '%1$d posts · %2$d%% avg completeness', '4wp-seo-helper' ),
						(int) $inventory_stats['posts'],
						(int) $inventory_stats['avg_completeness']
					)
					: ( $inventory_enabled ? __( 'API enabled', '4wp-seo-helper' ) : __( 'Off', '4wp-seo-helper' ) )
			);
			self::render_status_card(
				__( 'Integrations', '4wp-seo-helper' ),
				sprintf(
					/* translators: 1: SEO adapter label, 2: multilingual provider label */
					__( 'SEO: %1$s · Multilingual: %2$s', '4wp-seo-helper' ),
					$seo_adapter->get_label(),
					$multilingual->get_label()
				),
				'none' !== $seo_adapter->get_id() ? 'live' : 'warn',
				'none' !== $seo_adapter->get_id()
					? __( 'Adapter active', '4wp-seo-helper' )
					: __( 'No SEO plugin detected', '4wp-seo-helper' )
			);
			self::render_status_card(
				__( 'TechArticle schema', '4wp-seo-helper' ),
				__( 'JSON-LD and Gutenberg blocks for technical articles.', '4wp-seo-helper' ),
				Release::is_module_public( Release::MODULE_TECHARTICLE ) ? 'live' : 'soon',
				Release::is_module_public( Release::MODULE_TECHARTICLE )
					? __( 'Active in editor & frontend', '4wp-seo-helper' )
					: __( 'Planned for a future release', '4wp-seo-helper' )
			);
			self::render_status_card(
				__( 'Google Search Console', '4wp-seo-helper' ),
				__( 'OAuth, property picker, URL inspection, analytics.', '4wp-seo-helper' ),
				Release::is_module_public( Release::MODULE_GSC )
					? ( ! GscModule::get_instance()->is_enabled()
						? 'off'
						: ( GscAdmin::get_instance()->is_menu_visible() ? 'live' : ( $is_connected ? 'warn' : 'warn' ) ) )
					: 'soon',
				Release::is_module_public( Release::MODULE_GSC )
					? ( ! GscModule::get_instance()->is_enabled()
						? __( 'Disabled — enable in Settings', '4wp-seo-helper' )
						: ( GscAdmin::get_instance()->is_menu_visible()
							? __( 'Ready — open GSC in the sidebar', '4wp-seo-helper' )
							: ( $is_connected ? __( 'Choose a property in Search Console tab', '4wp-seo-helper' ) : __( 'Not connected', '4wp-seo-helper' ) ) ) )
					: __( 'Planned for a future release', '4wp-seo-helper' )
			);
			self::render_status_card(
				__( 'LLMS.txt', '4wp-seo-helper' ),
				__( 'Machine-readable site index for AI crawlers.', '4wp-seo-helper' ),
				Release::is_module_public( Release::MODULE_LLMS ) ? 'live' : 'soon',
				Release::is_module_public( Release::MODULE_LLMS )
					? (string) home_url( '/llms.txt' )
					: __( 'Planned for a future release', '4wp-seo-helper' )
			);
			self::render_status_card(
				__( 'Cross posting', '4wp-seo-helper' ),
				__( 'Editor tools for social / syndication drafts.', '4wp-seo-helper' ),
				Release::is_module_public( Release::MODULE_CROSSPOSTING ) ? ( $crossposting_enabled ? 'live' : 'off' ) : 'soon',
				Release::is_module_public( Release::MODULE_CROSSPOSTING )
					? ( $crossposting_enabled ? __( 'Enabled', '4wp-seo-helper' ) : __( 'Disabled', '4wp-seo-helper' ) )
					: __( 'Planned for a future release', '4wp-seo-helper' )
			);
			?>
		</div>
		<?php
	}

	private static function render_status_card( string $title, string $desc, string $badge, string $meta ): void {
		$badge_class = 'forwp-seo-badge--off';
		$badge_label = __( 'Off', '4wp-seo-helper' );

		if ( 'live' === $badge ) {
			$badge_class = 'forwp-seo-badge--live';
			$badge_label = __( 'Active', '4wp-seo-helper' );
		} elseif ( 'soon' === $badge ) {
			$badge_class = 'forwp-seo-badge--soon';
			$badge_label = __( 'Coming soon', '4wp-seo-helper' );
		} elseif ( 'warn' === $badge ) {
			$badge_class = 'forwp-seo-badge--warn';
			$badge_label = __( 'Setup', '4wp-seo-helper' );
		}

		?>
		<article class="forwp-seo-status-card">
			<div class="forwp-seo-status-card__head">
				<h3 class="forwp-seo-status-card__title"><?php echo esc_html( $title ); ?></h3>
				<span class="forwp-seo-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span>
			</div>
			<p class="forwp-seo-status-card__desc"><?php echo esc_html( $desc ); ?></p>
			<p class="forwp-seo-status-card__meta"><?php echo esc_html( $meta ); ?></p>
		</article>
		<?php
	}

	private static function render_settings_tab( bool $crossposting_enabled, bool $inventory_enabled, bool $gsc_enabled ): void {
		unset( $crossposting_enabled );
		?>
		<div class="forwp-seo-intro-card">
			<h2 class="forwp-seo-intro-card__title"><?php esc_html_e( 'General settings', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-intro-card__text">
				<?php esc_html_e( 'Enable optional modules for this site.', '4wp-seo-helper' ); ?>
			</p>
		</div>

		<div class="forwp-seo-panel">
			<form method="post">
				<?php wp_nonce_field( 'forwp_seo_settings', 'forwp_seo_settings_nonce' ); ?>
				<input type="hidden" name="forwp_seo_save_settings" value="1" />
				<?php if ( Release::is_module_public( Release::MODULE_INVENTORY_API ) ) : ?>
				<label class="forwp-seo-toggle-row">
					<input type="checkbox" name="forwp_seo_inventory_enabled" value="1" <?php checked( $inventory_enabled ); ?> />
					<span>
						<strong><?php esc_html_e( 'SEO inventory API', '4wp-seo-helper' ); ?></strong><br />
						<span class="forwp-seo-admin-muted"><?php esc_html_e( 'Enable REST endpoints for the admin inventory, analytics dashboard, and Google Sheets sync.', '4wp-seo-helper' ); ?></span>
					</span>
				</label>
				<?php endif; ?>

				<?php if ( Release::is_module_public( Release::MODULE_GSC ) ) : ?>
				<label class="forwp-seo-toggle-row">
					<input type="checkbox" name="forwp_seo_gsc_enabled" value="1" <?php checked( $gsc_enabled ); ?> />
					<span>
						<strong><?php esc_html_e( 'Google Search Console', '4wp-seo-helper' ); ?></strong><br />
						<span class="forwp-seo-admin-muted"><?php esc_html_e( 'Enable the webmaster panel: OAuth, property picker, URL inspection, and search metrics.', '4wp-seo-helper' ); ?></span>
					</span>
				</label>
				<?php endif; ?>

				<div class="forwp-seo-form-actions">
					<?php submit_button( __( 'Save settings', '4wp-seo-helper' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>

		<?php self::render_excluded_post_types_panel(); ?>

		<?php self::render_ai_assistant_panel(); ?>

		<?php self::render_coming_soon_modules_panel(); ?>
		<?php
	}

	private static function render_excluded_post_types_panel(): void {
		$types = PostTypeDiscovery::get_manageable_labeled();
		if ( [] === $types ) {
			return;
		}
		?>
		<div class="forwp-seo-panel">
			<h2 class="forwp-seo-admin-section-title"><?php esc_html_e( 'Exclude post types', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-admin-muted">
				<?php esc_html_e( 'Hide CPTs that are not used on the front end from SEO Inventory. Saved until you uncheck them.', '4wp-seo-helper' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'forwp_seo_settings', 'forwp_seo_settings_nonce' ); ?>
				<input type="hidden" name="forwp_seo_save_excluded_post_types" value="1" />
				<div class="forwp-seo-exclude-types">
					<?php foreach ( $types as $type ) : ?>
						<label class="forwp-seo-exclude-types__item">
							<input
								type="checkbox"
								name="forwp_seo_excluded_post_types[]"
								value="<?php echo esc_attr( $type['slug'] ); ?>"
								<?php checked( $type['excluded'] ); ?>
							/>
							<span>
								<?php echo esc_html( $type['label'] ); ?>
								<code><?php echo esc_html( $type['slug'] ); ?></code>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="forwp-seo-form-actions">
					<?php submit_button( __( 'Save excluded types', '4wp-seo-helper' ), 'secondary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	private static function render_positions_tab(): void {
		$providers = PositionsSettings::providers();
		$open      = PositionsSettings::requested_provider();
		$key_set   = '' !== PositionsSettings::get_serper_key();
		?>
		<form method="post">
			<?php wp_nonce_field( 'forwp_seo_settings', 'forwp_seo_settings_nonce' ); ?>
			<input type="hidden" name="forwp_seo_positions_provider" value="<?php echo esc_attr( PositionsSettings::PROVIDER_SERPER ); ?>" />
			<input type="hidden" name="forwp_seo_open_provider" id="forwp_seo_open_provider" value="<?php echo esc_attr( $open ); ?>" />

			<div class="forwp-seo-intro-card" id="forwp-seo-positions-intro">
				<h2 class="forwp-seo-intro-card__title"><?php esc_html_e( 'Position providers', '4wp-seo-helper' ); ?></h2>
				<p class="forwp-seo-intro-card__text">
					<?php esc_html_e( 'Pick a card to open settings. Live connectors can look up Google SERP positions for your queries. Rank history on the Positions page starts after a key is saved.', '4wp-seo-helper' ); ?>
				</p>
			</div>

			<div id="forwp-seo-provider-detail-wrap" class="forwp-seo-provider-detail-wrap" data-open-provider="<?php echo esc_attr( $open ); ?>" hidden>
				<div class="forwp-seo-provider-detail-head">
					<h3 class="forwp-seo-admin-section-title forwp-seo-provider-detail-head__title" id="forwp-seo-provider-detail-title"><?php esc_html_e( 'Provider', '4wp-seo-helper' ); ?></h3>
					<button type="button" class="button" id="forwp-seo-provider-back"><?php esc_html_e( '← Back to overview', '4wp-seo-helper' ); ?></button>
				</div>
				<?php foreach ( $providers as $provider ) : ?>
					<?php self::render_position_provider_detail( $provider, $key_set ); ?>
				<?php endforeach; ?>
			</div>

			<h3 class="forwp-seo-admin-section-title forwp-seo-registry-title"><?php esc_html_e( 'Providers', '4wp-seo-helper' ); ?></h3>
			<p class="forwp-seo-admin-muted forwp-seo-registry-hint"><?php esc_html_e( 'Click a card to open settings or the provider site.', '4wp-seo-helper' ); ?></p>
			<div class="forwp-seo-provider-grid" id="forwp-seo-provider-grid">
				<?php foreach ( $providers as $provider ) : ?>
					<?php self::render_position_provider_card( $provider ); ?>
				<?php endforeach; ?>
			</div>
		</form>
		<?php
	}

	/**
	 * @param array<string, mixed> $provider Provider row from PositionsSettings::providers().
	 */
	private static function render_position_provider_card( array $provider ): void {
		$id      = (string) ( $provider['id'] ?? '' );
		$label   = (string) ( $provider['label'] ?? '' );
		$live    = ! empty( $provider['live'] );
		$ready   = ! empty( $provider['configured'] );
		$classes = 'forwp-seo-provider-card';
		if ( $ready ) {
			$classes .= ' is-enabled';
		}
		?>
		<div
			class="<?php echo esc_attr( $classes ); ?>"
			role="button"
			tabindex="0"
			data-provider="<?php echo esc_attr( $id ); ?>"
			data-label="<?php echo esc_attr( $label ); ?>"
			aria-pressed="false"
		>
			<div class="forwp-seo-provider-card__head">
				<div>
					<div class="forwp-seo-provider-card__label"><?php echo esc_html( $label ); ?></div>
					<div class="forwp-seo-provider-card__slug"><code><?php echo esc_html( (string) ( $provider['slug'] ?? $id ) ); ?></code></div>
				</div>
				<div class="forwp-seo-provider-card__badges">
					<?php if ( $live ) : ?>
						<?php if ( $ready ) : ?>
							<span class="forwp-seo-badge forwp-seo-badge--on"><?php esc_html_e( 'On', '4wp-seo-helper' ); ?></span>
						<?php endif; ?>
						<span class="forwp-seo-badge forwp-seo-badge--live"><?php esc_html_e( 'Live', '4wp-seo-helper' ); ?></span>
					<?php else : ?>
						<span class="forwp-seo-badge forwp-seo-badge--soon"><?php esc_html_e( 'Coming soon', '4wp-seo-helper' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<p class="forwp-seo-provider-card__status"><?php echo esc_html( (string) ( $provider['blurb'] ?? '' ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $provider Provider row from PositionsSettings::providers().
	 */
	private static function render_position_provider_detail( array $provider, bool $key_set ): void {
		$id   = (string) ( $provider['id'] ?? '' );
		$live = ! empty( $provider['live'] );
		?>
		<div class="forwp-seo-provider-detail" data-provider="<?php echo esc_attr( $id ); ?>" hidden>
			<?php if ( $live && PositionsSettings::PROVIDER_SERPER === $id ) : ?>
				<div class="forwp-seo-provider-detail__panel">
					<?php self::render_provider_links( $provider ); ?>
					<div class="forwp-seo-field">
						<label class="forwp-seo-field__label" for="forwp_seo_positions_serper_key"><?php esc_html_e( 'API key', '4wp-seo-helper' ); ?></label>
						<input
							type="password"
							class="regular-text code"
							name="forwp_seo_positions_serper_key"
							id="forwp_seo_positions_serper_key"
							value=""
							autocomplete="new-password"
							placeholder="<?php echo $key_set ? esc_attr__( 'Key saved — paste a new key to replace it', '4wp-seo-helper' ) : ''; ?>"
						/>
						<?php if ( $key_set ) : ?>
							<label class="forwp-seo-toggle-row" style="margin-top:8px;">
								<input type="checkbox" name="forwp_seo_positions_clear_key" value="1" />
								<span><?php esc_html_e( 'Remove saved key', '4wp-seo-helper' ); ?></span>
							</label>
						<?php endif; ?>
						<p class="forwp-seo-field__help"><?php esc_html_e( 'Leave empty to keep the current key.', '4wp-seo-helper' ); ?></p>
					</div>
					<div class="forwp-seo-credentials-grid">
						<?php LocaleCombo::render_country( 'forwp_seo_positions_gl', PositionsSettings::get_gl() ); ?>
						<?php LocaleCombo::render_language( 'forwp_seo_positions_hl', PositionsSettings::get_hl() ); ?>
					</div>
					<div class="forwp-seo-form-actions">
						<?php submit_button( __( 'Save provider', '4wp-seo-helper' ), 'primary', 'forwp_seo_save_position_providers', false ); ?>
						<?php submit_button( __( 'Test connection', '4wp-seo-helper' ), 'secondary', 'forwp_seo_test_position_provider', false ); ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::POSITIONS_PAGE_SLUG ) ); ?>">
							<?php esc_html_e( 'Open Positions', '4wp-seo-helper' ); ?>
						</a>
					</div>
				</div>
			<?php else : ?>
				<div class="notice notice-info inline">
					<p><?php echo esc_html( (string) ( $provider['blurb'] ?? __( 'Not available yet.', '4wp-seo-helper' ) ) ); ?></p>
				</div>
				<?php self::render_provider_links( $provider ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $provider Provider row.
	 */
	private static function render_provider_links( array $provider ): void {
		$site_url   = (string) ( $provider['site_url'] ?? '' );
		$site_label = (string) ( $provider['site_label'] ?? $site_url );
		$extra      = is_array( $provider['extra_links'] ?? null ) ? $provider['extra_links'] : [];
		if ( '' === $site_url ) {
			return;
		}
		?>
		<p class="forwp-seo-field__links">
			<a href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener noreferrer">
				<?php
				printf(
					/* translators: %s: provider hostname */
					esc_html__( 'Provider site: %s', '4wp-seo-helper' ),
					esc_html( $site_label )
				);
				?>
			</a>
			<?php foreach ( $extra as $link ) : ?>
				<?php
				$url   = (string) ( $link['url'] ?? '' );
				$label = (string) ( $link['label'] ?? '' );
				if ( '' === $url || '' === $label ) {
					continue;
				}
				?>
				<span aria-hidden="true">·</span>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</p>
		<?php
	}

	private static function render_gsc_tab( bool $gsc_enabled ): void {
		GscAdmin::get_instance()->render_connect_section( $gsc_enabled );
	}

	private static function render_ai_assistant_panel(): void {
		$url = admin_url( 'admin.php?page=' . Menu::AI_PAGE_SLUG );
		?>
		<div class="forwp-seo-panel">
			<h2 class="forwp-seo-admin-section-title"><?php esc_html_e( 'AI Assistant', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-admin-muted">
				<?php esc_html_e( 'The assistant is not in the sidebar. Open it from here when you need a draft for titles or meta.', '4wp-seo-helper' ); ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open AI Assistant', '4wp-seo-helper' ); ?></a>
			</p>
		</div>
		<?php
	}

	private static function render_coming_soon_modules_panel(): void {
		$modules = [
			Release::MODULE_TECHARTICLE  => __( 'TechArticle schema & blocks', '4wp-seo-helper' ),
			Release::MODULE_LLMS         => __( 'LLMS.txt', '4wp-seo-helper' ),
			Release::MODULE_CROSSPOSTING => __( 'Cross posting', '4wp-seo-helper' ),
		];

		$upcoming = array_filter(
			$modules,
			static function ( string $label, string $slug ): bool {
				unset( $label );
				return ! Release::is_module_public( $slug );
			},
			ARRAY_FILTER_USE_BOTH
		);

		if ( empty( $upcoming ) ) {
			return;
		}
		?>
		<div class="forwp-seo-panel forwp-seo-panel--soon">
			<h2 class="forwp-seo-admin-section-title"><?php esc_html_e( 'Coming soon', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-admin-muted">
				<?php esc_html_e( 'These modules are already in the codebase and will ship in upcoming releases.', '4wp-seo-helper' ); ?>
			</p>
			<ul class="forwp-seo-soon-list">
				<?php foreach ( $upcoming as $label ) : ?>
					<li>
						<span class="forwp-seo-badge forwp-seo-badge--soon"><?php esc_html_e( 'Coming soon', '4wp-seo-helper' ); ?></span>
						<?php echo esc_html( $label ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	private static function render_api_tab( bool $inventory_enabled ): void {
		$token           = InventoryModule::get_instance()->get_api_token();
		$base_url        = rest_url( 'forwp-seo-helper/v1/seo-inventory' );
		$priority_labels = PriorityLabels::get_all();

		if ( '' === $token ) {
			$token = InventoryModule::get_instance()->regenerate_api_token();
		}
		?>
		<div class="forwp-seo-intro-card">
			<h2 class="forwp-seo-intro-card__title"><?php esc_html_e( 'REST sync for external tools', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-intro-card__text">
				<?php esc_html_e( 'Use these endpoints from 4wp-analytics-dashboard or Google Apps Script. Configure priority lane names for SEO Inventory below. Sample script: docs/google-sheets-sync.gs in the plugin folder.', '4wp-seo-helper' ); ?>
			</p>
		</div>

		<div class="forwp-seo-panel">
			<h2><?php esc_html_e( 'Connection details', '4wp-seo-helper' ); ?></h2>
			<table class="forwp-seo-ref-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', '4wp-seo-helper' ); ?></th>
						<td>
							<span class="forwp-seo-badge <?php echo $inventory_enabled ? 'forwp-seo-badge--live' : 'forwp-seo-badge--off'; ?>">
								<?php echo esc_html( $inventory_enabled ? __( 'Enabled', '4wp-seo-helper' ) : __( 'Disabled', '4wp-seo-helper' ) ); ?>
							</span>
							<?php if ( ! $inventory_enabled ) : ?>
								<p class="forwp-seo-admin-muted"><?php esc_html_e( 'Enable the inventory API in Settings to accept requests.', '4wp-seo-helper' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Base URL', '4wp-seo-helper' ); ?></th>
						<td><code class="forwp-seo-code"><?php echo esc_html( $base_url ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'API contract', '4wp-seo-helper' ); ?></th>
						<td><code class="forwp-seo-code"><?php echo esc_html( rest_url( 'forwp-seo-helper/v1/seo-inventory/meta' ) ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auth header', '4wp-seo-helper' ); ?></th>
						<td><code class="forwp-seo-code">Authorization: Bearer &lt;token&gt;</code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sync token', '4wp-seo-helper' ); ?></th>
						<td>
							<code class="forwp-seo-code"><?php echo esc_html( $token ); ?></code>
							<form method="post" class="forwp-seo-inline-actions">
								<?php wp_nonce_field( 'forwp_seo_settings', 'forwp_seo_settings_nonce' ); ?>
								<input type="hidden" name="forwp_seo_regenerate_inventory_token" value="1" />
								<?php submit_button( __( 'Regenerate token', '4wp-seo-helper' ), 'secondary', 'submit', false ); ?>
							</form>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="forwp-seo-panel">
			<h2 class="forwp-seo-admin-section-title"><?php esc_html_e( 'Inventory priority tiers', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-admin-muted">
				<?php esc_html_e( 'Name the three priority lanes used in SEO Inventory. Priority reflects business importance (e.g. main service pages), not SEO score — a page can stay in P1 even at 100%. Search Console signals such as clicks or indexing may feed into ranking later.', '4wp-seo-helper' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'forwp_seo_settings', 'forwp_seo_settings_nonce' ); ?>
				<input type="hidden" name="forwp_seo_save_priority_labels" value="1" />
				<div class="forwp-seo-priority-labels">
					<?php foreach ( PriorityLabels::get_defaults() as $lane_id => $default_label ) : ?>
						<label class="forwp-seo-priority-labels__row">
							<span class="forwp-seo-priority-labels__key"><?php echo esc_html( 'P' . $lane_id ); ?></span>
							<input
								type="text"
								class="regular-text"
								name="forwp_seo_priority_label_<?php echo esc_attr( $lane_id ); ?>"
								value="<?php echo esc_attr( $priority_labels[ $lane_id ] ); ?>"
								placeholder="<?php echo esc_attr( $default_label ); ?>"
							/>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="forwp-seo-form-actions">
					<?php submit_button( __( 'Save priority tiers', '4wp-seo-helper' ), 'secondary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	public static function process_settings_post(): ?string {
		if ( empty( $_POST['forwp_seo_settings_nonce'] ) ) {
			return null;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['forwp_seo_settings_nonce'] ) ), 'forwp_seo_settings' ) ) {
			return null;
		}

		$tab   = self::TAB_SETTINGS;
		$saved = false;

		if ( ! empty( $_POST['forwp_seo_regenerate_inventory_token'] ) ) {
			InventoryModule::get_instance()->regenerate_api_token();
			$tab   = self::TAB_API;
			$saved = 'token';
		}

		if ( ! empty( $_POST['forwp_seo_save_settings'] ) ) {
			if ( Release::is_module_public( Release::MODULE_CROSSPOSTING ) ) {
				$enabled = isset( $_POST['forwp_seo_crossposting_enabled'] ) ? '1' : '0';
				update_option( CrossPostingModule::OPTION_ENABLED, $enabled );
			}

			if ( Release::is_module_public( Release::MODULE_INVENTORY_API ) ) {
				$inventory_enabled = isset( $_POST['forwp_seo_inventory_enabled'] ) ? '1' : '0';
				update_option( InventoryModule::OPTION_ENABLED, $inventory_enabled );
			}

			if ( Release::is_module_public( Release::MODULE_GSC ) ) {
				$gsc_enabled = isset( $_POST['forwp_seo_gsc_enabled'] ) ? '1' : '0';
				update_option( GscModule::OPTION_ENABLED, $gsc_enabled );
				if ( '0' === $gsc_enabled ) {
					GscSync::unschedule_cron();
				} else {
					GscSync::sync_cron_state( GscModule::get_instance()->is_cron_enabled() );
				}
			}

			$tab   = self::TAB_SETTINGS;
			$saved = 'settings';
		}

		if ( ! empty( $_POST['forwp_seo_save_position_providers'] ) || ! empty( $_POST['forwp_seo_test_position_provider'] ) ) {
			PositionsSettings::save_from_post();
			$tab   = self::TAB_POSITIONS;
			$saved = 'positions';

			if ( ! empty( $_POST['forwp_seo_test_position_provider'] ) ) {
				$result = PositionsSettings::test_connection();
				set_transient(
					'forwp_seo_positions_test_' . get_current_user_id(),
					$result,
					60
				);
				$saved = 'positions_test';
			}
		}

		if ( ! empty( $_POST['forwp_seo_save_excluded_post_types'] ) ) {
			$slugs = isset( $_POST['forwp_seo_excluded_post_types'] ) ? (array) wp_unslash( $_POST['forwp_seo_excluded_post_types'] ) : [];
			ExcludedPostTypes::set( $slugs );
			$tab   = self::TAB_SETTINGS;
			$saved = 'excluded_types';
		}

		if ( ! empty( $_POST['forwp_seo_save_priority_labels'] ) ) {
			PriorityLabels::save(
				[
					'1' => sanitize_text_field( wp_unslash( (string) ( $_POST['forwp_seo_priority_label_1'] ?? '' ) ) ),
					'2' => sanitize_text_field( wp_unslash( (string) ( $_POST['forwp_seo_priority_label_2'] ?? '' ) ) ),
					'3' => sanitize_text_field( wp_unslash( (string) ( $_POST['forwp_seo_priority_label_3'] ?? '' ) ) ),
				]
			);

			$tab   = self::TAB_API;
			$saved = 'priority';
		}

		if ( false === $saved ) {
			return null;
		}

		$args = [
			'page'  => Menu::SETTINGS_PAGE_SLUG,
			'tab'   => $tab,
			'saved' => $saved,
		];

		if ( self::TAB_POSITIONS === $tab ) {
			$open = sanitize_key( wp_unslash( (string) ( $_POST['forwp_seo_open_provider'] ?? PositionsSettings::PROVIDER_SERPER ) ) );
			if ( '' !== $open ) {
				$args['provider'] = $open;
			}
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only PRG success and OAuth redirect flags.
		if ( isset( $_GET['saved'] ) ) {
			$saved = sanitize_key( wp_unslash( $_GET['saved'] ) );
			if ( 'token' === $saved ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sync token regenerated.', '4wp-seo-helper' ) . '</p></div>';
			} elseif ( 'settings' === $saved ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', '4wp-seo-helper' ) . '</p></div>';
			} elseif ( 'priority' === $saved ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Priority tiers saved.', '4wp-seo-helper' ) . '</p></div>';
			} elseif ( 'excluded_types' === $saved ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Excluded post types saved.', '4wp-seo-helper' ) . '</p></div>';
			} elseif ( 'positions' === $saved ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Position provider saved.', '4wp-seo-helper' ) . '</p></div>';
			} elseif ( 'positions_test' === $saved ) {
				$test = get_transient( 'forwp_seo_positions_test_' . get_current_user_id() );
				delete_transient( 'forwp_seo_positions_test_' . get_current_user_id() );
				if ( is_array( $test ) ) {
					$ok = ! empty( $test['ok'] );
					printf(
						'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
						$ok ? 'success' : 'error',
						esc_html( (string) ( $test['message'] ?? '' ) )
					);
				}
			}
		}

		if ( Release::is_module_public( Release::MODULE_GSC ) ) {
			if ( ! empty( $_GET['gsc_error'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['gsc_error'] ) ) ) . '</p></div>';
			}
			if ( ! empty( $_GET['gsc_connected'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Google Search Console connected.', '4wp-seo-helper' ) . '</p></div>';
			}
			if ( ! empty( $_GET['gsc_disconnected'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Google Search Console disconnected.', '4wp-seo-helper' ) . '</p></div>';
			}
			if ( isset( $_GET['gsc_saved'] ) ) {
				$gsc_saved = sanitize_key( wp_unslash( $_GET['gsc_saved'] ) );
				if ( 'property' === $gsc_saved ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Search Console property saved.', '4wp-seo-helper' ) . '</p></div>';
				} elseif ( 'credentials' === $gsc_saved ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Search Console credentials saved.', '4wp-seo-helper' ) . '</p></div>';
				} elseif ( 'redirect' === $gsc_saved ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'OAuth redirect saved.', '4wp-seo-helper' ) . '</p></div>';
				}
			}
			if ( isset( $_GET['data_cleared'] ) && '1' === (string) wp_unslash( $_GET['data_cleared'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All synced Search Console data was cleared.', '4wp-seo-helper' ) . '</p></div>';
			}
			if ( ! empty( $_GET['clear_error'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['clear_error'] ) ) ) . '</p></div>';
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Plugin version for admin UI (uppercase, from plugin header).
	 */
	public static function get_version_label(): string {
		$version = defined( 'FORWP_SEO_HELPER_VERSION' ) ? FORWP_SEO_HELPER_VERSION : '0.0.0';

		return strtoupper( (string) $version );
	}

	/**
	 * @return array<string, array<string, bool>>
	 */
	public static function svg_allowed_html(): array {
		return [
			'svg'  => [
				'xmlns'       => true,
				'viewbox'     => true,
				'width'       => true,
				'height'      => true,
				'fill'        => true,
				'focusable'   => true,
				'aria-hidden' => true,
			],
			'path' => [
				'd'               => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			],
			'circle' => [
				'cx'   => true,
				'cy'   => true,
				'r'    => true,
				'fill' => true,
			],
		];
	}
}
