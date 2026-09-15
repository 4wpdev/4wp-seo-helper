<?php
/**
 * AI Assistant: what generation already does, plus later prompt-pattern tabs.
 */

namespace Forwp\SeoHelper\Admin;

use ForWP\AI\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AiAssistantPage {
	public const TAB_SEO_COPY = 'seo-copy';

	public static function is_available(): bool {
		return class_exists( Client::class ) && Client::is_available();
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$available   = self::is_available();
		$heading_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="none" focusable="false" aria-hidden="true"><path d="M12 3v3M12 18v3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M3 12h3M18 12h3M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/><circle cx="12" cy="12" r="3.25" stroke="currentColor" stroke-width="1.75"/></svg>';
		?>
		<div class="wrap forwp-seo-admin-shell">
			<h1 class="forwp-seo-admin-heading">
				<span class="forwp-seo-admin-heading__icon" aria-hidden="true">
					<?php echo wp_kses( $heading_svg, Page::svg_allowed_html() ); ?>
				</span>
				<span class="forwp-seo-admin-heading__text"><?php esc_html_e( 'AI Assistant', '4wp-seo-helper' ); ?></span>
			</h1>
			<p class="forwp-seo-admin-lead">
				<?php esc_html_e( 'Generate SEO titles, meta descriptions, and focus keyphrases from the page — then review before you save.', '4wp-seo-helper' ); ?>
			</p>

			<?php if ( ! $available ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'AI is not available. Configure a connector under Settings → Connectors.', '4wp-seo-helper' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="forwp-seo-admin-app">
				<div class="forwp-seo-tab-panel components-tab-panel">
					<div class="components-tab-panel__tabs" role="tablist" aria-label="<?php esc_attr_e( 'AI Assistant', '4wp-seo-helper' ); ?>">
						<?php self::render_tab_button( self::TAB_SEO_COPY, __( 'SEO copy', '4wp-seo-helper' ), true ); ?>
					</div>

					<div id="forwp-seo-panel-seo-copy" role="tabpanel" class="components-tab-panel__tab-content" aria-labelledby="forwp-seo-tab-seo-copy">
						<?php self::render_seo_copy_tab( $available ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_tab_button( string $id, string $label, bool $active ): void {
		printf(
			'<button type="button" role="tab" id="forwp-seo-tab-%1$s" class="components-button components-tab-panel__tabs-item forwp-seo-tab%2$s" data-tab="%1$s" aria-selected="%3$s" aria-controls="forwp-seo-panel-%1$s" tabindex="%4$d">%5$s</button>',
			esc_attr( $id ),
			$active ? ' is-active' : '',
			$active ? 'true' : 'false',
			$active ? 0 : -1,
			esc_html( $label )
		);
	}

	private static function render_seo_copy_tab( bool $available ): void {
		$badge_class = $available ? 'forwp-seo-badge--live' : 'forwp-seo-badge--warn';
		$badge_label = $available ? __( 'Available', '4wp-seo-helper' ) : __( 'Needs connector', '4wp-seo-helper' );
		$inventory   = admin_url( 'admin.php?page=' . Menu::INVENTORY_PAGE_SLUG );
		?>
		<div class="forwp-seo-intro-card">
			<h2 class="forwp-seo-intro-card__title"><?php esc_html_e( 'What you can generate now', '4wp-seo-helper' ); ?></h2>
			<p class="forwp-seo-intro-card__text">
				<?php esc_html_e( 'SEO Inventory already has an AI panel. Open a row, generate copy from the page content (or the page URL), then apply the fields you want. Nothing is saved until you review and Update.', '4wp-seo-helper' ); ?>
			</p>
			<div class="forwp-seo-intro-card__actions">
				<a class="button button-primary" href="<?php echo esc_url( $inventory ); ?>">
					<?php esc_html_e( 'Open SEO Inventory', '4wp-seo-helper' ); ?>
				</a>
			</div>
		</div>

		<h2 class="forwp-seo-admin-section-title"><?php esc_html_e( 'Fields', '4wp-seo-helper' ); ?></h2>
		<div class="forwp-seo-status-grid">
			<article class="forwp-seo-status-card">
				<div class="forwp-seo-status-card__head">
					<h3 class="forwp-seo-status-card__title"><?php esc_html_e( 'SEO title', '4wp-seo-helper' ); ?></h3>
					<span class="forwp-seo-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span>
				</div>
				<p class="forwp-seo-status-card__desc">
					<?php esc_html_e( 'A search-result title for the page, written from the content you send to the model.', '4wp-seo-helper' ); ?>
				</p>
			</article>
			<article class="forwp-seo-status-card">
				<div class="forwp-seo-status-card__head">
					<h3 class="forwp-seo-status-card__title"><?php esc_html_e( 'Meta description', '4wp-seo-helper' ); ?></h3>
					<span class="forwp-seo-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span>
				</div>
				<p class="forwp-seo-status-card__desc">
					<?php esc_html_e( 'A short snippet for search results. Review length and claims before you apply it.', '4wp-seo-helper' ); ?>
				</p>
			</article>
			<article class="forwp-seo-status-card">
				<div class="forwp-seo-status-card__head">
					<h3 class="forwp-seo-status-card__title"><?php esc_html_e( 'Focus keyphrases', '4wp-seo-helper' ); ?></h3>
					<span class="forwp-seo-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span>
				</div>
				<p class="forwp-seo-status-card__desc">
					<?php esc_html_e( 'Primary focus terms for Yoast or All in One SEO, based on what the page actually covers.', '4wp-seo-helper' ); ?>
				</p>
			</article>
		</div>

		<h2 class="forwp-seo-admin-section-title"><?php esc_html_e( 'How to use it', '4wp-seo-helper' ); ?></h2>
		<ol class="forwp-seo-setup-steps">
			<li><?php esc_html_e( 'Open SEO Inventory and start quick edit on a page.', '4wp-seo-helper' ); ?></li>
			<li><?php esc_html_e( 'Open the AI panel. Page content is the default context; switch to Page URL if you want a lighter prompt.', '4wp-seo-helper' ); ?></li>
			<li><?php esc_html_e( 'Generate, then Apply the fields you keep. Update the post when you are ready.', '4wp-seo-helper' ); ?></li>
		</ol>

		<p class="forwp-seo-admin-muted">
			<?php esc_html_e( 'Prompt patterns and per-model interaction settings will land on extra tabs here. This tab documents what already works.', '4wp-seo-helper' ); ?>
		</p>
		<?php
	}
}
