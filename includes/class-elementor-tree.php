<?php
/**
 * Pure helpers on an Elementor element tree (the decoded `_elementor_data`).
 *
 * No WordPress or Elementor calls here, so the logic can be unit tested on its own.
 *
 * @package Maia
 */

namespace Maia;

defined( 'ABSPATH' ) || defined( 'MAIA_TESTING' ) || exit;

/**
 * Finds, outlines and patches elements of an Elementor tree.
 */
final class Elementor_Tree {

	/**
	 * Settings that usually carry the visible text of a widget, in the order they are tried for the outline summary.
	 */
	const SUMMARY_KEYS = array( 'title', 'editor', 'text', 'title_text', 'description_text', 'heading', 'caption', 'html', 'testimonial_content', 'alert_title', 'inner_text' );

	const SUMMARY_LENGTH = 120;

	/**
	 * Setting keys that may be written. Elementor uses snake case plus a leading underscore for advanced settings.
	 */
	const KEY_PATTERN = '/^[A-Za-z0-9_\-]{1,100}$/';

	/**
	 * Stable fingerprint of a tree. The client sends it back to detect a concurrent edit.
	 *
	 * @param array $elements Tree.
	 */
	public static function hash( array $elements ): string {
		return md5( (string) json_encode( $elements ) );
	}

	/**
	 * Finds an element by its Elementor id.
	 *
	 * @param array  $elements Tree.
	 * @param string $element_id Element id (8 hex chars as Elementor generates them, but any string is accepted).
	 * @return array|null The element, or null if it does not exist.
	 */
	public static function find( array $elements, string $element_id ): ?array {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['id'] ) && (string) $element['id'] === $element_id ) {
				return $element;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = self::find( $element['elements'], $element_id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * One element without its children: the element's settings plus how many children it has.
	 *
	 * @param array $element Element.
	 */
	public static function describe( array $element ): array {
		$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
		return array(
			'id'         => (string) ( $element['id'] ?? '' ),
			'elType'     => (string) ( $element['elType'] ?? '' ),
			'widgetType' => isset( $element['widgetType'] ) ? (string) $element['widgetType'] : null,
			'settings'   => self::settings_of( $element ),
			'children'   => array_values(
				array_map(
					static function ( $child ) {
						return is_array( $child ) ? (string) ( $child['id'] ?? '' ) : '';
					},
					$children
				)
			),
		);
	}

	/**
	 * The structure of the tree without settings, plus a short plain-text summary per element.
	 * Much smaller than the full tree, so a client can locate an element before loading its settings.
	 *
	 * @param array $elements Tree.
	 */
	public static function outline( array $elements ): array {
		$outline = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$node = array(
				'id'     => (string) ( $element['id'] ?? '' ),
				'elType' => (string) ( $element['elType'] ?? '' ),
			);
			if ( isset( $element['widgetType'] ) ) {
				$node['widgetType'] = (string) $element['widgetType'];
			}
			$summary = self::summary( self::settings_of( $element ) );
			if ( '' !== $summary ) {
				$node['summary'] = $summary;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$node['elements'] = self::outline( $element['elements'] );
			}
			$outline[] = $node;
		}
		return $outline;
	}

	/**
	 * Checks a settings patch before it is applied.
	 *
	 * @param mixed $patch Decoded request value.
	 * @return string|null An error message, or null if the patch is valid.
	 */
	public static function validate_patch( $patch ): ?string {
		if ( ! is_array( $patch ) || array() === $patch || array_keys( $patch ) === range( 0, count( $patch ) - 1 ) ) {
			return 'settings must be a non-empty object of setting key to value.';
		}
		foreach ( $patch as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( self::KEY_PATTERN, $key ) ) {
				return sprintf( 'Invalid setting key "%s".', (string) $key );
			}
			if ( ! is_null( $value ) && ! is_scalar( $value ) && ! is_array( $value ) ) {
				return sprintf( 'Invalid value for setting "%s".', $key );
			}
		}
		return null;
	}

	/**
	 * Applies a settings patch to one element. A null value removes the setting, so the `before`
	 * of a patch is always a valid patch that undoes it.
	 *
	 * @param array    $elements   Tree.
	 * @param string   $element_id Element to change.
	 * @param array    $patch      Setting key => new value (null removes the key). Validated with validate_patch().
	 * @param callable $sanitize   Applied to every string in the new values (e.g. wp_kses_post).
	 * @return array{elements: array, before: array, after: array}|null Null if the element does not exist.
	 */
	public static function patch_settings( array $elements, string $element_id, array $patch, callable $sanitize ): ?array {
		$before = array();
		$after  = array();
		$found  = false;

		$elements = self::map_element(
			$elements,
			$element_id,
			static function ( array $element ) use ( $patch, $sanitize, &$before, &$after, &$found ) {
				$found    = true;
				$settings = self::settings_of( $element );
				foreach ( $patch as $key => $value ) {
					$before[ $key ] = array_key_exists( $key, $settings ) ? $settings[ $key ] : null;
					if ( null === $value ) {
						unset( $settings[ $key ] );
						$after[ $key ] = null;
					} else {
						$settings[ $key ] = self::sanitize_value( $value, $sanitize );
						$after[ $key ]    = $settings[ $key ];
					}
				}
				$element['settings'] = $settings;
				return $element;
			}
		);

		return $found ? array(
			'elements' => $elements,
			'before'   => $before,
			'after'    => $after,
		) : null;
	}

	/**
	 * Settings of an element as an array (Elementor writes an empty list for no settings).
	 *
	 * @param array $element Element.
	 */
	private static function settings_of( array $element ): array {
		return isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
	}

	/**
	 * Replaces one element (found by id) with the result of $change.
	 *
	 * @param array    $elements   Tree.
	 * @param string   $element_id Element id.
	 * @param callable $change     Receives the element, returns the new one.
	 */
	private static function map_element( array $elements, string $element_id, callable $change ): array {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['id'] ) && (string) $element['id'] === $element_id ) {
				$elements[ $index ] = $change( $element );
				return $elements;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$elements[ $index ]['elements'] = self::map_element( $element['elements'], $element_id, $change );
			}
		}
		return $elements;
	}

	/**
	 * Applies $sanitize to every string of a (possibly nested) value.
	 *
	 * @param mixed    $value    Value.
	 * @param callable $sanitize String sanitizer.
	 * @return mixed
	 */
	private static function sanitize_value( $value, callable $sanitize ) {
		if ( is_string( $value ) ) {
			return $sanitize( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::sanitize_value( $item, $sanitize );
			}
		}
		return $value;
	}

	/**
	 * First non-empty text setting, without tags and shortened.
	 *
	 * @param array $settings Settings of one element.
	 */
	private static function summary( array $settings ): string {
		foreach ( self::SUMMARY_KEYS as $key ) {
			if ( empty( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) ) {
				continue;
			}
			$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $settings[ $key ] ), ENT_QUOTES, 'UTF-8' ) ) );
			if ( '' === $text ) {
				continue;
			}
			return mb_strlen( $text ) > self::SUMMARY_LENGTH ? mb_substr( $text, 0, self::SUMMARY_LENGTH - 1 ) . '…' : $text;
		}
		return '';
	}
}
