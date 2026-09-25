<?php

use Maia\Elementor_Tree;
use PHPUnit\Framework\TestCase;

final class ElementorTreeTest extends TestCase {

	private function tree(): array {
		return array(
			array(
				'id'       => 'c1',
				'elType'   => 'container',
				'settings' => array( 'flex_direction' => 'column' ),
				'elements' => array(
					array(
						'id'         => 'h1',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array(
							'title'       => 'Summer <b>sale</b>',
							'title_color' => '#000',
						),
						'elements'   => array(),
					),
					array(
						'id'         => 't1',
						'elType'     => 'widget',
						'widgetType' => 'text-editor',
						'settings'   => array( 'editor' => '<p>' . str_repeat( 'word ', 40 ) . '</p>' ),
						'elements'   => array(),
					),
				),
			),
			array(
				'id'       => 'c2',
				'elType'   => 'container',
				'settings' => array(),
				'elements' => array(),
			),
		);
	}

	private function identity(): callable {
		return static function ( string $s ): string {
			return $s;
		};
	}

	public function test_find_returns_nested_element_or_null(): void {
		$this->assertSame( 'heading', Elementor_Tree::find( $this->tree(), 'h1' )['widgetType'] );
		$this->assertSame( 'c2', Elementor_Tree::find( $this->tree(), 'c2' )['id'] );
		$this->assertNull( Elementor_Tree::find( $this->tree(), 'nope' ) );
	}

	public function test_outline_drops_settings_and_keeps_a_short_plain_summary(): void {
		$outline = Elementor_Tree::outline( $this->tree() );

		$this->assertArrayNotHasKey( 'settings', $outline[0] );
		$this->assertArrayNotHasKey( 'summary', $outline[0] );
		$this->assertSame( 'Summer sale', $outline[0]['elements'][0]['summary'] );
		$this->assertSame( 120, mb_strlen( $outline[0]['elements'][1]['summary'] ) );
		$this->assertStringEndsWith( '…', $outline[0]['elements'][1]['summary'] );
		$this->assertArrayNotHasKey( 'elements', $outline[1] );
	}

	public function test_describe_lists_child_ids_instead_of_children(): void {
		$element = Elementor_Tree::describe( Elementor_Tree::find( $this->tree(), 'c1' ) );

		$this->assertSame( array( 'h1', 't1' ), $element['children'] );
		$this->assertSame( array( 'flex_direction' => 'column' ), $element['settings'] );
		$this->assertNull( $element['widgetType'] );
	}

	public function test_patch_changes_only_the_target_and_reports_before_and_after(): void {
		$result = Elementor_Tree::patch_settings( $this->tree(), 'h1', array( 'title' => 'Winter sale', 'align' => 'center' ), $this->identity() );

		$heading = Elementor_Tree::find( $result['elements'], 'h1' );
		$this->assertSame( 'Winter sale', $heading['settings']['title'] );
		$this->assertSame( '#000', $heading['settings']['title_color'] );
		$this->assertSame( array( 'title' => 'Summer <b>sale</b>', 'align' => null ), $result['before'] );
		$this->assertSame( array( 'title' => 'Winter sale', 'align' => 'center' ), $result['after'] );
		$this->assertSame( Elementor_Tree::find( $this->tree(), 't1' ), Elementor_Tree::find( $result['elements'], 't1' ) );
	}

	public function test_before_undoes_the_patch(): void {
		$changed = Elementor_Tree::patch_settings( $this->tree(), 'h1', array( 'title' => 'X', 'align' => 'left' ), $this->identity() );
		$undone  = Elementor_Tree::patch_settings( $changed['elements'], 'h1', $changed['before'], $this->identity() );

		$this->assertSame( Elementor_Tree::hash( $this->tree() ), Elementor_Tree::hash( $undone['elements'] ) );
	}

	public function test_patch_sanitizes_nested_strings(): void {
		$strip  = static function ( string $s ): string {
			return strip_tags( $s );
		};
		$result = Elementor_Tree::patch_settings( $this->tree(), 'h1', array( 'link' => array( 'url' => '<script>x</script>https://a.de' ) ), $strip );

		$this->assertSame( 'xhttps://a.de', Elementor_Tree::find( $result['elements'], 'h1' )['settings']['link']['url'] );
	}

	public function test_patch_of_unknown_element_returns_null(): void {
		$this->assertNull( Elementor_Tree::patch_settings( $this->tree(), 'nope', array( 'title' => 'x' ), $this->identity() ) );
	}

	public function test_patch_on_element_with_empty_list_settings(): void {
		$result = Elementor_Tree::patch_settings( $this->tree(), 'c2', array( 'min_height' => 100 ), $this->identity() );
		$this->assertSame( array( 'min_height' => 100 ), Elementor_Tree::find( $result['elements'], 'c2' )['settings'] );
	}

	/**
	 * @dataProvider invalid_patches
	 * @param mixed $patch Patch.
	 */
	public function test_validate_patch_rejects_bad_input( $patch ): void {
		$this->assertIsString( Elementor_Tree::validate_patch( $patch ) );
	}

	public function invalid_patches(): array {
		return array(
			'not an array' => array( 'title' ),
			'empty'        => array( array() ),
			'list'         => array( array( 'a', 'b' ) ),
			'bad key'      => array( array( 'ti tle' => 'x' ) ),
			'object value' => array( array( 'title' => new stdClass() ) ),
		);
	}

	public function test_validate_patch_accepts_scalars_arrays_and_null(): void {
		$this->assertNull( Elementor_Tree::validate_patch( array( 'title' => 'x', '_margin' => array( 'top' => '1' ), 'size' => 3, 'gone' => null ) ) );
	}
}
