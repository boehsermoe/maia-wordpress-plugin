<?php
/**
 * Pure logic for reading and changing the CSS of the active theme (no WordPress functions, so PHPUnit can run it).
 *
 * @package Maia
 */

namespace Maia;

defined( 'ABSPATH' ) || defined( 'MAIA_TESTING' ) || exit;

/**
 * Additional CSS validation, hashing and safe access to the stylesheet files of a theme.
 */
final class Theme_Css {

	/** Longest Additional CSS accepted (WordPress itself sets no limit; the site loads it on every page). */
	const MAX_CSS_LENGTH = 200000;

	/** Most bytes of one stylesheet file that are returned. */
	const MAX_FILE_BYTES = 100000;

	/** Most files listed. */
	const MAX_FILES = 100;

	/** Deepest sub-directory of a theme that is searched for stylesheets. */
	const MAX_DEPTH = 3;

	/** Directories that hold dependencies, not the theme's own CSS. */
	const SKIPPED_DIRECTORIES = array( 'node_modules', 'vendor', '.git' );

	/**
	 * Fingerprint of a CSS text; a write with a stale one is refused.
	 *
	 * @param string $css CSS.
	 */
	public static function hash( string $css ): string {
		return md5( $css );
	}

	/**
	 * Why a CSS text can't be saved, or null when it can. Same markup rule as the Customizer.
	 *
	 * @param mixed $css Value from the request.
	 */
	public static function validate_css( $css ): ?string {
		if ( ! is_string( $css ) ) {
			return 'css must be a text.';
		}
		if ( strlen( $css ) > self::MAX_CSS_LENGTH ) {
			return 'The CSS is longer than ' . self::MAX_CSS_LENGTH . ' characters.';
		}
		if ( preg_match( '#</?\w+#', $css ) ) {
			return 'Markup is not allowed in CSS.';
		}
		return null;
	}

	/**
	 * Whether a path is a plain relative path to a .css file: no traversal, no absolute or Windows paths.
	 *
	 * @param mixed $path Path from the request.
	 */
	public static function is_safe_path( $path ): bool {
		if ( ! is_string( $path ) || '' === $path || strlen( $path ) > 200 ) {
			return false;
		}
		if ( ! preg_match( '#^[A-Za-z0-9_\-. /]+\.css$#iD', $path ) ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment || in_array( strtolower( $segment ), self::SKIPPED_DIRECTORIES, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The stylesheets inside a theme directory, style.css first, then by path.
	 *
	 * @param string $root Absolute path of the theme directory.
	 * @return array{files: array<int, array{path: string, size: int}>, truncated: bool}
	 */
	public static function list_files( string $root ): array {
		$base = realpath( $root );
		if ( false === $base || ! is_dir( $base ) ) {
			return array(
				'files'     => array(),
				'truncated' => false,
			);
		}

		$directory = new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS );
		$filter    = new \RecursiveCallbackFilterIterator(
			$directory,
			static function ( \SplFileInfo $entry ): bool {
				return ! $entry->isDir() || ! in_array( strtolower( $entry->getFilename() ), self::SKIPPED_DIRECTORIES, true );
			}
		);
		$iterator  = new \RecursiveIteratorIterator( $filter );
		$iterator->setMaxDepth( self::MAX_DEPTH );

		$files = array();
		foreach ( $iterator as $entry ) {
			if ( ! $entry->isFile() || $entry->isLink() || 'css' !== strtolower( $entry->getExtension() ) ) {
				continue;
			}
			$path = str_replace( '\\', '/', substr( $entry->getPathname(), strlen( $base ) + 1 ) );
			if ( self::is_safe_path( $path ) ) {
				$files[] = array(
					'path' => $path,
					'size' => (int) $entry->getSize(),
				);
			}
		}

		usort(
			$files,
			static function ( array $a, array $b ): int {
				$rank = static function ( string $path ): int {
					return 'style.css' === $path ? 0 : 1;
				};
				return array( $rank( $a['path'] ), $a['path'] ) <=> array( $rank( $b['path'] ), $b['path'] );
			}
		);

		return array(
			'files'     => array_slice( $files, 0, self::MAX_FILES ),
			'truncated' => count( $files ) > self::MAX_FILES,
		);
	}

	/**
	 * Reads one stylesheet of a theme. Null for anything that is not a plain .css file inside the theme directory.
	 *
	 * @param string $root Absolute path of the theme directory.
	 * @param mixed  $path Relative path from the request.
	 * @return array{css: string, size: int, truncated: bool}|null
	 */
	public static function read_file( string $root, $path ): ?array {
		if ( ! self::is_safe_path( $path ) ) {
			return null;
		}
		$base = realpath( $root );
		$full = false === $base ? false : realpath( $base . '/' . $path );
		// The real location must still be inside the theme directory (a symlink could lead out of it).
		if ( false === $full || 0 !== strpos( $full, $base . DIRECTORY_SEPARATOR ) || ! is_file( $full ) ) {
			return null;
		}

		$content = file_get_contents( $full, false, null, 0, self::MAX_FILE_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return null;
		}
		$truncated = strlen( $content ) > self::MAX_FILE_BYTES;
		if ( $truncated ) {
			$content = substr( $content, 0, self::MAX_FILE_BYTES );
		}
		// A cut may end inside a multi-byte character, and JSON refuses invalid UTF-8.
		$clean = iconv( 'UTF-8', 'UTF-8//IGNORE', $content );

		return array(
			'css'       => false === $clean ? '' : $clean,
			'size'      => (int) filesize( $full ),
			'truncated' => $truncated,
		);
	}
}
