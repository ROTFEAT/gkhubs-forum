<?php
/**
 * Plugin Name: gkhubs REST
 * Description: 给爬虫写入用的自定义 REST 端点
 */
defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route('gkhubs/v1', '/upsert-figure', [
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
        'callback' => 'gkhubs_upsert_figure',
    ]);
    register_rest_route('gkhubs/v1', '/upsert-listing', [
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
        'callback' => 'gkhubs_upsert_listing',
    ]);
    register_rest_route('gkhubs/v1', '/figures-with-listings', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => 'gkhubs_figures_with_listings',
        'args' => [
            'per_page' => ['default' => 20, 'sanitize_callback' => 'absint'],
            'page' => ['default' => 1, 'sanitize_callback' => 'absint'],
        ],
    ]);
});

function gkhubs_upsert_figure(WP_REST_Request $req) {
    $body = $req->get_json_params();
    if (empty($body['slug']) || empty($body['title'])) {
        return new WP_Error('bad_request', 'slug 和 title 必填', ['status' => 400]);
    }

    $existing = get_page_by_path($body['slug'], OBJECT, 'gk_figure');
    $post_id = $existing ? $existing->ID : 0;

    $args = [
        'ID' => $post_id,
        'post_type' => 'gk_figure',
        'post_status' => 'publish',
        'post_title' => sanitize_text_field($body['title']),
        'post_name' => sanitize_title($body['slug']),
        'post_content' => isset($body['description']) ? wp_kses_post($body['description']) : '',
    ];
    $post_id = $post_id ? wp_update_post($args, true) : wp_insert_post($args, true);
    if (is_wp_error($post_id)) return $post_id;

    foreach (['ip' => 'gk_ip', 'character' => 'gk_character', 'studio' => 'gk_studio'] as $key => $tax) {
        if (!empty($body[$key])) {
            wp_set_object_terms($post_id, [sanitize_text_field($body[$key])], $tax, false);
        }
    }

    foreach (['scale','material','release_date','msrp','msrp_currency','status','canonical_listing_id'] as $k) {
        if (array_key_exists($k, $body)) {
            update_post_meta($post_id, $k, $body[$k]);
        }
    }

    return [
        'id' => (int) $post_id,
        'slug' => get_post_field('post_name', $post_id),
    ];
}

function gkhubs_upsert_listing(WP_REST_Request $req) {
    $body = $req->get_json_params();
    foreach (['shop', 'shop_listing_url', 'title', 'price_current', 'price_currency', 'stock_status', 'fetched_at'] as $k) {
        if (!isset($body[$k]) || $body[$k] === '') {
            return new WP_Error('bad_request', "缺少字段: $k", ['status' => 400]);
        }
    }

    $existing = get_posts([
        'post_type' => 'gk_listing',
        'post_status' => 'any',
        'numberposts' => 1,
        'meta_query' => [[
            'key' => 'shop_listing_url',
            'value' => $body['shop_listing_url'],
            'compare' => '=',
        ]],
        'fields' => 'ids',
    ]);
    $post_id = $existing[0] ?? 0;

    $args = [
        'ID' => $post_id,
        'post_type' => 'gk_listing',
        'post_status' => 'publish',
        'post_title' => sanitize_text_field($body['title']),
    ];
    $post_id = $post_id ? wp_update_post($args, true) : wp_insert_post($args, true);
    if (is_wp_error($post_id)) return $post_id;

    wp_set_object_terms($post_id, [sanitize_text_field($body['shop'])], 'gk_shop', false);

    $figure_ref = 0;
    if (!empty($body['gk_figure_slug'])) {
        $fig = get_page_by_path($body['gk_figure_slug'], OBJECT, 'gk_figure');
        if ($fig) $figure_ref = (int) $fig->ID;
    } elseif (!empty($body['gk_figure_ref'])) {
        $figure_ref = (int) $body['gk_figure_ref'];
    }
    if ($figure_ref) update_post_meta($post_id, 'gk_figure_ref', $figure_ref);

    foreach ([
        'shop_listing_url','price_current','price_currency','stock_status',
        'listing_type','ship_from','fetched_at','match_confidence','raw_payload'
    ] as $k) {
        if (array_key_exists($k, $body)) update_post_meta($post_id, $k, $body[$k]);
    }

    return [
        'id' => (int) $post_id,
        'figure_ref' => $figure_ref,
    ];
}

function gkhubs_figures_with_listings(WP_REST_Request $req) {
    $per_page = min(50, max(1, (int) $req->get_param('per_page')));
    $page = max(1, (int) $req->get_param('page'));

    $q = new WP_Query([
        'post_type' => 'gk_figure',
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    $items = [];
    foreach ($q->posts as $p) {
        $listings = get_posts([
            'post_type' => 'gk_listing',
            'post_status' => 'publish',
            'numberposts' => 20,
            'meta_query' => [[
                'key' => 'gk_figure_ref',
                'value' => (string) $p->ID,
                'compare' => '=',
            ]],
        ]);
        $listing_payload = array_map(function ($l) {
            return [
                'id' => $l->ID,
                'title' => $l->post_title,
                'shop' => wp_get_post_terms($l->ID, 'gk_shop', ['fields' => 'names'])[0] ?? null,
                'shop_listing_url' => get_post_meta($l->ID, 'shop_listing_url', true),
                'price_current' => (float) get_post_meta($l->ID, 'price_current', true),
                'price_currency' => get_post_meta($l->ID, 'price_currency', true),
                'stock_status' => get_post_meta($l->ID, 'stock_status', true),
                'fetched_at' => get_post_meta($l->ID, 'fetched_at', true),
            ];
        }, $listings);

        $items[] = [
            'id' => $p->ID,
            'slug' => $p->post_name,
            'title' => $p->post_title,
            'ip' => wp_get_post_terms($p->ID, 'gk_ip', ['fields' => 'names'])[0] ?? null,
            'character' => wp_get_post_terms($p->ID, 'gk_character', ['fields' => 'names'])[0] ?? null,
            'studio' => wp_get_post_terms($p->ID, 'gk_studio', ['fields' => 'names'])[0] ?? null,
            'scale' => get_post_meta($p->ID, 'scale', true),
            'material' => get_post_meta($p->ID, 'material', true),
            'release_date' => get_post_meta($p->ID, 'release_date', true),
            'msrp' => (float) get_post_meta($p->ID, 'msrp', true),
            'msrp_currency' => get_post_meta($p->ID, 'msrp_currency', true),
            'status' => get_post_meta($p->ID, 'status', true),
            'listings' => $listing_payload,
        ];
    }

    return [
        'items' => $items,
        'page' => $page,
        'per_page' => $per_page,
        'total' => (int) $q->found_posts,
    ];
}
