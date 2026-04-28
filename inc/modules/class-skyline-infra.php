<?php
if (!defined('ABSPATH')) exit;

class Skyline_Infra {
    public function __construct() {
        if (class_exists('Skyline_Redis_Mod')) new Skyline_Redis_Mod();
        if (class_exists('Skyline_Turbo_Mod')) new Skyline_Turbo_Mod();
        if (class_exists('Skyline_OSS_Mod')) new Skyline_OSS_Mod();
    }
}

// 1. Redis
if (!class_exists('Skyline_Redis_Mod')) {
class Skyline_Redis_Mod {
    public function __construct() {
        add_action('init', [$this, 'page_cache'], 0);
        add_action('save_post', [$this, 'smart_flush'], 10, 2); 
        add_action('wp_ajax_sky_redis_test', [$this, 'test_connection']);
        add_action('wp_ajax_sky_redis_flush', [$this, 'ajax_flush']);
    }
    
    public function page_cache() {
        if (!class_exists('Skyline_Core')) return;
        $core = Skyline_Core::instance();
        
        if(!$core->get_opt('redis_enable') || is_user_logged_in() || is_admin() || $_SERVER['REQUEST_METHOD']!='GET') return;
        
        $uri = $_SERVER['REQUEST_URI'];
        $exclude_str = (string)$core->get_opt('redis_exclude', '');
        $excludes = explode("\n", $exclude_str);
        
        foreach($excludes as $ex) {
            $ex = trim($ex);
            if($ex && strpos($uri, $ex) !== false) return;
        }

        $redis = $this->connect(); if(!$redis) return;
        
        // 优化：处理搜索参数，防止搜索结果缓存混淆
        $cache_uri = preg_replace('/\?.*$/', '', $uri);
        if (isset($_GET['s'])) {
            $cache_uri .= '?s=' . $_GET['s'];
        }
        $key = 'sky_pc_' . md5(home_url($cache_uri));
        
        try {
            $c = $redis->get($key);
            if($c) { 
                header('X-Sky-Redis: HIT'); 
                echo (string)$c . ''; 
                exit; 
            }
        } catch (Exception $e) { return; }
        
        ob_start(function($buf) use ($redis, $key, $core){
            $buf = (string)$buf;
            if(strlen($buf)>200 && http_response_code()==200 && !is_404()) {
                $ttl = intval($core->get_opt('redis_ttl', 3600));
                $redis->setex($key, $ttl, $buf);
            }
            return $buf;
        });
    }

    public function connect() {
        if(!class_exists('Redis')) return false;
        static $r = null; if($r) return $r;
        
        try {
            $r = new Redis();
            $core = Skyline_Core::instance();
            $host = (string)$core->get_opt('redis_host', '127.0.0.1');
            $port = intval($core->get_opt('redis_port', 6379));
            
            if(!$r->connect($host, $port, 1.0)) return false; 
            if($auth = $core->get_opt('redis_auth')) $r->auth($auth);
            if($db = intval($core->get_opt('redis_db'))) $r->select($db);
            
            // 优化：应用序列化和压缩配置
            $ser = $core->get_opt('redis_serializer');
            if ($ser == 'igbinary' && defined('Redis::SERIALIZER_IGBINARY')) $r->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
            elseif (defined('Redis::SERIALIZER_PHP')) $r->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

            $comp = $core->get_opt('redis_compression');
            if ($comp == 'zstd' && defined('Redis::COMPRESSION_ZSTD')) $r->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_ZSTD);
            elseif ($comp == 'lzf' && defined('Redis::COMPRESSION_LZF')) $r->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZF);
            
            return $r;
        } catch(Exception $e) { return false; }
    }

    public function smart_flush($post_id, $post) {
        if(!Skyline_Core::instance()->get_opt('redis_enable')) return;
        if(wp_is_post_revision($post_id) || $post->post_status != 'publish') return;

        $redis = $this->connect();
        if(!$redis) return;

        if(Skyline_Core::instance()->get_opt('redis_smart_purge')) {
            $redis->del('sky_pc_' . md5(home_url('/')));
            $redis->del('sky_pc_' . md5(get_permalink($post_id)));
        } else {
            try { 
                $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
                $it = NULL; while($keys = $redis->scan($it, 'sky_pc_*')) if($keys) $redis->del($keys);
            } catch (Exception $e) {}
        }
    }

    public function ajax_flush() { 
        check_ajax_referer('sky_redis_nonce');
        if(!current_user_can('manage_options')) wp_send_json_error('权限不足');
        $this->flush_all();
        // 新增：日志
        Skyline_Core::instance()->log('Redis 缓存已手动清空', 'warn', 'Redis');
        wp_send_json_success('缓存已清空'); 
    }

    private function flush_all() {
        $redis = $this->connect();
        if($redis) {
            try { 
                $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
                $it = NULL; while($keys = $redis->scan($it, 'sky_pc_*')) if($keys) $redis->del($keys);
            } catch(Exception $e) {}
        }
    }

    public function test_connection() {
        check_ajax_referer('sky_redis_nonce');
        if(!current_user_can('manage_options')) wp_send_json_error('权限不足');
        $r = $this->connect();
        wp_send_json_success($r ? "连接成功 PONG" : "连接失败");
    }
}
}

// 2. Turbo
if (!class_exists('Skyline_Turbo_Mod')) {
class Skyline_Turbo_Mod {
    public function __construct() {
        if(!class_exists('Skyline_Core')) return;
        $core = Skyline_Core::instance();

        add_filter('heartbeat_settings', function($s){ $s['interval']=60; return $s; });
        if($core->get_opt('turbo_disable_emoji')) {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('wp_print_styles', 'print_emoji_styles');
        }
        if($core->get_opt('turbo_disable_embeds')) wp_deregister_script('wp-embed');
        if($core->get_opt('turbo_disable_xmlrpc')) add_filter('xmlrpc_enabled', '__return_false');
        
        if($core->get_opt('turbo_lazy_load')) {
            add_filter('the_content', [$this, 'add_lazy_loading']);
        }

        add_filter('upload_size_limit', [$this, 'limit_size']);
        add_filter('upload_mimes', [$this, 'allow_types']);
        add_filter('sanitize_file_name', [$this, 'sanitize_name']);
        add_filter('wp_generate_attachment_metadata', [$this, 'compress'], 10, 2);
    }

    public function add_lazy_loading($content) {
        return str_replace('<img', '<img loading="lazy"', $content);
    }

    public function limit_size($size) { return Skyline_Core::instance()->get_opt('turbo_limit_5m') ? 5*1024*1024 : $size; }
    
    public function allow_types($mimes) {
        if(Skyline_Core::instance()->get_opt('turbo_allow_svg')) { $mimes['svg']='image/svg+xml'; $mimes['webp']='image/webp'; }
        return $mimes;
    }

    public function sanitize_name($name) {
        if(Skyline_Core::instance()->get_opt('turbo_sanitize_names')) return date('YmdHis').rand(100,999).'.'.pathinfo($name, PATHINFO_EXTENSION);
        return $name;
    }

    /**
     * 图片压缩核心 (本地高性能版 - 针对 1核/2G 服务器优化)
     */
    public function compress($metadata, $attachment_id) {
        $core = Skyline_Core::instance();
        // 1. 检查开关
        if(!$core->get_opt('turbo_enable_image_opt')) return $metadata;
        
        // 2. 获取文件路径
        $file = get_attached_file($attachment_id);
        if(!$file || !file_exists($file)) return $metadata;
        
        // 【保护机制】如果图片大于 10MB，直接跳过，保护 CPU (1核机器扛不住太大)
        if(filesize($file) > 10 * 1024 * 1024) { 
            $core->log("Turbo: 图片过大(>10MB)跳过压缩，保护服务器资源", 'warn', 'Turbo');
            return $metadata; 
        }

        // 3. 检查文件类型 (仅处理 JPG/PNG/WebP)
        $type = get_post_mime_type($attachment_id);
        if(!in_array($type, ['image/jpeg', 'image/png', 'image/webp'])) return $metadata;

        // 4. 调用 WP 内置编辑器 (GD 或 ImageMagick)
        $editor = wp_get_image_editor($file);
        if(is_wp_error($editor)) {
            return $metadata;
        }

        $quality = (int)$core->get_opt('turbo_quality', 85);
        $original_size = filesize($file);

        // 【缩放保护】最大宽度限制在 2560px，既保证高清，又省内存
        $size = $editor->get_size();
        if($size && $size['width'] > 2560) {
            $editor->resize(2560, null, false);
        }

        // 5. 设置压缩质量并保存
        $editor->set_quality($quality);
        $result = $editor->save($file); // 覆盖原文件

        if(!is_wp_error($result)) {
            clearstatcache();
            $new_size = filesize($file);
            
            // 只有当文件确实变小了才更新统计
            if($new_size < $original_size) {
                $saved_kb = ($original_size - $new_size) / 1024;
                $core->stat_inc('saved_kb', $saved_kb);
                // 修复：记录压缩日志
                $core->log("图片压缩成功: 节省 ".round($saved_kb, 1)."KB", 'info', 'Turbo');
            }
        } else {
            $core->log('Turbo Compress Error: ' . $result->get_error_message(), 'error', 'Turbo');
        }
        
        unset($editor);
        return $metadata;
    }
}
}

// 3. OSS
if (!class_exists('Skyline_OSS_Mod')) {
class Skyline_OSS_Mod {
    public function __construct() {
        add_filter('wp_update_attachment_metadata', [$this, 'upload_all'], 10, 2);
        add_filter('wp_get_attachment_url', [$this, 'replace_url'], 10, 2);
        add_action('wp_ajax_sky_oss_test', [$this, 'test_connection']);
    }

    public function test_connection() {
        check_ajax_referer('sky_oss_nonce');
        if(!current_user_can('manage_options')) wp_send_json_error('权限不足');
        $core = Skyline_Core::instance();
        try {
            $client = new Sky_S3_Client($core->get_opt('oss_ak'), $core->get_opt('oss_sk'), $core->get_opt('oss_bucket'), $core->get_opt('oss_endpoint'), $core->get_opt('oss_ssl_verify', true));
            if($client->putContent('sky_test.txt', 'OK')) wp_send_json_success("连接成功");
            else wp_send_json_error("上传失败，请检查配置");
        } catch (Exception $e) { wp_send_json_error($e->getMessage()); }
    }

    public function upload_all($data, $pid) {
        $core = Skyline_Core::instance();
        if(!$core->get_opt('oss_enable')) return $data;
        
        $file = get_attached_file($pid);
        if (!$file || !file_exists($file)) return $data;

        $key = basename($file);
        try {
            $client = new Sky_S3_Client($core->get_opt('oss_ak'), $core->get_opt('oss_sk'), $core->get_opt('oss_bucket'), $core->get_opt('oss_endpoint'), $core->get_opt('oss_ssl_verify', true));
            if($client->putFile($key, $file)) {
                $domain = $core->get_opt('oss_domain') ?: "https://{$core->get_opt('oss_bucket')}.{$core->get_opt('oss_endpoint')}";
                update_post_meta($pid, 'sky_oss_url', rtrim($domain, '/')."/".$key);
                $core->stat_inc('oss_count');
                // 新增：日志
                $core->log("OSS 上传成功: $key", 'info', 'OSS');
            }
        } catch (Exception $e) {
             $core->log("OSS Upload Fail: ".$e->getMessage(), 'error', 'OSS');
        }
        return $data;
    }

    public function replace_url($url, $pid) {
        if (!class_exists('Skyline_Core') || !Skyline_Core::instance()->get_opt('oss_enable')) return $url;
        return get_post_meta($pid, 'sky_oss_url', true) ?: $url;
    }
}
}

if (!class_exists('Sky_S3_Client')) {
class Sky_S3_Client {
    private $ak, $sk, $host, $region='us-east-1', $ssl_verify;
    // 优化：支持 SSL 配置传入
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
        // 优化：应用 SSL 验证配置
        curl_setopt_array($ch, [CURLOPT_PUT=>1, CURLOPT_INFILE=>$fp=fopen('php://memory','r+'), CURLOPT_INFILESIZE=>strlen($content), CURLOPT_HTTPHEADER=>["Authorization: {$auth}", "x-amz-date: {$dt}", "x-amz-content-sha256: {$hash}"], CURLOPT_RETURNTRANSFER=>1, CURLOPT_SSL_VERIFYPEER=>$this->ssl_verify]);
        fwrite($fp, $content); rewind($fp); curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ($code >= 200 && $code < 300);
    }
}
}