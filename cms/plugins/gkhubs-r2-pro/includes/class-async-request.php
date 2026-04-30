<?php
/**
 * Async_Request：fire-and-forget 异步请求基类（仿 AS3CF_Async_Request）。
 * dispatch() 给自己的 admin-ajax.php 发 timeout=0.01 非阻塞 POST，
 * 当前请求立即返回；admin-ajax 那边 maybe_handle() 接住、过 nonce、调 handle()。
 */

namespace GKHubs\R2\Pro;

defined('ABSPATH') || exit;

abstract class Async_Request {
    protected $prefix = 'gkhubs_r2_pro';
    protected $action = 'async_request';
    protected $identifier;
    protected $data = [];

    public function __construct() {
        $this->identifier = $this->prefix . '_' . $this->action;
        add_action('wp_ajax_' . $this->identifier, [$this, 'maybe_handle']);
        add_action('wp_ajax_nopriv_' . $this->identifier, [$this, 'maybe_handle']);
    }

    public function data(array $data): self { $this->data = $data; return $this; }

    public function dispatch() {
        $url  = add_query_arg([
            'action' => $this->identifier,
            'nonce'  => wp_create_nonce($this->identifier),
        ], admin_url('admin-ajax.php'));
        return wp_remote_post(esc_url_raw($url), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'cookies'   => $_COOKIE,
            'body'      => $this->data,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
    }

    public function maybe_handle(): void {
        session_write_close();
        check_ajax_referer($this->identifier, 'nonce');
        $this->handle();
        wp_die();
    }

    abstract protected function handle(): void;
}
