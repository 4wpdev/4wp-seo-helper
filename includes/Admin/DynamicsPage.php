<?php
/**
 * Dynamics: per-URL SEO change list and detail.
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\Gsc\Indexing;
use Forwp\SeoHelper\Inventory\History;
use Forwp\SeoHelper\Inventory\HistoryLogger;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DynamicsPage {
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$heading_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="none" focusable="false" aria-hidden="true"><path d="M4 19V5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/><path d="m4 16 4-5 3 3 5-7 4 4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin view.
		$post_id = absint( wp_unslash( (string) ( $_GET['forwp_post'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap forwp-seo-admin-shell forwp-seo-admin-shell--wide">
			<header class="forwp-seo-dash__header">
				<div class="forwp-seo-dash__header-main">
					<h1 class="forwp-seo-admin-heading">
						<span class="forwp-seo-admin-heading__icon" aria-hidden="true">
							<?php echo wp_kses( $heading_svg, Page::svg_allowed_html() ); ?>
						</span>
						<span class="forwp-seo-admin-heading__text"><?php esc_html_e( 'Dynamics', '4wp-seo-helper' ); ?></span>
					</h1>
				</div>
			</header>
			<?php
			if ( $post_id > 0 ) {
				self::render_detail( $post_id );
			} else {
				self::render_list();
			}
			?>
		</div>
		<?php
	}

	public static function url_for_post( int $post_id ): string {
		return add_query_arg(
			[
				'page'       => Menu::DYNAMICS_PAGE_SLUG,
				'forwp_post' => absint( $post_id ),
			],
			admin_url( 'admin.php' )
		);
	}

	public static function list_url(): string {
		return admin_url( 'admin.php?page=' . Menu::DYNAMICS_PAGE_SLUG );
	}

	private static function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$paged  = max( 1, absint( wp_unslash( (string) ( $_GET['paged'] ?? '1' ) ) ) );
		$search = sanitize_text_field( wp_unslash( (string) ( $_GET['s'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 20;
		$result   = History::listed_posts( $paged, $per_page, $search );
		$items    = $result['items'];
		$total    = $result['total'];
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$waiting  = History::waiting_for_crawl( 8 );
		$ids      = array_map( static fn( array $row ): int => (int) $row['post_id'], $items );
		$typed    = [];
		foreach ( array_unique( array_filter( array_column( $items, 'last_type' ) ) ) as $type ) {
			$typed[ (string) $type ] = History::latest_typed( $ids, (string) $type, 2 );
		}
		?>
		<p class="forwp-seo-admin-muted forwp-seo-dynamics-lead">
			<?php esc_html_e( 'URLs with a recorded SEO or Search Console change, newest first. Open a row for the timeline.', '4wp-seo-helper' ); ?>
		</p>
		<div class="forwp-seo-dynamics-toolbar">
			<form method="get" class="forwp-seo-dynamics-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::DYNAMICS_PAGE_SLUG ); ?>" />
				<label class="screen-reader-text" for="forwp-seo-dynamics-search"><?php esc_html_e( 'Search pages', '4wp-seo-helper' ); ?></label>
				<input type="search" id="forwp-seo-dynamics-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by title…', '4wp-seo-helper' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Search', '4wp-seo-helper' ); ?></button>
				<?php if ( '' !== $search ) : ?>
					<a class="button button-link" href="<?php echo esc_url( self::list_url() ); ?>"><?php esc_html_e( 'Clear', '4wp-seo-helper' ); ?></a>
				<?php endif; ?>
			</form>
		</div>

		<?php if ( [] !== $waiting ) : ?>
			<p class="forwp-seo-admin-muted forwp-seo-dynamics-waiting">
				<?php esc_html_e( 'Waiting for crawl:', '4wp-seo-helper' ); ?>
				<?php
				$wait_links = [];
				foreach ( $waiting as $row ) {
					$wait_links[] = sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( self::url_for_post( (int) $row['post_id'] ) ),
						esc_html( self::post_label( (int) $row['post_id'] ) )
					);
				}
				echo wp_kses_post( implode( ', ', $wait_links ) );
				?>
			</p>
		<?php endif; ?>

		<?php if ( [] === $items ) : ?>
			<div class="forwp-seo-panel">
				<p class="forwp-seo-admin-muted">
					<?php esc_html_e( 'Nothing here yet. Request indexing, inspect a URL, save a post, or wait for Search Console sync.', '4wp-seo-helper' ); ?>
				</p>
			</div>
		<?php else : ?>
			<div class="forwp-seo-admin-app">
			<table class="widefat striped forwp-seo-dynamics-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page', '4wp-seo-helper' ); ?></th>
						<th><?php esc_html_e( 'What changed', '4wp-seo-helper' ); ?></th>
						<th><?php esc_html_e( 'When', '4wp-seo-helper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $row ) : ?>
						<?php
						$post_id    = (int) $row['post_id'];
						$permalink  = get_permalink( $post_id );
						$edit       = get_edit_post_link( $post_id, 'raw' );
						$last_type  = (string) $row['last_type'];
						$snaps      = $typed[ $last_type ][ $post_id ] ?? [];
						$current    = isset( $snaps[0]['snapshot'] ) && is_array( $snaps[0]['snapshot'] ) ? $snaps[0]['snapshot'] : (array) ( $row['last_snapshot'] ?? [] );
						$previous   = isset( $snaps[1]['snapshot'] ) && is_array( $snaps[1]['snapshot'] ) ? $snaps[1]['snapshot'] : null;
						$change     = self::change_summary( $last_type, $current, $previous );
						?>
						<tr>
							<td>
								<strong>
									<a href="<?php echo esc_url( self::url_for_post( $post_id ) ); ?>">
										<?php echo esc_html( self::post_label( $post_id ) ); ?>
									</a>
								</strong>
								<div class="row-actions">
									<?php if ( is_string( $edit ) && '' !== $edit ) : ?>
										<span class="edit"><a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', '4wp-seo-helper' ); ?></a> | </span>
									<?php endif; ?>
									<?php if ( is_string( $permalink ) && '' !== $permalink ) : ?>
										<span class="view"><a href="<?php echo esc_url( $permalink ); ?>"><?php esc_html_e( 'View', '4wp-seo-helper' ); ?></a> | </span>
									<?php endif; ?>
									<span class="inventory">
										<a href="<?php echo esc_url( InventoryPage::url_for_post( $post_id ) ); ?>"><?php esc_html_e( 'Inventory', '4wp-seo-helper' ); ?></a>
									</span>
								</div>
							</td>
							<td>
								<span class="forwp-seo-badge"><?php echo esc_html( self::event_label( $last_type ) ); ?></span>
								<span class="forwp-seo-dynamics-change"><?php echo esc_html( $change ); ?></span>
							</td>
							<td><?php echo esc_html( self::format_relative( (string) $row['last_at'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php
			echo '<div class="tablenav bottom forwp-seo-dynamics-pager"><div class="tablenav-pages">';
			echo '<span class="displaying-num">' . esc_html(
				sprintf(
					/* translators: %s: number of pages */
					_n( '%s page', '%s pages', $total, '4wp-seo-helper' ),
					number_format_i18n( $total )
				)
			) . '</span>';
			if ( $pages > 1 ) {
				$base = add_query_arg(
					array_filter(
						[
							'page' => Menu::DYNAMICS_PAGE_SLUG,
							's'    => $search,
						]
					),
					admin_url( 'admin.php' )
				);
				echo '<span class="pagination-links">';
				echo wp_kses_post(
					(string) paginate_links(
						[
							'base'      => add_query_arg( 'paged', '%#%', $base ),
							'format'    => '',
							'total'     => $pages,
							'current'   => min( $paged, $pages ),
							'mid_size'  => 2,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						]
					)
				);
				echo '</span>';
			}
			echo '</div></div>';
			?>
		<?php endif; ?>
		<?php
	}

	private static function render_detail( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Post not found.', '4wp-seo-helper' ) . '</p></div>';
			return;
		}

		$events = History::for_post( $post_id );
		if ( [] === $events ) {
			HistoryLogger::seed_existing( $post );
			$events = History::for_post( $post_id );
		}
		$latest  = History::latest_by_type( $post_id );
		$inspect = Indexing::inventory_fields( $post_id );
		$chars   = HistoryLogger::character_count( $post );
		$edit    = get_edit_post_link( $post_id, 'raw' );
		?>
		<p>
			<a href="<?php echo esc_url( self::list_url() ); ?>">&larr; <?php esc_html_e( 'All pages', '4wp-seo-helper' ); ?></a>
			<?php if ( is_string( $edit ) && '' !== $edit ) : ?>
				<span class="forwp-seo-admin-muted"> · </span>
				<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', '4wp-seo-helper' ); ?></a>
			<?php endif; ?>
			<span class="forwp-seo-admin-muted"> · </span>
			<a href="<?php echo esc_url( (string) get_permalink( $post ) ); ?>"><?php esc_html_e( 'View', '4wp-seo-helper' ); ?></a>
			<span class="forwp-seo-admin-muted"> · </span>
			<a href="<?php echo esc_url( InventoryPage::url_for_post( $post_id ) ); ?>"><?php esc_html_e( 'Inventory', '4wp-seo-helper' ); ?></a>
		</p>
		<h2 class="forwp-seo-dash-post-title"><?php echo esc_html( self::post_label( $post_id ) ); ?></h2>

		<div class="forwp-seo-dash-check">
			<?php
			self::render_check_item(
				__( 'Index requested', '4wp-seo-helper' ),
				$latest[ History::TYPE_INDEX_REQUEST ],
				$inspect['gsc_index_requested_at'] > 0
					? gmdate( 'Y-m-d H:i:s', $inspect['gsc_index_requested_at'] )
					: ''
			);
			self::render_check_item(
				__( 'Robot crawled', '4wp-seo-helper' ),
				$latest[ History::TYPE_CRAWL ],
				(string) $inspect['gsc_last_crawl']
			);
			self::render_check_item(
				__( 'Content length', '4wp-seo-helper' ),
				$latest[ History::TYPE_CONTENT ],
				'',
				sprintf(
					/* translators: %s: character count */
					__( 'Now: %s characters', '4wp-seo-helper' ),
					number_format_i18n( $chars )
				)
			);
			self::render_check_item(
				__( 'SEO fields', '4wp-seo-helper' ),
				$latest[ History::TYPE_SEO ]
			);
			self::render_check_item(
				__( 'Search metrics', '4wp-seo-helper' ),
				$latest[ History::TYPE_GSC ]
			);
			?>
		</div>

		<div class="forwp-seo-panel">
			<h2><?php esc_html_e( 'Timeline', '4wp-seo-helper' ); ?></h2>
			<?php if ( [] === $events ) : ?>
				<p class="forwp-seo-admin-muted">
					<?php esc_html_e( 'No recorded changes yet for this URL. Save the post, inspect it, or request indexing.', '4wp-seo-helper' ); ?>
				</p>
			<?php else : ?>
				<ol class="forwp-seo-dash-timeline">
					<?php
					$previous = [];
					$pairs    = [];
					foreach ( array_reverse( $events ) as $row ) {
						$type              = (string) $row['event_type'];
						$pairs[]           = [ $row, $previous[ $type ] ?? null ];
						$previous[ $type ] = $row;
					}
					foreach ( array_reverse( $pairs ) as $pair ) {
						self::render_event_item( $pair[0], $pair[1] );
					}
					?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed>|null $event
	 */
	private static function render_check_item( string $label, ?array $event, string $fallback_date = '', string $extra = '' ): void {
		$done = null !== $event || '' !== $fallback_date;
		$when = '';
		if ( is_array( $event ) ) {
			$when = self::format_gmt( (string) $event['occurred_at'] );
		} elseif ( '' !== $fallback_date ) {
			$when = self::format_gmt( $fallback_date );
		}
		?>
		<div class="forwp-seo-dash-check__item<?php echo $done ? ' is-done' : ''; ?>">
			<span class="forwp-seo-dash-check__mark" aria-hidden="true"><?php echo $done ? '✓' : '○'; ?></span>
			<div>
				<strong><?php echo esc_html( $label ); ?></strong>
				<p class="forwp-seo-admin-muted">
					<?php
					echo esc_html( $done ? $when : __( 'Not recorded yet', '4wp-seo-helper' ) );
					if ( '' !== $extra ) {
						echo ' · ' . esc_html( $extra );
					}
					?>
				</p>
				<?php if ( is_array( $event ) ) : ?>
					<p class="forwp-seo-dash-snap"><?php echo esc_html( self::snapshot_summary( (array) $event['snapshot'] ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed>      $row
	 * @param array<string, mixed>|null $previous
	 */
	private static function render_event_item( array $row, ?array $previous = null ): void {
		$type  = (string) $row['event_type'];
		$snap  = (array) ( $row['snapshot'] ?? [] );
		$delta = '';
		if ( is_array( $previous ) ) {
			$delta = self::snapshot_delta( (array) ( $previous['snapshot'] ?? [] ), $snap, $type );
		}
		?>
		<li>
			<div class="forwp-seo-dash-timeline__head">
				<span class="forwp-seo-badge"><?php echo esc_html( self::event_label( $type ) ); ?></span>
				<time datetime="<?php echo esc_attr( (string) $row['occurred_at'] ); ?>">
					<?php echo esc_html( self::format_gmt( (string) $row['occurred_at'] ) ); ?>
				</time>
			</div>
			<p class="forwp-seo-dash-snap"><?php echo esc_html( self::snapshot_summary( $snap ) ); ?></p>
			<?php if ( '' !== $delta ) : ?>
				<p class="forwp-seo-dash-delta"><?php echo esc_html( $delta ); ?></p>
			<?php endif; ?>
		</li>
		<?php
	}

	/**
	 * @param array<string, mixed> $snapshot
	 */
	private static function snapshot_summary( array $snapshot ): string {
		$parts = [];

		if ( isset( $snapshot['chars'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: character count */
				__( '%s chars', '4wp-seo-helper' ),
				number_format_i18n( (int) $snapshot['chars'] )
			);
		}
		if ( isset( $snapshot['score'] ) ) {
			$parts[] = sprintf(
				/* translators: %d: SEO score */
				__( 'score %d', '4wp-seo-helper' ),
				(int) $snapshot['score']
			);
		}
		if ( ! empty( $snapshot['keys'] ) && is_array( $snapshot['keys'] ) ) {
			$parts[] = implode( ', ', array_map( 'strval', array_slice( $snapshot['keys'], 0, 5 ) ) );
		}
		if ( isset( $snapshot['clicks'] ) || isset( $snapshot['impr'] ) ) {
			$parts[] = sprintf(
				/* translators: 1: clicks, 2: impressions, 3: average position */
				__( '%1$s clicks / %2$s impr / pos %3$s', '4wp-seo-helper' ),
				number_format_i18n( (int) ( $snapshot['clicks'] ?? 0 ) ),
				number_format_i18n( (int) ( $snapshot['impr'] ?? 0 ) ),
				isset( $snapshot['pos'] ) ? (string) $snapshot['pos'] : '—'
			);
		}
		if ( ! empty( $snapshot['sugg'] ) && is_array( $snapshot['sugg'] ) ) {
			$queries = [];
			foreach ( array_slice( $snapshot['sugg'], 0, 3 ) as $row ) {
				if ( is_array( $row ) && ! empty( $row['q'] ) ) {
					$queries[] = (string) $row['q'];
				}
			}
			if ( [] !== $queries ) {
				$parts[] = sprintf(
					/* translators: %s: comma-separated search queries */
					__( 'suggest: %s', '4wp-seo-helper' ),
					implode( ', ', $queries )
				);
			}
		}
		if ( ! empty( $snapshot['cov'] ) ) {
			$parts[] = (string) $snapshot['cov'];
		}

		return implode( ' · ', $parts );
	}

	/**
	 * @param array<string, mixed> $from
	 * @param array<string, mixed> $to
	 */
	private static function snapshot_delta( array $from, array $to, string $type ): string {
		if ( History::TYPE_CONTENT === $type && isset( $from['chars'], $to['chars'] ) && (int) $from['chars'] !== (int) $to['chars'] ) {
			return sprintf(
				/* translators: 1: previous character count, 2: new character count */
				__( '%1$s → %2$s characters', '4wp-seo-helper' ),
				number_format_i18n( (int) $from['chars'] ),
				number_format_i18n( (int) $to['chars'] )
			);
		}

		if ( History::TYPE_GSC === $type && ( isset( $from['clicks'] ) || isset( $to['clicks'] ) ) ) {
			return sprintf(
				/* translators: 1: previous clicks, 2: new clicks, 3: previous position, 4: new position */
				__( 'clicks %1$s → %2$s · pos %3$s → %4$s', '4wp-seo-helper' ),
				number_format_i18n( (int) ( $from['clicks'] ?? 0 ) ),
				number_format_i18n( (int) ( $to['clicks'] ?? 0 ) ),
				isset( $from['pos'] ) ? (string) $from['pos'] : '—',
				isset( $to['pos'] ) ? (string) $to['pos'] : '—'
			);
		}

		if ( History::TYPE_CRAWL === $type && ! empty( $from['crawl'] ) && ! empty( $to['crawl'] ) && $from['crawl'] !== $to['crawl'] ) {
			return sprintf(
				/* translators: 1: previous crawl datetime, 2: new crawl datetime */
				__( 'previous crawl %1$s → %2$s', '4wp-seo-helper' ),
				self::format_gmt( (string) $from['crawl'] ),
				self::format_gmt( (string) $to['crawl'] )
			);
		}

		return '';
	}

	/**
	 * @param array<string, mixed>      $current  Latest snapshot.
	 * @param array<string, mixed>|null $previous Previous snapshot of the same type.
	 */
	private static function change_summary( string $type, array $current, ?array $previous ): string {
		if ( History::TYPE_GSC === $type ) {
			$clicks = (int) ( $current['clicks'] ?? 0 );
			$impr   = (int) ( $current['impr'] ?? 0 );
			if ( is_array( $previous ) ) {
				$dc = $clicks - (int) ( $previous['clicks'] ?? 0 );
				$di = $impr - (int) ( $previous['impr'] ?? 0 );
				$parts = [];
				if ( 0 !== $dc ) {
					$parts[] = sprintf( '%+d clicks', $dc );
				}
				if ( 0 !== $di ) {
					$parts[] = sprintf( '%+d impr', $di );
				}
				if ( [] !== $parts ) {
					return implode( ' · ', $parts );
				}
			}

			return sprintf(
				/* translators: 1: clicks, 2: impressions */
				__( '%1$s clicks · %2$s impr', '4wp-seo-helper' ),
				number_format_i18n( $clicks ),
				number_format_i18n( $impr )
			);
		}

		if ( History::TYPE_CONTENT === $type ) {
			$chars = (int) ( $current['chars'] ?? 0 );
			if ( is_array( $previous ) && (int) ( $previous['chars'] ?? 0 ) !== $chars ) {
				return sprintf(
					/* translators: 1: previous character count, 2: new character count */
					__( '%1$s → %2$s characters', '4wp-seo-helper' ),
					number_format_i18n( (int) $previous['chars'] ),
					number_format_i18n( $chars )
				);
			}

			return sprintf(
				/* translators: %s: character count */
				__( '%s characters', '4wp-seo-helper' ),
				number_format_i18n( $chars )
			);
		}

		if ( History::TYPE_SEO === $type ) {
			$score = (int) ( $current['score'] ?? 0 );
			if ( is_array( $previous ) && (int) ( $previous['score'] ?? 0 ) !== $score ) {
				return sprintf(
					/* translators: 1: previous score, 2: new score */
					__( 'Score %1$d → %2$d', '4wp-seo-helper' ),
					(int) $previous['score'],
					$score
				);
			}

			return sprintf(
				/* translators: %d: SEO score */
				__( 'Score %d', '4wp-seo-helper' ),
				$score
			);
		}

		if ( History::TYPE_CRAWL === $type ) {
			return __( 'Google crawled this URL', '4wp-seo-helper' );
		}

		if ( History::TYPE_INDEX_REQUEST === $type ) {
			return __( 'Indexing requested', '4wp-seo-helper' );
		}

		if ( History::TYPE_INSPECT === $type ) {
			return __( 'URL inspected in Search Console', '4wp-seo-helper' );
		}

		return self::event_label( $type );
	}

	private static function event_label( string $type ): string {
		$labels = [
			History::TYPE_INDEX_REQUEST => __( 'Index request', '4wp-seo-helper' ),
			History::TYPE_INSPECT       => __( 'Inspect', '4wp-seo-helper' ),
			History::TYPE_CRAWL         => __( 'Crawl', '4wp-seo-helper' ),
			History::TYPE_CONTENT       => __( 'Content', '4wp-seo-helper' ),
			History::TYPE_SEO           => __( 'SEO', '4wp-seo-helper' ),
			History::TYPE_GSC           => __( 'Search', '4wp-seo-helper' ),
		];

		return $labels[ $type ] ?? ( '' !== $type ? $type : '—' );
	}

	private static function post_label( int $post_id ): string {
		$title = get_the_title( $post_id );

		return '' !== $title ? $title : '#' . $post_id;
	}

	private static function format_relative( string $gmt ): string {
		$absolute = self::format_gmt( $gmt );
		if ( '—' === $absolute ) {
			return $absolute;
		}

		$ts = strtotime( $gmt . ' UTC' );
		if ( false === $ts ) {
			return $absolute;
		}

		return sprintf(
			/* translators: %s: human-readable time difference */
			__( '%s ago', '4wp-seo-helper' ),
			human_time_diff( $ts )
		);
	}

	private static function format_gmt( string $gmt ): string {
		if ( '' === $gmt ) {
			return '—';
		}

		$local = get_date_from_gmt( $gmt );
		if ( ! is_string( $local ) || '' === $local || '0000-00-00 00:00:00' === $local ) {
			$ts = strtotime( $gmt );
			if ( false === $ts ) {
				return $gmt;
			}
			$local = wp_date( 'Y-m-d H:i:s', $ts );
		}

		$formatted = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $local, true );

		return is_string( $formatted ) && '' !== $formatted ? $formatted : $gmt;
	}
}
