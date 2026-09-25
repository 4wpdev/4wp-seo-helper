<?php
/**
 * Build Gutenberg markup from TechArticle section data (import / editor shell).
 */

namespace Forwp\SeoHelper\Content;

use Forwp\SeoHelper\Blocks\TechArticleWrappers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TechArticleMarkup {
	/**
	 * Compose post_content from normalized section payload.
	 *
	 * @param array<string, mixed> $sections Section data (goal, steps, troubleshooting, …).
	 * @param array<string, mixed> $data     Optional challenge JSON for terminal attrs.
	 */
	public static function build_post_content( array $sections, array $data = [] ): string {
		$parts = [];

		$goal = isset( $sections['goal'] ) && is_string( $sections['goal'] ) ? trim( $sections['goal'] ) : '';
		if ( $goal !== '' ) {
			$parts[] = self::wrap_section(
				TechArticleWrappers::GOAL_BLOCK,
				self::heading_paragraph_section( __( 'Goal', '4wp-seo-helper' ), $goal )
			);
		}

		$terminal = self::build_terminal_block( $sections, $data );
		$steps    = $sections['steps'] ?? [];
		$steps_inner = '';
		if ( is_array( $steps ) && $steps !== [] ) {
			$steps_inner = self::wrap_section(
				TechArticleWrappers::STEPS_BLOCK,
				self::heading_block( __( 'Steps', '4wp-seo-helper' ), 2 ) . "\n\n" . self::build_steps_inner( $steps )
			);
		}

		if ( $terminal !== '' || $steps_inner !== '' ) {
			$parts[] = self::build_workspace_columns( $terminal, $steps_inner );
		}

		$issues = $sections['troubleshooting'] ?? [];
		if ( is_array( $issues ) && $issues !== [] ) {
			$parts[] = self::wrap_section(
				TechArticleWrappers::ISSUES_BLOCK,
				self::heading_block( __( 'Common mistakes', '4wp-seo-helper' ), 2 ) . "\n\n" . self::build_list_block( $issues )
			);
		}

		$real_cases = $sections['real_cases'] ?? [];
		if ( is_array( $real_cases ) && $real_cases !== [] ) {
			$parts[] = self::build_real_cases_block( $real_cases );
		}

		$faq = $sections['faq'] ?? [];
		if ( is_array( $faq ) && $faq !== [] ) {
			$parts[] = self::build_faq_block( $faq, $data );
		}

		$completion = $sections['completion'] ?? [];
		if ( is_array( $completion ) && $completion !== [] ) {
			$parts[] = self::build_completion_block( $completion );
		}

		return implode( "\n\n", array_filter( $parts ) );
	}

	/**
	 * Default editor / CPT template block list.
	 *
	 * @return array<int, array{0: string, 1?: array<string, mixed>}>
	 */
	public static function get_editor_block_template(): array {
		return [
			[ 'forwp-advanced-code/terminal', [ 'profile' => 'embedded' ] ],
			[ TechArticleWrappers::GOAL_BLOCK, [] ],
			[ TechArticleWrappers::STEPS_BLOCK, [] ],
			[ TechArticleWrappers::ISSUES_BLOCK, [] ],
		];
	}

	/**
	 * Whether post content already uses TechArticle wrapper blocks.
	 */
	public static function has_techarticle_blocks( string $content ): bool {
		return str_contains( $content, 'forwp-seo/techarticle-' );
	}

	/**
	 * Valid imported/editor shell: terminal + TechArticle wrappers, no legacy LMS slots.
	 */
	public static function is_valid_post_shell( string $content ): bool {
		$content = trim( $content );

		if ( $content === '' ) {
			return false;
		}

		if ( str_contains( $content, 'lms4wp/practice-case-' ) ) {
			return false;
		}

		if ( ! str_contains( $content, 'forwp-advanced-code/terminal' )
			|| ! self::has_techarticle_blocks( $content ) ) {
			return false;
		}

		if ( ! str_contains( $content, 'forwp-practice-case__workspace' ) ) {
			return false;
		}

		// Legacy hand-crafted core/code markup (lang/class on <code>) fails block validation.
		if ( str_contains( $content, 'lang="bash"' ) || str_contains( $content, 'class="language-bash"' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $sections Section data.
	 * @param array<string, mixed> $data     Challenge JSON.
	 */
	private static function build_terminal_block( array $sections, array $data ): string {
		$welcome = '';
		$terminal = $sections['terminal'] ?? [];
		if ( is_array( $terminal ) && ! empty( $terminal['welcomeMessage'] ) && is_string( $terminal['welcomeMessage'] ) ) {
			$welcome = $terminal['welcomeMessage'];
		} elseif ( ! empty( $data['instructions'] ) && is_string( $data['instructions'] ) ) {
			$welcome = $data['instructions'];
		} elseif ( ! empty( $data['description'] ) && is_string( $data['description'] ) ) {
			$welcome = $data['description'];
		}

		$attrs = wp_json_encode(
			[
				'profile'        => 'embedded',
				'welcomeMessage' => $welcome,
				'className'      => 'forwp-practice-case__terminal',
			],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return sprintf( '<!-- wp:forwp-advanced-code/terminal %s /-->', $attrs );
	}

	/**
	 * Goal on top; terminal (left) + steps (right) in a sticky workspace row.
	 */
	private static function build_workspace_columns( string $terminal, string $steps ): string {
		$cols_attrs = wp_json_encode(
			[
				'className'         => 'forwp-practice-case__workspace',
				'isStackedOnMobile' => true,
			],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$terminal_col_attrs = wp_json_encode(
			[ 'className' => 'forwp-practice-case__workspace-terminal' ],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$steps_col_attrs = wp_json_encode(
			[ 'className' => 'forwp-practice-case__workspace-steps' ],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return sprintf(
			"<!-- wp:columns %1\$s -->\n<div class=\"wp-block-columns forwp-practice-case__workspace\">" . "\n"
			. "<!-- wp:column %2\$s -->\n<div class=\"wp-block-column forwp-practice-case__workspace-terminal\">%3\$s</div>\n<!-- /wp:column -->\n\n"
			. "<!-- wp:column %4\$s -->\n<div class=\"wp-block-column forwp-practice-case__workspace-steps\">%5\$s</div>\n<!-- /wp:column -->\n"
			. "</div>\n<!-- /wp:columns -->",
			$cols_attrs,
			$terminal_col_attrs,
			$terminal,
			$steps_col_attrs,
			$steps
		);
	}

	private static function wrap_section( string $block_name, string $inner ): string {
		return sprintf(
			"<!-- wp:%1\$s -->\n%2\$s\n<!-- /wp:%1\$s -->",
			$block_name,
			trim( $inner )
		);
	}

	private static function heading_paragraph_section( string $heading, string $text ): string {
		return self::heading_block( $heading, 2 ) . "\n\n" . self::paragraph_block( $text );
	}

	private static function heading_block( string $text, int $level = 2 ): string {
		$attrs = wp_json_encode( [ 'level' => $level ], JSON_UNESCAPED_UNICODE );
		$tag   = 'h' . $level;

		return sprintf(
			"<!-- wp:heading %1\$s -->\n<%2\$s class=\"wp-block-heading\">%3\$s</%2\$s>\n<!-- /wp:heading -->",
			$attrs,
			$tag,
			esc_html( $text )
		);
	}

	private static function paragraph_block( string $text ): string {
		return sprintf(
			"<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
			wp_kses_post( $text )
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $steps Article steps.
	 */
	private static function build_steps_inner( array $steps ): string {
		$parts       = [];
		$step_number = 0;

		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$title = isset( $step['title'] ) && is_string( $step['title'] ) ? $step['title'] : '';
			if ( $title === '' ) {
				continue;
			}

			++$step_number;
			$inner = self::heading_block( $title, 3 );

			$paragraphs = $step['paragraphs'] ?? [];
			if ( is_string( $paragraphs ) ) {
				$paragraphs = [ $paragraphs ];
			}
			if ( is_array( $paragraphs ) ) {
				foreach ( $paragraphs as $paragraph ) {
					if ( is_string( $paragraph ) && trim( $paragraph ) !== '' ) {
						$inner .= "\n\n" . self::paragraph_block( $paragraph );
					}
				}
			}

			$command = isset( $step['command'] ) && is_string( $step['command'] ) ? trim( $step['command'] ) : '';
			if ( $command !== '' ) {
				$inner .= "\n\n" . self::code_block( $command );
			}

			$step_attrs = wp_json_encode(
				[ 'stepNumber' => $step_number ],
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);

			$parts[] = sprintf(
				"<!-- wp:forwp-seo/techarticle-step %1\$s -->\n%2\$s\n<!-- /wp:forwp-seo/techarticle-step -->",
				$step_attrs,
				trim( $inner )
			);
		}

		return implode( "\n\n", $parts );
	}

	private static function code_block( string $command ): string {
		$command = trim( $command );
		if ( $command === '' ) {
			return '';
		}

		$inner = '<pre class="wp-block-code"><code>' . esc_html( $command ) . '</code></pre>';

		return serialize_block(
			[
				'blockName'    => 'core/code',
				'attrs'        => [ 'language' => 'bash' ],
				'innerBlocks'  => [],
				'innerHTML'    => $inner,
				'innerContent' => [ $inner ],
			]
		);
	}

	/**
	 * @param array<int, string> $items List item HTML strings.
	 */
	private static function build_list_block( array $items ): string {
		$inners = [];
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) || trim( $item ) === '' ) {
				continue;
			}
			$inners[] = wp_kses_post( $item );
		}

		return self::serialize_list( $inners );
	}

	/**
	 * core/list with core/list-item children so the editor accepts the markup.
	 *
	 * @param array<int, string> $inners Inner HTML for each <li>, already sanitized.
	 */
	private static function serialize_list( array $inners ): string {
		$items = [];
		foreach ( $inners as $inner ) {
			if ( ! is_string( $inner ) || '' === trim( $inner ) ) {
				continue;
			}
			$li      = '<li>' . $inner . '</li>';
			$items[] = [
				'blockName'    => 'core/list-item',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => $li,
				'innerContent' => [ $li ],
			];
		}

		if ( $items === [] ) {
			return '';
		}

		$open           = '<ul class="wp-block-list">';
		$close          = '</ul>';
		$inner_content  = [ $open ];
		$inner_html     = $open;
		foreach ( $items as $index => $item ) {
			if ( $index > 0 ) {
				$inner_content[] = "\n\n";
			}
			$inner_content[] = null;
			$inner_html     .= $item['innerHTML'];
		}
		$inner_content[] = $close;
		$inner_html     .= $close;

		return serialize_block(
			[
				'blockName'    => 'core/list',
				'attrs'        => [],
				'innerBlocks'  => $items,
				'innerHTML'    => $inner_html,
				'innerContent' => $inner_content,
			]
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $items Link rows.
	 */
	private static function build_real_cases_block( array $items ): string {
		$inners = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = isset( $item['label'] ) && is_string( $item['label'] ) ? $item['label'] : '';
			$url   = isset( $item['url'] ) && is_string( $item['url'] ) ? self::resolve_url( $item['url'] ) : '';
			if ( $label === '' || $url === '' ) {
				continue;
			}
			$inners[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}

		$list = self::serialize_list( $inners );
		if ( $list === '' ) {
			return '';
		}

		return sprintf(
			"<!-- wp:group {\"className\":\"forwp-practice-case__real-cases\"} -->\n<div class=\"wp-block-group forwp-practice-case__real-cases\">%s\n%s</div>\n<!-- /wp:group -->",
			"\n" . self::heading_block( __( 'Real cases', '4wp-seo-helper' ), 2 ),
			$list
		);
	}

	/**
	 * @param array<string, array<int, string>> $completion
	 */
	private static function build_completion_block( array $completion ): string {
		$attrs = [
			'perfect'  => array_values( array_filter( (array) ( $completion['perfect'] ?? [] ), 'is_string' ) ),
			'optimize' => array_values( array_filter( (array) ( $completion['optimize'] ?? [] ), 'is_string' ) ),
			'next'     => array_values( array_filter( (array) ( $completion['next'] ?? [] ), 'is_string' ) ),
		];

		if ( empty( $attrs['perfect'] ) && empty( $attrs['optimize'] ) && empty( $attrs['next'] ) ) {
			return '';
		}

		$json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return sprintf( '<!-- wp:forwp-seo/techarticle-completion %s /-->', $json );
	}

	/**
	 * @param array<int, array<string, mixed>> $items FAQ rows.
	 * @param array<string, mixed>             $data  Challenge JSON (taxonomies live under content).
	 */
	private static function build_faq_block( array $items, array $data = [] ): string {
		if ( ! class_exists( '\ForWP\FAQ\Plugin' ) ) {
			return self::build_faq_fallback( $items );
		}

		$inner = '';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$question = isset( $item['question'] ) && is_string( $item['question'] ) ? $item['question'] : '';
			$answer   = isset( $item['answer'] ) && is_string( $item['answer'] ) ? $item['answer'] : '';
			if ( $question === '' || $answer === '' ) {
				continue;
			}
			$inner .= self::build_details_block( $question, $answer );
		}

		if ( $inner === '' ) {
			return '';
		}

		$attrs = [
			'jsonLd' => 'enable',
		];
		$category_name = self::faq_category_name( $data );
		if ( $category_name !== '' ) {
			$attrs['categoryMode'] = 'new';
			$attrs['categoryName'] = $category_name;
		}

		$json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return sprintf(
			'<!-- wp:forwp/faq %1$s -->' . "\n<div class=\"wp-block-4wp-faq\">%2$s</div>\n<!-- /wp:forwp/faq -->",
			$json,
			trim( $inner )
		);
	}

	/**
	 * FAQ category label from content.taxonomies term names (tool + category).
	 *
	 * @param array<string, mixed> $data Challenge JSON.
	 */
	private static function faq_category_name( array $data ): string {
		$content = isset( $data['content'] ) && is_array( $data['content'] ) ? $data['content'] : [];
		$tax     = isset( $content['taxonomies'] ) && is_array( $content['taxonomies'] ) ? $content['taxonomies'] : [];

		$tool = self::practice_term_name(
			'lms_practice_tool',
			isset( $tax['pc_tool'] ) && is_string( $tax['pc_tool'] ) ? $tax['pc_tool'] : ''
		);
		$category = self::practice_term_name(
			'lms_practice_category',
			isset( $tax['pc_category'] ) && is_string( $tax['pc_category'] ) ? $tax['pc_category'] : ''
		);

		$parts = array_values( array_filter( [ $tool, $category ] ) );

		return implode( ' ', $parts );
	}

	private static function practice_term_name( string $taxonomy, string $slug ): string {
		$slug = sanitize_title( $slug );
		if ( $slug === '' || ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		$term = get_term_by( 'slug', $slug, $taxonomy );

		return $term instanceof \WP_Term ? $term->name : '';
	}

	/**
	 * @param array<int, array<string, mixed>> $items FAQ rows.
	 */
	private static function build_faq_fallback( array $items ): string {
		$details = '';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$question = isset( $item['question'] ) && is_string( $item['question'] ) ? $item['question'] : '';
			$answer   = isset( $item['answer'] ) && is_string( $item['answer'] ) ? $item['answer'] : '';
			if ( $question === '' || $answer === '' ) {
				continue;
			}
			$details .= self::build_details_block( $question, $answer ) . "\n\n";
		}

		if ( $details === '' ) {
			return '';
		}

		return self::heading_block( __( 'FAQ', '4wp-seo-helper' ), 2 ) . "\n\n" . trim( $details );
	}

	private static function build_details_block( string $question, string $answer ): string {
		$attrs = wp_json_encode( [ 'summary' => $question ], JSON_UNESCAPED_UNICODE );

		return sprintf(
			"<!-- wp:details %s -->\n<details class=\"wp-block-details\"><summary>%s</summary>%s</details>\n<!-- /wp:details -->",
			$attrs,
			esc_html( $question ),
			self::paragraph_block( $answer )
		);
	}

	private static function resolve_url( string $path_or_url ): string {
		$path_or_url = trim( $path_or_url );
		if ( $path_or_url === '' ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $path_or_url ) ) {
			return esc_url_raw( $path_or_url );
		}
		return esc_url_raw( home_url( '/' . ltrim( $path_or_url, '/' ) ) );
	}
}
