<?php
/**
 * Empties the caches of a WordPress site: the well-known page cache plugins, the object cache and
 * Elementor's generated CSS. Each cache system is only touched when it is active, through its own
 * public function or action, so nothing is guessed about a plugin that is not there.
 *
 * Caches outside WordPress (a CDN, the host's own page cache) are not reached.
 *
 * @package Maia
 */

namespace Maia;

defined( 'ABSPATH' ) || defined( 'MAIA_TESTING' ) || exit;

/**
 * Cache purging for POST /cache/purge.
 */
final class Cache_Purger {

	/**
	 * Whether a URL is a page of this site: http(s), no login data, same host as the site address
	 * (a leading "www." does not count).
	 *
	 * @param string $url  URL to check.
	 * @param string $home The site address, e.g. home_url().
	 */
	public static function is_site_url( string $url, string $home ): bool {
		$target = wp_parse_url( $url );
		$base   = wp_parse_url( $home );
		if ( ! is_array( $target ) || ! is_array( $base ) || empty( $target['host'] ) || empty( $base['host'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $target['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( isset( $target['user'] ) || isset( $target['pass'] ) ) {
			return false;
		}
		return self::host_key( $target['host'] ) === self::host_key( $base['host'] );
	}

	/**
	 * Empties all caches.
	 *
	 * @return array{cleared: string[], failed: string[]} Names of the caches that were emptied, and of those that threw.
	 */
	public static function purge_all(): array {
		return self::run( 'all', null );
	}

	/**
	 * Purges one page from the caches that can do that.
	 *
	 * @param string $url A page of this site (checked with is_site_url() by the caller).
	 * @return array{cleared: string[], failed: string[]}
	 */
	public static function purge_url( string $url ): array {
		return self::run( 'url', $url );
	}

	/**
	 * Which supported cache systems are active on this site, without touching them.
	 * `url_purge` lists those that offer to purge one page (a purge can still fail, e.g. in an old version).
	 *
	 * @return array{active: string[], url_purge: string[]}
	 */
	public static function detect(): array {
		$active    = array();
		$url_purge = array();
		foreach ( self::purgers() as $purger ) {
			try {
				if ( ! ( $purger['active'] )() ) {
					continue;
				}
			} catch ( \Throwable $e ) {
				continue;
			}
			$active[] = $purger['name'];
			if ( isset( $purger['url'] ) ) {
				$url_purge[] = $purger['name'];
			}
		}
		return array(
			'active'    => $active,
			'url_purge' => $url_purge,
		);
	}

	/**
	 * The supported cache systems. `active` says whether it is there, `all` empties it completely,
	 * `url` (optional) purges one page.
	 *
	 * @return array<int, array{name: string, active: callable, all: callable, url?: callable}>
	 */
	private static function purgers(): array {
		return array(
			array(
				'name'   => 'WP Rocket',
				'active' => static fn() => function_exists( 'rocket_clean_domain' ),
				'all'    => static function () {
					rocket_clean_domain();
					if ( function_exists( 'rocket_clean_minify' ) ) {
						rocket_clean_minify();
					}
				},
				'url'    => static fn( string $url ) => rocket_clean_files( array( $url ) ),
			),
			array(
				'name'   => 'W3 Total Cache',
				'active' => static fn() => function_exists( 'w3tc_flush_all' ),
				'all'    => static fn() => w3tc_flush_all(),
				'url'    => static fn( string $url ) => w3tc_flush_url( $url ),
			),
			array(
				'name'   => 'LiteSpeed Cache',
				'active' => static fn() => defined( 'LSCWP_V' ),
				'all'    => static fn() => do_action( 'litespeed_purge_all' ),
				'url'    => static fn( string $url ) => do_action( 'litespeed_purge_url', $url ),
			),
			array(
				'name'   => 'WP Super Cache',
				'active' => static fn() => function_exists( 'wp_cache_clean_cache' ),
				'all'    => static function () {
					global $file_prefix;
					wp_cache_clean_cache( $file_prefix, true );
				},
				'url'    => static function ( string $url ) {
					if ( ! function_exists( 'wpsc_delete_url_cache' ) ) {
						throw new \RuntimeException( 'No single page purge in this version' );
					}
					wpsc_delete_url_cache( $url );
				},
			),
			array(
				'name'   => 'WP Fastest Cache',
				'active' => static fn() => function_exists( 'wpfc_clear_all_cache' ),
				'all'    => static fn() => wpfc_clear_all_cache( true ),
			),
			array(
				'name'   => 'Cache Enabler',
				'active' => static fn() => class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_complete_cache' ),
				'all'    => static fn() => \Cache_Enabler::clear_complete_cache(),
				'url'    => static function ( string $url ) {
					if ( ! method_exists( 'Cache_Enabler', 'clear_page_cache_by_url' ) ) {
						throw new \RuntimeException( 'No single page purge in this version' );
					}
					\Cache_Enabler::clear_page_cache_by_url( $url );
				},
			),
			array(
				'name'   => 'SiteGround Optimizer',
				'active' => static fn() => function_exists( 'sg_cachepress_purge_cache' ),
				'all'    => static fn() => sg_cachepress_purge_cache(),
				'url'    => static fn( string $url ) => sg_cachepress_purge_cache( $url ),
			),
			array(
				'name'   => 'Breeze',
				'active' => static fn() => has_action( 'breeze_clear_all_cache' ),
				'all'    => static fn() => do_action( 'breeze_clear_all_cache' ),
			),
			array(
				'name'   => 'Hummingbird',
				'active' => static fn() => has_action( 'wphb_clear_page_cache' ),
				'all'    => static fn() => do_action( 'wphb_clear_page_cache' ),
			),
			array(
				'name'   => 'WP-Optimize',
				'active' => static fn() => function_exists( 'WP_Optimize' ) && method_exists( WP_Optimize()->get_page_cache(), 'purge' ),
				'all'    => static fn() => WP_Optimize()->get_page_cache()->purge(),
			),
			array(
				'name'   => 'Autoptimize',
				'active' => static fn() => class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ),
				'all'    => static fn() => \autoptimizeCache::clearall(),
			),
			array(
				'name'   => 'Elementor CSS',
				'active' => static fn() => class_exists( 'Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ),
				'all'    => static fn() => \Elementor\Plugin::$instance->files_manager->clear_cache(),
			),
			array(
				'name'   => 'WordPress object cache',
				'active' => static fn() => function_exists( 'wp_cache_flush' ),
				'all'    => static fn() => wp_cache_flush(),
			),
		);
	}

	/**
	 * Runs every active purger of the mode; a purger that throws does not stop the others.
	 *
	 * @param string      $mode 'all' or 'url'.
	 * @param string|null $url  The page for mode 'url'.
	 * @return array{cleared: string[], failed: string[]}
	 */
	private static function run( string $mode, ?string $url ): array {
		$cleared = array();
		$failed  = array();
		foreach ( self::purgers() as $purger ) {
			if ( ! isset( $purger[ $mode ] ) ) {
				continue;
			}
			try {
				if ( ! ( $purger['active'] )() ) {
					continue;
				}
				( $purger[ $mode ] )( ...( null === $url ? array() : array( $url ) ) );
				$cleared[] = $purger['name'];
			} catch ( \Throwable $e ) {
				$failed[] = $purger['name'];
			}
		}
		return array(
			'cleared' => $cleared,
			'failed'  => $failed,
		);
	}

	/**
	 * A host without "www." and in lower case, for comparing two addresses of the same site.
	 *
	 * @param string $host Host name.
	 */
	private static function host_key( string $host ): string {
		return preg_replace( '/^www\./', '', strtolower( $host ) );
	}
}
