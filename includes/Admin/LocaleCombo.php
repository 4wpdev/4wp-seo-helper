<?php
/**
 * Searchable country / language combobox (Serper gl / hl).
 */

namespace Forwp\SeoHelper\Admin;

use Forwp\SeoHelper\Positions\SerperLocales;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LocaleCombo {
	/**
	 * @return list<array{value: string, label: string}>
	 */
	public static function country_options(): array {
		$out = [];
		foreach ( SerperLocales::countries() as $code => $name ) {
			unset( $name );
			$out[] = [
				'value' => $code,
				'label' => SerperLocales::country_label( $code ),
			];
		}

		return $out;
	}

	/**
	 * @return list<array{value: string, label: string}>
	 */
	public static function language_options(): array {
		$out = [];
		foreach ( SerperLocales::languages() as $code => $name ) {
			unset( $name );
			$out[] = [
				'value' => $code,
				'label' => SerperLocales::language_label( $code ),
			];
		}

		return $out;
	}

	public static function render_country( string $name, string $value ): void {
		self::render( $name, __( 'Country', '4wp-seo-helper' ), 'gl', $value, self::country_options() );
	}

	public static function render_language( string $name, string $value ): void {
		self::render( $name, __( 'Language', '4wp-seo-helper' ), 'hl', $value, self::language_options() );
	}

	/**
	 * @param list<array{value: string, label: string}> $options
	 */
	public static function render( string $name, string $label, string $param, string $value, array $options ): void {
		$current_label = $value;
		foreach ( $options as $option ) {
			if ( $option['value'] === $value ) {
				$current_label = $option['label'];
				break;
			}
		}
		$list_id = $name . '_list';
		?>
		<div class="forwp-seo-field forwp-seo-combo" data-combo>
			<label class="forwp-seo-field__label" for="<?php echo esc_attr( $name . '_search' ); ?>">
				<?php echo esc_html( $label ); ?>
				<span class="forwp-seo-combo__param"><?php echo esc_html( $param ); ?></span>
			</label>
			<div class="forwp-seo-combo__control">
				<input
					type="text"
					class="forwp-seo-combo__search"
					id="<?php echo esc_attr( $name . '_search' ); ?>"
					value="<?php echo esc_attr( $current_label ); ?>"
					autocomplete="off"
					spellcheck="false"
					role="combobox"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $list_id ); ?>"
					aria-autocomplete="list"
				/>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<span class="forwp-seo-combo__chevron" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="18" height="18" focusable="false"><path fill="currentColor" d="M16.59 8.59L12 13.17 7.41 8.59 6 10l6 6 6-6z"></path></svg>
				</span>
			</div>
			<ul class="forwp-seo-combo__list" id="<?php echo esc_attr( $list_id ); ?>" role="listbox" hidden>
				<?php foreach ( $options as $option ) : ?>
					<li
						role="option"
						data-value="<?php echo esc_attr( $option['value'] ); ?>"
						data-label="<?php echo esc_attr( $option['label'] ); ?>"
						<?php echo $option['value'] === $value ? 'aria-selected="true"' : ''; ?>
					><?php echo esc_html( $option['label'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
