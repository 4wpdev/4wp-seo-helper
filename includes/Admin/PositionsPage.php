<?php
/**
 * Positions admin: tracked queries and one-query checks.
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\Positions\Client;
use Forwp\SeoHelper\Positions\Ideas;
use Forwp\SeoHelper\Positions\Repository;
use Forwp\SeoHelper\Positions\SerperLocales;
use Forwp\SeoHelper\Positions\Settings as PositionsSettings;
use Forwp\SeoHelper\Positions\Target;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PositionsPage {
	public static function handle_load(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::process_post();
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$configured = PositionsSettings::is_configured();
		$notice     = get_transient( 'forwp_positions_notice_' . get_current_user_id() );
		$rows       = Repository::list_queries();
		$tab        = self::current_tab();
		$heading_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="none" focusable="false" aria-hidden="true"><path d="M4 19V5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/><path d="M8 16v-5M12 16V8M16 16v-3M20 16V6" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>';
		?>
		<div class="wrap forwp-seo-admin-shell forwp-seo-positions-shell">
			<h1 class="forwp-seo-admin-heading">
				<span class="forwp-seo-admin-heading__icon" aria-hidden="true">
					<?php echo wp_kses( $heading_svg, Page::svg_allowed_html() ); ?>
				</span>
				<span class="forwp-seo-admin-heading__text"><?php esc_html_e( 'Positions', '4wp-seo-helper' ); ?></span>
			</h1>
			<p class="forwp-seo-admin-lead">
				<?php esc_html_e( 'Each row is a search query in one country. Defaults come from provider settings; override per query. Check one query to store a dated snapshot.', '4wp-seo-helper' ); ?>
			</p>

			<?php
			if ( is_array( $notice ) ) {
				delete_transient( 'forwp_positions_notice_' . get_current_user_id() );
				printf(
					'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
					esc_attr( (string) ( $notice['type'] ?? 'info' ) ),
					esc_html( (string) ( $notice['message'] ?? '' ) )
				);
			}
			?>

			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'No position provider is configured. You can still add queries; checks need a Serper API key.', '4wp-seo-helper' ); ?>
						<a class="button" href="<?php echo esc_url( PositionsSettings::settings_url() ); ?>">
							<?php esc_html_e( 'Open position providers', '4wp-seo-helper' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<div class="forwp-seo-admin-app">
				<div class="forwp-seo-tab-panel components-tab-panel">
					<div class="components-tab-panel__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Positions', '4wp-seo-helper' ); ?>">
						<?php self::render_tab_button( 'queries', __( 'Queries', '4wp-seo-helper' ), $tab ); ?>
						<?php self::render_tab_button( 'related', __( 'Related', '4wp-seo-helper' ), $tab ); ?>
					</div>

					<div id="forwp-seo-panel-queries" role="tabpanel" class="components-tab-panel__tab-content" aria-labelledby="forwp-seo-tab-queries" <?php echo 'queries' !== $tab ? 'hidden' : ''; ?>>
						<?php self::render_target_card(); ?>
						<?php self::render_queries_panel( $rows, $configured ); ?>
					</div>

					<div id="forwp-seo-panel-related" role="tabpanel" class="components-tab-panel__tab-content" aria-labelledby="forwp-seo-tab-related" <?php echo 'related' !== $tab ? 'hidden' : ''; ?>>
						<?php self::render_related_panel( $rows ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'queries';

		return 'related' === $tab ? 'related' : 'queries';
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
	 * @param list<array<string, mixed>> $rows
	 */
	private static function render_queries_panel( array $rows, bool $configured ): void {
		$paa_by_query     = Ideas::phrases_by_query( Ideas::KIND_PAA );
		$related_by_query = Ideas::phrases_by_query( Ideas::KIND_RELATED );
		?>
			<section class="forwp-seo-positions-add" id="forwp-seo-positions-add">
				<form method="post" class="forwp-seo-positions-add__form">
					<?php wp_nonce_field( 'forwp_positions_add', 'forwp_positions_add_nonce' ); ?>
					<input type="hidden" name="forwp_positions_action" value="add" />
					<div class="forwp-seo-field forwp-seo-positions-add__query">
						<label class="forwp-seo-field__label" for="forwp_positions_query"><?php esc_html_e( 'Query', '4wp-seo-helper' ); ?></label>
						<input type="text" class="regular-text" name="forwp_positions_query" id="forwp_positions_query" required maxlength="512" placeholder="<?php esc_attr_e( 'Search phrase…', '4wp-seo-helper' ); ?>" />
					</div>
					<?php LocaleCombo::render_country( 'forwp_positions_gl', PositionsSettings::get_gl() ); ?>
					<?php LocaleCombo::render_language( 'forwp_positions_hl', PositionsSettings::get_hl() ); ?>
					<div class="forwp-seo-field forwp-seo-positions-add__depth">
						<label class="forwp-seo-field__label" for="forwp_positions_default_depth"><?php esc_html_e( 'Depth', '4wp-seo-helper' ); ?></label>
						<?php self::render_depth_select( 'forwp_positions_default_depth', Client::DEFAULT_PAGES, 'forwp-positions-depth-default' ); ?>
					</div>
					<div class="forwp-seo-positions-add__submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Add query', '4wp-seo-helper' ); ?></button>
					</div>
				</form>
			</section>

			<table class="widefat striped forwp-seo-positions-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Query', '4wp-seo-helper' ); ?></th>
						<th class="forwp-seo-positions-col--geo"><?php esc_html_e( 'Geo', '4wp-seo-helper' ); ?></th>
						<th class="forwp-seo-positions-col--lang"><?php esc_html_e( 'Lang', '4wp-seo-helper' ); ?></th>
						<th><?php esc_html_e( 'Last check', '4wp-seo-helper' ); ?></th>
						<th><?php esc_html_e( 'Site position', '4wp-seo-helper' ); ?></th>
						<th><?php esc_html_e( 'Ask / Suggest', '4wp-seo-helper' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No queries yet. Add one above.', '4wp-seo-helper' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$id  = (int) $row['id'];
							$gl  = (string) $row['gl'];
							$hl  = (string) $row['hl'];
							$at  = (string) ( $row['check_date'] ?? $row['last_checked_at'] ?? '' );
							$pos = $row['site_position'] ?? null;
							$gl_code = SerperLocales::normalize_gl( $gl );
							$hl_code = SerperLocales::normalize_hl( $hl );
							$gl_tip  = SerperLocales::country_label( $gl_code );
							$hl_tip  = SerperLocales::language_label( $hl_code );
							$organic = Client::organic_from_payload( (string) ( $row['check_payload'] ?? '' ) );
							$ideas   = Client::ideas_from_payload( (string) ( $row['check_payload'] ?? '' ) );
							$paa     = $paa_by_query[ $id ] ?? self::idea_questions( $ideas['people_also_ask'] );
							$suggest = $related_by_query[ $id ] ?? $ideas['related_searches'];
							?>
							<tr>
								<td class="forwp-seo-positions-col--query">
									<div class="forwp-seo-positions-query-cell">
										<span class="forwp-seo-positions-query"><?php echo esc_html( (string) $row['query_text'] ); ?></span>
										<?php self::render_preview( $id, $organic, (string) $row['query_text'] ); ?>
									</div>
								</td>
								<td class="forwp-seo-positions-col--geo">
									<span class="forwp-seo-positions-flag" title="<?php echo esc_attr( $gl_tip ); ?>"><?php echo esc_html( SerperLocales::flag( $gl_code ) ); ?></span>
								</td>
								<td class="forwp-seo-positions-col--lang">
									<abbr class="forwp-seo-positions-lang" title="<?php echo esc_attr( $hl_tip ); ?>"><?php echo esc_html( $hl_code ); ?></abbr>
								</td>
								<td>
									<?php
									if ( '' === $at ) {
										esc_html_e( 'Never', '4wp-seo-helper' );
									} else {
										echo esc_html( mysql2date( 'Y-m-d H:i', $at ) ?: $at );
									}
									?>
								</td>
								<td>
									<?php echo $pos && (int) $pos > 0 ? esc_html( (string) (int) $pos ) : '—'; ?>
								</td>
								<td class="forwp-seo-positions-col--ideas">
									<?php self::render_query_ideas( $id, $paa, $suggest ); ?>
								</td>
								<td class="forwp-seo-positions-col--actions">
									<div class="forwp-seo-positions-actions">
										<form method="post" class="forwp-seo-positions-check">
											<?php wp_nonce_field( 'forwp_positions_check', 'forwp_positions_check_nonce' ); ?>
											<input type="hidden" name="forwp_positions_action" value="check" />
											<input type="hidden" name="query_id" value="<?php echo esc_attr( (string) $id ); ?>" />
											<?php self::render_depth_select( 'forwp_positions_depth', Client::DEFAULT_PAGES, 'forwp-positions-depth' ); ?>
											<button type="submit" class="forwp-seo-positions-check__btn" title="<?php esc_attr_e( 'Update', '4wp-seo-helper' ); ?>" <?php disabled( ! $configured ); ?>>
												<span class="dashicons dashicons-update" aria-hidden="true"></span>
												<span class="screen-reader-text"><?php esc_html_e( 'Update', '4wp-seo-helper' ); ?></span>
											</button>
										</form>
										<form method="post" class="forwp-seo-positions-delete" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this query and its check history?', '4wp-seo-helper' ) ); ?>');">
											<?php wp_nonce_field( 'forwp_positions_delete', 'forwp_positions_delete_nonce' ); ?>
											<input type="hidden" name="forwp_positions_action" value="delete" />
											<input type="hidden" name="query_id" value="<?php echo esc_attr( (string) $id ); ?>" />
											<button type="submit" class="forwp-seo-positions-delete__btn" title="<?php esc_attr_e( 'Delete', '4wp-seo-helper' ); ?>">
												<span class="dashicons dashicons-trash" aria-hidden="true"></span>
												<span class="screen-reader-text"><?php esc_html_e( 'Delete', '4wp-seo-helper' ); ?></span>
											</button>
										</form>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		<?php
	}

	/**
	 * @param list<mixed> $rows
	 * @return list<string>
	 */
	private static function idea_questions( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$phrase = is_array( $row ) ? trim( (string) ( $row['question'] ?? '' ) ) : trim( (string) $row );
			if ( '' !== $phrase ) {
				$out[] = $phrase;
			}
		}

		return $out;
	}

	/**
	 * @param list<string> $paa
	 * @param list<string> $suggest
	 */
	private static function render_query_ideas( int $id, array $paa, array $suggest ): void {
		if ( [] === $paa && [] === $suggest ) {
			echo '—';
			return;
		}
		$panel_id = 'forwp-positions-ideas-' . $id;
		$icon     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16" fill="none" aria-hidden="true" focusable="false"><path d="M3.2 3.2h9.6c.7 0 1.2.5 1.2 1.2v6c0 .7-.5 1.2-1.2 1.2H8.2L5.5 13.8V11.6H3.2C2.5 11.6 2 11.1 2 10.4v-6c0-.7.5-1.2 1.2-1.2Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M5.2 6.4h5.6M5.2 8.8h3.6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>';
		?>
		<span class="forwp-seo-positions-ideas-tip">
			<button type="button" class="forwp-seo-positions-ideas-tip__btn" aria-describedby="<?php echo esc_attr( $panel_id ); ?>" title="<?php esc_attr_e( 'Ask / Suggest', '4wp-seo-helper' ); ?>">
				<?php echo wp_kses( $icon, Page::svg_allowed_html() ); ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Ask / Suggest', '4wp-seo-helper' ); ?></span>
			</button>
			<div class="forwp-seo-positions-ideas-tip__panel" id="<?php echo esc_attr( $panel_id ); ?>" role="tooltip">
				<p class="forwp-seo-positions-ideas-tip__title"><?php esc_html_e( 'Ask / Suggest', '4wp-seo-helper' ); ?></p>
				<?php if ( [] !== $paa ) : ?>
					<p class="forwp-seo-positions-ideas-tip__label"><?php esc_html_e( 'People also ask', '4wp-seo-helper' ); ?></p>
					<ul class="forwp-seo-positions-ideas-tip__list">
						<?php foreach ( $paa as $phrase ) : ?>
							<li><?php echo esc_html( $phrase ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( [] !== $suggest ) : ?>
					<p class="forwp-seo-positions-ideas-tip__label"><?php esc_html_e( 'Related searches', '4wp-seo-helper' ); ?></p>
					<ul class="forwp-seo-positions-ideas-tip__list is-suggest">
						<?php foreach ( $suggest as $phrase ) : ?>
							<li><?php echo esc_html( $phrase ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</span>
		<?php
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private static function render_related_panel( array $rows ): void {
		$labels = [];
		foreach ( $rows as $row ) {
			$labels[ (int) $row['id'] ] = (string) $row['query_text'];
		}
		$paa     = Ideas::list_kind( Ideas::KIND_PAA );
		$related = Ideas::list_kind( Ideas::KIND_RELATED );
		?>
		<p class="forwp-seo-admin-lead forwp-seo-positions-ideas__lead">
			<?php esc_html_e( 'Collected on each Update. The same phrase keeps every source query id.', '4wp-seo-helper' ); ?>
		</p>
		<div class="forwp-seo-positions-ideas">
			<?php self::render_ideas_group( __( 'People also ask', '4wp-seo-helper' ), $paa, $labels ); ?>
			<?php self::render_ideas_group( __( 'Related queries', '4wp-seo-helper' ), $related, $labels ); ?>
		</div>
		<?php
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @param array<int, string>         $labels
	 */
	private static function render_ideas_group( string $title, array $items, array $labels ): void {
		?>
		<section class="forwp-seo-positions-ideas__group">
			<h2><?php echo esc_html( $title ); ?></h2>
			<?php if ( empty( $items ) ) : ?>
				<p class="forwp-seo-admin-muted"><?php esc_html_e( 'Nothing yet. Update a query to collect this group.', '4wp-seo-helper' ); ?></p>
			<?php else : ?>
				<ul class="forwp-seo-positions-ideas__list">
					<?php foreach ( $items as $item ) : ?>
						<li>
							<span class="forwp-seo-positions-ideas__phrase"><?php echo esc_html( (string) $item['phrase'] ); ?></span>
							<?php
							$ids = isset( $item['source_ids'] ) && is_array( $item['source_ids'] ) ? $item['source_ids'] : [];
							if ( [] !== $ids ) :
								?>
								<span class="forwp-seo-positions-ideas__sources">
									<?php foreach ( $ids as $source_id ) : ?>
										<?php
										$source_id = (int) $source_id;
										$label     = $labels[ $source_id ] ?? ( '#' . $source_id );
										?>
										<abbr title="<?php echo esc_attr( $label ); ?>">#<?php echo esc_html( (string) $source_id ); ?></abbr>
									<?php endforeach; ?>
								</span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_target_card(): void {
		$property = Target::property();
		$host     = Target::host();
		$url      = Target::settings_url();
		?>
		<div class="forwp-seo-positions-target <?php echo '' === $property ? 'is-missing' : ''; ?>">
			<?php if ( '' === $property ) : ?>
				<p>
					<?php esc_html_e( 'No Search Console property selected — we do not know which domain to find in the SERP.', '4wp-seo-helper' ); ?>
					<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Set property', '4wp-seo-helper' ); ?></a>
				</p>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'Looking for', '4wp-seo-helper' ); ?>
					<code title="<?php echo esc_attr( $property ); ?>"><?php echo esc_html( '' !== $host ? $host : $property ); ?></code>
					<span class="forwp-seo-admin-muted"><?php echo esc_html( $property ); ?></span>
					<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Search Console', '4wp-seo-helper' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_depth_select( string $name, int $selected, string $class ): void {
		$selected = Client::normalize_pages( $selected );
		?>
		<select name="<?php echo esc_attr( $name ); ?>" class="<?php echo esc_attr( $class ); ?>" title="<?php esc_attr_e( 'SERP depth. Extra pages are requested only if this site is missing from the previous page.', '4wp-seo-helper' ); ?>">
			<option value="1" <?php selected( $selected, 1 ); ?>><?php esc_html_e( 'Top 10', '4wp-seo-helper' ); ?></option>
			<option value="2" <?php selected( $selected, 2 ); ?>><?php esc_html_e( 'Top 20', '4wp-seo-helper' ); ?></option>
			<option value="3" <?php selected( $selected, 3 ); ?>><?php esc_html_e( 'Top 30', '4wp-seo-helper' ); ?></option>
		</select>
		<?php
	}

	/**
	 * @param list<array{rank: int, title: string, link: string, snippet: string, ours: bool}> $organic
	 */
	private static function render_preview( int $id, array $organic, string $query ): void {
		if ( [] === $organic ) {
			return;
		}
		$panel_id = 'forwp-positions-preview-' . $id;
		$icon     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16" fill="none" aria-hidden="true" focusable="false"><path d="M2.5 3.25h11M2.5 8h11M2.5 12.75h7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="13" cy="12.75" r="1.35" fill="currentColor"/></svg>';
		?>
		<span class="forwp-seo-positions-preview">
			<button type="button" class="forwp-seo-positions-preview__btn" aria-describedby="<?php echo esc_attr( $panel_id ); ?>" title="<?php esc_attr_e( 'SERP snapshot', '4wp-seo-helper' ); ?>">
				<?php echo wp_kses( $icon, Page::svg_allowed_html() ); ?>
				<span class="screen-reader-text"><?php esc_html_e( 'SERP snapshot', '4wp-seo-helper' ); ?></span>
			</button>
			<div class="forwp-seo-positions-preview__panel" id="<?php echo esc_attr( $panel_id ); ?>" role="tooltip">
				<p class="forwp-seo-positions-preview__title"><?php echo esc_html( $query ); ?></p>
				<ol class="forwp-seo-positions-preview__list">
					<?php foreach ( $organic as $hit ) : ?>
						<?php
						$host = (string) wp_parse_url( (string) $hit['link'], PHP_URL_HOST );
						?>
						<li class="<?php echo ! empty( $hit['ours'] ) ? 'is-ours' : ''; ?>">
							<span class="forwp-seo-positions-preview__rank"><?php echo esc_html( (string) (int) $hit['rank'] ); ?></span>
							<span class="forwp-seo-positions-preview__body">
								<span class="forwp-seo-positions-preview__hit"><?php echo esc_html( (string) $hit['title'] ); ?></span>
								<a href="<?php echo esc_url( (string) $hit['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $host ); ?></a>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>
		</span>
		<?php
	}

	private static function process_post(): void {
		$action = isset( $_POST['forwp_positions_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['forwp_positions_action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}

		if ( 'add' === $action ) {
			check_admin_referer( 'forwp_positions_add', 'forwp_positions_add_nonce' );
			$result = Repository::add_query(
				(string) wp_unslash( $_POST['forwp_positions_query'] ?? '' ),
				(string) wp_unslash( $_POST['forwp_positions_gl'] ?? PositionsSettings::get_gl() ),
				(string) wp_unslash( $_POST['forwp_positions_hl'] ?? PositionsSettings::get_hl() )
			);
			if ( is_wp_error( $result ) ) {
				self::set_notice( 'error', $result->get_error_message() );
			} else {
				self::set_notice( 'success', __( 'Query added.', '4wp-seo-helper' ) );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=' . Menu::POSITIONS_PAGE_SLUG ) );
			exit;
		}

		if ( 'check' === $action ) {
			check_admin_referer( 'forwp_positions_check', 'forwp_positions_check_nonce' );
			$query_id = isset( $_POST['query_id'] ) ? (int) $_POST['query_id'] : 0;
			$row      = Repository::get( $query_id );
			if ( ! $row ) {
				self::set_notice( 'error', __( 'Query not found.', '4wp-seo-helper' ) );
				wp_safe_redirect( admin_url( 'admin.php?page=' . Menu::POSITIONS_PAGE_SLUG ) );
				exit;
			}

			$result = Client::check_query(
				(string) $row['query_text'],
				(string) $row['gl'],
				(string) $row['hl'],
				(int) ( $_POST['forwp_positions_depth'] ?? Client::DEFAULT_PAGES )
			);
			Repository::save_check( $query_id, $result );

			$when   = mysql2date( 'Y-m-d H:i', (string) $result['checked_at'] ) ?: (string) $result['checked_at'];
			$target = (string) ( $result['target_property'] ?? Target::property() );
			if ( ! empty( $result['ok'] ) ) {
				self::set_notice(
					'success',
					'' !== $target
						? sprintf(
							/* translators: 1: datetime, 2: GSC property, 3: max rank searched */
							__( 'Checked %1$s for %2$s (top %3$d).', '4wp-seo-helper' ),
							$when,
							$target,
							(int) ( $result['depth'] ?? Client::PAGE_SIZE )
						)
						: sprintf(
							/* translators: %s: datetime */
							__( 'Checked %s.', '4wp-seo-helper' ),
							$when
						)
				);
			} else {
				self::set_notice(
					'error',
					sprintf(
						/* translators: 1: datetime, 2: error */
						__( 'Check at %1$s failed: %2$s', '4wp-seo-helper' ),
						$when,
						(string) ( $result['error'] ?? '' )
					)
				);
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . Menu::POSITIONS_PAGE_SLUG ) );
			exit;
		}

		if ( 'delete' === $action ) {
			check_admin_referer( 'forwp_positions_delete', 'forwp_positions_delete_nonce' );
			$query_id = isset( $_POST['query_id'] ) ? (int) $_POST['query_id'] : 0;
			if ( Repository::delete_query( $query_id ) ) {
				self::set_notice( 'success', __( 'Query deleted.', '4wp-seo-helper' ) );
			} else {
				self::set_notice( 'error', __( 'Could not delete the query.', '4wp-seo-helper' ) );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=' . Menu::POSITIONS_PAGE_SLUG ) );
			exit;
		}
	}

	private static function set_notice( string $type, string $message ): void {
		set_transient(
			'forwp_positions_notice_' . get_current_user_id(),
			[
				'type'    => $type,
				'message' => $message,
			],
			MINUTE_IN_SECONDS
		);
	}
}
