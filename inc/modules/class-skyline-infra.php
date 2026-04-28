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
    }

    private function init_redis() {
        if (!class_exists('Redis')) return;
        $core = Skyline_Core::instance();
        try {
            $this->redis = new Redis();
            $this->redis->connect($core->get_opt('redis_host', '127.0.0.1'), (int)$core->get_opt('redis_port', 6379));
            if ($auth = $core->get_opt('redis_auth')) $this->redis->auth($auth);
            if ($db = (int)$core->get_opt('redis_db')) $this->redis->select($db);
        } catch (Exception $e) { $this->redis = null; }
    }

    public function cache_get($key, $fallback = null, $ttl = 3600) {
        if ($this->redis) {
            $val = $this->redis->get('sky:' . $key);
            if ($val !== false) return $val;
        }
        if (is_callable($fallback)) {
            $val = $fallback();
            if ($this->redis) $this->redis->setex('sky:' . $key, $ttl, $val);
            return $val;
        }
        return null;
    }

    public function cache_set($key, $val, $ttl = 3600) {
        if ($this->redis) $this->redis->setex('sky:' . $key, $ttl, $val);
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
        $core = Skyline_Core::instance();
        if (!$core->get_opt('redis_enable') || is_user_logged_in() || is_admin() || $_SERVER['REQUEST_METHOD'] !== 'GET') return;
        $uri = $_SERVER['REQUEST_URI'];
        $excludes = explode("\n", (string)$core->get_opt('redis_exclude', ''));
        foreach ($excludes as $ex) { if (trim($ex) && strpos($uri, trim($ex)) !== false) return; }
        
        $infra = Skyline_Infra::instance();
        $key = 'page_' . md5(home_url($uri));
        $cached = $infra->cache_get($key);
        if ($cached) { header('X-Sky-Redis: HIT'); echo $cached; exit; }
        
        ob_start(function($buf) use ($infra, $key) {
            if (strlen($buf) > 200 && http_response_code() === 200 && !is_404()) {
                $infra->cache_set($key, $buf, 3600);
            }
            return $buf;
        });
    }

    public function smart_flush($pid, $post) {
        $infra = Skyline_Infra::instance();
        $infra->cache_del('page_' . md5(home_url('/')));
        $infra->cache_del('page_' . md5(get_permalink($pid)));
    }
}
}

if (!class_exists('Skyline_Turbo_Mod')) {
class Skyline_Turbo_Mod {
    public function __construct() {
        add_filter('wp_generate_attachment_metadata', [$this, 'compress'], 10, 2);
    }
    public function compress($meta, $pid) {
        if (!Skyline_Core::instance()->get_opt('turbo_enable_image_opt')) return $meta;
        $file = get_attached_file($pid);
        if (!$file || !file_exists($file)) return $meta;
        $editor = wp_get_image_editor($file);
        if (!is_wp_error($editor)) {
            $editor->set_quality((int)Skyline_Core::instance()->get_opt('turbo_quality', 85));
            $editor->save($file);
        }
        return $meta;
    }
}
}

if (!class_exists('Skyline_COS_Mod')) {
class Skyline_COS_Mod {
    public function __construct() {
        add_filter('wp_handle_upload', [$this, 'sync_to_cos'], 10, 2);
        add_filter('wp_generate_attachment_metadata', [$this, 'sync_all_sizes'], 15, 2);
        add_filter('wp_get_attachment_url', [$this, 'replace_url'], 10, 2);
        add_filter('wp_get_attachment_image_src', [$this, 'replace_image_src'], 10, 2);
        add_action('delete_attachment', [$this, 'delete_from_cos']);
    }

    public function sync_to_cos($upload) {
        $core = Skyline_Core::instance();
        if (!$core->get_opt('oss_enable')) return $upload;
        
        $file_path = $upload['file'];
        $file_name = basename($file_path);
        
        try {
            $client = new Skyline_COS_Client($core->get_opt('oss_ak'), $core->get_opt('oss_sk'), $core->get_opt('oss_bucket'), $core->get_opt('oss_endpoint'));
            if ($client->upload($file_name, $file_path)) {
                if ($core->get_opt('oss_delete_local')) @unlink($file_path);
            }
        } catch (Exception $e) { error_log('COS Error: ' . $e->getMessage()); }
        return $upload;
    }

    public function sync_all_sizes($meta, $pid) {
        $core = Skyline_Core::instance();
        if (!$core->get_opt('oss_enable')) return $meta;
        
        $file = get_attached_file($pid);
        $client = new Skyline_COS_Client($core->get_opt('oss_ak'), $core->get_opt('oss_sk'), $core->get_opt('oss_bucket'), $core->get_opt('oss_endpoint'));
        
        // Upload original
        $client->upload(basename($file), $file);
        
        // Upload all generated sizes
        if (isset($meta['sizes'])) {
            $upload_dir = dirname($file);
            foreach ($meta['sizes'] as $size) {
                $size_path = $upload_dir . '/' . $size['file'];
                if (file_exists($size_path)) {
                    $client->upload(basename($size_path), $size_path);
                }
            }
        }
        
        if ($core->get_opt('oss_delete_local')) {
            // Delete original and sizes
            @unlink($file);
            if (isset($meta['sizes'])) {
                foreach ($meta['sizes'] as $size) {
                    @unlink($upload_dir . '/' . $size['file']);
                }
            }
        }
        return $meta;
    }

    public function replace_url($url, $pid) {
        $core = Skyline_Core::instance();
        $domain = $core->get_opt('oss_domain');
        if (!$domain) return $url;
        return str_replace(get_site_url(), rtrim($domain, '/'), $url);
    }

    public function replace_image_src($image, $pid) {
        if (!$image) return $image;
        $core = Skyline_Core::instance();
        $domain = $core->get_opt('oss_domain');
        if (!$domain) return $image;
        
        $url = $image[0];
        $image[0] = str_replace(get_site_url(), rtrim($domain, '/'), $url);
        return $image;
    }

    public function delete_from_cos($pid) {
        $core = Skyline_Core::instance();
        if (!$core->get_opt('oss_enable')) return;
        $file = get_post_meta($pid, '_wp_attached_file', true);
        if (!$file) return;
        $client = new Skyline_COS_Client($core->get_opt('oss_ak'), $core->get_opt('oss_sk'), $core->get_opt('oss_bucket'), $core->get_opt('oss_endpoint'));
        $client->delete(basename($file));
    }
}

class Skyline_COS_Client {
    private $id, $key, $bucket, $endpoint, $region;
    public function __construct($id, $key, $bucket, $endpoint) {
        $this->id = $id; $this->key = $key; $this->bucket = $bucket; $this->endpoint = rtrim($endpoint, '/');
        if (preg_match('/oss-([a-z0-9-]+)\./', $endpoint, $m)) $this->region = $m[1]; else $this->region = 'ap-guangzhou';
    }
    public function upload($key, $path) {
        $content = file_get_contents($path);
        $dt = gmdate('Ymd\\THis\\Z'); $date = gmdate('Ymd');
        $canon = "host:{$this->bucket}.{$this->endpoint}\nx-cos-content-sha256:UNSIGNED-PAYLOAD";
        $signed = "host;x-cos-content-sha256";
        $hash = "UNSIGNED-PAYLOAD";
        $cr = "PUT\n/{$key}\n\n{$canon}\n{$signed}\n{$hash}";
        $st = "sha256\n{$dt}\n{$date}/{$this->bucket}/{$this->region()}\n{$cr}";
        $skey = $this->sign('aws4_request', $this->key);
        $skey = $this->sign($this->region(), $skey);
        $skey = $this->sign($date, $skey);
        $skey = $this->sign('cos', $skey);
        $sig = hash_hmac('sha256', $st, $skey);
        $auth = "AWS4-HMAC-SHA256 Credential={$this->id}/{$date}/{$this->region()}/cos/aws4_request, SignedHeaders={$signed}, Signature={$sig}";
        $ch = curl_init("https://{$this->bucket}.{$this->endpoint}/{$key}");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $content, CURLOPT_HTTPHEADER => ["Authorization: {$auth}", "x-cos-content-sha256: UNSIGNED-PAYLOAD"], CURLOPT_RETURNTRANSFER => true]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }
    public function delete($key) {
        $dt = gmdate('Ymd\\THis\\Z'); $date = gmdate('Ymd');
        $canon = "host:{$this->bucket}.{$this->endpoint}\n/\n\nhost:{$this->bucket}.{$this->endpoint}\nx-cos-content-sha256:UNSIGNED-PAYLOAD";
        $st = "sha256\n{$dt}\n{$date}/{$this->bucket}/{$this->region()}\n{$canon}";
        $skey = $this->sign('aws4_request', $this->key);
        $skey = $this->sign($this->region(), $skey);
        $skey = $this->sign($date, $skey);
        $skey = $this->sign('cos', $skey);
        $sig = hash_hmac('sha256', $st, $skey);
        $auth = "AWS4-HMAC-SHA256 Credential={$this->id}/{$date}/{$this->region()}/cos/aws4_request, SignedHeaders=host;x-cos-content-sha256, Signature={$sig}";
        $ch = curl_init("https://{$this->bucket}.{$this->endpoint}/{$key}");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_HTTPHEADER => ["Authorization: {$auth}", "x-cos-content-sha256: UNSIGNED-PAYLOAD"], CURLOPT_RETURNTRANSFER => true]);
        curl_exec($ch); curl_close($ch);
        return true;
    }
    private function sign($k, $d) { return hash_hmac('sha256', $d, $k, true); }
}
