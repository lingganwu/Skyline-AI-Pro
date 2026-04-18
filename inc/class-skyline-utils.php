<?php
if (!defined('ABSPATH')) exit;

class Skyline_Utils {
    /**
     * AI Quality Assessment
     */
    public static function assess_quality($content) {
        $readability_score = self::calculate_readability($content);
        $seo_score = self::calculate_seo($content);
        return [
            'readability' => $readability_score,
            'seo' => $seo_score,
            'overall' => round(($readability_score + $seo_score) / 2, 2)
        ];
    }

    private static function calculate_readability($content) {
        // Placeholder logic - implementation can be expanded
        return rand(60, 95);
    }

    private static function calculate_seo($content) {
        // Placeholder logic - implementation can be expanded
        return rand(60, 95);
    }

    /**
     * Batch Processing
     */
    public static function batch_process($posts, $model = 'deepseek-v3') {
        $results = [];
        foreach ($posts as $post) {
            $results[] = skyline_generate_content($post['content'], $model);
        }
        return $results;
    }

    /**
     * Data Export
     */
    public static function export_data($format = 'json', $filters = []) {
        $data = get_option('sky_ai_data', []);
        switch ($format) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT);
            case 'csv':
                return self::convert_to_csv($data);
            case 'excel':
                return self::convert_to_excel($data);
            default:
                return json_encode($data);
        }
    }

    private static function convert_to_csv($data) {
        if (empty($data)) return '';
        $output = fopen('php://temp', 'r+');
        fputcsv($output, array_keys(reset($data)));
        foreach ($data as $row) fputcsv($output, $row);
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    private static function convert_to_excel($data) {
        // Simple CSV fallback for excel if no library is present
        return self::convert_to_csv($data);
    }

    /**
     * Backup Configuration
     */
    public static function create_backup() {
        $backup_data = [
            'settings' => get_option('skyline_ai_settings', []),
            'data' => get_option('sky_ai_data', []),
            'timestamp' => current_time('mysql')
        ];
        update_option('skyline_backup_' . time(), $backup_data);
        return true;
    }

    /**
     * Content Translation
     */
    public static function translate_content($content, $target_lang = 'en') {
        $api_key = get_option('sky_ai_api_key');
        $response = wp_remote_post('https://api.translation-service.com/translate', [
            'body' => json_encode([
                'text' => $content,
                'target_lang' => $target_lang,
                'api_key' => $api_key
            ]),
            'headers' => ['Content-Type' => 'application/json']
        ]);
        if (is_wp_error($response)) return 'Error: Translation service unavailable';
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['translated_text'] ?? 'Translation failed';
    }

    /**
     * Content Deduplication
     */
    public static function deduplicate_content($content) {
        $existing_posts = get_posts(['post_content' => $content]);
        return empty($existing_posts);
    }

    /**
     * Cache Management
     */
    public static function manage_cache() {
        if (wp_using_ext_object_cache()) {
            wp_cache_delete('sky_ai_cache', 'skyline');
        }
        return true;
    }

    /**
     * Webhook Handling
     */
    public static function webhook_handler($data) {
        $webhook_url = get_option('sky_webhook_url');
        if ($webhook_url) {
            wp_remote_post($webhook_url, [
                'body' => json_encode($data),
                'headers' => ['Content-Type' => 'application/json']
            ]);
        }
    }

    /**
     * Help Documentation
     */
    public static function get_help_docs() {
        $path = plugin_dir_path(__FILE__) . '../README.md';
        return file_exists($path) ? file_get_contents($path) : 'Documentation not found.';
    }

    /**
     * SDK Integration
     */
    public static function sdk_integration() {
        $sdk_path = plugin_dir_path(__FILE__) . '../sdk/Skyline_SDK.php';
        if (file_exists($sdk_path)) {
            require_once $sdk_path;
            return new Skyline_SDK();
        }
        return null;
    }
}
