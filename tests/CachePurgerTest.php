<?php
// WordPress and cache plugin functions the purger calls, as recording stubs. Functions cannot be
// undefined again, so this file only defines the ones its tests need: every other cache system
// counts as "not installed", which is what the tests rely on too.

use Maia\Cache_Purger;
use PHPUnit\Framework\TestCase;

$GLOBALS['maia_calls'] = array();

function wp_parse_url( $url ) {
	return parse_url( $url );
}
function has_action() {
	return false;
}
function do_action( $hook, ...$args ) {
	$GLOBALS['maia_calls'][] = array_merge( array( 'do_action:' . $hook ), $args );
}
function rocket_clean_domain() {
	$GLOBALS['maia_calls'][] = array( 'rocket_clean_domain' );
}
function rocket_clean_files( $urls ) {
	$GLOBALS['maia_calls'][] = array( 'rocket_clean_files', $urls );
}
function w3tc_flush_all() {
	$GLOBALS['maia_calls'][] = array( 'w3tc_flush_all' );
	throw new RuntimeException( 'Disk full' );
}
function w3tc_flush_url( $url ) {
	$GLOBALS['maia_calls'][] = array( 'w3tc_flush_url', $url );
}
function wp_cache_flush() {
	$GLOBALS['maia_calls'][] = array( 'wp_cache_flush' );
	return true;
}

final class CachePurgerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['maia_calls'] = array();
	}

	private function calls(): array {
		return array_map( static fn( $call ) => $call[0], $GLOBALS['maia_calls'] );
	}

	public function test_is_site_url_accepts_pages_of_the_site_only(): void {
		$home = 'https://www.shop.de/blog';
		$this->assertTrue( Cache_Purger::is_site_url( 'https://shop.de/landing/', $home ) );
		$this->assertTrue( Cache_Purger::is_site_url( 'http://WWW.SHOP.DE/a?b=1#c', $home ) );
		$this->assertFalse( Cache_Purger::is_site_url( 'https://shop.de.evil.example/', $home ) );
		$this->assertFalse( Cache_Purger::is_site_url( 'https://sub.shop.de/', $home ) );
		$this->assertFalse( Cache_Purger::is_site_url( 'https://user:pw@shop.de/', $home ) );
		$this->assertFalse( Cache_Purger::is_site_url( 'javascript://shop.de/%0Aalert(1)', $home ) );
		$this->assertFalse( Cache_Purger::is_site_url( 'ftp://shop.de/', $home ) );
		$this->assertFalse( Cache_Purger::is_site_url( '/relative', $home ) );
		$this->assertFalse( Cache_Purger::is_site_url( 'not a url', $home ) );
	}

	public function test_detect_lists_the_active_caches_without_purging_anything(): void {
		$this->assertSame(
			array(
				'active'    => array( 'WP Rocket', 'W3 Total Cache', 'WordPress object cache' ),
				'url_purge' => array( 'WP Rocket', 'W3 Total Cache' ),
			),
			Cache_Purger::detect()
		);
		$this->assertSame( array(), $GLOBALS['maia_calls'] );
	}

	public function test_purge_all_empties_the_active_caches_and_reports_the_one_that_failed(): void {
		$result = Cache_Purger::purge_all();

		$this->assertSame( array( 'WP Rocket', 'WordPress object cache' ), $result['cleared'] );
		$this->assertSame( array( 'W3 Total Cache' ), $result['failed'] );
		// The failing cache did not stop the caches after it.
		$this->assertSame( array( 'rocket_clean_domain', 'w3tc_flush_all', 'wp_cache_flush' ), $this->calls() );
	}

	public function test_purge_url_only_uses_caches_that_can_purge_one_page(): void {
		$result = Cache_Purger::purge_url( 'https://shop.de/landing/' );

		$this->assertSame( array( 'WP Rocket', 'W3 Total Cache' ), $result['cleared'] );
		$this->assertSame( array(), $result['failed'] );
		$this->assertSame( array( 'rocket_clean_files', array( 'https://shop.de/landing/' ) ), $GLOBALS['maia_calls'][0] );
		$this->assertSame( array( 'w3tc_flush_url', 'https://shop.de/landing/' ), $GLOBALS['maia_calls'][1] );
		// The object cache is not flushed for one page.
		$this->assertNotContains( 'wp_cache_flush', $this->calls() );
	}

	// Last on purpose: the constant stays defined for the rest of the run.
	public function test_litespeed_is_purged_through_its_action(): void {
		define( 'LSCWP_V', '7.0' );

		$all = Cache_Purger::purge_all();
		$this->assertContains( 'LiteSpeed Cache', $all['cleared'] );
		$this->assertContains( array( 'do_action:litespeed_purge_all' ), $GLOBALS['maia_calls'] );

		$page = Cache_Purger::purge_url( 'https://shop.de/x/' );
		$this->assertContains( 'LiteSpeed Cache', $page['cleared'] );
		$this->assertContains( array( 'do_action:litespeed_purge_url', 'https://shop.de/x/' ), $GLOBALS['maia_calls'] );
	}
}
