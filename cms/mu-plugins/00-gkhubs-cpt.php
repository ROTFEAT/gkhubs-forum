<?php
/**
 * Plugin Name: gkhubs CPT
 * Description: figure 与 listing 的自定义文章类型
 */
defined('ABSPATH') || exit;

add_action('init', function () {
    register_post_type('gk_figure', [
        'label' => 'Figures',
        'labels' => [
            'name' => 'Figures',
            'singular_name' => 'Figure',
        ],
        // Headless：Next.js 是唯一的渲染端。关掉 WP 主题渲染单页/归档，
        // 保证搜索引擎不会看到 WP 风格的 URL。
        'public' => false,
        'show_ui' => true,              // wp-admin 里仍可编辑
        'show_in_menu' => true,
        'show_in_rest' => true,
        'rest_base' => 'figures',
        'has_archive' => false,
        'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'taxonomies' => ['gk_ip', 'gk_character', 'gk_studio'],
    ]);

    foreach (['gk_ip' => 'IPs', 'gk_character' => 'Characters', 'gk_studio' => 'Studios'] as $tax => $label) {
        register_taxonomy($tax, 'gk_figure', [
            'label' => $label,
            'show_in_rest' => true,
            'hierarchical' => false,
            // editor 角色（bot 用户）必须能通过 REST 创建/挂 term。
            // 默认 caps 是 admin-only（manage_categories），upsert 时 wp_set_object_terms() 会静默失败。
            'capabilities' => [
                'manage_terms' => 'edit_posts',
                'edit_terms'   => 'edit_posts',
                'delete_terms' => 'edit_posts',
                'assign_terms' => 'edit_posts',
            ],
        ]);
    }

    foreach ([
        'scale' => 'string',
        'material' => 'string',
        'release_date' => 'string',
        'msrp' => 'number',
        'msrp_currency' => 'string',
        'status' => 'string',
        'canonical_listing_id' => 'integer',
    ] as $key => $type) {
        register_post_meta('gk_figure', $key, [
            'type' => $type,
            'single' => true,
            'show_in_rest' => true,
        ]);
    }

    register_post_type('gk_listing', [
        'label' => 'Listings',
        'labels' => ['name' => 'Listings', 'singular_name' => 'Listing'],
        'public' => false,
        'show_in_rest' => true,
        'rest_base' => 'listings',
        'show_ui' => true,
        'show_in_menu' => true,
        'supports' => ['title', 'thumbnail', 'custom-fields'],
        'taxonomies' => ['gk_shop'],
    ]);

    register_taxonomy('gk_shop', 'gk_listing', [
        'label' => 'Shops',
        'show_in_rest' => true,
        'hierarchical' => false,
        'capabilities' => [
            'manage_terms' => 'edit_posts',
            'edit_terms'   => 'edit_posts',
            'delete_terms' => 'edit_posts',
            'assign_terms' => 'edit_posts',
        ],
    ]);

    foreach ([
        'shop_listing_url' => 'string',
        'price_current' => 'number',
        'price_currency' => 'string',
        'stock_status' => 'string',
        'listing_type' => 'string',
        'ship_from' => 'string',
        'fetched_at' => 'string',
        'gk_figure_ref' => 'integer',
        'match_confidence' => 'number',
        'raw_payload' => 'string',
    ] as $key => $type) {
        register_post_meta('gk_listing', $key, [
            'type' => $type,
            'single' => true,
            'show_in_rest' => true,
        ]);
    }
});

// shop_listing_url 的查询索引（mu-plugin 没有 register_activation_hook，用一次性 flag）
add_action('init', function () {
    if (get_option('gkhubs_listing_url_index_v1')) return;
    global $wpdb;
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(1) FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = %s
           AND index_name = 'gkhubs_listing_url_idx'",
        $wpdb->postmeta
    ));
    if ((int) $existing === 0) {
        $created = $wpdb->query(
            "CREATE INDEX gkhubs_listing_url_idx ON {$wpdb->postmeta} (meta_key(20), meta_value(64))"
        );
        if ($created === false) return;
    }
    update_option('gkhubs_listing_url_index_v1', 1);
}, 20);
