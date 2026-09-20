<?php
/**
 * Trends admin: Google Trends JSON import and snapshot table.
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\Trends\Importer;
use Forwp\SeoHelper\Trends\Parser;
use Forwp\SeoHelper\Trends\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TrendsPage {
	public const PAGE_SLUG = 'forwp-seo-trends';

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

		$notice  = get_transient( 'forwp_trends_notice_' . get_current_user_id() );
		$preview = get_transient( Importer::PREVIEW_TRANSIENT . get_current_user_id() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$lang_filter   = isset( $_GET['trends_lang'] ) ? sanitize_key( wp_unslash( (string) $_GET['trends_lang'] ) ) : '';
		$region_filter = isset( $_GET['trends_region'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['trends_region'] ) ) : '';
		$search        = isset( $_GET['trends_s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['trends_s'] ) ) : '';
		// phpcs:enable

		$keys      = Repository::list_keys( $lang_filter, $region_filter, $search );
		$stopped   = Repository::list_keys( $lang_filter, $region_filter, $search, true );
		$labels    = Repository::snapshot_labels( $keys );
		$batches   = Repository::batches();
		$languages = Repository::languages();
		$regions   = Repository::regions();

		$heading_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="none" focusable="false" aria-hidden="true"><path d="M4 19V5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/><path d="m4 16 4-5 3 3 5-7 4 4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>';

		echo '<div class="wrap forwp-seo-admin-shell forwp-seo-trends-shell">';
		echo '<h1 class="forwp-seo-admin-heading">';
		echo '<span class="forwp-seo-admin-heading__icon" aria-hidden="true">';
		echo wp_kses( $heading_svg, Page::svg_allowed_html() );
		echo '</span><span class="forwp-seo-admin-heading__text">';
		echo esc_html__( 'Google Trends', '4wp-seo-helper' );
		echo '</span></h1>';

		if ( is_array( $notice ) ) {
			delete_transient( 'forwp_trends_notice_' . get_current_user_id() );
			$type = (string) ( $notice['type'] ?? 'info' );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				esc_html( (string) ( $notice['message'] ?? '' ) )
			);
		}

		echo '<div class="forwp-seo-admin-app forwp-seo-trends-app">';

		echo '<div class="forwp-seo-trends-intro">';
		echo '<h2>' . esc_html__( 'Query interest over time', '4wp-seo-helper' ) . '</h2>';
		echo '<p>' . esc_html__( 'Each row is a Google Trends query in a region. A new file adds another date column — existing rows are never overwritten. Language is only a filter, not part of the key.', '4wp-seo-helper' ) . '</p>';
		echo '</div>';

		self::render_upload( is_array( $preview ) ? $preview : null );
		self::render_filters( $lang_filter, $region_filter, $search, $languages, $regions );
		self::render_table( $keys, $labels );
		self::render_stop_list( $stopped );
		self::render_batches( $batches );

		echo '</div></div>';
	}

	/**
	 * @param array<string, mixed>|null $preview
	 */
	private static function render_upload( ?array $preview ): void {
		$has_preview = is_array( $preview ) && ! empty( $preview['rows'] );
		?>
		<section class="forwp-seo-trends-upload">
			<form method="post" enctype="multipart/form-data" class="forwp-seo-trends-upload__form">
				<?php wp_nonce_field( 'forwp_trends_upload', 'forwp_trends_upload_nonce' ); ?>
				<input type="hidden" name="forwp_trends_action" value="preview" />
				<div class="forwp-seo-file-drop">
					<input type="file" name="forwp_trends_file" id="forwp_trends_file" class="forwp-seo-file-drop__input" accept="application/json,.json,text/csv,.csv" required />
					<label for="forwp_trends_file" class="forwp-seo-file-drop__label">
						<span class="forwp-seo-file-drop__title"><?php esc_html_e( 'Drop a Google Trends export here, or browse', '4wp-seo-helper' ); ?></span>
						<span class="forwp-seo-file-drop__hint"><?php esc_html_e( 'JSON, related-queries CSV, or time-series CSV. Geo is read from the filename.', '4wp-seo-helper' ); ?></span>
						<span class="forwp-seo-file-drop__cta">
							<span class="button"><?php esc_html_e( 'Choose file', '4wp-seo-helper' ); ?></span>
							<span class="forwp-seo-file-drop__name" data-empty="<?php esc_attr_e( 'No file selected', '4wp-seo-helper' ); ?>"><?php esc_html_e( 'No file selected', '4wp-seo-helper' ); ?></span>
						</span>
					</label>
				</div>
				<div class="forwp-seo-trends-upload__actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Parse file', '4wp-seo-helper' ); ?></button>
				</div>
			</form>
			<?php
			if ( $has_preview ) {
				self::render_preview( $preview );
			}
			?>
		</section>
		<?php
	}

	/**
	 * @param array{rows: list<array<string, mixed>>, snapshot_hint: string, filename?: string, summary?: array<string, int>} $preview
	 */
	private static function render_preview( array $preview ): void {
		$summary = is_array( $preview['summary'] ?? null ) ? $preview['summary'] : Importer::summarize( $preview );
		?>
		<div class="forwp-seo-trends-preview">
			<p class="forwp-seo-trends-preview__kicker"><?php esc_html_e( 'Parsed — not imported yet', '4wp-seo-helper' ); ?></p>
			<p class="forwp-seo-trends-preview__title">
				<?php echo esc_html( (string) ( $preview['filename'] ?? __( 'Trends file', '4wp-seo-helper' ) ) ); ?>
			</p>
			<p class="forwp-seo-trends-preview__stats">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: new keys, 2: existing keys, 3: snapshot rows */
						__( '%1$d new query+region rows · %2$d already in the table · %3$d snapshots in this file.', '4wp-seo-helper' ),
						(int) ( $summary['new_keys'] ?? 0 ),
						(int) ( $summary['existing_keys'] ?? 0 ),
						(int) ( $summary['snapshots'] ?? 0 )
					)
				);
				?>
			</p>
			<form method="post" class="forwp-seo-trends-preview__form">
				<?php wp_nonce_field( 'forwp_trends_import', 'forwp_trends_import_nonce' ); ?>
				<input type="hidden" name="forwp_trends_action" value="import" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Import this file', '4wp-seo-helper' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * @param list<string> $languages
	 * @param list<string> $regions
	 */
	private static function render_filters( string $lang, string $region, string $search, array $languages, array $regions ): void {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<form method="get" class="forwp-seo-trends-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<div class="forwp-seo-trends-filters__fields">
				<label class="forwp-seo-trends-filters__search">
					<span><?php esc_html_e( 'Search', '4wp-seo-helper' ); ?></span>
					<input type="search" name="trends_s" id="trends_s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Query…', '4wp-seo-helper' ); ?>" />
				</label>
				<label>
					<span><?php esc_html_e( 'Language', '4wp-seo-helper' ); ?></span>
					<select name="trends_lang" id="trends_lang">
						<option value=""><?php esc_html_e( 'All', '4wp-seo-helper' ); ?></option>
						<?php foreach ( $languages as $code ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $lang, $code ); ?>><?php echo esc_html( strtoupper( $code ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Region', '4wp-seo-helper' ); ?></span>
					<select name="trends_region" id="trends_region">
						<option value=""><?php esc_html_e( 'All', '4wp-seo-helper' ); ?></option>
						<?php foreach ( $regions as $code ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $region, $code ); ?>><?php echo esc_html( self::format_region( $code ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<div class="forwp-seo-trends-filters__actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', '4wp-seo-helper' ); ?></button>
				<a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Reset', '4wp-seo-helper' ); ?></a>
			</div>
		</form>
		<?php
	}

	/**
	 * @param list<array<string, mixed>> $keys
	 * @param list<string>               $labels
	 */
	private static function render_table( array $keys, array $labels ): void {
		echo '<h2>' . esc_html__( 'Queries', '4wp-seo-helper' ) . '</h2>';
		if ( empty( $keys ) ) {
			echo '<p>' . esc_html__( 'No trends imported yet.', '4wp-seo-helper' ) . '</p>';
			return;
		}

		echo '<div class="forwp-seo-trends-scroll">';
		echo '<table class="widefat striped forwp-seo-trends-table"><thead><tr>';
		echo '<th class="forwp-seo-trends-col--stop">' . esc_html__( 'Exclude', '4wp-seo-helper' ) . '</th>';
		echo '<th class="forwp-seo-trends-col--query">' . esc_html__( 'Query', '4wp-seo-helper' ) . '</th>';
		echo '<th class="forwp-seo-trends-col--region">' . esc_html__( 'Region', '4wp-seo-helper' ) . '</th>';
		echo '<th class="forwp-seo-trends-col--lang">' . esc_html__( 'Lang', '4wp-seo-helper' ) . '</th>';
		foreach ( $labels as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '<th>Δ</th>';
		echo '</tr></thead><tbody>';

		foreach ( $keys as $row ) {
			$by = [];
			foreach ( (array) ( $row['snapshots'] ?? [] ) as $snap ) {
				$lab = (string) ( $snap['snapshot_label'] ?? '' );
				$id  = (int) ( $snap['id'] ?? 0 );
				if ( ! isset( $by[ $lab ] ) || $id > (int) $by[ $lab ]['id'] ) {
					$by[ $lab ] = $snap;
				}
			}
			$delta_label = (string) ( $row['delta_label'] ?? '' );
			if ( '' === $delta_label ) {
				$delta = (float) ( $row['delta'] ?? 0 );
				$arrow = $delta > 0 ? '↑' : ( $delta < 0 ? '↓' : '→' );
				$delta_label = $arrow . ' ' . ( 0.0 === $delta ? '0' : (string) $delta );
			}

			$region      = (string) $row['region'];
			$region_label = self::format_region( $region );

			echo '<tr>';
			echo '<td class="forwp-seo-trends-col--stop">';
			echo self::exclude_form( (int) $row['id'], true );
			echo '</td>';
			echo '<td class="forwp-seo-trends-col--query">' . esc_html( (string) $row['query_key'] ) . '</td>';
			echo '<td class="forwp-seo-trends-col--region" title="' . esc_attr( $region ) . '">' . esc_html( $region_label ) . '</td>';
			echo '<td class="forwp-seo-trends-col--lang">' . esc_html( strtoupper( (string) ( $row['language'] ?? '' ) ) ) . '</td>';
			foreach ( $labels as $label ) {
				$val = isset( $by[ $label ] ) ? (string) (float) $by[ $label ]['interest'] : '—';
				echo '<td>' . esc_html( $val ) . '</td>';
			}
			echo '<td>' . esc_html( $delta_label ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * @param list<array<string, mixed>> $keys
	 */
	private static function render_stop_list( array $keys ): void {
		echo '<h2 class="forwp-seo-trends-stop-heading">' . esc_html__( 'Stop phrases', '4wp-seo-helper' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Excluded queries stay in the database but leave the main table and inventory Trends column.', '4wp-seo-helper' ) . '</p>';
		if ( empty( $keys ) ) {
			echo '<p>' . esc_html__( 'Nothing excluded yet.', '4wp-seo-helper' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped forwp-seo-trends-stop"><thead><tr>';
		echo '<th>' . esc_html__( 'Query', '4wp-seo-helper' ) . '</th>';
		echo '<th>' . esc_html__( 'Region', '4wp-seo-helper' ) . '</th>';
		echo '<th>' . esc_html__( 'Lang', '4wp-seo-helper' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $keys as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['query_key'] ) . '</td>';
			echo '<td>' . esc_html( self::format_region( (string) $row['region'] ) ) . '</td>';
			echo '<td>' . esc_html( strtoupper( (string) ( $row['language'] ?? '' ) ) ) . '</td>';
			echo '<td>' . self::exclude_form( (int) $row['id'], false ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function exclude_form( int $key_id, bool $exclude ): string {
		$label = $exclude ? __( 'Exclude this query', '4wp-seo-helper' ) : __( 'Restore this query', '4wp-seo-helper' );
		ob_start();
		?>
		<form method="post" class="forwp-seo-trends-exclude">
			<?php wp_nonce_field( 'forwp_trends_exclude', 'forwp_trends_exclude_nonce' ); ?>
			<input type="hidden" name="forwp_trends_action" value="exclude" />
			<input type="hidden" name="key_id" value="<?php echo esc_attr( (string) $key_id ); ?>" />
			<input type="hidden" name="excluded" value="<?php echo $exclude ? '1' : '0'; ?>" />
			<label>
				<input
					type="checkbox"
					<?php checked( ! $exclude ); ?>
					onchange="this.form.submit()"
					title="<?php echo esc_attr( $label ); ?>"
					aria-label="<?php echo esc_attr( $label ); ?>"
				/>
			</label>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param list<array<string, mixed>> $batches
	 */
	private static function render_batches( array $batches ): void {
		echo '<h2 style="margin-top:24px;">' . esc_html__( 'Imported files', '4wp-seo-helper' ) . '</h2>';
		if ( empty( $batches ) ) {
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'File', '4wp-seo-helper' ) . '</th>';
		echo '<th>' . esc_html__( 'Rows', '4wp-seo-helper' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', '4wp-seo-helper' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $batches as $batch ) {
			$id = (int) $batch['id'];
			echo '<tr>';
			echo '<td>' . esc_html( (string) $batch['filename'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $batch['rows_count'] ) . '</td>';
			echo '<td>' . esc_html( (string) $batch['created_at'] ) . '</td>';
			echo '<td>';
			echo '<form method="post" style="margin:0;" onsubmit="return confirm(\'' . esc_js( __( 'Delete this file’s snapshots only?', '4wp-seo-helper' ) ) . '\');">';
			wp_nonce_field( 'forwp_trends_delete', 'forwp_trends_delete_nonce' );
			echo '<input type="hidden" name="forwp_trends_action" value="delete" />';
			echo '<input type="hidden" name="batch_id" value="' . esc_attr( (string) $id ) . '" />';
			echo '<button type="submit" class="button-link-delete">' . esc_html__( 'Delete batch', '4wp-seo-helper' ) . '</button>';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function format_region( string $region ): string {
		if ( 'WORLDWIDE' === strtoupper( $region ) ) {
			return 'WW';
		}

		return $region;
	}

	private static function process_post(): void {
		$action = isset( $_POST['forwp_trends_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['forwp_trends_action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}

		if ( 'preview' === $action ) {
			check_admin_referer( 'forwp_trends_upload', 'forwp_trends_upload_nonce' );
			self::handle_preview();
			return;
		}

		if ( 'import' === $action ) {
			check_admin_referer( 'forwp_trends_import', 'forwp_trends_import_nonce' );
			self::handle_import();
			return;
		}

		if ( 'exclude' === $action ) {
			check_admin_referer( 'forwp_trends_exclude', 'forwp_trends_exclude_nonce' );
			$key_id   = isset( $_POST['key_id'] ) ? (int) $_POST['key_id'] : 0;
			$excluded = ! empty( $_POST['excluded'] );
			Repository::set_excluded( $key_id, $excluded );
			self::set_notice(
				'success',
				$excluded
					? __( 'Query moved to stop phrases.', '4wp-seo-helper' )
					: __( 'Query restored to the table.', '4wp-seo-helper' )
			);
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		if ( 'delete' === $action ) {
			check_admin_referer( 'forwp_trends_delete', 'forwp_trends_delete_nonce' );
			$batch_id = isset( $_POST['batch_id'] ) ? (int) $_POST['batch_id'] : 0;
			Importer::delete_batch( $batch_id );
			self::set_notice( 'success', __( 'Batch deleted.', '4wp-seo-helper' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}
	}

	private static function handle_preview(): void {
		$file = $_FILES['forwp_trends_file'] ?? null;
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			self::set_notice( 'error', __( 'Choose a JSON or CSV file.', '4wp-seo-helper' ) );
			return;
		}

		$name = (string) ( $file['name'] ?? '' );
		if ( ! preg_match( '/\.(json|csv)$/i', $name ) ) {
			self::set_notice( 'error', __( 'JSON or CSV only.', '4wp-seo-helper' ) );
			return;
		}

		$raw = file_get_contents( (string) $file['tmp_name'] );
		if ( ! is_string( $raw ) ) {
			self::set_notice( 'error', __( 'Could not read the file.', '4wp-seo-helper' ) );
			return;
		}

		$parsed = Parser::parse_file( $raw, $name );
		if ( is_wp_error( $parsed ) ) {
			self::set_notice( 'error', $parsed->get_error_message() );
			return;
		}

		$parsed['filename'] = sanitize_file_name( $name );
		$parsed['summary']  = Importer::summarize( $parsed );
		set_transient( Importer::PREVIEW_TRANSIENT . get_current_user_id(), $parsed, 15 * MINUTE_IN_SECONDS );
	}

	private static function handle_import(): void {
		$parsed = get_transient( Importer::PREVIEW_TRANSIENT . get_current_user_id() );
		if ( ! is_array( $parsed ) || empty( $parsed['rows'] ) ) {
			self::set_notice( 'error', __( 'Parse a file first.', '4wp-seo-helper' ) );
			return;
		}

		$result = Importer::import( $parsed, (string) ( $parsed['filename'] ?? 'trends.json' ) );
		delete_transient( Importer::PREVIEW_TRANSIENT . get_current_user_id() );

		self::set_notice(
			'success',
			sprintf(
				/* translators: %d: snapshot rows imported */
				__( 'Imported %d snapshots.', '4wp-seo-helper' ),
				(int) ( $result['summary']['snapshots'] ?? 0 )
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	private static function set_notice( string $type, string $message ): void {
		set_transient(
			'forwp_trends_notice_' . get_current_user_id(),
			[
				'type'    => $type,
				'message' => $message,
			],
			MINUTE_IN_SECONDS
		);
	}
}
