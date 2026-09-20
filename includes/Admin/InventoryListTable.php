<?php
/**
 * SEO inventory admin list table.
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\Core\Release;
use Forwp\SeoHelper\Gsc\Admin as GscAdmin;
use Forwp\SeoHelper\Gsc\Module as GscModule;
use Forwp\SeoHelper\Gsc\PageMetrics;
use Forwp\SeoHelper\Gsc\PropertyResolver;
use Forwp\SeoHelper\Gsc\ReportPeriod;
use Forwp\SeoHelper\Inventory\PriorityLabels;
use Forwp\SeoHelper\Inventory\Repository;
use Forwp\SeoHelper\SeoMeta\Registry as SeoMetaRegistry;
use Forwp\SeoHelper\Trends\Parser as TrendsParser;
use Forwp\SeoHelper\Trends\Repository as TrendsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class InventoryListTable extends \WP_List_Table {
	private Repository $repository;

	/** @var array<string, mixed> */
	private array $filters = [];

	private bool $show_language = false;

	/** @var string */
	private string $redirect_base = '';

	/** @var int|null|string null = all groups, 1-3 = single, 'queued' = P1-P3 only */
	private $priority_filter = null;

	private bool $show_gsc_metrics = false;

	private bool $show_gsc_indexing = false;

	/** @var array<string, array{interest: float, delta: float, snapshot: string, region: string}>|null */
	private ?array $trends_by_query = null;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;

		parent::__construct(
			[
				'singular' => 'seo_inventory_item',
				'plural'   => 'seo_inventory_items',
				'ajax'     => false,
			]
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	public function set_filters( array $filters ): void {
		$this->filters = $filters;
	}

	public function set_show_language( bool $show ): void {
		$this->show_language = $show;
	}

	public function set_redirect_base( string $url ): void {
		$this->redirect_base = $url;
	}

	/**
	 * @param int|null|string $priority null, 1–3, or 'queued'.
	 */
	public function set_priority_filter( $priority ): void {
		$this->priority_filter = $priority;
	}

	public function set_show_gsc_metrics( bool $show ): void {
		$this->show_gsc_metrics = $show;
	}

	public function set_show_gsc_indexing( bool $show ): void {
		$this->show_gsc_indexing = $show;
	}

	private function current_orderby(): string {
		$sortable = $this->get_sortable_columns();
		if ( empty( $sortable ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list table sort.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : '';
		if ( '' === $orderby || ! isset( $sortable[ $orderby ] ) ) {
			return '';
		}

		return $orderby;
	}

	private function current_order(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list table sort.
		$order = isset( $_GET['order'] ) ? strtolower( sanitize_key( wp_unslash( (string) $_GET['order'] ) ) ) : 'asc';

		return 'desc' === $order ? 'desc' : 'asc';
	}

	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="post_ids[]" value="%s" />',
			esc_attr( (string) (int) $item['post_id'] )
		);
	}

	protected function get_bulk_actions(): array {
		return [
			'priority_p1'    => sprintf(
				/* translators: %s: formatted priority tier label */
				__( 'Set %s', '4wp-seo-helper' ),
				PriorityLabels::get_formatted( 1 )
			),
			'priority_p2'    => sprintf(
				/* translators: %s: formatted priority tier label */
				__( 'Set %s', '4wp-seo-helper' ),
				PriorityLabels::get_formatted( 2 )
			),
			'priority_p3'    => sprintf(
				/* translators: %s: formatted priority tier label */
				__( 'Set %s', '4wp-seo-helper' ),
				PriorityLabels::get_formatted( 3 )
			),
			'priority_clear' => __( 'Clear priority', '4wp-seo-helper' ),
		];
	}

	public function get_columns(): array {
		$columns = [
			'post_id'          => __( 'ID', '4wp-seo-helper' ),
			'wp_title'         => __( 'Title', '4wp-seo-helper' ),
			'post_type'        => __( 'Type', '4wp-seo-helper' ),
			'taxonomies'       => __( 'Taxonomies', '4wp-seo-helper' ),
			'seo_title'        => __( 'SEO title', '4wp-seo-helper' ),
			'meta_description' => __( 'Meta description', '4wp-seo-helper' ),
			'focus_keyword'    => __( 'Focus keyphrases', '4wp-seo-helper' ),
			'trends'           => '<span title="' . esc_attr__( 'Not site traffic. Numbers come from an imported Google Trends file. A cell is filled only when a focus keyphrase exactly matches a query in that file.', '4wp-seo-helper' ) . '">' . esc_html__( 'Trends', '4wp-seo-helper' ) . '</span>',
			'og_image'         => __( 'OG image', '4wp-seo-helper' ),
			'completeness'     => $this->get_score_column_label(),
			'missing'          => __( 'Missing', '4wp-seo-helper' ),
		];

		if ( $this->show_gsc_indexing ) {
			$index_cols = [
				'gsc_index_status'    => '<span title="' . esc_attr__( 'Index status', '4wp-seo-helper' ) . '">' . esc_html__( 'Index', '4wp-seo-helper' ) . '</span>',
				'gsc_index_requested' => __( 'Index requested', '4wp-seo-helper' ),
				'gsc_last_crawl'      => __( 'Last crawl', '4wp-seo-helper' ),
				'gsc_actions'         => __( 'Index actions', '4wp-seo-helper' ),
			];
			$columns = array_merge(
				array_slice( $columns, 0, -2, true ),
				$index_cols,
				array_slice( $columns, -2, null, true )
			);
		}

		if ( $this->show_gsc_metrics ) {
			$range_label = ReportPeriod::label();
			$columns['gsc'] = sprintf(
				/* translators: %s: date range label, e.g. Last 28 days */
				__( 'Search Console (%s)', '4wp-seo-helper' ),
				$range_label
			);
		}

		if ( $this->show_language ) {
			$columns = array_merge(
				array_slice( $columns, 0, 2, true ),
				[ 'lang' => __( 'Lang', '4wp-seo-helper' ) ],
				array_slice( $columns, 2, null, true )
			);
		}

		return $columns;
	}

	protected function get_sortable_columns(): array {
		if ( ! $this->show_gsc_indexing ) {
			return [];
		}

		return [
			'gsc_index_status' => [ 'gsc_index_status', false ],
		];
	}

	protected function get_primary_column_name(): string {
		return 'wp_title';
	}

	public function prepare_items(): void {
		$this->process_bulk_action();

		$per_page = $this->get_items_per_page( Menu::INVENTORY_PER_PAGE_OPTION, Menu::INVENTORY_PER_PAGE_DEFAULT );
		$page     = $this->get_pagenum();

		$columns  = $this->get_columns();
		$hidden   = get_hidden_columns( $this->screen );
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = [ $columns, $hidden, $sortable ];

		$result = $this->repository->query(
			[
				'page'             => $page,
				'per_page'         => $per_page,
				'post_type'        => (string) ( $this->filters['post_type'] ?? '' ),
				'taxonomy'         => (string) ( $this->filters['taxonomy'] ?? '' ),
				'term'             => ! empty( $this->filters['term'] ) ? (int) $this->filters['term'] : 0,
				'lang'             => (string) ( $this->filters['lang'] ?? '' ),
				'status'           => (string) ( $this->filters['status'] ?? 'publish' ),
				'missing'          => (string) ( $this->filters['missing'] ?? '' ),
				'max_score'        => ! empty( $this->filters['max_score'] ) ? (int) $this->filters['max_score'] : null,
				'search'           => (string) ( $this->filters['search'] ?? '' ),
				'post_id'          => ! empty( $this->filters['post_id'] ) ? (int) $this->filters['post_id'] : 0,
				'sort_by_priority' => true,
				'priority_filter'  => $this->priority_filter,
				'orderby'          => $this->current_orderby(),
				'order'            => $this->current_order(),
			]
		);

		if ( $this->show_gsc_metrics ) {
			$result['items'] = ( new PageMetrics() )->enrich_records(
				$result['items'],
				GscAdmin::get_site_property(),
				ReportPeriod::get_days()
			);
		}

		$this->items = $result['items'];
		$this->set_pagination_args(
			[
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			]
		);
	}

	public function display_rows(): void {
		$groups = $this->bucket_items_by_priority();

		if ( empty( $groups ) && empty( $this->items ) ) {
			return;
		}

		$labels = PriorityLabels::get_group_labels();

		$order = $this->get_group_order();

		foreach ( $order as $priority ) {
			if ( ! array_key_exists( $priority, $groups ) ) {
				continue;
			}

			$this->render_priority_group_header( $priority, $labels[ $priority ], $groups[ $priority ] );

			if ( empty( $groups[ $priority ] ) ) {
				$this->render_priority_group_empty( $priority );
				continue;
			}

			foreach ( $groups[ $priority ] as $item ) {
				$this->single_row( $item );
			}
		}
	}

	/**
	 * @return list<int>
	 */
	private function get_group_order(): array {
		if ( is_int( $this->priority_filter ) && $this->priority_filter >= 1 && $this->priority_filter <= 3 ) {
			return [ $this->priority_filter ];
		}

		if ( 'queued' === $this->priority_filter ) {
			return [ 1, 2, 3 ];
		}

		return [ 1, 2, 3, 0 ];
	}

	/**
	 * @return array<int, list<array<string, mixed>>>
	 */
	private function bucket_items_by_priority(): array {
		$groups = [
			1 => [],
			2 => [],
			3 => [],
			0 => [],
		];

		foreach ( $this->items as $item ) {
			$priority = isset( $item['priority'] ) ? (int) $item['priority'] : 0;
			if ( $priority < 1 || $priority > 3 ) {
				$priority = 0;
			}

			if ( is_int( $this->priority_filter ) && $this->priority_filter >= 1 && $this->priority_filter <= 3 ) {
				if ( $priority !== $this->priority_filter ) {
					continue;
				}
			} elseif ( 'queued' === $this->priority_filter && 0 === $priority ) {
				continue;
			}

			$groups[ $priority ][] = $item;
		}

		if ( is_int( $this->priority_filter ) && $this->priority_filter >= 1 && $this->priority_filter <= 3 ) {
			return [ $this->priority_filter => $groups[ $this->priority_filter ] ];
		}

		if ( 'queued' === $this->priority_filter ) {
			return [
				1 => $groups[1],
				2 => $groups[2],
				3 => $groups[3],
			];
		}

		return $groups;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	private function render_priority_group_header( int $priority, string $label, array $items ): void {
		$collapsible = $priority >= 0 && $priority <= 3;
		$count       = count( $items );
		$stats       = $this->compute_group_stats( $items );
		$classes     = 'forwp-seo-priority-group forwp-seo-priority-group--p' . $priority;
		if ( $collapsible ) {
			$classes .= ' forwp-seo-priority-group--collapsible';
		}

		echo '<tr class="' . esc_attr( $classes ) . '" data-priority="' . esc_attr( (string) $priority ) . '" data-dropzone="1"';
		if ( $collapsible ) {
			echo ' aria-expanded="true" role="button" tabindex="0"';
		}
		echo '>';
		echo '<td colspan="' . esc_attr( (string) $this->get_table_column_count() ) . '">';
		echo '<div class="forwp-seo-priority-group__bar">';
		echo '<div class="forwp-seo-priority-group__main">';
		if ( $collapsible ) {
			echo '<span class="forwp-seo-priority-group__chevron" aria-hidden="true">▾</span>';
		}
		echo '<span class="forwp-seo-priority-group__label">' . esc_html( $label ) . '</span>';
		echo '<span class="forwp-seo-priority-group__count">' . esc_html( (string) $count ) . '</span>';
		if ( $priority >= 1 && $priority <= 3 ) {
			echo '<span class="forwp-seo-priority-group__hint">' . esc_html__( 'Drop rows here', '4wp-seo-helper' ) . '</span>';
		}
		echo '</div>';
		echo '<div class="forwp-seo-priority-group__stats">';
		if ( $count > 0 ) {
			printf(
				'<span class="forwp-seo-priority-group__stat forwp-seo-priority-group__avg">%s</span>',
				esc_html(
					sprintf(
						/* translators: %d: average completeness percent */
						__( 'Avg %d%%', '4wp-seo-helper' ),
						$stats['avg']
					)
				)
			);
			echo '<span class="forwp-seo-priority-group__stat forwp-seo-priority-group__gaps"' . ( $stats['gaps'] > 0 ? '' : ' hidden' ) . '>';
			printf(
				esc_html(
					/* translators: %d: number of items with SEO gaps */
					_n(
						'%d with gaps',
						'%d with gaps',
						$stats['gaps'],
						'4wp-seo-helper'
					)
				),
				absint( $stats['gaps'] )
			);
			echo '</span>';
		}
		echo '</div>';
		echo '</div>';
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * @param list<array<string, mixed>> $items
	 *
	 * @return array{avg: int, gaps: int}
	 */
	private function compute_group_stats( array $items ): array {
		if ( empty( $items ) ) {
			return [
				'avg'  => 0,
				'gaps' => 0,
			];
		}

		$sum  = 0;
		$gaps = 0;

		foreach ( $items as $item ) {
			$sum += (int) ( $item['completeness'] ?? 0 );
			$missing = is_array( $item['missing'] ?? null ) ? $item['missing'] : [];
			if ( ! empty( $missing ) ) {
				++$gaps;
			}
		}

		return [
			'avg'  => (int) round( $sum / count( $items ) ),
			'gaps' => $gaps,
		];
	}

	private function render_priority_group_empty( int $priority ): void {
		echo '<tr class="forwp-seo-priority-group-empty" data-priority-group="' . esc_attr( (string) $priority ) . '" data-dropzone="1">';
		echo '<td colspan="' . esc_attr( (string) $this->get_table_column_count() ) . '">';
		echo esc_html__( 'No items — drop here', '4wp-seo-helper' );
		echo '</td>';
		echo '</tr>';
	}

	private function get_table_column_count(): int {
		$columns = $this->get_columns();
		$count   = count( $columns ) + 1;

		return max( 1, $count );
	}

	private function get_priority_redirect_url(): string {
		if ( '' !== $this->redirect_base ) {
			return $this->redirect_base;
		}

		return admin_url( 'admin.php?page=' . Menu::INVENTORY_PAGE_SLUG );
	}

	protected function process_bulk_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = $this->current_action();
		if ( ! $action ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		$priority_map = [
			'priority_p1'    => 1,
			'priority_p2'    => 2,
			'priority_p3'    => 3,
			'priority_clear' => null,
		];

		if ( ! array_key_exists( $action, $priority_map ) ) {
			return;
		}

		$post_ids = array_map( 'intval', (array) wp_unslash( $_REQUEST['post_ids'] ?? [] ) );
		$post_ids = array_values( array_filter( $post_ids ) );
		if ( empty( $post_ids ) ) {
			return;
		}

		$queue    = new \Forwp\SeoHelper\Inventory\PriorityQueue( $this->repository );
		$priority = $priority_map[ $action ];

		foreach ( $post_ids as $post_id ) {
			if ( null === $this->repository->get_record( $post_id ) ) {
				continue;
			}
			$queue->assign_post( $post_id, $priority );
		}

		\Forwp\SeoHelper\Inventory\PriorityQueue::reset_cache();

		$redirect = add_query_arg( 'forwp_priority_updated', count( $post_ids ), $this->get_priority_redirect_url() );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_wp_title( $item ): string {
		$title = (string) $item['wp_title'];
		$url   = (string) $item['url'];
		$priority = isset( $item['priority'] ) ? (int) $item['priority'] : 0;

		$output = '<span class="forwp-seo-row-drag" aria-hidden="true" title="' . esc_attr__( 'Drag to reorder or change priority', '4wp-seo-helper' ) . '">⠿</span> ';

		if ( $priority >= 1 && $priority <= 3 ) {
			$output .= sprintf(
				'<span class="forwp-seo-priority-badge forwp-seo-priority-badge--p%d" title="%s">%s</span> ',
				$priority,
				esc_attr( PriorityLabels::get_formatted( $priority ) ),
				esc_html( PriorityLabels::get_badge( $priority ) )
			);
		}

		if ( '' !== $url ) {
			$output .= '<strong><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a></strong>';
		} else {
			$output .= '<strong>' . esc_html( $title ) . '</strong>';
		}

		$post_id = (int) $item['post_id'];

		$actions = [
			'inline' => sprintf(
				'<button type="button" class="button-link forwp-seo-quick-edit-trigger" aria-label="%1$s" aria-expanded="false">%2$s</button>',
				esc_attr(
					sprintf(
						/* translators: %s: post title */
						__( 'Quick edit “%s” inline', '4wp-seo-helper' ),
						$title
					)
				),
				esc_html__( 'Quick Edit', '4wp-seo-helper' )
			),
		];

		$edit_link = get_edit_post_link( $post_id );
		if ( is_string( $edit_link ) ) {
			$actions['edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_link ),
				esc_html__( 'Edit', '4wp-seo-helper' )
			);
		}

		if ( '' !== $url ) {
			$actions['view'] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $url ),
				esc_html__( 'View', '4wp-seo-helper' )
			);
		}

		$actions['dynamics'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( DynamicsPage::url_for_post( $post_id ) ),
			esc_html__( 'Dynamics', '4wp-seo-helper' )
		);

		return $output . $this->row_actions( $actions, true );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function single_row( $item ): void {
		$priority = isset( $item['priority'] ) ? (int) $item['priority'] : 0;
		if ( $priority < 1 || $priority > 3 ) {
			$priority = 0;
		}

		$data = wp_json_encode(
			[
				'post_id'               => (int) $item['post_id'],
				'seo_title'             => (string) ( $item['seo_title'] ?? '' ),
				'seo_title_raw'         => (string) ( $item['seo_title_raw'] ?? '' ),
				'seo_title_is_auto'     => ! empty( $item['seo_title_is_auto'] ),
				'meta_description'      => (string) ( $item['meta_description'] ?? '' ),
				'meta_description_raw'  => (string) ( $item['meta_description_raw'] ?? '' ),
				'meta_description_is_auto' => ! empty( $item['meta_description_is_auto'] ),
				'focus_keyword'         => (string) ( $item['focus_keyword'] ?? '' ),
				'focus_keyphrases_text' => (string) ( $item['focus_keyphrases_text'] ?? '' ),
				'og_image'              => (string) ( $item['og_image'] ?? '' ),
				'gsc_clicks'            => (int) ( $item['gsc_clicks'] ?? 0 ),
				'gsc_impressions'       => (int) ( $item['gsc_impressions'] ?? 0 ),
				'gsc_position'          => (float) ( $item['gsc_position'] ?? 0 ),
				'gsc_ctr'               => (float) ( $item['gsc_ctr'] ?? 0 ),
				'gsc_top_queries'       => is_array( $item['gsc_top_queries'] ?? null ) ? $item['gsc_top_queries'] : [],
			]
		);

		echo '<tr id="forwp-seo-row-' . esc_attr( (string) (int) $item['post_id'] ) . '" class="forwp-seo-inventory-row forwp-seo-inventory-row--draggable';
		if ( ! empty( $item['priority'] ) ) {
			echo ' forwp-seo-inventory-row--queued';
		}
		echo '" data-post-id="' . esc_attr( (string) (int) $item['post_id'] ) . '"';
		echo ' data-priority="' . esc_attr( (string) $priority ) . '"';
		echo ' data-priority-group="' . esc_attr( (string) $priority ) . '"';
		if ( is_string( $data ) ) {
			echo ' data-item="' . esc_attr( $data ) . '"';
		}
		echo '>';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_og_image( $item ): string {
		$url = (string) ( $item['og_image'] ?? '' );

		if ( '' === $url ) {
			return '<span class="forwp-seo-og-thumb forwp-seo-og-thumb--empty" aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No OG image', '4wp-seo-helper' ) . '</span>';
		}

		return sprintf(
			'<a href="%1$s" class="forwp-seo-og-thumb" target="_blank" rel="noopener noreferrer">' .
			'<img src="%1$s" alt="" width="48" height="48" loading="lazy" decoding="async" />' .
			'<span class="screen-reader-text">%2$s</span>' .
			'</a>',
			esc_url( $url ),
			esc_html__( 'View OG image', '4wp-seo-helper' )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'post_id':
				return $this->render_post_id_cell( $item );

			case 'completeness':
				return $this->render_score_cell( $item );

			case 'post_type':
				return $this->render_post_type_cell( (string) ( $item['post_type'] ?? '' ) );

			case 'taxonomies':
				return $this->render_taxonomies_cell( $item );

			case 'gsc':
				return $this->render_gsc_cell( $item );

			case 'gsc_index_status':
				return $this->render_gsc_index_status_cell( $item );

			case 'gsc_index_requested':
				return $this->render_gsc_index_requested_cell( $item );

			case 'gsc_last_crawl':
				return $this->render_gsc_last_crawl_cell( $item );

			case 'gsc_actions':
				return $this->render_gsc_actions_cell( $item );

			case 'missing':
				$missing = is_array( $item['missing'] ?? null ) ? $item['missing'] : [];
				return esc_html( implode( ', ', $missing ) );

			case 'gsc_clicks':
			case 'gsc_impressions':
				unset( $item );
				return '';

			case 'seo_title':
				return $this->render_text_excerpt_cell(
					(string) ( $item['seo_title'] ?? '' ),
					false,
					! empty( $item['seo_title_is_auto'] )
				);

			case 'meta_description':
				return $this->render_text_excerpt_cell(
					(string) ( $item['meta_description'] ?? '' ),
					false,
					! empty( $item['meta_description_is_auto'] )
				);

			case 'focus_keyword':
				return $this->render_focus_keyphrases_cell(
					(string) ( $item['focus_keyphrases_text'] ?? $item['focus_keyword'] ?? '' )
				);

			case 'trends':
				return $this->render_trends_cell( $item );

			default:
				return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
		}
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function render_trends_cell( array $item ): string {
		if ( null === $this->trends_by_query ) {
			$this->trends_by_query = TrendsRepository::latest_by_query();
		}

		if ( empty( $this->trends_by_query ) ) {
			return '—';
		}

		$phrases = preg_split( '/\r\n|\r|\n/', (string) ( $item['focus_keyphrases_text'] ?? $item['focus_keyword'] ?? '' ) );
		$phrases = is_array( $phrases ) ? $phrases : [];
		$hit     = null;

		foreach ( $phrases as $phrase ) {
			$key = TrendsParser::normalize_query( (string) $phrase );
			if ( '' !== $key && isset( $this->trends_by_query[ $key ] ) ) {
				$hit = $this->trends_by_query[ $key ];
				break;
			}
		}

		if ( ! is_array( $hit ) ) {
			return '—';
		}

		$interest = (float) ( $hit['interest'] ?? 0 );
		$interest_label = fmod( $interest, 1.0 ) === 0.0
			? (string) (int) $interest
			: (string) $interest;

		$delta       = (float) ( $hit['delta'] ?? 0 );
		$delta_label = (string) ( $hit['delta_label'] ?? '' );
		if ( '' === $delta_label ) {
			$arrow       = $delta > 0 ? '↑' : ( $delta < 0 ? '↓' : '→' );
			$delta_label = $arrow . ' ' . ( 0.0 === $delta ? '0' : (string) $delta );
		}

		$delta_class = $delta > 0 ? 'is-up' : ( $delta < 0 ? 'is-down' : 'is-flat' );
		if ( false !== stripos( $delta_label, 'breakout' ) ) {
			$delta_class = 'is-up';
		}

		$title = sprintf(
			/* translators: 1: query, 2: region, 3: YYYY-MM, 4: interest, 5: delta */
			__( 'Matched focus keyphrase “%1$s” to imported Trends query (%2$s, %3$s). %4$s/100 = relative interest in that file (100 = strongest query there, not search volume). %5$s = change (CSV increase %% until a second month exists).', '4wp-seo-helper' ),
			(string) ( $hit['query'] ?? '' ),
			(string) ( $hit['region'] ?? '' ),
			(string) ( $hit['snapshot'] ?? '' ),
			$interest_label,
			$delta_label
		);

		$query = (string) ( $hit['query'] ?? '' );

		return sprintf(
			'<span class="forwp-seo-trends" title="%1$s"><span class="forwp-seo-trends__query">%2$s</span><span class="forwp-seo-trends__interest">%3$s/100</span><span class="forwp-seo-trends__delta %4$s">%5$s</span></span>',
			esc_attr( $title ),
			esc_html( $query ),
			esc_html( $interest_label ),
			esc_attr( $delta_class ),
			esc_html( $delta_label )
		);
	}

	public function no_items(): void {
		esc_html_e( 'No posts found for the current filters.', '4wp-seo-helper' );
	}

	private function get_score_column_label(): string {
		$adapter = SeoMetaRegistry::get_active();
		if ( 'none' === $adapter->get_id() ) {
			return __( 'Completeness', '4wp-seo-helper' );
		}

		return sprintf(
			/* translators: %s: SEO plugin name, e.g. Yoast SEO */
			__( 'SEO score (%s)', '4wp-seo-helper' ),
			$adapter->get_label()
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function render_score_cell( array $item ): string {
		$label_text = (string) ( $item['seo_score_label'] ?? '' );
		$no_focus   = ! empty( $item['seo_no_focus'] );
		$seo_score  = $item['seo_score'] ?? null;

		if ( $no_focus && ( null === $seo_score || 0 === (int) $seo_score ) ) {
			return '<span class="forwp-seo-score forwp-seo-score--na" title="' . esc_attr( $label_text ) . '">' . esc_html( $label_text ?: __( 'No focus keyphrase', '4wp-seo-helper' ) ) . '</span>';
		}

		$score = null !== $seo_score ? (int) $seo_score : (int) ( $item['completeness'] ?? 0 );
		$class = $score >= 71 ? 'good' : ( $score >= 41 ? 'medium' : 'low' );
		$title = $label_text ?: '';

		return '<span class="forwp-seo-score forwp-seo-score--' . esc_attr( $class ) . '" title="' . esc_attr( $title ) . '">' . esc_html( (string) $score ) . '</span>';
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function render_post_id_cell( array $item ): string {
		$post_id   = (int) ( $item['post_id'] ?? 0 );
		$post_type = (string) ( $item['post_type'] ?? '' );
		$id_html   = esc_html( (string) $post_id );
		$edit_link = get_edit_post_link( $post_id );

		if ( is_string( $edit_link ) && '' !== $edit_link ) {
			$id_html = '<a href="' . esc_url( $edit_link ) . '">' . $id_html . '</a>';
		}

		$icon_html = '';
		if ( '' !== $post_type ) {
			$object = get_post_type_object( $post_type );
			$label  = $object ? (string) $object->labels->singular_name : $post_type;
			$icon   = $this->resolve_post_type_icon( $object, $post_type );

			if ( str_starts_with( $icon, 'dashicons-' ) ) {
				$icon_html = sprintf(
					'<span class="forwp-seo-id-type dashicons %1$s" title="%2$s (%3$s)" aria-label="%2$s"></span>',
					esc_attr( $icon ),
					esc_attr( $label ),
					esc_attr( $post_type )
				);
			} elseif ( '' !== $icon && filter_var( $icon, FILTER_VALIDATE_URL ) ) {
				$icon_html = sprintf(
					'<span class="forwp-seo-id-type forwp-seo-id-type--img" title="%2$s (%3$s)" aria-label="%2$s">' .
					'<img src="%1$s" alt="" width="16" height="16" />' .
					'</span>',
					esc_url( $icon ),
					esc_attr( $label ),
					esc_attr( $post_type )
				);
			}
		}

		return '<span class="forwp-seo-id-cell">' .
			'<span class="forwp-seo-id-cell__id">' . $id_html . '</span>' .
			$icon_html .
			'</span>';
	}

	private function render_post_type_cell( string $post_type ): string {
		if ( '' === $post_type ) {
			return '';
		}

		$object = get_post_type_object( $post_type );
		$label  = $object ? (string) $object->labels->singular_name : $post_type;
		$icon   = $this->resolve_post_type_icon( $object, $post_type );

		$icon_html = '';
		if ( str_starts_with( $icon, 'dashicons-' ) ) {
			$icon_html = sprintf(
				'<span class="forwp-seo-post-type-badge__icon dashicons %1$s" aria-hidden="true"></span>',
				esc_attr( $icon )
			);
		} elseif ( '' !== $icon && filter_var( $icon, FILTER_VALIDATE_URL ) ) {
			$icon_html = sprintf(
				'<span class="forwp-seo-post-type-badge__icon" aria-hidden="true"><img src="%1$s" alt="" width="16" height="16" /></span>',
				esc_url( $icon )
			);
		}

		return sprintf(
			'<span class="forwp-seo-post-type-badge" title="%1$s (%2$s)">%3$s<span class="forwp-seo-post-type-badge__label">%4$s</span></span>',
			esc_attr( $label ),
			esc_attr( $post_type ),
			$icon_html,
			esc_html( $label )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function render_taxonomies_cell( array $item ): string {
		$groups = isset( $item['taxonomies'] ) && is_array( $item['taxonomies'] ) ? $item['taxonomies'] : [];
		if ( [] === $groups ) {
			return '<span class="forwp-seo-admin-muted">—</span>';
		}

		$blocks = [];
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$taxonomy = sanitize_key( (string) ( $group['taxonomy'] ?? '' ) );
			$label    = (string) ( $group['label'] ?? $taxonomy );
			$terms    = isset( $group['terms'] ) && is_array( $group['terms'] ) ? $group['terms'] : [];
			$links    = [];
			foreach ( $terms as $term ) {
				if ( ! is_array( $term ) ) {
					continue;
				}
				$name = (string) ( $term['name'] ?? '' );
				$id   = (int) ( $term['id'] ?? 0 );
				if ( '' === $name || $id <= 0 || '' === $taxonomy ) {
					continue;
				}
				$url     = $this->taxonomy_filter_url( $taxonomy, $id );
				$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
			}
			if ( [] === $links ) {
				continue;
			}
			$blocks[] = '<div class="forwp-seo-tax-group"><span class="forwp-seo-tax-group__label">' . esc_html( $label ) . '</span> ' . implode( ', ', $links ) . '</div>';
		}

		if ( [] === $blocks ) {
			return '<span class="forwp-seo-admin-muted">—</span>';
		}

		return '<div class="forwp-seo-tax-groups">' . implode( '', $blocks ) . '</div>';
	}

	private function taxonomy_filter_url( string $taxonomy, int $term_id ): string {
		return add_query_arg(
			array_filter(
				[
					'page'                       => Menu::INVENTORY_PAGE_SLUG,
					'view'                       => 'inventory',
					Menu::INVENTORY_POST_TYPE_ARG => (string) ( $this->filters['post_type'] ?? '' ),
					Menu::INVENTORY_TAXONOMY_ARG  => $taxonomy,
					Menu::INVENTORY_TERM_ARG      => (string) $term_id,
					'lang'                       => (string) ( $this->filters['lang'] ?? '' ),
					'status'                     => (string) ( $this->filters['status'] ?? '' ),
					'missing'                    => (string) ( $this->filters['missing'] ?? '' ),
				]
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @param \WP_Post_Type|null $object
	 */
	private function resolve_post_type_icon( ?\WP_Post_Type $object, string $post_type ): string {
		$icon = is_object( $object ) && ! empty( $object->menu_icon ) ? (string) $object->menu_icon : '';

		if ( str_starts_with( $icon, 'dashicons-' ) ) {
			return $icon;
		}

		if ( '' !== $icon && filter_var( $icon, FILTER_VALIDATE_URL ) ) {
			return $icon;
		}

		return match ( $post_type ) {
			'page' => 'dashicons-admin-page',
			'post' => 'dashicons-admin-post',
			'product' => 'dashicons-cart',
			default => 'dashicons-admin-post',
		};
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function render_gsc_cell( array $item ): string {
		$clicks      = (int) ( $item['gsc_clicks'] ?? 0 );
		$impressions = (int) ( $item['gsc_impressions'] ?? 0 );
		$position    = (float) ( $item['gsc_position'] ?? 0 );
		$ctr         = (float) ( $item['gsc_ctr'] ?? 0 );
		$queries     = is_array( $item['gsc_top_queries'] ?? null ) ? $item['gsc_top_queries'] : [];

		if ( $clicks <= 0 && $impressions <= 0 ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No synced data', '4wp-seo-helper' ) . '</span>';
		}

		$html = '<div class="forwp-seo-gsc-inventory">';
		$html .= '<div class="forwp-seo-gsc-inventory__metrics">';
		$html .= esc_html(
			sprintf(
				/* translators: 1: clicks, 2: impressions */
				__( '%1$s clicks · %2$s impr.', '4wp-seo-helper' ),
				number_format_i18n( $clicks ),
				number_format_i18n( $impressions )
			)
		);
		if ( $position > 0 || $ctr > 0 ) {
			$html .= '<br /><span class="forwp-seo-gsc-inventory__sub">';
			$html .= esc_html(
				sprintf(
					/* translators: 1: average position, 2: CTR percent */
					__( 'Pos %1$s · CTR %2$s%%', '4wp-seo-helper' ),
					number_format_i18n( $position, 1 ),
					number_format_i18n( $ctr * 100, 1 )
				)
			);
			$html .= '</span>';
		}
		$html .= '</div>';

		if ( ! empty( $queries ) ) {
			$html .= '<ul class="forwp-seo-gsc-inventory__queries">';
			foreach ( array_slice( $queries, 0, 3 ) as $query_row ) {
				$query = (string) ( $query_row['query'] ?? '' );
				if ( '' === $query ) {
					continue;
				}
				$html .= '<li>' . esc_html( wp_html_excerpt( $query, 48, '…' ) );
				if ( ! empty( $query_row['clicks'] ) ) {
					$html .= ' <span class="forwp-seo-gsc-inventory__q-clicks">(' . esc_html( number_format_i18n( (int) $query_row['clicks'] ) ) . ')</span>';
				}
				$html .= '</li>';
			}
			$html .= '</ul>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function render_gsc_index_status_cell( array $item ): string {
		$coverage = (string) ( $item['gsc_coverage'] ?? '' );
		$verdict  = (string) ( $item['gsc_verdict'] ?? '' );
		$error    = (string) ( $item['gsc_inspect_error'] ?? '' );
		$link     = (string) ( $item['gsc_inspect_link'] ?? '' );

		if ( '' !== $error && '' === $coverage && '' === $verdict ) {
			return $this->render_gsc_index_status_icon(
				'bad',
				$error,
				__( 'Inspect error', '4wp-seo-helper' ),
				''
			);
		}

		$label = '' !== $coverage ? $coverage : ( '' !== $verdict ? $verdict : '' );
		if ( '' === $label ) {
			return '<span class="forwp-seo-index-icon forwp-seo-index-icon--na" title="' . esc_attr__( 'Not inspected yet', '4wp-seo-helper' ) . '" aria-label="' . esc_attr__( 'Not inspected yet', '4wp-seo-helper' ) . '">'
				. '<span class="dashicons dashicons-minus" aria-hidden="true"></span>'
				. '</span>';
		}

		return $this->render_gsc_index_status_icon(
			$this->gsc_coverage_tone( $coverage, $verdict ),
			$label,
			$label,
			$link
		);
	}

	private function render_gsc_index_status_icon( string $tone, string $title, string $aria, string $link ): string {
		$icon = match ( $tone ) {
			'good' => 'dashicons-yes-alt',
			'bad' => 'dashicons-dismiss',
			default => 'dashicons-warning',
		};

		$html = sprintf(
			'<span class="forwp-seo-index-icon forwp-seo-index-icon--%1$s" title="%2$s" aria-label="%3$s">' .
			'<span class="dashicons %4$s" aria-hidden="true"></span>' .
			'</span>',
			esc_attr( $tone ),
			esc_attr( $title ),
			esc_attr( $aria ),
			esc_attr( $icon )
		);

		if ( '' !== $link && PropertyResolver::is_valid_inspection_result_link( $link ) ) {
			$html = '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer" class="forwp-seo-index-icon-link">' . $html . '</a>';
		}

		return $html;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function render_gsc_index_requested_cell( array $item ): string {
		return esc_html( $this->format_gsc_unix_time( (int) ( $item['gsc_index_requested_at'] ?? 0 ) ) );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function render_gsc_actions_cell( array $item ): string {
		$gsc       = GscAdmin::get_instance();
		$post_id   = (int) ( $item['post_id'] ?? 0 );
		$published = 'publish' === (string) ( $item['status'] ?? '' );
		$ready     = $gsc->is_connected() && '' !== GscAdmin::get_site_property();
		$disabled  = $published ? '' : ' disabled aria-disabled="true"';

		$html = sprintf(
			'<div class="forwp-seo-gsc-actions">' .
			'<button type="button" class="button button-small forwp-seo-gsc-refresh" data-post-id="%1$d"%2$s title="%3$s">%4$s</button>',
			$post_id,
			$disabled,
			esc_attr__( 'Fetch latest index status from Google Search Console', '4wp-seo-helper' ),
			esc_html__( 'Refresh status', '4wp-seo-helper' )
		);

		if ( $ready ) {
			$html .= sprintf(
				'<button type="button" class="button button-small forwp-seo-gsc-request-index" data-post-id="%1$d"%2$s title="%4$s">%3$s</button>',
				$post_id,
				$disabled,
				esc_html__( 'Request indexing', '4wp-seo-helper' ),
				esc_attr__( 'Open Search Console to request indexing for this URL', '4wp-seo-helper' )
			);
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function render_gsc_last_crawl_cell( array $item ): string {
		return esc_html( $this->format_gsc_iso_time( (string) ( $item['gsc_last_crawl'] ?? '' ) ) );
	}

	private function render_focus_keyphrases_cell( string $value ): string {
		$phrases = preg_split( '/\r\n|\r|\n| · /', $value ) ?: [];
		$phrases = array_values(
			array_filter(
				array_map( 'trim', $phrases ),
				static function ( string $phrase ): bool {
					return '' !== $phrase;
				}
			)
		);

		if ( empty( $phrases ) ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Empty', '4wp-seo-helper' ) . '</span>';
		}

		$items = array_map(
			static function ( string $phrase ): string {
				return '<span class="forwp-seo-keyphrases__item">' . esc_html( $phrase ) . '</span>';
			},
			$phrases
		);

		return '<span class="forwp-seo-keyphrases">' . implode( '', $items ) . '</span>';
	}

	private function render_text_excerpt_cell( string $value, bool $multiline = false, bool $is_auto = false ): string {
		if ( '' === $value ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Empty', '4wp-seo-helper' ) . '</span>';
		}

		$display = $multiline ? str_replace( [ "\r\n", "\r", "\n" ], ' · ', $value ) : $value;
		$excerpt = esc_html( wp_html_excerpt( $display, 80, '…' ) );

		if ( ! $is_auto ) {
			return $excerpt;
		}

		$title = __( 'From the Yoast template (%%variables%%). Not saved as a custom title/description on this post.', '4wp-seo-helper' );

		return sprintf(
			'<span class="forwp-seo-auto-meta" title="%1$s"><span class="forwp-seo-auto-meta__text">%2$s</span><abbr class="forwp-seo-auto-meta__badge" title="%1$s">%3$s</abbr></span>',
			esc_attr( $title ),
			$excerpt,
			esc_html__( 'auto', '4wp-seo-helper' )
		);
	}

	private function format_gsc_unix_time( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}

		return wp_date( 'd.m.Y H:i', $timestamp );
	}

	private function format_gsc_iso_time( string $iso ): string {
		if ( '' === trim( $iso ) ) {
			return '—';
		}

		$timestamp = strtotime( $iso );
		if ( false === $timestamp ) {
			return $iso;
		}

		return wp_date( 'd.m.Y H:i', $timestamp );
	}

	private function gsc_coverage_tone( string $coverage, string $verdict ): string {
		$text = $coverage . ' ' . $verdict;
		if ( preg_match( '/indexed/i', $text ) && ! preg_match( '/not indexed|excluded/i', $text ) ) {
			return 'good';
		}
		if ( preg_match( '/not indexed|excluded|error|fail/i', $text ) ) {
			return 'bad';
		}

		return 'ok';
	}
}
