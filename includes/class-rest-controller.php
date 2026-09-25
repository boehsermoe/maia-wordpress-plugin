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
