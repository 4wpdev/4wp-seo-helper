<?php
/**
 * Cross posting dashboard: only posts imported from a meta.json package.
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\CrossPosting\Platforms;
use Forwp\SeoHelper\CrossPosting\Record;
use Forwp\SeoHelper\CrossPosting\Schedule;
use Forwp\SeoHelper\Gsc\Indexing;
use Forwp\SeoHelper\SeoMeta\FocusKeyphrases;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CrossPostPage {
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$ids = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => [ 'publish', 'draft', 'future', 'pending', 'private' ],
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => Schedule::PACKAGE_META,
			]
		);

		$labels = [];
		foreach ( Platforms::all( false ) as $row ) {
			$labels[ $row['key'] ] = $row['label'];
		}

		$cards    = [];
		$posted   = 0;
		$waiting  = 0;
		foreach ( $ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$platforms = self::platforms_for( (int) $post->ID, $labels );
			foreach ( $platforms as $platform ) {
				if ( 'posted' === $platform['status'] ) {
					++$posted;
				} else {
					++$waiting;
				}
			}
			$cards[] = [
				'post'      => $post,
				'platforms' => $platforms,
			];
		}
		?>
		<div class="wrap forwp-seo-admin-shell forwp-seo-admin-shell--wide">
			<header class="forwp-seo-dash__header">
				<div class="forwp-seo-dash__header-main">
					<h1 class="forwp-seo-admin-heading">
						<span class="forwp-seo-admin-heading__text"><?php esc_html_e( 'Cross posting', '4wp-seo-helper' ); ?></span>
					</h1>
					<p class="forwp-seo-dash__lead"><?php esc_html_e( 'Materials imported from a meta.json package.', '4wp-seo-helper' ); ?></p>
				</div>
			</header>
			<?php if ( [] === $cards ) : ?>
				<section class="forwp-seo-pane">
					<div class="forwp-seo-pane__body">
						<p class="forwp-seo-pane__empty"><?php esc_html_e( 'No posts imported from a meta.json package yet.', '4wp-seo-helper' ); ?></p>
					</div>
				</section>
			<?php else : ?>
				<div class="forwp-seo-metric-grid">
					<div class="forwp-seo-metric-card">
						<p class="forwp-seo-metric-card__value"><?php echo esc_html( (string) count( $cards ) ); ?></p>
						<p class="forwp-seo-metric-card__label"><?php esc_html_e( 'Materials', '4wp-seo-helper' ); ?></p>
					</div>
					<div class="forwp-seo-metric-card is-ok">
						<p class="forwp-seo-metric-card__value"><?php echo esc_html( (string) $posted ); ?></p>
						<p class="forwp-seo-metric-card__label"><?php esc_html_e( 'Posted', '4wp-seo-helper' ); ?></p>
					</div>
					<div class="forwp-seo-metric-card<?php echo $waiting > 0 ? ' is-warn' : ''; ?>">
						<p class="forwp-seo-metric-card__value"><?php echo esc_html( (string) $waiting ); ?></p>
						<p class="forwp-seo-metric-card__label"><?php esc_html_e( 'Still to post', '4wp-seo-helper' ); ?></p>
					</div>
				</div>
				<div class="forwp-seo-xpost">
					<?php foreach ( $cards as $card ) : ?>
						<?php self::render_card( $card['post'], $card['platforms'] ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param list<array{key: string, label: string, status: string, url: string, slug: string, posted_at: string}> $platforms
	 */
	private static function render_card( WP_Post $post, array $platforms ): void {
		$post_id   = (int) $post->ID;
		$primary   = (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
		$phrases   = FocusKeyphrases::read_for_post( $post_id, $primary );
		$index     = Indexing::inventory_fields( $post_id );
		$permalink = (string) get_permalink( $post );
		$edit      = (string) get_edit_post_link( $post_id, 'raw' );
		$requested = (int) ( $index['gsc_index_requested_at'] ?? 0 );
		$coverage  = (string) ( $index['gsc_coverage'] ?? '' );
		$verdict   = (string) ( $index['gsc_verdict'] ?? '' );
		$crawl     = (string) ( $index['gsc_last_crawl'] ?? '' );
		$indexed   = '' !== $coverage || '' !== $verdict;
		$status    = get_post_status_object( $post->post_status );
		$when      = get_post_timestamp( $post );
		?>
		<article class="forwp-seo-pane forwp-seo-xpost__card">
			<header class="forwp-seo-pane__header forwp-seo-xpost__head">
				<div>
					<h2 class="forwp-seo-xpost__title">
						<?php if ( '' !== $permalink ) : ?>
							<a href="<?php echo esc_url( $permalink ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_the_title( $post ) ); ?></a>
						<?php else : ?>
							<?php echo esc_html( get_the_title( $post ) ); ?>
						<?php endif; ?>
					</h2>
					<p class="forwp-seo-xpost__meta">
						<?php if ( $status ) : ?>
							<span class="forwp-seo-badge <?php echo 'publish' === $post->post_status ? 'forwp-seo-badge--live' : 'forwp-seo-badge--off'; ?>"><?php echo esc_html( $status->label ); ?></span>
						<?php endif; ?>
						<?php if ( $when ) : ?>
							<time datetime="<?php echo esc_attr( wp_date( 'c', $when ) ); ?>"><?php echo esc_html( wp_date( 'j M Y', $when ) ); ?></time>
						<?php endif; ?>
						<?php if ( '' !== $edit ) : ?>
							<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', '4wp-seo-helper' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
			</header>
			<div class="forwp-seo-pane__body">
				<div class="forwp-seo-xpost__facts">
					<div class="forwp-seo-xpost__fact">
						<span class="forwp-seo-xpost__fact-label"><?php esc_html_e( 'Focus keyphrases', '4wp-seo-helper' ); ?></span>
						<?php if ( [] === $phrases ) : ?>
							<p class="forwp-seo-xpost__muted"><?php esc_html_e( 'None.', '4wp-seo-helper' ); ?></p>
						<?php else : ?>
							<ul class="forwp-seo-xpost__chips">
								<?php foreach ( $phrases as $phrase ) : ?>
									<li><?php echo esc_html( $phrase ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
					<div class="forwp-seo-xpost__fact">
						<span class="forwp-seo-xpost__fact-label"><?php esc_html_e( 'Index requested', '4wp-seo-helper' ); ?></span>
						<p class="forwp-seo-xpost__value"><?php echo esc_html( $requested > 0 ? wp_date( 'j M Y, H:i', $requested ) : '—' ); ?></p>
					</div>
					<div class="forwp-seo-xpost__fact<?php echo $indexed ? ' is-ok' : ''; ?>">
						<span class="forwp-seo-xpost__fact-label"><?php esc_html_e( 'Indexed', '4wp-seo-helper' ); ?></span>
						<p class="forwp-seo-xpost__value">
							<?php
							if ( ! $indexed ) {
								esc_html_e( 'Not inspected yet.', '4wp-seo-helper' );
							} else {
								echo esc_html( trim( $verdict . ( '' !== $coverage ? ' · ' . $coverage : '' ) ) );
								if ( '' !== $crawl ) {
									echo '<span class="forwp-seo-xpost__sub">' . esc_html( $crawl ) . '</span>';
								}
							}
							?>
						</p>
					</div>
				</div>
				<?php if ( [] === $platforms ) : ?>
					<p class="forwp-seo-pane__empty"><?php esc_html_e( 'No platforms in this package.', '4wp-seo-helper' ); ?></p>
				<?php else : ?>
					<ul class="forwp-seo-xpost__platforms">
						<?php foreach ( $platforms as $platform ) : ?>
							<?php $state = self::platform_state( $platform['status'] ); ?>
							<li class="forwp-seo-xpost__platform is-<?php echo esc_attr( $state ); ?>">
								<div class="forwp-seo-xpost__platform-top">
									<strong><?php echo esc_html( $platform['label'] ); ?></strong>
									<span class="forwp-seo-badge <?php echo esc_attr( self::badge_class( $state ) ); ?>"><?php echo esc_html( self::status_label( $platform['status'] ) ); ?></span>
								</div>
								<?php $stamp = self::when( $platform['posted_at'] ); ?>
								<?php if ( '' !== $stamp ) : ?>
									<span class="forwp-seo-xpost__when"><?php echo esc_html( $stamp ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $platform['url'] ) : ?>
									<a class="forwp-seo-xpost__link" href="<?php echo esc_url( $platform['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( '' !== $platform['slug'] ? $platform['slug'] : __( 'Open', '4wp-seo-helper' ) ); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}

	/**
	 * Enabled platforms declared in meta.json, with the local publish log overlaid.
	 *
	 * @param array<string, string> $labels
	 * @return list<array{key: string, label: string, status: string, url: string, slug: string, posted_at: string}>
	 */
	private static function platforms_for( int $post_id, array $labels ): array {
		$logged = [];
		foreach ( Record::read( $post_id ) as $row ) {
			$logged[ $row['platform'] ] = $row;
		}

		$package  = apply_filters( 'forwp_drive_package', null, $post_id );
		$declared = ( is_array( $package ) && isset( $package['platforms'] ) && is_array( $package['platforms'] ) ) ? $package['platforms'] : [];

		$rows = [];
		$seen = [];
		foreach ( Platforms::all() as $platform ) {
			$key = $platform['key'];
			if ( ! isset( $declared[ $key ] ) || ! is_array( $declared[ $key ] ) ) {
				continue;
			}
			$rows[] = self::platform_row( $key, $labels, $declared[ $key ], $logged[ $key ] ?? null );
			$seen[ $key ] = true;
		}

		foreach ( $logged as $key => $row ) {
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$rows[] = self::platform_row( $key, $labels, [], $row );
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed>                                                    $entry Package platform block.
	 * @param array{platform: string, url: string, slug: string, posted_at: string}|null $local Local log row.
	 * @param array<string, string>                                                   $labels
	 * @return array{key: string, label: string, status: string, url: string, slug: string, posted_at: string}
	 */
	private static function platform_row( string $key, array $labels, array $entry, ?array $local ): array {
		$status    = (string) ( $entry['status'] ?? '' );
		$url       = (string) ( $entry['url'] ?? '' );
		$slug      = (string) ( $entry['slug'] ?? '' );
		$posted_at = (string) ( $entry['posted_at'] ?? '' );

		if ( is_array( $local ) ) {
			if ( '' !== $local['url'] ) {
				$url = $local['url'];
			}
			if ( '' !== $local['slug'] ) {
				$slug = $local['slug'];
			}
			if ( '' !== $local['posted_at'] ) {
				$posted_at = $local['posted_at'];
				$status    = 'posted';
			}
		}

		if ( '' === $status ) {
			$status = 'open';
		}

		return [
			'key'       => $key,
			'label'     => $labels[ $key ] ?? $key,
			'status'    => $status,
			'url'       => $url,
			'slug'      => $slug,
			'posted_at' => $posted_at,
		];
	}

	private static function platform_state( string $status ): string {
		if ( 'posted' === $status ) {
			return 'posted';
		}
		if ( in_array( $status, [ 'ready', 'scheduled' ], true ) ) {
			return 'ready';
		}

		return 'open';
	}

	private static function badge_class( string $state ): string {
		if ( 'posted' === $state ) {
			return 'forwp-seo-badge--live';
		}
		if ( 'ready' === $state ) {
			return 'forwp-seo-badge--warn';
		}

		return 'forwp-seo-badge--off';
	}

	private static function status_label( string $status ): string {
		$labels = [
			'posted'    => __( 'Posted', '4wp-seo-helper' ),
			'ready'     => __( 'Ready', '4wp-seo-helper' ),
			'draft'     => __( 'Draft', '4wp-seo-helper' ),
			'open'      => __( 'Open', '4wp-seo-helper' ),
			'scheduled' => __( 'Scheduled', '4wp-seo-helper' ),
			'skipped'   => __( 'Skipped', '4wp-seo-helper' ),
		];

		return $labels[ $status ] ?? $status;
	}

	private static function when( string $iso ): string {
		if ( '' === $iso ) {
			return '';
		}
		$stamp = strtotime( $iso );
		if ( false === $stamp ) {
			return $iso;
		}

		return wp_date( 'j M Y, H:i', $stamp );
	}
}
