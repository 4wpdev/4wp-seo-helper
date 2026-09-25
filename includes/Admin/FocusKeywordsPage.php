<?php
/**
 * Keyword Bank admin: focus scan + manual / CSV phrases.
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\FocusKeywords\Bank;
use Forwp\SeoHelper\FocusKeywords\Repository;
use Forwp\SeoHelper\FocusKeywords\Scanner;
use Forwp\SeoHelper\FocusKeywords\Schema;
use Forwp\SeoHelper\FocusKeywords\Topics;
use Forwp\SeoHelper\Positions\Target;
use Forwp\SeoHelper\SeoMeta\FocusKeyphrases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FocusKeywordsPage {
	public const PAGE_SLUG         = 'forwp-seo-focus-keywords';
	public const PER_PAGE_OPTION   = 'forwp_seo_keyword_bank_per_page';
	public const PER_PAGE_DEFAULT  = 50;
	public const PER_PAGE_CHOICES  = [ 20, 50, 100, 200 ];

	public static function handle_load(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		$action = isset( $_POST['forwp_fk_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['forwp_fk_action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}

		$notice = null;
		$redir  = [
			'page' => self::PAGE_SLUG,
		];

		$keep_s = isset( $_POST['forwp_fk_return_s'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['forwp_fk_return_s'] ) ) : '';
		$keep_t = isset( $_POST['forwp_fk_return_topic'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['forwp_fk_return_topic'] ) ) : '';
		$keep_p = isset( $_POST['forwp_fk_return_per_page'] ) ? (int) $_POST['forwp_fk_return_per_page'] : 0;
		if ( '' !== $keep_s ) {
			$redir['s'] = $keep_s;
		}
		if ( '' !== $keep_t ) {
			$redir['topic'] = $keep_t;
		}
		if ( $keep_p > 0 ) {
			$redir['per_page'] = self::sanitize_per_page( $keep_p );
		}

		if ( 'scan' === $action ) {
			check_admin_referer( 'forwp_focus_keywords_scan' );
			$result = Scanner::run();
			$notice = [
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: bank size, 2: pages using focus phrases */
					__( 'Scan complete: %1$d keywords in bank · %2$d page uses.', '4wp-seo-helper' ),
					(int) ( $result['keywords'] ?? 0 ),
					(int) ( $result['uses'] ?? 0 )
				),
			];
		} elseif ( 'add' === $action ) {
			check_admin_referer( 'forwp_keyword_bank_add' );
			$raw     = isset( $_POST['forwp_fk_phrases'] ) ? wp_unslash( (string) $_POST['forwp_fk_phrases'] ) : '';
			$topic   = isset( $_POST['forwp_fk_topic'] ) ? wp_unslash( (string) $_POST['forwp_fk_topic'] ) : '';
			$phrases = FocusKeyphrases::normalize_input( $raw );
			$result  = Bank::upsert_phrases( $phrases, $topic );
			$notice  = [
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: added, 2: already in bank, 3: skipped */
					__( 'Added %1$d · already in bank %2$d · skipped %3$d.', '4wp-seo-helper' ),
					(int) $result['added'],
					(int) $result['updated'],
					(int) $result['skipped']
				),
			];
		} elseif ( 'import' === $action ) {
			check_admin_referer( 'forwp_keyword_bank_import' );
			$topic = isset( $_POST['forwp_fk_topic'] ) ? wp_unslash( (string) $_POST['forwp_fk_topic'] ) : '';
			$file  = $_FILES['forwp_fk_csv'] ?? null;
			if ( ! is_array( $file ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
				$notice = [
					'type'    => 'error',
					'message' => __( 'Choose a CSV or text file to import.', '4wp-seo-helper' ),
				];
			} else {
				$tmp = (string) ( $file['tmp_name'] ?? '' );
				if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
					$notice = [
						'type'    => 'error',
						'message' => __( 'Upload failed.', '4wp-seo-helper' ),
					];
				} else {
					$phrases = Bank::phrases_from_upload( $tmp );
					$result  = Bank::upsert_phrases( $phrases, $topic );
					$notice  = [
						'type'    => 'success',
						'message' => sprintf(
							/* translators: 1: parsed rows, 2: added, 3: already present */
							__( 'Import: %1$d phrases · added %2$d · already in bank %3$d.', '4wp-seo-helper' ),
							count( $phrases ),
							(int) $result['added'],
							(int) $result['updated']
						),
					];
					if ( '' !== Bank::normalize_topic( $topic ) ) {
						$redir['topic'] = Bank::normalize_topic( $topic );
					}
				}
			}
		} elseif ( 'bulk_delete' === $action ) {
			check_admin_referer( 'forwp_keyword_bank_bulk' );
			$ids     = self::posted_ids();
			$deleted = Bank::delete_ids( $ids );
			$notice  = [
				'type'    => $deleted > 0 ? 'success' : 'warning',
				'message' => $deleted > 0
					? sprintf(
						/* translators: %d: deleted count */
						_n( 'Deleted %d keyword from the bank.', 'Deleted %d keywords from the bank.', $deleted, '4wp-seo-helper' ),
						$deleted
					)
					: __( 'Nothing selected.', '4wp-seo-helper' ),
			];
		} elseif ( 'bulk_topic' === $action ) {
			check_admin_referer( 'forwp_keyword_bank_bulk' );
			$ids   = self::posted_ids();
			$topic = isset( $_POST['forwp_fk_bulk_topic'] ) ? wp_unslash( (string) $_POST['forwp_fk_bulk_topic'] ) : '';
			$n     = Bank::set_topic_for_ids( $ids, $topic );
			$notice = [
				'type'    => $n > 0 ? 'success' : 'warning',
				'message' => $n > 0
					? sprintf(
						/* translators: 1: count, 2: topic */
						__( 'Topic set on %1$d keywords: %2$s', '4wp-seo-helper' ),
						$n,
						'' !== Bank::normalize_topic( $topic ) ? Bank::normalize_topic( $topic ) : '—'
					)
					: __( 'Nothing selected.', '4wp-seo-helper' ),
			];
		} elseif ( 'bulk_topic_all' === $action ) {
			check_admin_referer( 'forwp_keyword_bank_bulk' );
			$topic = isset( $_POST['forwp_fk_bulk_topic'] ) ? wp_unslash( (string) $_POST['forwp_fk_bulk_topic'] ) : '';
			$n     = Bank::set_topic_for_all( $topic );
			$notice = [
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: count, 2: topic */
					__( 'Topic set on all %1$d keywords: %2$s', '4wp-seo-helper' ),
					$n,
					'' !== Bank::normalize_topic( $topic ) ? Bank::normalize_topic( $topic ) : '—'
				),
			];
		} elseif ( 'save_topics' === $action ) {
			check_admin_referer( 'forwp_keyword_bank_bulk' );
			$map = isset( $_POST['forwp_fk_row_topic'] ) && is_array( $_POST['forwp_fk_row_topic'] )
				? wp_unslash( $_POST['forwp_fk_row_topic'] )
				: [];
			$n   = Bank::save_topic_map( is_array( $map ) ? $map : [] );
			$notice = [
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %d: updated rows */
					__( 'Saved topics for %d keywords.', '4wp-seo-helper' ),
					$n
				),
			];
		} elseif ( 'topic_add' === $action ) {
			check_admin_referer( 'forwp_keyword_bank_topics' );
			$name = isset( $_POST['forwp_fk_topic_name'] ) ? wp_unslash( (string) $_POST['forwp_fk_topic_name'] ) : '';
			$ok   = Topics::add( $name );
			$notice = [
				'type'    => $ok ? 'success' : 'warning',
				'message' => $ok
					? sprintf(
						/* translators: %s: topic name */
						__( 'Topic added: %s', '4wp-seo-helper' ),
						Bank::normalize_topic( $name )
					)
					: __( 'Topic empty or already exists.', '4wp-seo-helper' ),
			];
		} elseif ( 'topic_delete' === $action ) {
			check_admin_referer( 'forwp_keyword_bank_topics' );
			$name = isset( $_POST['forwp_fk_topic_name'] ) ? wp_unslash( (string) $_POST['forwp_fk_topic_name'] ) : '';
			$ok   = Topics::delete( $name );
			$notice = [
				'type'    => $ok ? 'success' : 'warning',
				'message' => $ok
					? sprintf(
						/* translators: %s: topic name */
						__( 'Topic removed from catalog: %s (keyword assignments kept).', '4wp-seo-helper' ),
						Bank::normalize_topic( $name )
					)
					: __( 'Topic not found.', '4wp-seo-helper' ),
			];
		} else {
			return;
		}

		if ( is_array( $notice ) ) {
			set_transient( 'forwp_fk_notice_' . get_current_user_id(), $notice, 60 );
		}

		wp_safe_redirect( add_query_arg( $redir, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @return list<int>
	 */
	private static function posted_ids(): array {
		if ( ! isset( $_POST['forwp_fk_ids'] ) || ! is_array( $_POST['forwp_fk_ids'] ) ) {
			return [];
		}

		return array_map( 'intval', wp_unslash( $_POST['forwp_fk_ids'] ) );
	}

	public static function sanitize_per_page( int $per_page ): int {
		if ( in_array( $per_page, self::PER_PAGE_CHOICES, true ) ) {
			return $per_page;
		}

		return self::PER_PAGE_DEFAULT;
	}

	public static function current_per_page(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['per_page'] ) ) {
			$per_page = self::sanitize_per_page( (int) $_GET['per_page'] );
			update_user_meta( get_current_user_id(), self::PER_PAGE_OPTION, $per_page );
			return $per_page;
		}
		// phpcs:enable

		$saved = (int) get_user_meta( get_current_user_id(), self::PER_PAGE_OPTION, true );
		return self::sanitize_per_page( $saved > 0 ? $saved : self::PER_PAGE_DEFAULT );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Schema::maybe_upgrade();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$topic    = isset( $_GET['topic'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['topic'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable
		$per_page = self::current_per_page();

		$result = Repository::list( $search, $topic, $paged, $per_page );
		$rows   = $result['items'];
		$total  = (int) $result['total'];
		$pages  = max( 1, (int) ceil( $total / $per_page ) );
		if ( $paged > $pages ) {
			$paged  = $pages;
			$result = Repository::list( $search, $topic, $paged, $per_page );
			$rows   = $result['items'];
		}

		$topics       = Repository::list_topics();
		$topic_counts = Repository::topic_counts();
		$last         = Repository::last_scan();
		$notice       = get_transient( 'forwp_fk_notice_' . get_current_user_id() );
		$heading_svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="none" focusable="false" aria-hidden="true"><path d="M11 5a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z" stroke="currentColor" stroke-width="1.75"/><path d="M20 20 16.5 16.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>';
		$base_args    = [
			'page' => self::PAGE_SLUG,
		];
		if ( '' !== $search ) {
			$base_args['s'] = $search;
		}
		if ( '' !== $topic ) {
			$base_args['topic'] = $topic;
		}
		$base_args['per_page'] = $per_page;
		?>
		<div class="wrap forwp-seo-admin-shell forwp-seo-focus-keywords-shell">
			<h1 class="forwp-seo-admin-heading">
				<span class="forwp-seo-admin-heading__icon" aria-hidden="true">
					<?php echo wp_kses( $heading_svg, Page::svg_allowed_html() ); ?>
				</span>
				<span class="forwp-seo-admin-heading__text"><?php esc_html_e( 'Keyword Bank', '4wp-seo-helper' ); ?></span>
			</h1>
			<p class="forwp-seo-admin-lead">
				<?php esc_html_e( 'Phrase bank. Create topics in the catalog, then assign them from the dropdowns.', '4wp-seo-helper' ); ?>
			</p>

			<?php
			if ( is_array( $notice ) ) {
				delete_transient( 'forwp_fk_notice_' . get_current_user_id() );
				printf(
					'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
					esc_attr( (string) ( $notice['type'] ?? 'info' ) ),
					esc_html( (string) ( $notice['message'] ?? '' ) )
				);
			}
			?>

			<div class="forwp-seo-admin-app forwp-seo-focus-keywords-app">
				<div class="forwp-seo-fk-panels">
					<div class="forwp-seo-fk-panel">
						<h2><?php esc_html_e( 'Topics', '4wp-seo-helper' ); ?></h2>
						<form method="post" class="forwp-seo-fk-topic-add">
							<?php wp_nonce_field( 'forwp_keyword_bank_topics' ); ?>
							<input type="hidden" name="forwp_fk_action" value="topic_add" />
							<label class="screen-reader-text" for="forwp-fk-topic-name"><?php esc_html_e( 'New topic', '4wp-seo-helper' ); ?></label>
							<input type="text" class="regular-text" id="forwp-fk-topic-name" name="forwp_fk_topic_name" placeholder="<?php esc_attr_e( 'New topic name', '4wp-seo-helper' ); ?>" />
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Add topic', '4wp-seo-helper' ); ?></button>
						</form>
						<?php
						$none_cnt       = (int) ( $topic_counts['__none__'] ?? 0 );
						$none_active    = ( '__none__' === $topic );
						$none_filter_url = add_query_arg(
							[
								'page'     => self::PAGE_SLUG,
								'topic'    => '__none__',
								'per_page' => $per_page,
								's'        => '' !== $search ? $search : false,
							],
							admin_url( 'admin.php' )
						);
						?>
						<ul class="forwp-seo-fk-topic-list">
							<li class="forwp-seo-fk-topic--none<?php echo $none_active ? ' is-active' : ''; ?>">
								<span class="forwp-seo-fk-topic-name"><?php esc_html_e( 'No topic', '4wp-seo-helper' ); ?></span>
								<a
									class="forwp-seo-fk-topic-count"
									href="<?php echo esc_url( $none_filter_url ); ?>"
									title="<?php esc_attr_e( 'Filter keywords without a topic', '4wp-seo-helper' ); ?>"
								><?php echo esc_html( (string) $none_cnt ); ?></a>
							</li>
							<?php if ( [] === $topics ) : ?>
								<li class="forwp-seo-fk-topic-empty">
									<span class="description"><?php esc_html_e( 'No topics in catalog yet.', '4wp-seo-helper' ); ?></span>
								</li>
							<?php else : ?>
								<?php foreach ( $topics as $t ) : ?>
									<?php
									$cnt        = (int) ( $topic_counts[ $t ] ?? 0 );
									$is_active  = ( $topic === $t );
									$filter_url = add_query_arg(
										[
											'page'     => self::PAGE_SLUG,
											'topic'    => $t,
											'per_page' => $per_page,
											's'        => '' !== $search ? $search : false,
										],
										admin_url( 'admin.php' )
									);
									?>
									<li<?php echo $is_active ? ' class="is-active"' : ''; ?>>
										<span class="forwp-seo-fk-topic-name"><?php echo esc_html( $t ); ?></span>
										<a
											class="forwp-seo-fk-topic-count"
											href="<?php echo esc_url( $filter_url ); ?>"
											title="<?php echo esc_attr( sprintf( /* translators: %s: topic */ __( 'Filter by %s', '4wp-seo-helper' ), $t ) ); ?>"
										><?php echo esc_html( (string) $cnt ); ?></a>
										<form method="post" class="forwp-seo-fk-topic-del">
											<?php wp_nonce_field( 'forwp_keyword_bank_topics' ); ?>
											<input type="hidden" name="forwp_fk_action" value="topic_delete" />
											<input type="hidden" name="forwp_fk_topic_name" value="<?php echo esc_attr( $t ); ?>" />
											<button type="submit" class="button-link-delete" onclick="return confirm('Remove topic from catalog?');"><?php esc_html_e( 'Remove', '4wp-seo-helper' ); ?></button>
										</form>
									</li>
								<?php endforeach; ?>
							<?php endif; ?>
						</ul>
					</div>
					<div class="forwp-seo-fk-panel">
						<h2><?php esc_html_e( 'Add phrases', '4wp-seo-helper' ); ?></h2>
						<form method="post">
							<?php wp_nonce_field( 'forwp_keyword_bank_add' ); ?>
							<input type="hidden" name="forwp_fk_action" value="add" />
							<label class="screen-reader-text" for="forwp-fk-phrases"><?php esc_html_e( 'Phrases', '4wp-seo-helper' ); ?></label>
							<textarea id="forwp-fk-phrases" name="forwp_fk_phrases" rows="5" class="large-text code" placeholder="<?php esc_attr_e( "One phrase per line\nwp cli\ninstall wp cli", '4wp-seo-helper' ); ?>"></textarea>
							<p>
								<label for="forwp-fk-add-topic"><?php esc_html_e( 'Topic', '4wp-seo-helper' ); ?></label>
								<select id="forwp-fk-add-topic" name="forwp_fk_topic">
									<?php Topics::render_select_options( '' ); ?>
								</select>
							</p>
							<p class="submit" style="margin-bottom:0">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Add to bank', '4wp-seo-helper' ); ?></button>
							</p>
						</form>
					</div>
					<div class="forwp-seo-fk-panel">
						<h2><?php esc_html_e( 'Import CSV', '4wp-seo-helper' ); ?></h2>
						<form method="post" enctype="multipart/form-data">
							<?php wp_nonce_field( 'forwp_keyword_bank_import' ); ?>
							<input type="hidden" name="forwp_fk_action" value="import" />
							<label class="screen-reader-text" for="forwp-fk-csv"><?php esc_html_e( 'CSV file', '4wp-seo-helper' ); ?></label>
							<input type="file" id="forwp-fk-csv" name="forwp_fk_csv" accept=".csv,text/csv,text/plain" />
							<p>
								<label for="forwp-fk-import-topic"><?php esc_html_e( 'Topic for this import', '4wp-seo-helper' ); ?></label>
								<select id="forwp-fk-import-topic" name="forwp_fk_topic">
									<?php Topics::render_select_options( '' ); ?>
								</select>
							</p>
							<p class="description">
								<?php esc_html_e( 'Keyword column only. Pick a topic from the catalog.', '4wp-seo-helper' ); ?>
							</p>
							<p class="submit" style="margin-bottom:0">
								<button type="submit" class="button"><?php esc_html_e( 'Import', '4wp-seo-helper' ); ?></button>
							</p>
						</form>
					</div>
				</div>

				<div class="forwp-seo-fk-toolbar">
					<form method="post">
						<?php wp_nonce_field( 'forwp_focus_keywords_scan' ); ?>
						<input type="hidden" name="forwp_fk_action" value="scan" />
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Scan focus from site', '4wp-seo-helper' ); ?></button>
					</form>
					<?php if ( $last ) : ?>
						<p class="forwp-seo-fk-scan-meta">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: datetime, 2: keyword count */
									__( 'Last scan %1$s · %2$d in bank', '4wp-seo-helper' ),
									$last['at'],
									(int) $last['keywords']
								)
							);
							?>
						</p>
					<?php endif; ?>
					<form method="get" class="forwp-seo-fk-search">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
						<label class="screen-reader-text" for="forwp-fk-topic-filter"><?php esc_html_e( 'Topic', '4wp-seo-helper' ); ?></label>
						<select id="forwp-fk-topic-filter" name="topic">
							<option value=""><?php esc_html_e( 'All topics', '4wp-seo-helper' ); ?></option>
							<option value="__none__" <?php selected( $topic, '__none__' ); ?>><?php esc_html_e( 'No topic', '4wp-seo-helper' ); ?></option>
							<?php foreach ( $topics as $t ) : ?>
								<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $topic, $t ); ?>><?php echo esc_html( $t ); ?></option>
							<?php endforeach; ?>
						</select>
						<label class="screen-reader-text" for="forwp-fk-search"><?php esc_html_e( 'Search', '4wp-seo-helper' ); ?></label>
						<input type="search" id="forwp-fk-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Keyword…', '4wp-seo-helper' ); ?>" />
						<label for="forwp-fk-per-page"><?php esc_html_e( 'Per page', '4wp-seo-helper' ); ?></label>
						<select id="forwp-fk-per-page" name="per_page">
							<?php foreach ( self::PER_PAGE_CHOICES as $choice ) : ?>
								<option value="<?php echo (int) $choice; ?>" <?php selected( $per_page, $choice ); ?>><?php echo (int) $choice; ?></option>
							<?php endforeach; ?>
						</select>
						<?php submit_button( __( 'Filter', '4wp-seo-helper' ), '', '', false ); ?>
					</form>
				</div>

				<form method="post" id="forwp-fk-bulk-form" class="forwp-seo-fk-bulk">
					<?php wp_nonce_field( 'forwp_keyword_bank_bulk' ); ?>
					<input type="hidden" name="forwp_fk_action" id="forwp-fk-action" value="" />
					<input type="hidden" name="forwp_fk_return_s" value="<?php echo esc_attr( $search ); ?>" />
					<input type="hidden" name="forwp_fk_return_topic" value="<?php echo esc_attr( $topic ); ?>" />
					<input type="hidden" name="forwp_fk_return_per_page" value="<?php echo (int) $per_page; ?>" />
					<div class="forwp-seo-fk-list-meta">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: shown count, 2: total, 3: page, 4: pages */
								__( 'Showing %1$d of %2$d · page %3$d / %4$d', '4wp-seo-helper' ),
								count( $rows ),
								$total,
								$paged,
								$pages
							)
						);
						?>
					</div>
					<div class="forwp-seo-fk-bulk-bar">
						<label class="screen-reader-text" for="forwp-fk-bulk-topic"><?php esc_html_e( 'Bulk topic', '4wp-seo-helper' ); ?></label>
						<select id="forwp-fk-bulk-topic" name="forwp_fk_bulk_topic">
							<?php Topics::render_select_options( '' ); ?>
						</select>
						<button type="submit" class="button" data-fk-action="bulk_topic" id="forwp-fk-bulk-topic-btn" disabled>
							<?php esc_html_e( 'Apply to selected', '4wp-seo-helper' ); ?>
						</button>
						<button type="submit" class="button" data-fk-action="bulk_topic_all" id="forwp-fk-bulk-topic-all">
							<?php esc_html_e( 'Apply to all', '4wp-seo-helper' ); ?>
						</button>
						<button type="submit" class="button button-primary" data-fk-action="save_topics" id="forwp-fk-save-topics">
							<?php esc_html_e( 'Save row topics', '4wp-seo-helper' ); ?>
						</button>
						<button type="submit" class="button" data-fk-action="bulk_delete" id="forwp-fk-bulk-delete" disabled>
							<?php esc_html_e( 'Delete selected', '4wp-seo-helper' ); ?>
						</button>
						<span class="forwp-seo-fk-bulk-count" data-k="fk-selected-count" hidden></span>
					</div>

					<table class="widefat striped forwp-seo-fk-table">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column">
									<label class="screen-reader-text" for="forwp-fk-cb-all"><?php esc_html_e( 'Select all', '4wp-seo-helper' ); ?></label>
									<input type="checkbox" id="forwp-fk-cb-all" />
								</td>
								<th class="column-use"><?php esc_html_e( 'Use', '4wp-seo-helper' ); ?></th>
								<th class="column-topic"><?php esc_html_e( 'Topic', '4wp-seo-helper' ); ?></th>
								<th class="column-keyword"><?php esc_html_e( 'Keyword', '4wp-seo-helper' ); ?></th>
								<th class="column-where"><?php esc_html_e( 'Where (focus)', '4wp-seo-helper' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( [] === $rows ) : ?>
							<tr>
								<td colspan="5">
									<?php esc_html_e( 'Bank is empty. Import a CSV, add phrases, or scan focus from the site.', '4wp-seo-helper' ); ?>
								</td>
							</tr>
						<?php endif; ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<th scope="row" class="check-column">
									<label class="screen-reader-text" for="forwp-fk-cb-<?php echo (int) $row['id']; ?>">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: keyword */
												__( 'Select %s', '4wp-seo-helper' ),
												(string) $row['label']
											)
										);
										?>
									</label>
									<input type="checkbox" class="forwp-fk-cb" id="forwp-fk-cb-<?php echo (int) $row['id']; ?>" name="forwp_fk_ids[]" value="<?php echo (int) $row['id']; ?>" />
								</th>
								<td class="column-use"><?php echo esc_html( (string) (int) $row['use'] ); ?></td>
								<td class="column-topic">
									<label class="screen-reader-text" for="forwp-fk-topic-<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'Topic', '4wp-seo-helper' ); ?></label>
									<select
										class="forwp-fk-row-topic"
										id="forwp-fk-topic-<?php echo (int) $row['id']; ?>"
										name="forwp_fk_row_topic[<?php echo (int) $row['id']; ?>]"
									>
										<?php Topics::render_select_options( (string) ( $row['topic'] ?? '' ) ); ?>
									</select>
								</td>
								<td class="column-keyword">
									<strong><?php echo esc_html( (string) $row['label'] ); ?></strong>
									<?php if ( ! empty( $row['tracked'] ) ) : ?>
										<span class="forwp-seo-fk-badge"><?php esc_html_e( 'Positions', '4wp-seo-helper' ); ?></span>
									<?php endif; ?>
									<?php if ( (int) $row['use'] < 1 ) : ?>
										<span class="forwp-seo-fk-badge forwp-seo-fk-badge--bank"><?php esc_html_e( 'Bank only', '4wp-seo-helper' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="column-where">
									<?php self::render_where( (array) ( $row['uses'] ?? [] ), (array) ( $row['dates'] ?? [] ) ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( $pages > 1 ) : ?>
						<div class="tablenav bottom forwp-seo-fk-pager">
							<div class="tablenav-pages">
								<span class="displaying-num">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: total keyword count */
											_n( '%s item', '%s items', $total, '4wp-seo-helper' ),
											number_format_i18n( $total )
										)
									);
									?>
								</span>
								<span class="pagination-links">
									<?php
									echo wp_kses_post(
										(string) paginate_links(
											[
												'base'      => add_query_arg( array_merge( $base_args, [ 'paged' => '%#%' ] ), admin_url( 'admin.php' ) ),
												'format'    => '',
												'current'   => $paged,
												'total'     => $pages,
												'type'      => 'plain',
												'prev_text' => '&laquo;',
												'next_text' => '&raquo;',
												'mid_size'  => 2,
												'end_size'  => 1,
											]
										)
									);
									?>
								</span>
							</div>
						</div>
					<?php endif; ?>
				</form>
			</div>
		</div>
		<script>
		(function () {
			var form = document.getElementById('forwp-fk-bulk-form');
			if (!form) return;
			var actionInput = document.getElementById('forwp-fk-action');
			var all = document.getElementById('forwp-fk-cb-all');
			var boxes = function () { return Array.prototype.slice.call(form.querySelectorAll('.forwp-fk-cb')); };
			var deleteBtn = document.getElementById('forwp-fk-bulk-delete');
			var topicBtn = document.getElementById('forwp-fk-bulk-topic-btn');
			var countEl = form.querySelector('[data-k="fk-selected-count"]');
			var sync = function () {
				var list = boxes();
				var n = list.filter(function (el) { return el.checked; }).length;
				if (deleteBtn) deleteBtn.disabled = n < 1;
				if (topicBtn) topicBtn.disabled = n < 1;
				if (all) {
					all.checked = list.length > 0 && n === list.length;
					all.indeterminate = n > 0 && n < list.length;
				}
				if (countEl) {
					countEl.hidden = n < 1;
					countEl.textContent = n ? (n + ' selected') : '';
				}
			};
			if (all) {
				all.addEventListener('change', function () {
					boxes().forEach(function (el) { el.checked = all.checked; });
					sync();
				});
			}
			form.addEventListener('change', function (e) {
				if (e.target && e.target.classList && e.target.classList.contains('forwp-fk-cb')) sync();
			});
			form.querySelectorAll('[data-fk-action]').forEach(function (btn) {
				btn.addEventListener('click', function () {
					if (actionInput) actionInput.value = btn.getAttribute('data-fk-action') || '';
				});
			});
			form.addEventListener('submit', function (e) {
				var action = actionInput ? actionInput.value : '';
				var n = boxes().filter(function (el) { return el.checked; }).length;
				if (action === 'bulk_delete') {
					if (n < 1) { e.preventDefault(); return; }
					if (!window.confirm('Delete ' + n + ' keyword(s) from the bank?')) e.preventDefault();
					return;
				}
				if (action === 'bulk_topic') {
					if (n < 1) { e.preventDefault(); return; }
					return;
				}
				if (action === 'bulk_topic_all') {
					if (!window.confirm('Apply this topic to ALL keywords in the bank?')) e.preventDefault();
					return;
				}
				if (action === 'save_topics') {
					return;
				}
				e.preventDefault();
			});
			sync();
		})();
		</script>
		<?php
	}

	/**
	 * @param list<array<string, mixed>> $uses
	 * @param list<string>               $dates
	 */
	private static function render_where( array $uses, array $dates ): void {
		if ( [] === $uses ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}

		if ( [] === $dates ) {
			echo '<ul class="forwp-seo-fk-where">';
			foreach ( $uses as $use ) {
				echo '<li>' . self::page_label( $use ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul>';
			return;
		}

		echo '<div class="forwp-seo-fk-pos-wrap">';
		echo '<div class="forwp-seo-fk-pos" role="table" style="--fk-dates:' . (int) count( $dates ) . '">';
		echo '<div class="forwp-seo-fk-pos__row forwp-seo-fk-pos__row--head" role="row">';
		echo '<span role="columnheader">' . esc_html__( 'Page', '4wp-seo-helper' ) . '</span>';
		foreach ( $dates as $date ) {
			echo '<span role="columnheader">' . esc_html( mysql2date( 'Y-m-d', $date . ' 00:00:00' ) ?: $date ) . '</span>';
		}
		echo '</div>';
		foreach ( $uses as $use ) {
			$by_date = is_array( $use['by_date'] ?? null ) ? $use['by_date'] : [];
			echo '<div class="forwp-seo-fk-pos__row" role="row">';
			echo '<span class="forwp-seo-fk-pos__page" role="cell">' . self::page_label( $use ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $dates as $date ) {
				$rank = $by_date[ $date ] ?? null;
				echo '<span class="forwp-seo-fk-pos__rank" role="cell">';
				echo is_int( $rank ) && $rank > 0 ? esc_html( (string) $rank ) : '—';
				echo '</span>';
			}
			echo '</div>';
		}
		echo '</div></div>';
	}

	/**
	 * @param array<string, mixed> $use
	 */
	private static function page_label( array $use ): string {
		$title = (string) ( $use['title'] ?? '' );
		$url   = (string) ( $use['url'] ?? '' );
		if ( '' === $title ) {
			$title = '' !== $url ? Target::link_label( $url ) : '';
		}
		if ( '' === $title ) {
			return '';
		}
		if ( '' === $url ) {
			return esc_html( $title );
		}

		return '<a class="forwp-seo-fk-out" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html( $title )
			. '<span class="dashicons dashicons-external" aria-hidden="true"></span></a>';
	}
}
