<?php
/**
 * Plugin Name:       MAIA Connector
 * Plugin URI:        https://github.com/boehsermoe/maia-wordpress-plugin
 * Description:       Lets the MAIA commerce assistant read and edit Elementor pages and read and change the theme CSS through the WordPress REST API.
 * Version:           0.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Bennet Veit
 * License:           Proprietary
 * Text Domain:       maia-connector
 *
 * @package Maia
 */

defined( 'ABSPATH' ) || exit;

define( 'MAIA_PLUGIN_VERSION', '0.3.0' );

require_once __DIR__ . '/includes/class-elementor-tree.php';
require_once __DIR__ . '/includes/class-theme-css.php';
require_once __DIR__ . '/includes/class-rest-controller.php';

add_action( 'rest_api_init', array( \Maia\Rest_Controller::class, 'register' ) );
