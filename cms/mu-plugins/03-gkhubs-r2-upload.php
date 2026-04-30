<?php
/**
 * Plugin Name: gkhubs R2 Upload
 * Description: 把上传的媒体直接 PUT 到 Cloudflare R2（用 SigV4 签名）。
 *              替代 Media Cloud 的复杂初始化与 Freemius 许可门——直接做事。
 * 所需 env: R2_ENDPOINT、R2_BUCKET、R2_ACCESS_KEY、R2_SECRET、可选 R2_PUBLIC_URL
 */
defined('ABSPATH') || exit;

if (!function_exists('gkhubs_r2_put')) {
    function gkhubs_r2_put($local_path, $r2_key, $content_type = 'application/octet-stream') {
        $endpoint = rtrim((string) getenv('R2_ENDPOINT'), '/');
        $bucket   = (string) getenv('R2_BUCKET');
        $access   = (string) getenv('R2_ACCESS_KEY');
        $secret   = (string) getenv('R2_SECRET');
        if (!$endpoint || !$bucket || !$access || !$secret) return false;
        if (!is_readable($local_path)) return false;

        $body = file_get_contents($local_path);
        if ($body === false) return false;
        $payload_hash = hash('sha256', $body);

        $now  = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $host = parse_url($endpoint, PHP_URL_HOST);

        // 规范化 R2 key：去开头斜杠，按段 URL 编码（保留 /）
        $r2_key = ltrim($r2_key, '/');
        $encoded_key = implode('/', array_map('rawurlencode', explode('/', $r2_key)));
        $canonical_uri = '/' . rawurlencode($bucket) . '/' . $encoded_key;

        $headers = [
            'host'                 => $host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date'           => $now,
            'content-type'         => $content_type,
        ];
        ksort($headers);
        $canonical_headers = '';
        $signed_headers_arr = [];
        foreach ($headers as $h => $v) {
            $canonical_headers .= $h . ':' . trim($v) . "\n";
            $signed_headers_arr[] = $h;
        }
        $signed_headers = implode(';', $signed_headers_arr);

        $canonical_request = implode("\n", [
            'PUT',
            $canonical_uri,
            '',
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ]);

        $credential_scope = "$date/auto/s3/aws4_request";
        $string_to_sign   = implode("\n", [
            'AWS4-HMAC-SHA256',
            $now,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion  = hash_hmac('sha256', 'auto', $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);

        $auth = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $access, $credential_scope, $signed_headers, $signature
        );

        $put_url = $endpoint . $canonical_uri;
        $ch = curl_init($put_url);
        $curl_headers = ['Authorization: ' . $auth];
        foreach ($headers as $h => $v) {
            $curl_headers[] = $h . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $curl_headers,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code !== 200 && $code !== 204) {
            error_log("[gkhubs-r2] PUT $put_url failed http=$code curl=$err body=" . substr((string) $resp, 0, 300));
            return false;
        }
        return true;
    }
}

// 上传完成时同步推一份到 R2，并把返回 URL 改写成 R2 公网地址
add_filter('wp_handle_upload', function ($upload) {
    if (empty($upload['file']) || empty($upload['url'])) return $upload;
    $upload_dir = wp_upload_dir();
    if (!empty($upload_dir['error'])) return $upload;

    $relpath = ltrim(str_replace($upload_dir['basedir'], '', $upload['file']), '/');
    $ok = gkhubs_r2_put($upload['file'], $relpath, $upload['type'] ?? 'application/octet-stream');
    if (!$ok) return $upload;

    $public_url = getenv('R2_PUBLIC_URL');
    if ($public_url) {
        $upload['url'] = rtrim($public_url, '/') . '/' . $relpath;
    } else {
        $upload['url'] = rtrim((string) getenv('R2_ENDPOINT'), '/') . '/' . getenv('R2_BUCKET') . '/' . $relpath;
    }
    update_option('gkhubs_r2_last_upload_url', $upload['url'], false);
    return $upload;
}, 99);

// 子尺寸（缩略图）生成完成后也推到 R2
add_filter('wp_generate_attachment_metadata', function ($metadata, $attachment_id) {
    if (empty($metadata['sizes'])) return $metadata;
    $upload_dir = wp_upload_dir();
    $base = trailingslashit($upload_dir['basedir']);
    $orig_dir = trailingslashit(dirname(get_attached_file($attachment_id)));
    foreach ($metadata['sizes'] as $size) {
        if (empty($size['file'])) continue;
        $local = $orig_dir . $size['file'];
        if (!is_readable($local)) continue;
        $relpath = ltrim(str_replace($base, '', $local), '/');
        gkhubs_r2_put($local, $relpath, $size['mime-type'] ?? 'image/jpeg');
    }
    return $metadata;
}, 99, 2);

// 让 WP 返回 R2 公网 URL 而不是本地路径
add_filter('wp_get_attachment_url', function ($url, $post_id) {
    $public = getenv('R2_PUBLIC_URL') ?: rtrim((string) getenv('R2_ENDPOINT'), '/') . '/' . getenv('R2_BUCKET');
    $upload_dir = wp_upload_dir();
    $base_url = $upload_dir['baseurl'];
    if (!$base_url || !$public) return $url;
    if (strpos($url, $base_url) === 0) {
        return $public . str_replace($base_url, '', $url);
    }
    return $url;
}, 99, 2);
