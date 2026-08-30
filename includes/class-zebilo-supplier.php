<?php
defined( 'ABSPATH' ) || exit;

final class Zebilo_Supplier {
    const CAP = 'zebilo_manage_supplier_products';
    const NAMESPACE = 'zebilo/v1';
    const LOW_STOCK_THRESHOLD = 5;

    public static function init() {
        add_shortcode( 'zebilo_supplier_panel', array( __CLASS__, 'render_panel' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'template_redirect', array( __CLASS__, 'protect_panel' ) );
    }

    public static function register_routes() {
        register_rest_route( self::NAMESPACE, '/supplier/me', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'me' ),
            'permission_callback' => array( __CLASS__, 'permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/supplier/stats', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'stats' ),
            'permission_callback' => array( __CLASS__, 'permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/supplier/products', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'products' ),
            'permission_callback' => array( __CLASS__, 'permission' ),
            'args' => array(
                'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
                'per_page' => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
                'search' => array( 'type' => 'string', 'default' => '' ),
                'category' => array( 'type' => 'integer', 'default' => 0 ),
                'status' => array( 'type' => 'string', 'default' => 'all', 'enum' => array( 'all', 'instock', 'low', 'outofstock' ) ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/supplier/categories', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'categories' ),
            'permission_callback' => array( __CLASS__, 'permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/supplier/products/bulk', array(
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => array( __CLASS__, 'bulk_update' ),
            'permission_callback' => array( __CLASS__, 'permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/supplier/history', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'history' ),
            'permission_callback' => array( __CLASS__, 'permission' ),
            'args' => array(
                'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
                'per_page' => array( 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 100 ),
            ),
        ) );
    }

    public static function permission() {
        if ( ! is_user_logged_in() || ! current_user_can( self::CAP ) ) {
            return new WP_Error( 'zebilo_supplier_forbidden', 'دسترسی به پنل تأمین‌کننده مجاز نیست.', array( 'status' => 403 ) );
        }
        return true;
    }

    public static function me() {
        $user = wp_get_current_user();
        return rest_ensure_response( array(
            'id' => (int) $user->ID,
            'name' => $user->display_name ?: $user->user_login,
            'last_login' => get_user_meta( $user->ID, 'zebilo_last_login', true ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ) );
    }

    public static function products( WP_REST_Request $request ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return new WP_Error( 'woocommerce_required', 'ووکامرس فعال نیست.', array( 'status' => 500 ) );
        }

        $page = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
        $search = sanitize_text_field( (string) $request->get_param( 'search' ) );
        $category = (int) $request->get_param( 'category' );
        $status = sanitize_key( (string) $request->get_param( 'status' ) );

        $args = array(
            'status' => 'publish',
            'limit' => -1,
            'return' => 'objects',
            'orderby' => 'title',
            'order' => 'ASC',
            'type' => array( 'simple', 'variation' ),
        );
        if ( $search !== '' ) $args['search'] = $search;

        $items = wc_get_products( $args );
        $filtered = array();

        foreach ( $items as $product ) {
            if ( ! $product instanceof WC_Product ) continue;
            if ( $category && ! self::product_in_category( $product, $category ) ) continue;

            $stock = $product->get_stock_quantity();
            $stock = null === $stock ? 0 : (int) $stock;
            $normalized = ( 'outofstock' === $product->get_stock_status() || $stock <= 0 )
                ? 'outofstock'
                : ( $stock <= self::LOW_STOCK_THRESHOLD ? 'low' : 'instock' );

            if ( 'instock' === $status && 'instock' !== $normalized ) continue;
            if ( 'low' === $status && 'low' !== $normalized ) continue;
            if ( 'outofstock' === $status && 'outofstock' !== $normalized ) continue;

            $filtered[] = self::serialize_product( $product );
        }

        $total = count( $filtered );
        $offset = ( $page - 1 ) * $per_page;

        return new WP_REST_Response( array(
            'items' => array_slice( $filtered, $offset, $per_page ),
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'pages' => $total ? (int) ceil( $total / $per_page ) : 1,
        ) );
    }

    public static function categories() {
        $terms = get_terms( array(
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'orderby' => 'name',
            'order' => 'ASC',
        ) );
        if ( is_wp_error( $terms ) ) return array();

        return array_map( function ( $term ) {
            return array( 'id' => (int) $term->term_id, 'name' => $term->name, 'count' => (int) $term->count );
        }, $terms );
    }

    public static function stats() {
        $items = wc_get_products( array(
            'status' => 'publish',
            'limit' => -1,
            'return' => 'objects',
            'type' => array( 'simple', 'variation' ),
        ) );

        $stats = array( 'total' => 0, 'instock' => 0, 'low' => 0, 'outofstock' => 0 );
        foreach ( $items as $product ) {
            $stock = (int) $product->get_stock_quantity();
            $stats['total']++;
            if ( 'outofstock' === $product->get_stock_status() || $stock <= 0 ) $stats['outofstock']++;
            elseif ( $stock <= self::LOW_STOCK_THRESHOLD ) $stats['low']++;
            else $stats['instock']++;
        }
        return rest_ensure_response( $stats );
    }

    public static function bulk_update( WP_REST_Request $request ) {
        $body = $request->get_json_params();
        $updates = isset( $body['updates'] ) && is_array( $body['updates'] ) ? $body['updates'] : array();

        if ( empty( $updates ) ) return new WP_Error( 'zebilo_no_updates', 'هیچ تغییری برای ذخیره وجود ندارد.', array( 'status' => 400 ) );
        if ( count( $updates ) > 100 ) return new WP_Error( 'zebilo_too_many_updates', 'در هر درخواست حداکثر ۱۰۰ کالا قابل بروزرسانی است.', array( 'status' => 400 ) );

        $changed = array();
        $errors = array();

        foreach ( $updates as $update ) {
            $id = isset( $update['id'] ) ? absint( $update['id'] ) : 0;
            if ( ! $id ) {
                $errors[] = array( 'id' => 0, 'message' => 'شناسه کالا نامعتبر است.' );
                continue;
            }

            $product = wc_get_product( $id );
            if ( ! $product || ! $product->is_type( array( 'simple', 'variation' ) ) ) {
                $errors[] = array( 'id' => $id, 'message' => 'این کالا قابل ویرایش نیست.' );
                continue;
            }
            if ( 'publish' !== get_post_status( $product->get_id() ) ) {
                $errors[] = array( 'id' => $id, 'message' => 'فقط کالاهای منتشرشده قابل ویرایش هستند.' );
                continue;
            }

            $old_price = $product->get_regular_price();
            $old_stock = $product->get_stock_quantity();
            $price_changed = array_key_exists( 'regular_price', $update );
            $stock_changed = array_key_exists( 'stock_quantity', $update );

            if ( $price_changed ) {
                $price = wc_format_decimal( $update['regular_price'] );
                if ( '' === $price || (float) $price < 0 ) {
                    $errors[] = array( 'id' => $id, 'message' => 'قیمت نامعتبر است.' );
                    continue;
                }
                $product->set_regular_price( $price );
            }

            if ( $stock_changed ) {
                $stock = filter_var( $update['stock_quantity'], FILTER_VALIDATE_INT );
                if ( false === $stock || $stock < 0 ) {
                    $errors[] = array( 'id' => $id, 'message' => 'موجودی نامعتبر است.' );
                    continue;
                }
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $stock );
                $product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
            }

            if ( ! $price_changed && ! $stock_changed ) continue;

            $product->save();
            $new_price = $product->get_regular_price();
            $new_stock = $product->get_stock_quantity();

            if ( (string) $old_price !== (string) $new_price || (int) $old_stock !== (int) $new_stock ) {
                self::log_change( $product, $old_price, $new_price, $old_stock, $new_stock );
                $changed[] = array(
                    'id' => $id,
                    'name' => $product->get_name(),
                    'price' => $new_price,
                    'stock' => null === $new_stock ? 0 : (int) $new_stock,
                );
            }
        }

        return rest_ensure_response( array(
            'success' => true,
            'changed' => $changed,
            'errors' => $errors,
            'changed_count' => count( $changed ),
        ) );
    }

    public static function history( WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'zebilo_supplier_history';
        $page = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
        $offset = ( $page - 1 ) * $per_page;
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY changed_at DESC, id DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A );

        $items = array_map( function ( $row ) {
            return array(
                'id' => (int) $row['id'],
                'product_id' => (int) $row['product_id'],
                'variation_id' => (int) $row['variation_id'],
                'product_name' => $row['product_name'],
                'old_price' => $row['old_price'],
                'new_price' => $row['new_price'],
                'old_stock' => null === $row['old_stock'] ? null : (int) $row['old_stock'],
                'new_stock' => null === $row['new_stock'] ? null : (int) $row['new_stock'],
                'changed_at' => mysql2date( 'Y-m-d H:i:s', $row['changed_at'] ),
            );
        }, $rows ?: array() );

        return rest_ensure_response( array(
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'pages' => $total ? (int) ceil( $total / $per_page ) : 1,
        ) );
    }

    private static function serialize_product( WC_Product $product ) {
        $stock = $product->get_stock_quantity();
        $stock = null === $stock ? 0 : (int) $stock;
        $status = $stock <= 0 ? 'outofstock' : ( $stock <= self::LOW_STOCK_THRESHOLD ? 'low' : 'instock' );
        $categories = array();
        $parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : 0;
        $category_source = $parent_id ? wc_get_product( $parent_id ) : $product;

        if ( $category_source ) {
            foreach ( $category_source->get_category_ids() as $cat_id ) {
                $term = get_term( $cat_id, 'product_cat' );
                if ( $term && ! is_wp_error( $term ) ) $categories[] = $term->name;
            }
        }

        $name = $product->get_name();
        if ( $product->is_type( 'variation' ) && $parent_id ) {
            $parent = wc_get_product( $parent_id );
            if ( $parent ) $name = $parent->get_name() . ' — ' . wc_get_formatted_variation( $product, true, false, false );
        }

        return array(
            'id' => $product->get_id(),
            'name' => wp_strip_all_tags( $name ),
            'sku' => $product->get_sku() ?: '—',
            'image' => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: wc_placeholder_img_src( 'thumbnail' ),
            'category' => implode( '، ', $categories ),
            'regular_price' => $product->get_regular_price(),
            'current_price' => $product->get_price(),
            'stock_quantity' => $stock,
            'stock_status' => $status,
            'type' => $product->get_type(),
            'parent_id' => $parent_id,
            'permalink' => get_permalink( $product->get_id() ),
        );
    }

    private static function product_in_category( WC_Product $product, $category_id ) {
        $source_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
        return $source_id && has_term( $category_id, 'product_cat', $source_id );
    }

    private static function log_change( WC_Product $product, $old_price, $new_price, $old_stock, $new_stock ) {
        global $wpdb;
        $table = $wpdb->prefix . 'zebilo_supplier_history';

        $wpdb->insert(
            $table,
            array(
                'product_id' => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
                'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0,
                'user_id' => get_current_user_id(),
                'product_name' => $product->get_name(),
                'old_price' => '' === $old_price ? null : $old_price,
                'new_price' => '' === $new_price ? null : $new_price,
                'old_stock' => null === $old_stock ? null : (int) $old_stock,
                'new_stock' => null === $new_stock ? null : (int) $new_stock,
                'changed_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
        );
    }

    public static function enqueue_assets() {
        if ( ! is_user_logged_in() || ! current_user_can( self::CAP ) ) return;
        $page_id = (int) get_option( 'zebilo_supplier_panel_page_id' );
        if ( ! $page_id || ! is_page( $page_id ) ) return;

        wp_enqueue_style( 'zebilo-supplier', ZEBILO_CORE_URL . 'assets/supplier.css', array(), ZEBILO_CORE_VERSION );
        wp_enqueue_script( 'zebilo-supplier', ZEBILO_CORE_URL . 'assets/supplier.js', array(), ZEBILO_CORE_VERSION, true );

        wp_localize_script( 'zebilo-supplier', 'ZebiloSupplier', array(
            'apiBase' => esc_url_raw( rest_url( self::NAMESPACE ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'panelUrl' => esc_url_raw( get_permalink( $page_id ) ),
            'logoutUrl' => esc_url_raw( wp_logout_url( get_permalink( $page_id ) ) ),
            'currency' => 'تومان',
            'lowStockThreshold' => self::LOW_STOCK_THRESHOLD,
            'strings' => array(
                'saved' => 'تغییرات با موفقیت ذخیره شد.',
                'error' => 'ذخیره تغییرات انجام نشد. دوباره تلاش کنید.',
                'noChanges' => 'تغییری برای ذخیره وجود ندارد.',
                'loading' => 'در حال دریافت اطلاعات...',
            ),
        ) );
    }

    public static function render_panel() {
        if ( ! is_user_logged_in() ) return '<div class="zebilo-login-required"><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">ورود به پنل تأمین‌کننده</a></div>';
        if ( ! current_user_can( self::CAP ) ) return '<div class="zebilo-login-required">شما دسترسی لازم برای ورود به پنل تأمین‌کننده را ندارید.</div>';
        return '<div id="zebilo-supplier-app" dir="rtl" lang="fa"></div>';
    }

    public static function protect_panel() {
        $page_id = (int) get_option( 'zebilo_supplier_panel_page_id' );
        if ( ! $page_id || ! is_page( $page_id ) ) return;
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( get_permalink() ) );
            exit;
        }
        if ( ! current_user_can( self::CAP ) ) {
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }
    }
}

add_action( 'init', array( 'Zebilo_Supplier', 'init' ) );
