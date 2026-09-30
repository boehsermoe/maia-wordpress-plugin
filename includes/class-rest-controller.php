<?php
/**
 * REST routes that let MAIA read and edit Elementor content.
 *
 * Authentication is WordPress's own (application password over Basic auth for MAIA).
 * Every route checks the capability of the calling user, so MAIA can never do more than that user.
 *
 * @package Maia
 */

namespace Maia;

use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /wp-json/maia/v1.
 */
final class Rest_Controller {

	const NAMESPACE = 'maia/v1';

	const LISTED_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Registers the routes.
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'status' ),
				'permission_callback' => array( self::class, 'can_edit_posts' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/elementor/documents',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'list_documents' ),
				'permission_callback' => array( self::class, 'can_edit_posts' ),
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'search'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cache/purge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'purge_cache' ),
				'permission_callback' => array( self::class, 'can_manage_site' ),
				'args'                => array(
					'url' => array(
						'type'        => 'string',
						'description' => 'Purge only this page of the site. Without it the whole cache is emptied.',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/theme',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_theme' ),
				'permission_callback' => array( self::class, 'can_edit_theme' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/theme/file',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_theme_file' ),
				'permission_callback' => array( self::class, 'can_edit_theme' ),
				'args'                => array(
					'theme' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Stylesheet slug of the active theme or of its parent theme.',
					),
					'path'  => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Relative path of a .css file inside that theme, as listed by GET /theme.',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/theme/css',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'update_theme_css' ),
				'permission_callback' => array( self::class, 'can_edit_css' ),
				'args'                => array(
					'css'           => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'The complete new Additional CSS of the active theme. An empty text removes it.',
					),
					'expected_hash' => array(
						'type'        => 'string',
						'description' => 'Hash from the last read. The change is refused with 409 if the CSS changed since.',
					),
				),
			)
		);

		$document_id = array(
			'type'     => 'integer',
			'required' => true,
		);

		register_rest_route(
			self::NAMESPACE,
			'/elementor/documents/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_document' ),
				'permission_callback' => array( self::class, 'can_edit_document' ),
				'args'                => array(
					'id'     => $document_id,
					'format' => array(
						'type'        => 'string',
						'enum'        => array( 'outline', 'full' ),
						'default'     => 'outline',
						'description' => 'outline: structure and a short text per element; full: the whole element tree with all settings.',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/elementor/documents/(?P<id>\d+)/elements/(?P<element_id>[A-Za-z0-9_\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_element' ),
					'permission_callback' => array( self::class, 'can_edit_document' ),
					'args'                => array( 'id' => $document_id ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( self::class, 'update_element' ),
					'permission_callback' => array( self::class, 'can_edit_document' ),
					'args'                => array(
						'id'            => $document_id,
						'settings'      => array(
							'type'        => 'object',
							'required'    => true,
							'description' => 'Setting key => new value. null removes the setting.',
						),
						'expected_hash' => array(
							'type'        => 'string',
							'description' => 'Hash from the last read. The change is refused with 409 if the document changed since.',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission: any user who may edit posts.
	 */
	public static function can_edit_posts(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission: site administrators (manage_options), for actions on the whole site such as emptying the cache.
	 */
	public static function can_manage_site(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission: may look at the theme (Appearance).
	 */
	public static function can_edit_theme(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Permission: may change the Additional CSS. Needs the theme options (as the Customizer does) AND edit_css.
	 * WordPress maps edit_css to unfiltered_html, which editors have on a single site: edit_css alone would let
	 * an editor change the site's design. It is denied when DISALLOW_UNFILTERED_HTML is set and to
	 * non-super-admins on a multisite.
	 */
	public static function can_edit_css(): bool {
		return current_user_can( 'edit_theme_options' ) && current_user_can( 'edit_css' );
	}

	/**
	 * Permission: the user may edit this post. A missing post yields 404 in the callback, not here.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function can_edit_document( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post ) {
			return current_user_can( 'edit_posts' );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return self::forbidden();
		}
		return true;
	}

	/**
	 * GET /status: lets MAIA detect the plugin and whether Elementor is usable.
	 */
	public static function status(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'plugin_version'    => MAIA_PLUGIN_VERSION,
				'api_version'       => 1,
				'wordpress_version' => get_bloginfo( 'version' ),
				'elementor'         => array(
					'active'  => self::elementor_active(),
					'version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
				),
				'cache'             => Cache_Purger::detect(),
			)
		);
	}

	/**
	 * GET /elementor/documents: posts, pages and templates built with Elementor that the user may edit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_documents( WP_REST_Request $request ) {
		$inactive = self::require_elementor();
		if ( $inactive ) {
			return $inactive;
		}

		$args = array(
			'post_type'      => array_values( array_diff( get_post_types( array( 'show_ui' => true ) ), array( 'attachment' ) ) ),
			'post_status'    => self::LISTED_STATUSES,
			'posts_per_page' => (int) $request['per_page'],
			'paged'          => (int) $request['page'],
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_key'       => '_elementor_edit_mode', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => 'builder', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		);
		if ( ! empty( $request['search'] ) ) {
			$args['s'] = $request['search'];
		}
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}

		$query     = new WP_Query( $args );
		$documents = array();
		foreach ( $query->posts as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$documents[] = self::post_summary( $post );
			}
		}

		$response = new WP_REST_Response( $documents );
		$response->header( 'X-WP-Total', (string) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) $query->max_num_pages );
		return $response;
	}

	/**
	 * GET /elementor/documents/{id}: the element tree of one document.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_document( WP_REST_Request $request ) {
		$document = self::load_document( (int) $request['id'] );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		$elements = self::elements_of( $document );

		return new WP_REST_Response(
			self::post_summary( $document->get_post() ) + array(
				'hash'     => Elementor_Tree::hash( $elements ),
				'format'   => $request['format'],
				'elements' => 'full' === $request['format'] ? $elements : Elementor_Tree::outline( $elements ),
			)
		);
	}

	/**
	 * GET /elementor/documents/{id}/elements/{element_id}: one element with all its settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_element( WP_REST_Request $request ) {
		$document = self::load_document( (int) $request['id'] );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		$elements = self::elements_of( $document );
		$element  = Elementor_Tree::find( $elements, (string) $request['element_id'] );
		if ( null === $element ) {
			return self::element_not_found();
		}

		return new WP_REST_Response(
			array(
				'document_id' => $document->get_main_id(),
				'hash'        => Elementor_Tree::hash( $elements ),
				'element'     => Elementor_Tree::describe( $element ),
			)
		);
	}

	/**
	 * PATCH /elementor/documents/{id}/elements/{element_id}: changes settings of one element.
	 *
	 * Saves through Elementor's document API, so Elementor normalizes the data, keeps a revision
	 * and regenerates the page CSS. The response's `before` undoes the change when sent as `settings`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_element( WP_REST_Request $request ) {
		$patch   = $request->get_param( 'settings' );
		$invalid = Elementor_Tree::validate_patch( $patch );
		if ( $invalid ) {
			return new WP_Error( 'maia_invalid_settings', $invalid, array( 'status' => 400 ) );
		}

		$document = self::load_document( (int) $request['id'] );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		if ( ! $document->is_editable_by_current_user() ) {
			return self::forbidden();
		}

		$elements = self::elements_of( $document );
		$expected = $request->get_param( 'expected_hash' );
		if ( is_string( $expected ) && '' !== $expected && ! hash_equals( Elementor_Tree::hash( $elements ), $expected ) ) {
			return new WP_Error( 'maia_conflict', 'The document was changed since it was read. Read it again before changing it.', array( 'status' => 409 ) );
		}

		$sanitize = current_user_can( 'unfiltered_html' )
			? static function ( string $value ): string {
				return $value;
			}
			: 'wp_kses_post';
		$result   = Elementor_Tree::patch_settings( $elements, (string) $request['element_id'], $patch, $sanitize );
		if ( null === $result ) {
			return self::element_not_found();
		}

		if ( ! $document->save( array( 'elements' => $result['elements'] ) ) ) {
			return new WP_Error( 'maia_save_failed', 'Elementor did not save the document.', array( 'status' => 500 ) );
		}

		// Read back what Elementor stored: it may have normalized the values.
		$saved    = self::load_document( $document->get_main_id() );
		$elements = is_wp_error( $saved ) ? $result['elements'] : self::elements_of( $saved );
		$element  = Elementor_Tree::find( $elements, (string) $request['element_id'] );

		return new WP_REST_Response(
			array(
				'document_id' => $document->get_main_id(),
				'element_id'  => (string) $request['element_id'],
				'before'      => $result['before'],
				'after'       => $result['after'],
				'hash'        => Elementor_Tree::hash( $elements ),
				'element'     => null === $element ? null : Elementor_Tree::describe( $element ),
			)
		);
	}

	/**
	 * GET /theme: what MAIA needs to work out which kind of theme this is and where its CSS lives:
	 * the active theme (and its parent), whether it is a block theme, the Additional CSS and the
	 * stylesheet files of the theme.
	 */
	public static function get_theme(): WP_REST_Response {
		$theme  = wp_get_theme();
		$parent = $theme->parent();
		$files  = array();
		$cut    = false;
		foreach ( array_filter( array( $theme, $parent ) ) as $source ) {
			$listing = Theme_Css::list_files( $source->get_stylesheet_directory() );
			foreach ( $listing['files'] as $file ) {
				$files[] = array( 'theme' => $source->get_stylesheet() ) + $file;
			}
			$cut = $cut || $listing['truncated'];
		}
		$css = self::additional_css();

		return new WP_REST_Response(
			array(
				'theme'          => array(
					'stylesheet'     => $theme->get_stylesheet(),
					'name'           => (string) $theme->get( 'Name' ),
					'version'        => (string) $theme->get( 'Version' ),
					'is_block_theme' => $theme->is_block_theme(),
					'parent'         => $parent ? array(
						'stylesheet' => $parent->get_stylesheet(),
						'name'       => (string) $parent->get( 'Name' ),
					) : null,
				),
				'additional_css' => array(
					'css'      => $css,
					'hash'     => Theme_Css::hash( $css ),
					'writable' => self::can_edit_css(),
				),
				'files'          => $files,
				'files_cut'      => $cut,
			)
		);
	}

	/**
	 * GET /theme/file: one stylesheet of the active theme or its parent (read only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_theme_file( WP_REST_Request $request ) {
		$theme  = wp_get_theme();
		$wanted = (string) $request['theme'];
		$source = null;
		foreach ( array_filter( array( $theme, $theme->parent() ) ) as $candidate ) {
			if ( $candidate->get_stylesheet() === $wanted ) {
				$source = $candidate;
			}
		}
		if ( null === $source ) {
			return new WP_Error( 'maia_theme_unknown', 'Only the active theme and its parent theme can be read.', array( 'status' => 404 ) );
		}
		if ( ! Theme_Css::is_safe_path( $request['path'] ) ) {
			return new WP_Error( 'maia_theme_file_invalid', 'The path must be a plain relative path to a .css file.', array( 'status' => 400 ) );
		}
		$file = Theme_Css::read_file( $source->get_stylesheet_directory(), $request['path'] );
		if ( null === $file ) {
			return new WP_Error( 'maia_theme_file_not_found', 'This stylesheet does not exist in the theme.', array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'theme'     => $wanted,
				'path'      => (string) $request['path'],
				'css'       => $file['css'],
				'size'      => $file['size'],
				'truncated' => $file['truncated'],
			)
		);
	}

	/**
	 * PUT /theme/css: replaces the Additional CSS of the active theme (the custom_css post, as the
	 * Customizer saves it). The response's `before` undoes the change when sent as `css`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_theme_css( WP_REST_Request $request ) {
		$css     = $request->get_param( 'css' );
		$invalid = Theme_Css::validate_css( $css );
		if ( $invalid ) {
			return new WP_Error( 'maia_invalid_css', $invalid, array( 'status' => 400 ) );
		}

		$before   = self::additional_css();
		$expected = $request->get_param( 'expected_hash' );
		if ( is_string( $expected ) && '' !== $expected && ! hash_equals( Theme_Css::hash( $before ), $expected ) ) {
			return new WP_Error( 'maia_conflict', 'The Additional CSS was changed since it was read. Read it again before changing it.', array( 'status' => 409 ) );
		}

		$saved = wp_update_custom_css_post( $css, array( 'stylesheet' => get_stylesheet() ) );
		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'maia_save_failed', 'WordPress did not save the Additional CSS.', array( 'status' => 500 ) );
		}

		// Read back what WordPress stored.
		$after = self::additional_css();
		return new WP_REST_Response(
			array(
				'stylesheet' => get_stylesheet(),
				'before'     => $before,
				'after'      => $after,
				'hash'       => Theme_Css::hash( $after ),
			)
		);
	}

	/**
	 * The stored Additional CSS of the active theme. Read from the post itself: wp_get_custom_css() also
	 * applies filters, and writing that back would save CSS other code adds.
	 */
	private static function additional_css(): string {
		$post = wp_get_custom_css_post();
		return $post ? (string) $post->post_content : '';
	}

	/**
	 * POST /cache/purge: empties the caches of the site, or purges one page of it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function purge_cache( WP_REST_Request $request ) {
		$url = $request->get_param( 'url' );
		if ( null === $url || '' === $url ) {
			return new WP_REST_Response(
				array( 'scope' => 'all' ) + Cache_Purger::purge_all()
			);
		}
		if ( ! is_string( $url ) || ! Cache_Purger::is_site_url( $url, home_url() ) ) {
			return new WP_Error( 'maia_cache_url_outside_site', 'The URL is not a page of this site.', array( 'status' => 400 ) );
		}
		return new WP_REST_Response(
			array( 'scope' => 'url' ) + Cache_Purger::purge_url( $url )
		);
	}

	/**
	 * Whether Elementor is loaded.
	 */
	private static function elementor_active(): bool {
		return did_action( 'elementor/loaded' ) > 0 && class_exists( '\Elementor\Plugin' );
	}

	/**
	 * An error if Elementor is not active, otherwise null.
	 */
	private static function require_elementor(): ?WP_Error {
		return self::elementor_active()
			? null
			: new WP_Error( 'maia_elementor_inactive', 'Elementor is not active on this site.', array( 'status' => 503 ) );
	}

	/**
	 * Loads an Elementor document, fresh from the database.
	 *
	 * @param int $post_id Post id.
	 * @return \Elementor\Core\Base\Document|WP_Error
	 */
	private static function load_document( int $post_id ) {
		$inactive = self::require_elementor();
		if ( $inactive ) {
			return $inactive;
		}
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'maia_not_found', 'Document not found.', array( 'status' => 404 ) );
		}
		$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
		if ( ! $document || ! $document->is_built_with_elementor() ) {
			return new WP_Error( 'maia_not_elementor', 'This post is not built with Elementor.', array( 'status' => 404 ) );
		}
		return $document;
	}

	/**
	 * Element tree of a document.
	 *
	 * @param \Elementor\Core\Base\Document $document Document.
	 */
	private static function elements_of( $document ): array {
		$elements = $document->get_elements_data();
		return is_array( $elements ) ? $elements : array();
	}

	/**
	 * Fields every document response starts with.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function post_summary( \WP_Post $post ): array {
		return array(
			'id'       => $post->ID,
			'title'    => get_the_title( $post ),
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'link'     => get_permalink( $post ),
			'modified' => mysql_to_rfc3339( $post->post_modified_gmt ),
		);
	}

	/**
	 * 401/403 for a document the user may not edit.
	 */
	private static function forbidden(): WP_Error {
		return new WP_Error( 'rest_forbidden', 'You may not edit this document.', array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * 404 for an unknown element id.
	 */
	private static function element_not_found(): WP_Error {
		return new WP_Error( 'maia_element_not_found', 'No element with this id in the document.', array( 'status' => 404 ) );
	}
}
