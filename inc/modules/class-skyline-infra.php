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

    public function page_cache() {
        if (!class_exists('Skyline_Core')) return;
        $core = Skyline_Core::instance();
        
        if(!$core->get_opt('redis_enable') || is_user_logged_in() || is_admin() || $_SERVER['REQUEST_METHOD'] !== 'GET') return;
        
        $uri = $_SERVER['REQUEST_URI'];
        $excludes = explode("\n", (string)$core->get_opt('redis_exclude', ''));
        foreach ($excludes as $ex) { if (trim($ex) && strpos($uri, trim($ex)) !== false) return; }
        
        $infra = Skyline_Infra::instance();
        $cache_uri = preg_replace('/\?.*$/', '', $uri) . (isset($_GET['s']) ? '?s=' . $_GET['s'] : '');
        $key = 'page_' . md5(home_url($cache_uri));
        
        $cached = $infra->cache_get($key);
        if ($cached) { header('X-Sky-Redis: HIT'); echo $cached; exit; }
        
        ob_start(function($buf) use ($infra, $key, $core) {
            if (strlen($buf) > 200 && http_response_code() === 200 && !is_404()) {
                $infra->cache_set($key, $buf, intval($core->get_opt('redis_ttl', 3600)));
            }
            return $buf;
        });
    }

    public function smart_flush($pid, $post) {
        if(wp_is_post_revision($pid) || $post->post_status != 'publish') return;
        $infra = Skyline_Infra::instance();
        $infra->cache_del('page_' . md5(home_url('/')));
        $infra->cache_del('page_' . md5(get_permalink($pid)));
    }
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

// ====================== 官方腾讯云 COS 模块 ======================
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

        $core->log("🚀 [OSS] 开始处理附件 #{$attachment_id}: " . basename($file), 'info', 'OSS');

        try {
            $client = new Sky_Official_COS_Client(
                $core->get_opt('oss_ak'),
                $core->get_opt('oss_sk'),
                $core->get_opt('oss_bucket'),
                $core->get_opt('oss_endpoint')
            );

            $base_dir = dirname($file);
            $uploads = [];
            $upload_success = false;

            // 提取正确的相对路径（保留年/月文件夹）
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
                $core->log("✅ [OSS] 原图上传成功: {$object_key}", 'info', 'OSS');
            } else {
                $core->log("❌ [OSS] 原图上传失败: {$object_key}", 'error', 'OSS');
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
                            $core->log("✅ [OSS] 缩略图上传成功: {$size_object_key}", 'info', 'OSS');
                        } else {
                            $core->log("❌ [OSS] 缩略图上传失败: {$size_object_key}", 'error', 'OSS');
                        }
                    }
                }
            }

            if ($upload_success && count($uploads) > 0) {
                update_post_meta($attachment_id, '_sky_oss_synced', 1);
                $core->log("🎉 [OSS] 附件 #{$attachment_id} 全部上传成功！", 'info', 'OSS');

                if ($core->get_opt('oss_delete_local')) {
                    foreach ($uploads as $uploaded_file) {
                        @unlink($uploaded_file);
                    }
                    $core->log("🗑️ [OSS] Zero-Disk 已清理本地文件", 'info', 'OSS');
                }
            }

        } catch (Exception $e) {
            $core->log("💥 [OSS] 官方 SDK 异常: " . $e->getMessage(), 'error', 'OSS');
        }

        return $metadata;
    }

    public function replace_url($url, $post_id) {
        $core = Skyline_Core::instance();
        if (!$core->get_opt('oss_enable') || !get_post_meta($post_id, '_sky_oss_synced', true)) return $url;
        
        $domain = rtrim($core->get_opt('oss_domain') ?: "https://{$core->get_opt('oss_bucket')}.{$core->get_opt('oss_endpoint')}", '/');
        return str_replace(rtrim(get_site_url(), '/'), $domain, $url);
    }

    public function replace_image_src($image, $attachment_id, $size, $icon) {
        if (!$image) return $image;
        $core = Skyline_Core::instance();
        if (!$core->get_opt('oss_enable') || !get_post_meta($attachment_id, '_sky_oss_synced', true)) return $image;
        
        $domain = rtrim($core->get_opt('oss_domain') ?: "https://{$core->get_opt('oss_bucket')}.{$core->get_opt('oss_endpoint')}", '/');
        $image[0] = str_replace(rtrim(get_site_url(), '/'), $domain, $image[0]);
        return $image;
    }
}
}

// 👑 官方腾讯云 COS SDK 客户端（稳定版）
if (!class_exists('Sky_Official_COS_Client')) {
class Sky_Official_COS_Client {
    private $client;
    private $bucket;

    public function __construct($ak, $sk, $bucket, $endpoint) {
        $this->bucket = trim($bucket);

        // 自动提取 region（如 ap-beijing）
        $region = 'ap-beijing';
        if (preg_match('/cos\.([a-z0-9-]+)\.myqcloud/i', $endpoint, $m)) {
            $region = $m[1];
        }

        // 加载 Composer 安装的官方 SDK
        if (!class_exists('\Qcloud\Cos\Client')) {
            $autoload = dirname(dirname(dirname(__FILE__))) . '/vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
            } else {
                throw new Exception('腾讯云 SDK 未找到，请确认 composer install 已执行');
            }
        }

        $this->client = new \Qcloud\Cos\Client([
            'region'      => $region,
            'schema'      => 'https',
            'credentials' => [
                'secretId'  => trim($ak),
                'secretKey' => trim($sk),
            ]
        ]);
    }

    public function putFile($key, $file) {
        if (!file_exists($file)) return false;

        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key'    => ltrim($key, '/'),
                'Body'   => fopen($file, 'rb')
            ]);
            return true;
        } catch (\Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}
}
