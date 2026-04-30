<?php
/**
 * R2 (S3 兼容) 客户端：手写 AWS SigV4 签名。零外部依赖。
 * 支持 PUT / GET / HEAD / DELETE / LIST。
 */

namespace GKHubs\R2\Pro;

defined('ABSPATH') || exit;

class Client {
    private $endpoint;
    private $bucket;
    private $access;
    private $secret;
    private $region;

    public function __construct(array $cfg) {
        $this->endpoint = rtrim($cfg['endpoint'] ?? '', '/');
        $this->bucket   = $cfg['bucket'] ?? '';
        $this->access   = $cfg['access_key'] ?? '';
        $this->secret   = $cfg['secret'] ?? '';
        $this->region   = $cfg['region'] ?? 'auto';
    }

    public function ready(): bool {
        return !empty($this->endpoint) && !empty($this->bucket) && !empty($this->access) && !empty($this->secret);
    }

    /** 上传文件到 R2 */
    public function put_file(string $key, string $local_path, string $content_type = 'application/octet-stream'): array {
        if (!is_readable($local_path)) {
            return ['ok' => false, 'error' => "local not readable: $local_path"];
        }
        $body = file_get_contents($local_path);
        return $this->put_bytes($key, $body, $content_type);
    }

    /** 上传字节流到 R2 */
    public function put_bytes(string $key, string $body, string $content_type = 'application/octet-stream'): array {
        return $this->request('PUT', $key, '', $body, ['content-type' => $content_type]);
    }

    /** 检查对象是否存在 */
    public function head(string $key): array {
        return $this->request('HEAD', $key);
    }

    /** 删除对象 */
    public function delete(string $key): array {
        return $this->request('DELETE', $key);
    }

    /** GET 对象内容（拉回本地用） */
    public function _raw_get_for_downloader(string $key): array {
        return $this->request('GET', $key);
    }

    /** 列对象（用于测试连接 + 批量迁移） */
    public function list(string $prefix = '', int $max_keys = 1000): array {
        $qs = http_build_query([
            'list-type' => '2',
            'prefix'    => $prefix,
            'max-keys'  => $max_keys,
        ]);
        return $this->request('GET', '', $qs, '', [], true);
    }

    /** 测试连接：HEAD bucket */
    public function test_connection(): array {
        if (!$this->ready()) {
            return ['ok' => false, 'error' => '配置不完整（endpoint/bucket/access_key/secret 缺一不可）'];
        }
        return $this->request('HEAD', '', '', '', [], true);
    }

    /**
     * 通用请求方法
     * @param bool $bucket_root 若 true，请求路径是 /bucket（不是 /bucket/key）
     */
    private function request(string $method, string $key, string $qs = '', string $body = '', array $extra_headers = [], bool $bucket_root = false): array {
        if (!$this->ready()) {
            return ['ok' => false, 'error' => 'client not configured'];
        }

        // 规范化 path
        $key = ltrim($key, '/');
        $encoded_key = $key === '' ? '' : implode('/', array_map('rawurlencode', explode('/', $key)));
        $canonical_uri = '/' . rawurlencode($this->bucket);
        if (!$bucket_root && $encoded_key !== '') {
            $canonical_uri .= '/' . $encoded_key;
        }

        $now  = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $payload_hash = hash('sha256', $body);

        $headers = array_merge([
            'host'                 => $host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date'           => $now,
        ], array_change_key_case($extra_headers, CASE_LOWER));
        ksort($headers);

        $canonical_headers = '';
        $signed_headers_arr = [];
        foreach ($headers as $h => $v) {
            $canonical_headers .= $h . ':' . trim((string) $v) . "\n";
            $signed_headers_arr[] = $h;
        }
        $signed_headers = implode(';', $signed_headers_arr);

        $canonical_qs = $this->canonical_qs($qs);

        $canonical_request = implode("\n", [
            $method,
            $canonical_uri,
            $canonical_qs,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ]);

        $credential_scope = "$date/{$this->region}/s3/aws4_request";
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $now,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $this->secret, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);

        $auth = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->access, $credential_scope, $signed_headers, $signature
        );

        $url = $this->endpoint . $canonical_uri . ($canonical_qs ? '?' . $canonical_qs : '');

        $curl_headers = ['Authorization: ' . $auth];
        foreach ($headers as $h => $v) {
            $curl_headers[] = $h . ': ' . $v;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $curl_headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_NOBODY         => $method === 'HEAD',
        ]);
        if ($body !== '' && in_array($method, ['PUT', 'POST'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $ok = $code >= 200 && $code < 300;
        return [
            'ok'      => $ok,
            'status'  => $code,
            'body'    => $resp === false ? '' : (string) $resp,
            'error'   => $err ?: ($ok ? null : "http $code"),
            'url'     => $url,
        ];
    }

    private function canonical_qs(string $qs): string {
        if ($qs === '') return '';
        parse_str($qs, $params);
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = rawurlencode($k) . '=' . rawurlencode((string) $v);
        }
        return implode('&', $parts);
    }
}
