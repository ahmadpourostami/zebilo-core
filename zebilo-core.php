<?php
/**
 * Plugin Name: Zebilo Core
 * Description: Core API and supplier panel for Zebilo WooCommerce.
 * Version: 1.0.1
 * Author: Zebilo
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: zebilo-core
 */
defined( 'ABSPATH' ) || exit;
define( 'ZEBILO_CORE_VERSION', '1.0.1' );
define( 'ZEBILO_CORE_FILE', __FILE__ );
define( 'ZEBILO_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZEBILO_CORE_URL', plugin_dir_url( __FILE__ ) );
require_once ZEBILO_CORE_DIR . 'includes/class-zebilo-core.php';
require_once ZEBILO_CORE_DIR . 'includes/class-zebilo-supplier.php';
register_activation_hook( __FILE__, array( 'Zebilo_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Zebilo_Core', 'deactivate' ) );
add_action( 'plugins_loaded', function () { Zebilo_Core::instance(); } );
