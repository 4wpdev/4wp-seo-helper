<?php
/**
 * One post: focus keyphrases, indexing, and the cross-post log stored on the post.
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\CrossPosting\Platforms;
use Forwp\SeoHelper\CrossPosting\Record;
use Forwp\SeoHelper\Gsc\Indexing;
use Forwp\SeoHelper\SeoMeta\FocusKeyphrases;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MaterialPage {
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin view.
		$post_id = absint( wp_unslash( (string) ( $_GET['forwp_post'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		?>
		<div class="wrap forwp-seo-admin-shell">
			<header class="forwp-seo-dash__header">
				<div class="forwp-seo-dash__header-main">
					<h1 class="forwp-seo-admin-heading">
						<span class="forwp-seo-admin-heading__text"><?php esc_html_e( 'Material', '4wp-seo-helper' ); ?></span>
					</h1>
				</div>
			</header>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::CROSSPOST_PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Back to Cross posting', '4wp-seo-helper' ); ?></a>
			</p>
			<?php
			if ( ! $post instanceof WP_Post ) {
				echo '<p>' . esc_html__( 'Post not found.', '4wp-seo-helper' ) . '</p>';
				echo '</div>';
				return;
			}
			self::render_post( $post );
			?>
		</div>
		<?php
	}

	public static function url_for_post( int $post_id ): string {
		return add_query_arg(
			[
				'page'       => Menu::MATERIAL_PAGE_SLUG,
				'forwp_post' => absint( $post_id ),
			],
			admin_url( 'admin.php' )
		);
	}

	private static function render_post( WP_Post $post ): void {
		$post_id  = (int) $post->ID;
		$primary  = (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
		$phrases  = FocusKeyphrases::read_for_post( $post_id, $primary );
		$index    = Indexing::inventory_fields( $post_id );
		$labels   = [];
		foreach ( Platforms::all( false ) as $row ) {
			$labels[ $row['key'] ] = $row['label'];
		}
		$permalink = (string) get_permalink( $post );
		$requested = (int) ( $index['gsc_index_requested_at'] ?? 0 );
		$coverage  = (string) ( $index['gsc_coverage'] ?? '' );
		$verdict   = (string) ( $index['gsc_verdict'] ?? '' );
		$crawl     = (string) ( $index['gsc_last_crawl'] ?? '' );
		$indexed   = '' !== $coverage || '' !== $verdict;
		?>
		<h2>
			<?php if ( '' !== $permalink ) : ?>
				<a href="<?php echo esc_url( $permalink ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_the_title( $post ) ); ?></a>
			<?php else : ?>
				<?php echo esc_html( get_the_title( $post ) ); ?>
			<?php endif; ?>
		</h2>

		<h3><?php esc_html_e( 'Focus keyphrases', '4wp-seo-helper' ); ?></h3>
		<?php if ( [] === $phrases ) : ?>
			<p><?php esc_html_e( 'None.', '4wp-seo-helper' ); ?></p>
		<?php else : ?>
			<ul>
				<?php foreach ( $phrases as $phrase ) : ?>
					<li><?php echo esc_html( $phrase ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Indexing', '4wp-seo-helper' ); ?></h3>
		<table class="widefat striped" style="max-width: 720px">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Index requested', '4wp-seo-helper' ); ?></th>
					<td><?php echo esc_html( $requested > 0 ? wp_date( 'Y-m-d H:i', $requested ) : '—' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Indexed', '4wp-seo-helper' ); ?></th>
					<td>
						<?php
						if ( ! $indexed ) {
							esc_html_e( 'Not inspected yet.', '4wp-seo-helper' );
						} else {
							echo esc_html( trim( $verdict . ( '' !== $coverage ? ' · ' . $coverage : '' ) ) );
							if ( '' !== $crawl ) {
								echo ' · ' . esc_html( $crawl );
							}
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Cross posting', '4wp-seo-helper' ); ?></h3>
		<?php $log = Record::read( $post_id ); ?>
		<?php if ( [] === $log ) : ?>
			<p><?php esc_html_e( 'Nothing recorded on this post yet.', '4wp-seo-helper' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width: 720px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Platform', '4wp-seo-helper' ); ?></th>
						<th><?php esc_html_e( 'When', '4wp-seo-helper' ); ?></th>
						<th><?php esc_html_e( 'Slug', '4wp-seo-helper' ); ?></th>
						<th><?php esc_html_e( 'URL', '4wp-seo-helper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $labels[ $row['platform'] ] ?? $row['platform'] ); ?></td>
							<td><?php echo esc_html( self::when( $row['posted_at'] ) ); ?></td>
							<td><code><?php echo esc_html( $row['slug'] ); ?></code></td>
							<td>
								<?php if ( '' !== $row['url'] ) : ?>
									<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['url'] ); ?></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function when( string $iso ): string {
		$stamp = strtotime( $iso );
		if ( false === $stamp ) {
			return '' !== $iso ? $iso : '—';
		}

		return wp_date( 'Y-m-d H:i', $stamp );
	}
}
