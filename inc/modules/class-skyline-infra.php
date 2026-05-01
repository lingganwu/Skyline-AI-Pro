<?php
if (!defined('ABSPATH')) exit;

class Skyline_Infra {
    private static $instance = null;
    private $redis = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->init_redis();
        if (class_exists('Skyline_Redis_Mod')) new Skyline_Redis_Mod();
        if (class_exists('Skyline_Turbo_Mod')) new Skyline_Turbo_Mod();
        if (class_exists('Skyline_OSS_Mod')) new Skyline_OSS_Mod();
    }

    private function init_redis() {
        if (!class_exists('Redis')) return;
        $opts = get_option('skyline_ai_settings', []);
        if (!is_array($opts)) $opts = [];
        if (empty($opts['redis_enable'])) return;

        try {
            $this->redis = new Redis();
            $this->redis->connect($opts['redis_host'] ?? '127.0.0.1', (int)($opts['redis_port'] ?? 6379));
            if (!empty($opts['redis_auth'])) $this->redis->auth($opts['redis_auth']);
            if (!empty($opts['redis_db'])) $this->redis->select((int)$opts['redis_db']);
            
            if (isset($opts['redis_serializer']) && $opts['redis_serializer'] === 'igbinary' && defined('Redis::SERIALIZER_IGBINARY')) {
                $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
            }
            if (isset($opts['redis_compression']) && $opts['redis_compression'] === 'zstd' && defined('Redis::COMPRESSION_ZSTD')) {
                $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_ZSTD);
            }
        } catch (Exception $e) { $this->redis = null; }
    }

    public function cache_get($key, $fallback = null, $ttl = 3600) {
        if ($this->redis) {
            $val = $this->redis->get('sky:' . $key);
            if ($val !== false) return is_string($val) ? json_decode($val, true) ?? $val : $val;
        }
        if (is_callable($fallback)) {
            $val = $fallback();
            if ($this->redis) $this->redis->setex('sky:' . $key, $ttl, is_array($val) ? json_encode($val) : $val);
            return $val;
        }
        return null;
    }

    public function cache_set($key, $val, $ttl = 3600) {
        if ($this->redis) $this->redis->setex('sky:' . $key, $ttl, is_array($val) ? json_encode($val) : $val);
    }

    public function cache_del($key) {
        if ($this->redis) $this->redis->del('sky:' . $key);
    }
}

if (!class_exists('Skyline_Redis_Mod')) {
class Skyline_Redis_Mod {
    public function __construct() {
        add_action('init', [$this, 'page_cache'], 0);
        add_action('save_post', [$this, 'smart_flush'], 10, 2);
    }

    public function page_cache() { /* ... 保持不变 ... */ }
    public function smart_flush($pid, $post) { /* ... 保持不变 ... */ }
}
}

if (!class_exists('Skyline_Turbo_Mod')) {
class Skyline_Turbo_Mod {
    public function __construct() {
        add_filter('wp_generate_attachment_metadata', [$this, 'compress'], 5, 2);
    }
    public function compress($metadata, $attachment_id) {
        $core = Skyline_Core::instance();
        if(!$core->get_opt('turbo_enable_image_opt')) return $metadata;
        
        $file = get_attached_file($attachment_id);
        if(!$file || !file_exists($file) || filesize($file) > 10 * 1024 * 1024) return $metadata;
        
        $type = get_post_mime_type($attachment_id);
        if(!in_array($type, ['image/jpeg', 'image/png', 'image/webp'])) return $metadata;

        $editor = wp_get_image_editor($file);
        if(is_wp_error($editor)) return $metadata;

        $editor->set_quality((int)$core->get_opt('turbo_quality', 85));
        $size = $editor->get_size();
        if($size && $size['width'] > 2560) $editor->resize(2560, null, false);
        
        $editor->save($file);
        unset($editor);
        return $metadata;
    }
}
}

// ====================== 核心 OSS 模块（已全面优化） ======================
if (!class_exists('Skyline_OSS_Mod')) {
class Skyline_OSS_Mod {
    public function __construct() {
        add_filter('wp_generate_attachment_metadata', [$this, 'upload_all_sizes'], 99, 2);
        add_filter('wp_get_attachment_url', [$this, 'replace_url'], 99, 2);
        add_filter('wp_get_attachment_image_src', [$this, 'replace_image_src'], 99, 4);
    }

    public function upload_all_sizes($metadata, $attachment_id) {
        $core = Skyline_Core::instance();
        if (!$core->get_opt('oss_enable')) return $metadata;

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) return $metadata;

        $core->log("OSS 开始处理附件 #{$attachment_id}: " . basename($file), 'info', 'OSS');

        try {
            $client = new Sky_S3_Client(
                $core->get_opt('oss_ak'),
                $core->get_opt('oss_sk'),
                $core->get_opt('oss_bucket'),
                $core->get_opt('oss_endpoint'),
                $core->get_opt('oss_ssl_verify', true)
            );

            $base_dir = dirname($file);
            $uploads = [];
            $upload_success = false;

            // 1. 处理主文件路径
            $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            if (empty($attached_file) || strpos($attached_file, '/') === false) {
                $normalized = wp_normalize_path($file);
                if (preg_match('/(\d{4}\/\d{2}\/[^\/]+)$/', $normalized, $m)) {
                    $attached_file = $m[1];
                } else {
                    $attached_file = basename($file);
                }
                update_post_meta($attachment_id, '_wp_attached_file', $attached_file);
            }

            $upload_dir = wp_upload_dir();
            $base_url_path = trim(parse_url($upload_dir['baseurl'], PHP_URL_PATH), '/');
            if (empty($base_url_path)) $base_url_path = 'wp-content/uploads';

            $object_key = $base_url_path . '/' . ltrim($attached_file, '/');

            // 上传原图
            if ($client->putFile($object_key, $file)) {
                $uploads[] = $file;
                $upload_success = true;
                $core->log("✅ 原图上传成功: {$object_key}", 'info', 'OSS');
            } else {
                $core->log("❌ 原图上传失败: {$object_key}", 'error', 'OSS');
            }

            // 上传所有缩略图
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                $rel_dir = dirname($attached_file);
                if ($rel_dir === '.') $rel_dir = '';

                foreach ($metadata['sizes'] as $size => $size_info) {
                    $size_file = $base_dir . '/' . $size_info['file'];
                    if (file_exists($size_file)) {
                        $size_rel_path = $rel_dir ? $rel_dir . '/' . $size_info['file'] : $size_info['file'];
                        $size_object_key = $base_url_path . '/' . ltrim($size_rel_path, '/');
                        
                        if ($client->putFile($size_object_key, $size_file)) {
                            $uploads[] = $size_file;
                            $core->log("✅ 缩略图上传成功: {$size_object_key}", 'info', 'OSS');
                        } else {
                            $core->log("❌ 缩略图上传失败: {$size_object_key}", 'error', 'OSS');
                        }
                    }
                }
            }

            // 只有真正上传成功才标记 synced，并执行 Zero-Disk
            if ($upload_success && count($uploads) > 0) {
                update_post_meta($attachment_id, '_sky_oss_synced', 1);
                $core->log("🎉 附件 #{$attachment_id} 全部上传成功，已标记 _sky_oss_synced", 'info', 'OSS');

                if ($core->get_opt('oss_delete_local')) {
                    foreach ($uploads as $uploaded_file) {
                        @unlink($uploaded_file);
                    }
                    $core->log("🗑️ Zero-Disk 已清理本地文件", 'info', 'OSS');
                }
            } else {
                $core->log("⚠️ 附件 #{$attachment_id} 上传失败，未标记 synced", 'error', 'OSS');
            }

        } catch (Exception $e) {
            $core->log("💥 OSS Upload Exception: " . $e->getMessage(), 'error', 'OSS');
        }

        return $metadata;
    }

    public function replace_url($url, $post_id) {
        $core = Skyline_Core::instance();
        if (!$core->get_opt('oss_enable') || !get_post_meta($post_id, '_sky_oss_synced', true)) {
            return $url;
        }
        
        $domain = rtrim($core->get_opt('oss_domain') ?: "https://{$core->get_opt('oss_bucket')}.{$core->get_opt('oss_endpoint')}", '/');
        return str_replace(rtrim(get_site_url(), '/'), $domain, $url);
    }

    public function replace_image_src($image, $attachment_id, $size, $icon) {
        if (!$image) return $image;
        $core = Skyline_Core::instance();
        if (!$core->get_opt('oss_enable') || !get_post_meta($attachment_id, '_sky_oss_synced', true)) {
            return $image;
        }
        
        $domain = rtrim($core->get_opt('oss_domain') ?: "https://{$core->get_opt('oss_bucket')}.{$core->get_opt('oss_endpoint')}", '/');
        $image[0] = str_replace(rtrim(get_site_url(), '/'), $domain, $image[0]);
        return $image;
    }
}
}

if (!class_exists('Sky_S3_Client')) {
class Sky_S3_Client {
    private $ak, $sk, $host, $region = 'us-east-1', $ssl_verify;

    public function __construct($ak, $sk, $bucket, $endpoint, $ssl_verify = true) {
        $this->ak = (string)$ak;
        $this->sk = (string)$sk;
        $this->host = "{$bucket}.{$endpoint}";
        $this->ssl_verify = $ssl_verify;

        // 腾讯云 + 阿里云 region 识别
        if ($endpoint) {
            if (preg_match('/^oss-([a-z0-9-]+)\./i', $endpoint, $m)) {
                $this->region = $m[1];
            } elseif (preg_match('/cos\.([a-z0-9-]+)\.myqcloud\.com/i', $endpoint, $m)) {
                $this->region = $m[1];   // ap-beijing 等
            }
        }
    }

    public function putFile($key, $file) {
        if (!file_exists($file)) return false;
        return $this->putContent($key, file_get_contents($file));
    }

    public function putContent($key, $content) {
        $content = (string)$content;
        $dt = gmdate('Ymd\THis\Z');
        $d = gmdate('Ymd');
        $hash = hash('sha256', $content);

        $canon = "PUT\n/{$key}\n\nhost:{$this->host}\nx-amz-content-sha256:{$hash}\nx-amz-date:{$dt}\n\nhost;x-amz-content-sha256;x-amz-date\n{$hash}";
        $scope = "{$d}/{$this->region}/s3/aws4_request";

        $kSigning = hash_hmac('sha256', "aws4_request",
                    hash_hmac('sha256', "s3",
                    hash_hmac('sha256', $this->region,
                    hash_hmac('sha256', $d, "AWS4" . $this->sk, true), true), true), true);

        $sig = hash_hmac('sha256', "AWS4-HMAC-SHA256\n{$dt}\n{$scope}\n" . hash('sha256', $canon), $kSigning);

        $auth = "AWS4-HMAC-SHA256 Credential={$this->ak}/{$scope}, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$sig}";

        $ch = curl_init("https://{$this->host}/{$key}");
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, $content);
        rewind($fp);

        curl_setopt_array($ch, [
            CURLOPT_PUT => 1,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => strlen($content),
            CURLOPT_HTTPHEADER => [
                "Authorization: {$auth}",
                "x-amz-date: {$dt}",
                "x-amz-content-sha256: {$hash}"
            ],
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => $this->ssl_verify,
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        $success = ($http_code >= 200 && $http_code < 300);

        // 关键调试日志
        $core = Skyline_Core::instance();
        $log_msg = $success 
            ? "✅ COS PUT 成功 [{$http_code}] Key: {$key}"
            : "❌ COS PUT 失败 [{$http_code}] Key: {$key} | CurlError: {$curl_error} | Response: " . substr($response, 0, 300);
        
        $core->log($log_msg, $success ? 'info' : 'error', 'OSS');

        return $success;
    }
}
}
