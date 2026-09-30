<?php

use Maia\Theme_Css;
use PHPUnit\Framework\TestCase;

final class ThemeCssTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/maia-theme-' . uniqid();
		mkdir( $this->root . '/assets/css', 0777, true );
		mkdir( $this->root . '/node_modules/lib', 0777, true );
		file_put_contents( $this->root . '/style.css', "/*\nTheme Name: Klassic\n*/\nbody{color:#222}" );
		file_put_contents( $this->root . '/assets/css/main.css', 'a{color:red}' );
		file_put_contents( $this->root . '/node_modules/lib/x.css', 'x{}' );
		file_put_contents( $this->root . '/functions.php', '<?php // secret' );
		file_put_contents( $this->root . '/notes.txt', 'not css' );
	}

	protected function tearDown(): void {
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $this->root );
	}

	public function test_hash_changes_with_the_text(): void {
		$this->assertSame( Theme_Css::hash( 'a{}' ), Theme_Css::hash( 'a{}' ) );
		$this->assertNotSame( Theme_Css::hash( 'a{}' ), Theme_Css::hash( 'b{}' ) );
	}

	public function test_valid_css_passes_and_empty_css_is_allowed(): void {
		$this->assertNull( Theme_Css::validate_css( 'a > b { content: "<"; }' ) );
		$this->assertNull( Theme_Css::validate_css( '' ) );
	}

	public function test_markup_and_oversized_or_non_text_css_are_refused(): void {
		$this->assertStringContainsString( 'Markup', Theme_Css::validate_css( 'a{}</style><script>x</script>' ) );
		$this->assertStringContainsString( 'Markup', Theme_Css::validate_css( '<style>a{}' ) );
		$this->assertStringContainsString( 'longer', Theme_Css::validate_css( str_repeat( 'a', Theme_Css::MAX_CSS_LENGTH + 1 ) ) );
		$this->assertSame( 'css must be a text.', Theme_Css::validate_css( array( 'a' ) ) );
		$this->assertSame( 'css must be a text.', Theme_Css::validate_css( null ) );
	}

	/**
	 * @dataProvider unsafe_paths
	 */
	public function test_unsafe_paths_are_refused( $path ): void {
		$this->assertFalse( Theme_Css::is_safe_path( $path ) );
	}

	public function unsafe_paths(): array {
		return array(
			'traversal'       => array( '../wp-config.css' ),
			'nested traversal' => array( 'assets/../../x.css' ),
			'absolute'        => array( '/etc/passwd.css' ),
			'windows'         => array( 'assets\\main.css' ),
			'php file'        => array( 'functions.php' ),
			'no extension'    => array( 'style' ),
			'double extension' => array( 'style.css.php' ),
			'null byte'       => array( "style.css\0.php" ),
			'skipped dir'     => array( 'node_modules/lib/x.css' ),
			'empty segment'   => array( 'assets//main.css' ),
			'trailing newline' => array( "style.css\n" ),
			'skipped dir, upper case' => array( 'NODE_MODULES/lib/x.css' ),
			'empty'           => array( '' ),
			'not a string'    => array( array( 'style.css' ) ),
		);
	}

	public function test_plain_relative_css_paths_are_safe(): void {
		$this->assertTrue( Theme_Css::is_safe_path( 'style.css' ) );
		$this->assertTrue( Theme_Css::is_safe_path( 'assets/css/main.min.css' ) );
		$this->assertTrue( Theme_Css::is_safe_path( 'STYLE.CSS' ) );
	}

	public function test_lists_only_css_files_of_the_theme_with_style_css_first(): void {
		$listing = Theme_Css::list_files( $this->root );
		$this->assertSame( array( 'style.css', 'assets/css/main.css' ), array_column( $listing['files'], 'path' ) );
		$this->assertFalse( $listing['truncated'] );
		$this->assertSame( strlen( "/*\nTheme Name: Klassic\n*/\nbody{color:#222}" ), $listing['files'][0]['size'] );
	}

	public function test_listing_a_missing_directory_is_empty(): void {
		$this->assertSame( array(), Theme_Css::list_files( $this->root . '/missing' )['files'] );
	}

	public function test_reads_a_stylesheet(): void {
		$file = Theme_Css::read_file( $this->root, 'assets/css/main.css' );
		$this->assertSame( 'a{color:red}', $file['css'] );
		$this->assertFalse( $file['truncated'] );
	}

	public function test_a_long_file_is_cut_and_marked(): void {
		file_put_contents( $this->root . '/big.css', str_repeat( 'a{}', Theme_Css::MAX_FILE_BYTES ) );
		$file = Theme_Css::read_file( $this->root, 'big.css' );
		$this->assertTrue( $file['truncated'] );
		$this->assertSame( Theme_Css::MAX_FILE_BYTES, strlen( $file['css'] ) );
		$this->assertSame( 3 * Theme_Css::MAX_FILE_BYTES, $file['size'] );
	}

	public function test_a_cut_inside_a_multibyte_character_stays_valid_utf8(): void {
		file_put_contents( $this->root . '/utf.css', str_repeat( 'ä', Theme_Css::MAX_FILE_BYTES ) );
		$file = Theme_Css::read_file( $this->root, 'utf.css' );
		$this->assertTrue( mb_check_encoding( $file['css'], 'UTF-8' ) );
	}

	public function test_files_outside_the_theme_or_missing_ones_are_not_read(): void {
		$this->assertNull( Theme_Css::read_file( $this->root, '../style.css' ) );
		$this->assertNull( Theme_Css::read_file( $this->root, 'missing.css' ) );
		$this->assertNull( Theme_Css::read_file( $this->root, 'functions.php' ) );
		$this->assertNull( Theme_Css::read_file( $this->root, 'node_modules/lib/x.css' ) );
	}

	public function test_a_symlink_that_leads_out_of_the_theme_is_not_followed(): void {
		$outside = sys_get_temp_dir() . '/maia-outside-' . uniqid() . '.css';
		file_put_contents( $outside, 'secret{}' );
		symlink( $outside, $this->root . '/link.css' );

		$this->assertNull( Theme_Css::read_file( $this->root, 'link.css' ) );
		$this->assertNotContains( 'link.css', array_column( Theme_Css::list_files( $this->root )['files'], 'path' ) );

		unlink( $outside );
	}
}
