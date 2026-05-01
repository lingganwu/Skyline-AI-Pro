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
            
            // 补充优化：激活高阶性能配置
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
        // 核心逻辑：保证图片压缩在 COS 同步之前执行
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

// 核心功能：COS 全尺寸上云 + 本地秒删 Zero-Disk + 域名劫持
if (!class_exists('Skyline_OSS_Mod')) {
class Skyline_OSS_Mod {
    public function __construct() {
        // 在阶段 99 彻底接管所有的原图和缩略图
        add_filter('wp_generate_attachment_metadata', [$this, 'upload_all_sizes'], 99, 2);
        // 接管前台输出的图片链接，无缝替换 CDN
        add_filter('wp_get_attachment_url', [$this, 'replace_url'], 99, 2);
        add_filter('wp_get_attachment_image_src', [$this, 'replace_image_src'], 99, 4);
    }

    public function upload_all_sizes($metadata, $attachment_id) {
        $core = Skyline_Core::instance();
        if(!$core->get_opt('oss_enable')) return $metadata;
        
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) return $metadata;

        try {
            $client = new Sky_S3_Client($core->get_opt('oss_ak'), $core->get_opt('oss_sk'), $core->get_opt('oss_bucket'), $core->get_opt('oss_endpoint'), $core->get_opt('oss_ssl_verify', true));
            $base_dir = dirname($file);
            $uploads = [];
            
            // 🌟 物理开颅：完全抛弃错乱的数据库，直接从真实的绝对路径字符串中截取
            $normalized_file = wp_normalize_path($file);
            $rel_path = '';
            
            $pos = strrpos($normalized_file, '/uploads/');
            if ($pos !== false) {
                // 强制截取出类似于 2026/05/xxx.png 的绝对干净字符串
                $rel_path = substr($normalized_file, $pos + 9);
            } elseif (preg_match('/\/(\d{4}\/\d{2}\/.*)$/', $normalized_file, $matches)) {
                $rel_path = $matches[1];
            } else {
                $rel_path = basename($normalized_file);
            }

            // 清理软链接导致的连环 Bug：如果在提取中真的出现了 2026/05/2026/05 的双重情况，强制切掉一半
            $rel_path = ltrim($rel_path, '/');
            if (preg_match('/^(\d{4}\/\d{2}\/)\1(.*)$/', $rel_path, $m)) {
                $rel_path = $m[1] . $m[2];
            }

            // ⚠️ 极其关键的一步：将我们强制修正后的完美路径，强行写回 WordPress 数据库
            // 这样前台生成网址的时候，就不会带上那两层恶心的年月了
            update_post_meta($attachment_id, '_wp_attached_file', $rel_path);

            // 获取前缀 (wp-content/uploads)
            $upload_dir = wp_upload_dir();
            $base_url_path = trim((string)parse_url($upload_dir['baseurl'], PHP_URL_PATH), '/'); 
            if (empty($base_url_path)) $base_url_path = 'wp-content/uploads';

            // 1. 上传原图 (拼接云端完美的单层年月结构)
            $object_key = $base_url_path . '/' . $rel_path;
            
            if ($client->putFile($object_key, $file)) {
                $uploads[] = $file;
            }
            
            // 2. 上传系统自动裁切的所有尺寸缩略图
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                $rel_dir = dirname($rel_path);
                if ($rel_dir === '.') $rel_dir = '';
                
                foreach ($metadata['sizes'] as $size => $size_info) {
                    $size_file = $base_dir . '/' . $size_info['file'];
                    if (file_exists($size_file)) {
                        $size_rel_path = $rel_dir ? $rel_dir . '/' . $size_info['file'] : $size_info['file'];
                        $size_object_key = $base_url_path . '/' . ltrim($size_rel_path, '/');
                        
                        if ($client->putFile($size_object_key, $size_file)) {
                            $uploads[] = $size_file;
                        }
                    }
                }
            }

            // Zero-Disk：只有图片成功上了云，才清理本地，绝对安全
            if ($core->get_opt('oss_delete_local') && count($uploads) > 0) {
                foreach ($uploads as $uploaded_file) {
                    @unlink($uploaded_file);
                }
                $core->log("COS 终极同步成功，双重路径已修正: " . basename($file), 'info', 'OSS');
            }
            
            update_post_meta($attachment_id, '_sky_oss_synced', 1);

        } catch (Exception $e) {
             $core->log("COS Upload Fail: ".$e->getMessage(), 'error', 'OSS');
        }
        return $metadata;
    }

    public function replace_url($url, $post_id) {
        $core = Skyline_Core::instance();
        if(!$core->get_opt('oss_enable') || !get_post_meta($post_id, '_sky_oss_synced', true)) return $url;
        
        $domain = rtrim($core->get_opt('oss_domain') ?: "https://{$core->get_opt('oss_bucket')}.{$core->get_opt('oss_endpoint')}", '/');
        return str_replace(rtrim(get_site_url(), '/'), $domain, $url);
    }

    public function replace_image_src($image, $attachment_id, $size, $icon) {
        if (!$image) return $image;
        $core = Skyline_Core::instance();
        if(!$core->get_opt('oss_enable') || !get_post_meta($attachment_id, '_sky_oss_synced', true)) return $image;
        
        $domain = rtrim($core->get_opt('oss_domain') ?: "https://{$core->get_opt('oss_bucket')}.{$core->get_opt('oss_endpoint')}", '/');
        $image[0] = str_replace(rtrim(get_site_url(), '/'), $domain, $image[0]);
        return $image;
    }
}
}

if (!class_exists('Sky_S3_Client')) {
class Sky_S3_Client {
    private $ak, $sk, $host, $region='us-east-1', $ssl_verify;
    public function __construct($ak, $sk, $bucket, $endpoint, $ssl_verify = true) {
        $this->ak = (string)$ak; $this->sk = (string)$sk; $this->host = "{$bucket}.{$endpoint}";
        $this->ssl_verify = $ssl_verify;
        if($endpoint && preg_match('/^oss-([a-z0-9-]+)\./', $endpoint, $m)) $this->region = $m[1];
    }
    public function putFile($key, $file) { return file_exists($file) ? $this->putContent($key, file_get_contents($file)) : false; }
    public function putContent($key, $content) {
        $content = (string)$content; $dt = gmdate('Ymd\THis\Z'); $d = gmdate('Ymd');
        $hash = hash('sha256', $content);
        $canon = "PUT\n/{$key}\n\nhost:{$this->host}\nx-amz-content-sha256:{$hash}\nx-amz-date:{$dt}\n\nhost;x-amz-content-sha256;x-amz-date\n{$hash}";
        $scope = "{$d}/{$this->region}/s3/aws4_request";
        $kSigning = hash_hmac('sha256', "aws4_request", hash_hmac('sha256', "s3", hash_hmac('sha256', $this->region, hash_hmac('sha256', $d, "AWS4".$this->sk, true), true), true), true);
        $sig = hash_hmac('sha256', "AWS4-HMAC-SHA256\n{$dt}\n{$scope}\n".hash('sha256', $canon), $kSigning);
        $auth = "AWS4-HMAC-SHA256 Credential={$this->ak}/{$scope}, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$sig}";
        
        $ch = curl_init("https://{$this->host}/{$key}");
        curl_setopt_array($ch, [CURLOPT_PUT=>1, CURLOPT_INFILE=>$fp=fopen('php://memory','r+'), CURLOPT_INFILESIZE=>strlen($content), CURLOPT_HTTPHEADER=>["Authorization: {$auth}", "x-amz-date: {$dt}", "x-amz-content-sha256: {$hash}"], CURLOPT_RETURNTRANSFER=>1, CURLOPT_SSL_VERIFYPEER=>$this->ssl_verify]);
        fwrite($fp, $content); rewind($fp); curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ($code >= 200 && $code < 300);
    }
}
}
