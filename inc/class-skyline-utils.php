<?php
if (!defined('ABSPATH')) exit;

class Skyline_Utils {
    public static function sdk_integration() {
        // 未实现：不要返回假成功。SDK 集成尚未接入，调用方应按 WP_Error 处理。
        return new WP_Error('not_implemented', 'SDK 集成尚未实现。');
    }

    /**
     * AI Quality Assessment（本地启发式打分，非 LLM 调用）
     *
     * 注意：这里只做本地轻量启发式打分（结构 + 关键词 + 长度），
     * 不消耗 API token，用于"编辑前快速体检"。如需 LLM 深度评审，
     * 请走 handle_ai_task 的 'polish'/'rewrite' 流程。
     */
    public static function assess_quality($content) {
        $readability = self::calculate_readability($content);
        $seo = self::calculate_seo($content);
        return [
            'readability' => $readability,
            'seo' => $seo,
            'overall' => round(($readability + $seo) / 2, 2),
            'note' => '本地启发式打分（非 LLM），用于编辑前快速体检。'
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

    public static function batch_process($posts, $model = '') {
        $results = [];
        $core = Skyline_Core::instance();
        foreach ($posts as $post) {
            $results[] = $core->call_api([['role'=>'user', 'content'=>$post['content']]]);
        }
        return $results;
    }

    public static function create_backup() {
        $settings = get_option('skyline_ai_settings', []);
        // 备份不存密钥：api_key / oss_ak / oss_sk / redis_auth 是明文凭据，
        // 写进 skyline_backup_* option 会随数据库备份或 option 导出外泄。
        // 备份里只记被剔除的键名（不记值）；用备份恢复后需在设置页重新填写这几项。
        $sensitive_keys = ['api_key', 'oss_ak', 'oss_sk', 'redis_auth'];
        $removed_keys = [];
        if (is_array($settings)) {
            foreach ($sensitive_keys as $k) {
                if (array_key_exists($k, $settings)) {
                    unset($settings[$k]);
                    $removed_keys[] = $k;
                }
            }
        }
        $backup_data = [
            'settings'              => $settings,
            'settings_keys_removed' => $removed_keys,
            'prompts'   => get_option('skyline_prompt_library', []),
            'stats'     => get_option('skyline_ai_stats', []),
            'data'      => get_option('sky_ai_data', []),
            'timestamp' => current_time('mysql')
        ];

        global $wpdb;
        // 清理 7 天前的旧备份。注意：旧代码 SUBSTRING(option_name, 18, 8) 的
        // 偏移是错的——前缀 'skyline_backup_' 只有 15 个字符，日期从第 16 位
        // 开始，之前误写成第 18 位，截出来的是 '261108_1' 这类字符串，CAST
        // 后恒小于 cutoff，所以每次备份都会删掉全部历史备份；现已修正为
        // 从第 16 位截 8 位，只删 7 天前的旧备份。
        $cutoff = gmdate('Ymd', time() - 7 * 86400);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE %s
               AND CAST(SUBSTRING(option_name, 16, 8) AS UNSIGNED) < %d",
            $wpdb->esc_like('skyline_backup_') . '%',
            $cutoff
        ));

        update_option('skyline_backup_' . gmdate('Ymd_His'), $backup_data, false);
        return true;
    }

    public static function translate_content($content, $target_lang = 'en') {
        // 未实现：不要返回假成功。翻译功能尚未接入 API，调用方应按 WP_Error 处理。
        return new WP_Error('not_implemented', '翻译功能尚未实现。');
    }

    public static function manage_cache() {
        if (wp_using_ext_object_cache()) wp_cache_delete('sky_ai_cache', 'skyline');
        return true;
    }

    public static function get_help_docs() {
        // 未实现：不要返回一句指向 README 的假成功。帮助文档尚未接入，
        // 调用方应按 WP_Error 处理（正式文档见 readme.txt）。
        return new WP_Error('not_implemented', '帮助文档尚未实现，请查看 readme.txt。');
    }
}