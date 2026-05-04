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

// ====================== Redis 缓存模块 ======================
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
        $cache_uri = $uri;
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

// ====================== 图片压缩优化模块 ======================
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

// ====================== 旗舰版：安全生命周期的 OSS 模块 ======================
if (!class_exists('Skyline_OSS_Mod')) {
    class Skyline_OSS_Mod {
        private $files_to_delete = [];

        public function __construct() {
            add_filter('wp_generate_attachment_metadata', [$this, 'upload_all_sizes'], 99, 2);
            add_filter('wp_get_attachment_url', [$this, 'replace_url'], 99, 2);
            add_filter('wp_get_attachment_image_src', [$this, 'replace_image_src'], 99, 4);
            add_action('shutdown', [$this, 'cleanup_local_files']);
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

                $upload_dir = wp_upload_dir();
                $base_url_path = trim(parse_url($upload_dir['baseurl'], PHP_URL_PATH), '/') ?: 'wp-content/uploads';
                $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true) ?: basename($file);

                $object_key = $base_url_path . '/' . ltrim($attached_file, '/');
                $success_files = [];
                $failed_files = [];

                // 1. 上传原图
                if ($client->putFile($object_key, $file)) {
                    $success_files[] = $file;
                } else {
                    $failed_files[] = ['type' => 'original', 'path' => $file, 'key' => $object_key];
                }

                // 2. 上传缩略图
                if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                    $base_dir = dirname($file);
                    $rel_dir = dirname($attached_file);
                    if ($rel_dir === '.') $rel_dir = '';

                    foreach ($metadata['sizes'] as $size => $size_info) {
                        $size_file = $base_dir . '/' . $size_info['file'];
                        if (file_exists($size_file)) {
                            $size_rel_path = $rel_dir ? $rel_dir . '/' . $size_info['file'] : $size_info['file'];
                            $size_object_key = $base_url_path . '/' . ltrim($size_rel_path, '/');

                            if ($client->putFile($size_object_key, $size_file)) {
                                $success_files[] = $size_file;
                            } else {
                                $failed_files[] = ['type' => $size, 'path' => $size_file, 'key' => $size_object_key];
                            }
                        }
                    }
                }

                if (empty($failed_files)) {
                    update_post_meta($attachment_id, '_sky_oss_synced', 1);
                    $core->log("🎉 [OSS] 附件 #{$attachment_id} 全部上传成功！", 'info', 'OSS');

                    if ($core->get_opt('oss_delete_local')) {
                        $this->files_to_delete = array_merge($this->files_to_delete, $success_files);
                    }
                } else {
                    $core->log("⚠️ [OSS] 附件 #{$attachment_id} 存在失败项（共 " . count($failed_files) . " 个）", 'warn', 'OSS');
                }

            } catch (Exception $e) {
                $core->log("💥 [OSS] 处理异常: " . $e->getMessage(), 'error', 'OSS');
            }

            return $metadata;
        }

        public function cleanup_local_files() {
            if (empty($this->files_to_delete)) return;
            $core = Skyline_Core::instance();
            $deleted_count = 0;
            
            foreach (array_unique($this->files_to_delete) as $file) {
                if (file_exists($file)) {
                    @chmod($file, 0666); 
                    if (@unlink($file)) {
                        $deleted_count++;
                    }
                }
            }
            if ($deleted_count > 0) {
                $core->log("🗑️ [OSS-Zero-Disk] 生命周期结束，安全清理了 {$deleted_count} 个本地文件", 'info', 'OSS');
            }
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

if (!class_exists('Sky_Official_COS_Client')) {
    class Sky_Official_COS_Client {
        private $client;
        private $bucket;

        public function __construct($ak, $sk, $bucket, $endpoint) {
            $this->bucket = trim($bucket);
            $region = 'ap-beijing';
            if (preg_match('/cos[-.]([a-z0-9-]+)\.myqcloud/i', $endpoint, $m)) {
                $region = $m[1];
            }

            $autoload = SKY_PATH . 'vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;

            $this->client = new \Qcloud\Cos\Client([
                'region'          => $region,
                'schema'          => 'https',
                'credentials'     => ['secretId' => trim($ak), 'secretKey' => trim($sk)],
            ]);
        }

        public function putFile($key, $file) {
            if (!file_exists($file)) return false;

            $key = ltrim($key, '/');
            $core = Skyline_Core::instance();
            
            try {
                $body = file_get_contents($file);
                if ($body === false) {
                    throw new Exception('无法读取本地文件: ' . $file);
                }

                $this->client->putObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $key,
                    'Body'   => $body, 
                    'ACL'    => 'public-read'
                ]);

                $this->client->headObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $key
                ]);

                $core->log("✅ [OSS] 上传并真实验证成功 : {$key}", 'info', 'OSS');
                return true;

            } catch (\Exception $e) {
                $core->log("❌ [OSS] 上传失败: {$key} | 错误: " . $e->getMessage(), 'error', 'OSS');
                return false;
            }
        }
    }
}
