<?php
/**
 * SEO inventory query and record assembly.
 */

namespace Forwp\SeoHelper\Inventory;

use Forwp\SeoHelper\Gsc\Indexing;
use Forwp\SeoHelper\Multilingual\Registry as MultilingualRegistry;
use Forwp\SeoHelper\SeoMeta\FocusKeyphrases;
use Forwp\SeoHelper\SeoMeta\Registry as SeoMetaRegistry;
use Forwp\SeoHelper\SeoMeta\YoastAdapter;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Repository {
	private CompletenessScorer $scorer;

	public function __construct( ?CompletenessScorer $scorer = null ) {
		$this->scorer = $scorer ?? new CompletenessScorer();
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function query( array $args = [] ): array {
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page  = min( 999, max( 1, (int) ( $args['per_page'] ?? 50 ) ) );
		$post_type = sanitize_key( (string) ( $args['post_type'] ?? '' ) );
		$taxonomy  = sanitize_key( (string) ( $args['taxonomy'] ?? '' ) );
		$term_id   = absint( $args['term'] ?? 0 );
		$lang      = sanitize_key( (string) ( $args['lang'] ?? '' ) );
		$status    = sanitize_key( (string) ( $args['status'] ?? 'publish' ) );
		$missing   = sanitize_key( (string) ( $args['missing'] ?? '' ) );
		$search    = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$post_id   = absint( $args['post_id'] ?? 0 );
		$min_score = isset( $args['min_score'] ) && '' !== (string) $args['min_score'] ? (int) $args['min_score'] : null;
		$max_score = isset( $args['max_score'] ) && '' !== (string) $args['max_score'] ? (int) $args['max_score'] : null;
		$sort_by_priority    = ! empty( $args['sort_by_priority'] );
		$priority_filter     = $args['priority_filter'] ?? null;
		$has_priority_filter = null !== $priority_filter && '' !== $priority_filter && false !== $priority_filter;
		$orderby             = sanitize_key( (string) ( $args['orderby'] ?? '' ) );
		$order               = strtolower( sanitize_key( (string) ( $args['order'] ?? 'asc' ) ) );
		if ( 'gsc_index_status' !== $orderby ) {
			$orderby = '';
		}
		if ( 'desc' !== $order ) {
			$order = 'asc';
		}

		$has_post_filters = '' !== $missing || null !== $min_score || null !== $max_score || $sort_by_priority || $has_priority_filter;

		$tax_type     = TaxonomyDiscovery::taxonomy_from_type_filter( $post_type );
		$include_posts = '' === $tax_type;
		$include_terms = '' === $post_type || '' !== $tax_type;
		$merge_in_php  = $has_post_filters || $include_terms;

		$records = [];

		if ( $include_posts ) {
			$query_args = [
				'post_type'              => $this->resolve_post_types( $post_type ),
				'post_status'            => $status ?: 'publish',
				'posts_per_page'         => $merge_in_php ? -1 : $per_page,
				'paged'                  => $merge_in_php ? 1 : $page,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => $merge_in_php,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => true,
			];

			if ( $post_id > 0 ) {
				$query_args['post__in']       = [ $post_id ];
				$query_args['posts_per_page'] = 1;
				$query_args['paged']          = 1;
			} elseif ( '' !== $search ) {
				$query_args['s'] = $search;
			}

			$this->prepare_language_query( $query_args, $lang );
			$this->prepare_taxonomy_query( $query_args, $taxonomy, $term_id, $post_type );

			$query = $this->run_query( $query_args, $lang );

			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				$record = $this->build_record( $post );
				if ( null === $record ) {
					continue;
				}

				$records[] = $record;
			}

			$sql_total = (int) $query->found_posts;
		} else {
			$sql_total = 0;
		}

		if ( $include_terms && $post_id <= 0 ) {
			$records = array_merge(
				$records,
				$this->query_term_records( $tax_type, $taxonomy, $term_id, $search, $lang )
			);
		}

		$filtered = [];
		foreach ( $records as $record ) {
			if ( '' !== $missing && ! $this->scorer->matches_missing_filter( $record['missing'], $missing ) ) {
				continue;
			}

			if ( null !== $min_score && $record['completeness'] < $min_score ) {
				continue;
			}

			if ( null !== $max_score && $record['completeness'] > $max_score ) {
				continue;
			}

			$filtered[] = $record;
		}
		$records = $filtered;

		if ( $sort_by_priority && 'gsc_index_status' !== $orderby ) {
			$records = ( new PriorityQueue() )->sort_records( $records );
		}

		if ( 'gsc_index_status' === $orderby ) {
			$records = $this->sort_records_by_index_status( $records, $order );
		}

		if ( $has_priority_filter ) {
			$records = array_values(
				array_filter(
					$records,
					static function ( array $record ) use ( $priority_filter ): bool {
						$priority = isset( $record['priority'] ) ? (int) $record['priority'] : 0;
						if ( 'queued' === $priority_filter ) {
							return $priority >= 1 && $priority <= 3;
						}

						return $priority === (int) $priority_filter;
					}
				)
			);
		}

		if ( $merge_in_php ) {
			$total  = count( $records );
			$offset = ( $page - 1 ) * $per_page;
			$items  = array_slice( $records, $offset, $per_page );
		} else {
			$total = $sql_total;
			$items = $records;
		}

		return [
			'items'       => $items,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
		];
	}

	/**
	 * @param list<array<string, mixed>> $records
	 * @return list<array<string, mixed>>
	 */
	private function sort_records_by_index_status( array $records, string $order ): array {
		$direction = 'desc' === $order ? -1 : 1;

		usort(
			$records,
			static function ( array $left, array $right ) use ( $direction ): int {
				$cmp = Indexing::inventory_status_rank( $left ) <=> Indexing::inventory_status_rank( $right );
				if ( 0 !== $cmp ) {
					return $cmp * $direction;
				}

				$label_cmp = strcasecmp(
					Indexing::inventory_status_label( $left ),
					Indexing::inventory_status_label( $right )
				);
				if ( 0 !== $label_cmp ) {
					return $label_cmp * $direction;
				}

				return ( (int) ( $left['post_id'] ?? 0 ) ) <=> ( (int) ( $right['post_id'] ?? 0 ) );
			}
		);

		return $records;
	}

	/**
	 * Page number (1-based) where a post appears in the priority-sorted inventory.
	 *
	 * @param array<string, mixed> $args Same filters as query(), without post_id/page.
	 */
	public function find_page_for_post( int $post_id, array $args, int $per_page ): int {
		$post_id  = absint( $post_id );
		$per_page = max( 1, $per_page );
		if ( $post_id <= 0 ) {
			return 1;
		}

		$result = $this->query(
			array_merge(
				$args,
				[
					'page'             => 1,
					'per_page'         => 100000,
					'post_id'          => 0,
					'sort_by_priority' => true,
				]
			)
		);

		foreach ( $result['items'] as $index => $item ) {
			if ( (int) ( $item['post_id'] ?? 0 ) === $post_id ) {
				return (int) floor( $index / $per_page ) + 1;
			}
		}

		return 1;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_record( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		return $this->build_record( $post );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_stats( array $args = [] ): array {
		$post_type = sanitize_key( (string) ( $args['post_type'] ?? '' ) );
		$status    = sanitize_key( (string) ( $args['status'] ?? 'publish' ) );

		$query_args = [
			'post_type'              => $this->resolve_post_types( $post_type ),
			'post_status'            => $status ?: 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		$ids = get_posts( $query_args );

		$totals = [
			'posts'            => 0,
			'avg_completeness' => 0,
			'weak_count'       => 0,
			'gap_count'        => 0,
			'weakest'          => [],
			'by_language'      => [],
			'by_post_type'     => [],
			'missing_counts'   => [
				'title'         => 0,
				'description'   => 0,
				'focus_keyword' => 0,
				'og_image'      => 0,
			],
		];

		$score_sum = 0;
		$ranked    = [];

		foreach ( $ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$record = $this->build_record( $post );
			if ( null === $record ) {
				continue;
			}

			++$totals['posts'];
			$score = (int) $record['completeness'];
			$score_sum += $score;
			if ( $score < 50 ) {
				++$totals['weak_count'];
			}
			if ( ! empty( $record['missing'] ) ) {
				++$totals['gap_count'];
			}
			$ranked[] = [
				'post_id'   => (int) $record['post_id'],
				'title'     => (string) $record['wp_title'],
				'score'     => $score,
				'missing'   => $record['missing'],
				'post_type' => (string) $record['post_type'],
			];

			$lang = (string) $record['lang'];
			if ( ! isset( $totals['by_language'][ $lang ] ) ) {
				$totals['by_language'][ $lang ] = [
					'count'            => 0,
					'avg_completeness' => 0,
					'score_sum'        => 0,
				];
			}
			++$totals['by_language'][ $lang ]['count'];
			$totals['by_language'][ $lang ]['score_sum'] += (int) $record['completeness'];

			$type = (string) $record['post_type'];
			if ( ! isset( $totals['by_post_type'][ $type ] ) ) {
				$totals['by_post_type'][ $type ] = [
					'count'            => 0,
					'avg_completeness' => 0,
					'score_sum'        => 0,
				];
			}
			++$totals['by_post_type'][ $type ]['count'];
			$totals['by_post_type'][ $type ]['score_sum'] += (int) $record['completeness'];

			foreach ( $record['missing'] as $field ) {
				if ( isset( $totals['missing_counts'][ $field ] ) ) {
					++$totals['missing_counts'][ $field ];
				}
			}
		}

		if ( $totals['posts'] > 0 ) {
			$totals['avg_completeness'] = (int) round( $score_sum / $totals['posts'] );
		}

		usort(
			$ranked,
			static function ( array $left, array $right ): int {
				return $left['score'] <=> $right['score'];
			}
		);
		$totals['weakest'] = array_slice( $ranked, 0, 8 );

		foreach ( $totals['by_language'] as $lang => $data ) {
			if ( $data['count'] > 0 ) {
				$totals['by_language'][ $lang ]['avg_completeness'] = (int) round( $data['score_sum'] / $data['count'] );
			}
			unset( $totals['by_language'][ $lang ]['score_sum'] );
		}

		foreach ( $totals['by_post_type'] as $type => $data ) {
			if ( $data['count'] > 0 ) {
				$totals['by_post_type'][ $type ]['avg_completeness'] = (int) round( $data['score_sum'] / $data['count'] );
			}
			unset( $totals['by_post_type'][ $type ]['score_sum'] );
		}

		return $totals;
	}

	/**
	 * Published inventory URLs with post_date_gmt in [after, before].
	 */
	public function count_published_between( string $after_gmt, string $before_gmt ): int {
		$query = new \WP_Query(
			[
				'post_type'              => $this->get_supported_post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'date_query'             => [
					[
						'column'    => 'post_date_gmt',
						'after'     => $after_gmt,
						'before'    => $before_gmt,
						'inclusive' => true,
					],
				],
			]
		);

		return (int) $query->found_posts;
	}

	/**
	 * All public CPTs with admin UI — discovered from WordPress, not hardcoded.
	 *
	 * @return list<string>
	 */
	public function get_supported_post_types(): array {
		return PostTypeDiscovery::get_slugs();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function build_record( WP_Post $post ): ?array {
		if ( ! in_array( $post->post_type, $this->get_supported_post_types(), true ) ) {
			return null;
		}

		$adapter  = SeoMetaRegistry::get_active();
		$provider = MultilingualRegistry::get_active();
		$meta     = $adapter->read( $post->ID );
		$phrases  = FocusKeyphrases::read_for_post( $post->ID, (string) ( $meta['focus_keyword'] ?? '' ) );
		$meta['focus_keyphrases'] = $phrases;
		$meta['focus_keyword']    = FocusKeyphrases::primary( $phrases );

		if ( '' === $meta['og_image'] && has_post_thumbnail( $post ) ) {
			$thumb = wp_get_attachment_image_url( get_post_thumbnail_id( $post ), 'full' );
			if ( is_string( $thumb ) ) {
				$meta['og_image'] = $thumb;
			}
		}

		$completeness = $this->scorer->score( $meta );
		$seo_scores   = $adapter->read_scores( $post->ID );

		$seo_title          = (string) ( $meta['title'] ?? '' );
		$seo_description    = (string) ( $meta['description'] ?? '' );
		$seo_title_is_auto  = false;
		$seo_desc_is_auto   = false;
		if ( $adapter instanceof YoastAdapter ) {
			$presented         = $adapter->present( $post, $meta );
			$seo_title         = (string) $presented['title'];
			$seo_description   = (string) $presented['description'];
			$seo_title_is_auto = ! empty( $presented['title_is_auto'] ) && '' !== $seo_title;
			$seo_desc_is_auto  = ! empty( $presented['description_is_auto'] ) && '' !== $seo_description;
		}
		$group        = $provider->get_translation_group( $post->ID );
		$group_ids    = array_map(
			static function ( array $item ): int {
				return (int) $item['post_id'];
			},
			$group
		);

		$slot = ( new PriorityQueue() )->get_post_slot( $post->ID );

		$record = [
			'post_id'            => $post->ID,
			'term_id'            => 0,
			'object_kind'        => 'post',
			'lang'               => $provider->get_post_language( $post->ID ),
			'post_type'          => $post->post_type,
			'status'             => $post->post_status,
			'wp_title'           => get_the_title( $post ),
			'url'                => get_permalink( $post ) ?: '',
			'modified_gmt'       => $post->post_modified_gmt,
			'seo_title'             => $seo_title,
			'seo_title_raw'         => (string) ( $meta['title'] ?? '' ),
			'seo_title_is_auto'     => $seo_title_is_auto,
			'meta_description'      => $seo_description,
			'meta_description_raw'  => (string) ( $meta['description'] ?? '' ),
			'meta_description_is_auto' => $seo_desc_is_auto,
			'focus_keyword'      => $meta['focus_keyword'],
			'focus_keyphrases'   => $phrases,
			'focus_keyphrases_text' => FocusKeyphrases::format( $phrases ),
			'canonical'          => $meta['canonical'],
			'noindex'            => (bool) $meta['noindex'],
			'og_title'           => $meta['og_title'],
			'og_description'     => $meta['og_description'],
			'og_image'           => $meta['og_image'],
			'completeness'       => null !== $seo_scores['seo'] ? (int) $seo_scores['seo'] : $completeness['score'],
			'seo_score'          => $seo_scores['seo'],
			'seo_score_label'    => (string) $seo_scores['label'],
			'readability_score'  => $seo_scores['readability'],
			'seo_no_focus'       => (bool) $seo_scores['no_focus'],
			'missing'            => $completeness['missing'],
			'translation_group'  => $group_ids,
			'seo_adapter'        => $adapter->get_id(),
			'multilingual'       => $provider->get_id(),
			'priority'           => $slot['priority'] ?? null,
			'queue_position'     => isset( $slot['queue_position'] ) ? (int) $slot['queue_position'] : null,
			'taxonomies'         => TaxonomyDiscovery::get_post_groups( $post->ID, $post->post_type ),
		];
		$record['taxonomies_text'] = TaxonomyDiscovery::format_plain( $record['taxonomies'] );

		return array_merge( $record, Indexing::inventory_fields( $post->ID ) );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function query_term_records( string $type_taxonomy, string $filter_taxonomy, int $term_id, string $search, string $lang ): array {
		unset( $lang );

		$slugs = [];
		if ( '' !== $type_taxonomy ) {
			$slugs[] = $type_taxonomy;
		} elseif ( '' !== $filter_taxonomy && TaxonomyDiscovery::is_inventory_archive( $filter_taxonomy ) ) {
			$slugs[] = $filter_taxonomy;
		} else {
			foreach ( TaxonomyDiscovery::get_archive_labeled() as $row ) {
				$slugs[] = $row['slug'];
			}
		}

		$records = [];
		foreach ( array_unique( $slugs ) as $slug ) {
			foreach ( TaxonomyDiscovery::get_inventory_terms( $slug, $search, $term_id ) as $term ) {
				$record = $this->build_term_record( $term );
				if ( null !== $record ) {
					$records[] = $record;
				}
			}
		}

		return $records;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function build_term_record( \WP_Term $term ): ?array {
		$link = get_term_link( $term );
		$url  = is_wp_error( $link ) ? '' : (string) $link;
		if ( '' === $url ) {
			return null;
		}

		$adapter = SeoMetaRegistry::get_active();
		$meta    = [
			'title'           => '',
			'description'     => '',
			'focus_keyword'   => '',
			'canonical'       => '',
			'noindex'         => false,
			'og_title'        => '',
			'og_description'  => '',
			'og_image'        => '',
		];
		if ( $adapter instanceof YoastAdapter ) {
			$meta = $adapter->read_term( $term );
		}

		$phrases = FocusKeyphrases::parse( (string) ( $meta['focus_keyword'] ?? '' ) );
		$meta['focus_keyphrases'] = $phrases;
		$meta['focus_keyword']    = FocusKeyphrases::primary( $phrases );

		$completeness = $this->scorer->score( $meta );
		$seo_title    = (string) ( $meta['title'] ?? '' );
		$seo_desc     = (string) ( $meta['description'] ?? '' );
		$title_auto   = false;
		$desc_auto    = false;
		if ( $adapter instanceof YoastAdapter ) {
			$presented  = $adapter->present_term( $term, $meta );
			$seo_title  = (string) $presented['title'];
			$seo_desc   = (string) $presented['description'];
			$title_auto = ! empty( $presented['title_is_auto'] ) && '' !== $seo_title;
			$desc_auto  = ! empty( $presented['description_is_auto'] ) && '' !== $seo_desc;
		}

		$tax_object = get_taxonomy( $term->taxonomy );
		$type_label = $tax_object instanceof \WP_Taxonomy
			? (string) $tax_object->labels->singular_name
			: $term->taxonomy;

		$parent_groups = [];
		if ( $term->parent > 0 ) {
			$parent = get_term( (int) $term->parent, $term->taxonomy );
			if ( $parent instanceof \WP_Term ) {
				$parent_groups[] = [
					'taxonomy' => $term->taxonomy,
					'label'    => $type_label,
					'terms'    => [
						[
							'id'   => (int) $parent->term_id,
							'name' => (string) $parent->name,
							'slug' => (string) $parent->slug,
						],
					],
				];
			}
		}

		$record = [
			'post_id'                  => 0,
			'term_id'                  => (int) $term->term_id,
			'object_kind'              => 'term',
			'lang'                     => '',
			'post_type'                => $term->taxonomy,
			'status'                   => 'publish',
			'wp_title'                 => (string) $term->name,
			'url'                      => $url,
			'modified_gmt'             => '',
			'seo_title'                => $seo_title,
			'seo_title_raw'            => (string) ( $meta['title'] ?? '' ),
			'seo_title_is_auto'        => $title_auto,
			'meta_description'         => $seo_desc,
			'meta_description_raw'     => (string) ( $meta['description'] ?? '' ),
			'meta_description_is_auto' => $desc_auto,
			'focus_keyword'            => $meta['focus_keyword'],
			'focus_keyphrases'         => $phrases,
			'focus_keyphrases_text'    => FocusKeyphrases::format( $phrases ),
			'canonical'                => $meta['canonical'],
			'noindex'                  => (bool) $meta['noindex'],
			'og_title'                 => $meta['og_title'],
			'og_description'           => $meta['og_description'],
			'og_image'                 => $meta['og_image'],
			'completeness'             => $completeness['score'],
			'seo_score'                => null,
			'seo_score_label'          => '',
			'readability_score'        => null,
			'seo_no_focus'             => '' === (string) $meta['focus_keyword'],
			'missing'                  => $completeness['missing'],
			'translation_group'        => [],
			'seo_adapter'              => $adapter->get_id(),
			'multilingual'             => '',
			'priority'                 => null,
			'queue_position'           => null,
			'taxonomies'               => $parent_groups,
			'gsc_coverage'             => '',
			'gsc_verdict'              => '',
			'gsc_last_crawl'           => '',
			'gsc_index_requested_at'   => 0,
			'gsc_inspect_link'         => '',
			'gsc_inspect_error'        => '',
		];
		$record['taxonomies_text'] = TaxonomyDiscovery::format_plain( $record['taxonomies'] );

		return array_merge( $record, Indexing::inventory_fields( (int) $term->term_id, 'term' ) );
	}

	/**
	 * @return list<string>
	 */
	private function resolve_post_types( string $post_type ): array {
		$supported = $this->get_supported_post_types();

		if ( '' !== $post_type && in_array( $post_type, $supported, true ) ) {
			return [ $post_type ];
		}

		return $supported;
	}

	/**
	 * @param array<string, mixed> $query_args
	 */
	private function prepare_taxonomy_query( array &$query_args, string $taxonomy, int $term_id, string $post_type ): void {
		if ( '' === $taxonomy ) {
			return;
		}

		$allowed = [];
		foreach ( TaxonomyDiscovery::get_labeled( '' === $post_type ? [] : [ $post_type ] ) as $row ) {
			$allowed[ $row['slug'] ] = true;
		}

		if ( ! isset( $allowed[ $taxonomy ] ) ) {
			return;
		}

		$clause = [
			'taxonomy' => $taxonomy,
			'field'    => 'term_id',
		];

		if ( $term_id > 0 ) {
			$term = get_term( $term_id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				return;
			}
			$clause['terms']            = [ $term_id ];
			$clause['include_children'] = true;
		} else {
			$clause['operator'] = 'EXISTS';
		}

		$query_args['tax_query'] = [ $clause ];
	}

	/**
	 * @param array<string, mixed> $query_args
	 */
	private function prepare_language_query( array &$query_args, string $lang ): void {
		if ( '' === $lang ) {
			return;
		}

		$provider = MultilingualRegistry::get_active();

		if ( 'polylang' === $provider->get_id() ) {
			$query_args['lang'] = $lang;
		}

		if ( 'wpml' === $provider->get_id() ) {
			$query_args['suppress_filters'] = false;
		}
	}

	/**
	 * @param array<string, mixed> $query_args
	 */
	private function run_query( array $query_args, string $lang ): \WP_Query {
		$provider = MultilingualRegistry::get_active();
		$previous = null;

		if ( '' !== $lang && 'wpml' === $provider->get_id() && has_action( 'wpml_switch_language' ) ) {
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML third-party hooks.
			$previous = apply_filters( 'wpml_current_language', null );
			do_action( 'wpml_switch_language', $lang );
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

		$query = new \WP_Query( $query_args );

		if ( is_string( $previous ) && '' !== $previous && has_action( 'wpml_switch_language' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML third-party hook.
			do_action( 'wpml_switch_language', $previous );
		}

		return $query;
	}
}
