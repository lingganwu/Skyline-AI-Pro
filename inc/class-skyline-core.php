<?php
if (!defined('ABSPATH')) exit;

class Skyline_Core {
    private static $instance = null;
    private $infra = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->infra = Skyline_Infra::instance();
    }

    public function get_opt($key, $default = '') {
        $cache_key = 'opt_' . md5($key);
        $cached = $this->infra->cache_get($cache_key);
        if ($cached !== null) return $cached;
        
        $val = get_option('skyline_ai_settings', [])[$key] ?? $default;
        $this->infra->cache_set($cache_key, $val, 86400);
        return $val;
    }

    public function update_opt($key, $value) {
        $settings = get_option('skyline_ai_settings', []);
        $settings[$key] = $value;
        update_option('skyline_ai_settings', $settings);
        $this->infra->cache_del('opt_' . md5($key));
    }

    public function call_api($prompt, $model = null, $temp = 0.7) {
        if (!$model) $model = $this->get_opt('api_model', 'deepseek-chat');
        $cache_key = 'api_' . md5($prompt . $model . $temp);
        $cached = $this->infra->cache_get($cache_key, null, 3600);
        if ($cached !== null) return $cached;
        
        $api_key = $this->get_opt('api_key');
        $api_url = $this->get_opt('api_url', 'https://api.deepseek.com/v1/chat/completions');
        
        $response = wp_remote_post($api_url, [
            'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
            'body' => json_encode([
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => $temp
            ]),
            'timeout' => 60
        ]);
        
        if (is_wp_error($response)) return 'Error: ' . $response->get_error_message();
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $res = $body['choices'][0]['message']['content'] ?? 'Error: Empty Response';
        
        $this->infra->cache_set($cache_key, $res, 3600);
        return $res;
    }
}
