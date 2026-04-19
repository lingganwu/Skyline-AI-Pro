<?php
if (!defined('ABSPATH')) exit;

class Skyline_Utils {
    /**
     * AI Quality Assessment
     */
    public static function assess_quality($content) {
        $readability = self::calculate_readability($content);
        $seo = self::calculate_seo($content);
        return [
            'readability' => $readability,
            'seo' => $seo,
            'overall' => round(($readability + $seo) / 2, 2)
        ];
    }

    private static function calculate_readability($content) {
        $score = 50;
        $len = mb_strlen($content);
        if ($len > 1000) $score += 20;
        elseif ($len > 500) $score += 10;
        
        if (strpos($content, '##') !== false) $score += 15;
        if (strpos($content, '###') !== false) $score += 10;
        if (preg_match_all('/\n\n/', $content) > 3) $score += 15;
        
        return min(100, $score);
    }

    private static function calculate_seo($content) {
        $score = 40;
        if (preg_match('/<h[1-3]>/i', $content) || strpos($content, '##') !== false) $score += 20;
        if (preg_match('/\b(专业|指南|分析|教程)\b/u', $content)) $score += 20;
        if (mb_strlen($content) > 800) $score += 20;
        
        return min(100, $score);
    }

    public static function batch_process($posts, $model = 'deepseek-v3') {
        $results = [];
        foreach ($posts as $post) {
            $results[] = skyline_generate_content($post['content'], $model);
        }
        return $results;
    }

    public static function export_data($format = 'json', $filters = []) {
        $data = get_option('sky_ai_data', []);
        switch ($format) {
            case 'json': return json_encode($data, JSON_PRETTY_PRINT);
            case 'csv': return self::convert_to_csv($data);
            default: return json_encode($data);
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

    public static function create_backup() {
        $backup_data = [
            'settings' => get_option('skyline_ai_settings', []),
            'data' => get_option('sky_ai_data', []),
            'timestamp' => current_time('mysql')
        ];
        update_option('skyline_backup_' . time(), $backup_data);
        return true;
    }

    public static function translate_content($content, $target_lang = 'en') {
        return "Translation feature pending API integration.";
    }

    public static function deduplicate_content($content) {
        $existing = get_posts(['s' => $content, 'posts_per_page' => 1]);
        return empty($existing);
    }

    public static function manage_cache() {
        if (wp_using_ext_object_cache()) wp_cache_delete('sky_ai_cache', 'skyline');
        return true;
    }

    public static function webhook_handler($data) {
        $url = get_option('sky_webhook_url');
        if ($url) wp_remote_post($url, ['body' => json_encode($data), 'headers' => ['Content-Type' => 'application/json']]);
    }

    public static function get_help_docs() {
        return "Skyline AI Pro Documentation: Please refer to the official README.txt for details.";
    }
}
