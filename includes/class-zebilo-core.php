<?php
defined( 'ABSPATH' ) || exit;

final class Zebilo_Core {
    private static $instance;

    public static function instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'admin_init', array( $this, 'restrict_supplier_admin' ) );
        add_filter( 'show_admin_bar', array( $this, 'hide_supplier_admin_bar' ) );
    }

    public static function activate() {
        add_role( 'zebilo_supplier', 'تأمین‌کننده زبیلو', array(
            'read' => true,
            'zebilo_manage_supplier_products' => true,
        ) );

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'zebilo_supplier_history';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL,
            product_name varchar(255) NOT NULL,
            old_price decimal(20,6) NULL,
            new_price decimal(20,6) NULL,
            old_stock bigint(20) NULL,
            new_stock bigint(20) NULL,
            changed_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY user_id (user_id),
            KEY changed_at (changed_at)
        ) {$charset};";
        dbDelta( $sql );

        $page = get_page_by_path( 'supplier-panel' );
        if ( ! $page ) {
            $page_id = wp_insert_post( array(
                'post_title' => 'پنل تأمین‌کننده',
                'post_name' => 'supplier-panel',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_content' => '[zebilo_supplier_panel]',
            ) );
            if ( $page_id && ! is_wp_error( $page_id ) ) update_option( 'zebilo_supplier_panel_page_id', $page_id );
        } else {
            update_option( 'zebilo_supplier_panel_page_id', $page->ID );
        }
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public function register_routes() {
        if ( class_exists( 'Zebilo_Supplier' ) ) Zebilo_Supplier::register_routes();
    }

    public function restrict_supplier_admin() {
        if ( ! is_user_logged_in() || ! current_user_can( 'zebilo_manage_supplier_products' ) ) return;
        if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return;
        if ( is_admin() ) {
            $page_id = (int) get_option( 'zebilo_supplier_panel_page_id' );
            $url = $page_id ? get_permalink( $page_id ) : home_url( '/' );
            wp_safe_redirect( $url );
            exit;
        }
    }

    public function hide_supplier_admin_bar( $show ) {
        return ( is_user_logged_in() && current_user_can( 'zebilo_manage_supplier_products' ) ) ? false : $show;
    }
}
