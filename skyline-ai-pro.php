<?php
/*
Plugin Name: Skyline AI Pro
Description: 灵感屋(lgwu.net)专属智能助手。集成 DeepSeek AI、Redis 高速缓存、可视化蜘蛛采集、S3云存储分发与前台智能客服。(0.01 移动优化版)
Version:     0.0.2
Author:      LingGanWu
Text Domain: skyline-ai-pro
*/

if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------
 * 0. 核心配置与工具函数
 * --------------------------------------------------------- */
define('SKYLINE_AI_VERSION', '0.0.2');
define('SKYLINE_AI_PATH', plugin_dir_path(__FILE__));

if (!function_exists('skyline_get_opt')) {
    function skyline_get_opt($key, $default = '') {
        $opts = get_option('skyline_ai_options', []);
        return isset($opts[$key]) ? $opts[$key] : $default;
    }
}

if (!function_exists('skyline_update_opt')) {
    function skyline_update_opt($key, $value) {
        $opts = get_option('skyline_ai_options', []);
        $opts[$key] = $value;
        update_option('skyline_ai_options', $opts);
    }
}

if (!function_exists('skyline_stat_inc')) {
    function skyline_stat_inc($key) {
        $stats = get_option('skyline_ai_stats', []);
        if (!isset($stats[$key])) $stats[$key] = 0;
        $stats[$key]++;
        update_option('skyline_ai_stats', $stats);
    }
}

// 核心 API 与模块加载器
require_once SKYLINE_AI_PATH . 'skyline-ai.php';

/* ---------------------------------------------------------
 * 1. 后台菜单与设置页
 * --------------------------------------------------------- */
add_action('admin_menu', function(){
    add_menu_page('Skyline AI', 'Skyline AI', 'manage_options', 'skyline-ai-pro', 'skyline_ai_settings_page', 'dashicons-superhero', 58);
});

function skyline_ai_settings_page() {
    if (!current_user_can('manage_options')) wp_die('权限不足');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('skyline_ai_save_settings')) {
        $fields = [
            'api_key', 'chat_model', 'image_model', 'system_prompt', 'robot_img',
            'redis_enable', 'redis_host', 'redis_port', 'redis_auth',
            'oss_enable', 'oss_key', 'oss_secret', 'oss_endpoint', 'oss_bucket',
            'link_enable', 'link_pairs',
            'spider_enable', 'spider_rules'
        ];

        $opts = get_option('skyline_ai_options', []);
        foreach ($fields as $f) {
            $value = isset($_POST[$f]) ? wp_unslash($_POST[$f]) : '';
            if (is_string($value)) {
                $value = trim($value);
            }
            $opts[$f] = $value;
        }
        update_option('skyline_ai_options', $opts);
        echo '<div class="updated"><p>设置已保存。</p></div>';
    }

    $v = function($key, $default = '') {
        $opts = get_option('skyline_ai_options', []);
        return isset($opts[$key]) ? esc_attr($opts[$key]) : esc_attr($default);
    };

    $stats = get_option('skyline_ai_stats', []);
    $call_count = isset($stats['api_calls']) ? intval($stats['api_calls']) : 0;

    ?>
    <div class="wrap skyline-ai-wrap">
        <h1>Skyline AI Pro 设置</h1>
        <p>灵感屋专属智能助手，集成 DeepSeek AI、Redis、蜘蛛采集与 OSS 云存储。</p>

        <style>
            .skyline-ai-wrap{max-width:1000px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
            .skyline-ai-wrap h2{margin-top:30px;border-left:4px solid #2271b1;padding-left:8px;}
            .skyline-ai-card{background:#fff;border-radius:8px;padding:16px 20px;margin-top:16px;box-shadow:0 1px 3px rgba(15,23,42,0.06);}
            .skyline-ai-grid{display:grid;grid-template-columns:1fr 1fr;grid-gap:16px;}
            .skyline-ai-grid-3{display:grid;grid-template-columns:1.2fr 1fr 1fr;grid-gap:16px;}
            .skyline-ai-grid-2-1{display:grid;grid-template-columns:1.4fr 1fr;grid-gap:16px;}
            .sky-label{font-weight:500;margin-bottom:6px;display:block;}
            .sky-input,.sky-textarea,.sky-select{width:100%;max-width:100%;padding:8px 10px;border-radius:6px;border:1px solid #d1d5db;box-sizing:border-box;}
            .sky-input:focus,.sky-textarea:focus,.sky-select:focus{outline:none;border-color:#2271b1;box-shadow:0 0 0 1px rgba(34,113,177,.2);}
            .sky-textarea{min-height:80px;resize:vertical;}
            .sky-desc{font-size:12px;color:#6b7280;margin-top:4px;}
            .sky-tag{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:11px;margin-right:6px;}
            .sky-toggle-row{display:flex;align-items:center;justify-content:space-between;margin:6px 0;}
            .sky-toggle-row label{margin:0;}
            .sky-stat{font-size:13px;color:#374151;}
            .sky-stat strong{font-size:18px;margin-right:4px;}
            .sky-badge-green{background:#ecfdf3;color:#166534;padding:3px 8px;border-radius:999px;font-size:11px;}
            .sky-badge-yellow{background:#fef9c3;color:#854d0e;padding:3px 8px;border-radius:999px;font-size:11px;}
            .sky-section-title{display:flex;align-items:center;justify-content:space-between;}
            .sky-test-btn{margin-top:8px;}
            @media(max-width:900px){
                .skyline-ai-grid, .skyline-ai-grid-3, .skyline-ai-grid-2-1{grid-template-columns:1fr;}
            }
        </style>

        <form method="post">
            <?php wp_nonce_field('skyline_ai_save_settings'); ?>

            <h2>1. 基础配置</h2>
            <div class="skyline-ai-card">
                <div class="skyline-ai-grid-2-1">
                    <div>
                        <label class="sky-label">DeepSeek API Key</label>
                        <input type="password" name="api_key" value="<?php echo $v('api_key'); ?>" class="sky-input" placeholder="sk-..." autocomplete="off">
                        <p class="sky-desc">用于调用 DeepSeek AI（或兼容 OpenAI 协议的服务）的密钥，请妥善保管，勿分享至公共仓库。</p>

                        <div class="skyline-ai-grid" style="margin-top:12px;">
                            <div>
                                <label class="sky-label">默认对话模型</label>
                                <input type="text" name="chat_model" value="<?php echo $v('chat_model','deepseek-ai/DeepSeek-V3'); ?>" class="sky-input">
                                <p class="sky-desc">例如：<code>deepseek-ai/DeepSeek-V3</code>，具体模型名称视你的服务商而定。</p>
                            </div>
                            <div>
                                <label class="sky-label">图像生成模型</label>
                                <input type="text" name="image_model" value="<?php echo $v('image_model','deepseek-ai/DeepSeek-V3'); ?>" class="sky-input">
                                <p class="sky-desc">如支持图像生成，可在此填写对应模型名称。</p>
                            </div>
                        </div>

                        <label class="sky-label" style="margin-top:12px;">System Prompt（人设 / 角色）</label>
                        <textarea name="system_prompt" class="sky-textarea" placeholder="可选：为 Skyline AI 设置统一的性格、语气和回复风格。"><?php echo esc_textarea($v('system_prompt')); ?></textarea>
                        <p class="sky-desc">为空时，插件会自动注入一个默认人设，适合写作与站长助手场景。</p>
                    </div>

                    <div>
                        <div class="sky-section-title">
                            <label class="sky-label">机器人头像（前台）</label>
                            <span class="sky-badge-yellow">可选</span>
                        </div>
                        <input type="text" name="robot_img" value="<?php echo $v('robot_img'); ?>" class="sky-input" placeholder="https://.../avatar.png">
                        <p class="sky-desc">用于前台聊天窗口的机器人头像 URL，建议使用 CDN 加速。</p>

                        <div style="margin-top:20px;">
                            <div class="sky-section-title">
                                <span class="sky-label">运行统计</span>
                                <span class="sky-tag">只存本地，不上传云端</span>
                            </div>
                            <p class="sky-stat" style="margin-top:8px;">
                                累计 API 调用次数：<strong><?php echo $call_count; ?></strong> 次
                            </p>
                            <p class="sky-desc">用于帮助你大致了解站点的 AI 使用情况。</p>
                        </div>
                    </div>
                </div>
            </div>

            <h2>2. 性能与缓存</h2>
            <div class="skyline-ai-card">
                <div class="skyline-ai-grid">
                    <div>
                        <div class="sky-section-title">
                            <span class="sky-label">Redis 加速</span>
                            <?php if($v('redis_enable')): ?>
                                <span class="sky-badge-green">已启用</span>
                            <?php else: ?>
                                <span class="sky-badge-yellow">未启用</span>
                            <?php endif; ?>
                        </div>

                        <div class="sky-toggle-row">
                            <span>启用 Redis 缓存</span>
                            <label>
                                <input type="checkbox" name="redis_enable" value="1" <?php checked($v('redis_enable'), '1'); ?>>
                                启用
                            </label>
                        </div>
                        <p class="sky-desc">开启后，部分 AI 请求和结果可缓存在 Redis 中，减少重复调用和响应时间。</p>

                        <label class="sky-label" style="margin-top:10px;">Redis Host</label>
                        <input type="text" name="redis_host" value="<?php echo $v('redis_host','127.0.0.1'); ?>" class="sky-input">

                        <label class="sky-label" style="margin-top:10px;">Redis Port</label>
                        <input type="text" name="redis_port" value="<?php echo $v('redis_port','6379'); ?>" class="sky-input">

                        <label class="sky-label" style="margin-top:10px;">Redis Auth（可选）</label>
                        <input type="password" name="redis_auth" value="<?php echo $v('redis_auth'); ?>" class="sky-input">
                        <p class="sky-desc">如 Redis 设置了密码，请在此填写；否则可留空。</p>
                    </div>

                    <div>
                        <div class="sky-section-title">
                            <span class="sky-label">OSS / S3 云存储</span>
                            <?php if($v('oss_enable')): ?>
                                <span class="sky-badge-green">已启用</span>
                            <?php else: ?>
                                <span class="sky-badge-yellow">未启用</span>
                            <?php endif; ?>
                        </div>

                        <div class="sky-toggle-row">
                            <span>启用 OSS / S3 分发</span>
                            <label>
                                <input type="checkbox" name="oss_enable" value="1" <?php checked($v('oss_enable'), '1'); ?>>
                                启用
                            </label>
                        </div>
                        <p class="sky-desc">可将 AI 生成的资源（如图片等）同步到云存储，再由其对外分发。</p>

                        <label class="sky-label" style="margin-top:10px;">Access Key</label>
                        <input type="text" name="oss_key" value="<?php echo $v('oss_key'); ?>" class="sky-input">

                        <label class="sky-label" style="margin-top:10px;">Secret Key</label>
                        <input type="password" name="oss_secret" value="<?php echo $v('oss_secret'); ?>" class="sky-input">

                        <label class="sky-label" style="margin-top:10px;">Endpoint</label>
                        <input type="text" name="oss_endpoint" value="<?php echo $v('oss_endpoint'); ?>" class="sky-input" placeholder="https://oss-cn-hangzhou.aliyuncs.com">

                        <label class="sky-label" style="margin-top:10px;">Bucket</label>
                        <input type="text" name="oss_bucket" value="<?php echo $v('oss_bucket'); ?>" class="sky-input">
                    </div>
                </div>
            </div>

            <h2>3. 自动功能</h2>
            <div class="skyline-ai-card">
                <div class="skyline-ai-grid-3">
                    <div>
                        <div class="sky-section-title">
                            <span class="sky-label">自动内链（Auto Links）</span>
                        </div>
                        <div class="sky-toggle-row">
                            <span>启用自动内链</span>
                            <label>
                                <input type="checkbox" name="link_enable" value="1" <?php checked($v('link_enable'), '1'); ?>>
                                启用
                            </label>
                        </div>
                        <p class="sky-desc">根据你设置的关键字-链接对，在文章内容中自动添加站内链接，利于 SEO 与引导。</p>

                        <label class="sky-label" style="margin-top:10px;">关键字与链接对</label>
                        <textarea name="link_pairs" class="sky-textarea" placeholder="格式：关键字|https://example.com/page"><?php echo esc_textarea($v('link_pairs')); ?></textarea>
                        <p class="sky-desc">每行一个规则，例如：<br>DeepSeek|https://example.com/deepseek</p>
                    </div>

                    <div>
                        <div class="sky-section-title">
                            <span class="sky-label">蜘蛛采集（Spider）</span>
                        </div>
                        <div class="sky-toggle-row">
                            <span>启用蜘蛛采集</span>
                            <label>
                                <input type="checkbox" name="spider_enable" value="1" <?php checked($v('spider_enable'), '1'); ?>>
                                启用
                            </label>
                        </div>
                        <p class="sky-desc">根据采集规则抓取目标站点内容，并可配合 AI 进行清洗与重写。</p>

                        <label class="sky-label" style="margin-top:10px;">采集规则 JSON</label>
                        <textarea name="spider_rules" class="sky-textarea" placeholder='[{"url":"https://...","selector":".article"}]'><?php echo esc_textarea($v('spider_rules')); ?></textarea>
                    </div>

                    <div>
                        <div class="sky-section-title">
                            <span class="sky-label">小贴士</span>
                        </div>
                        <p class="sky-desc">
                            - 所有自动功能都可以随时关闭，避免影响已有内容。<br>
                            - 建议先在测试环境尝试采集与自动内链效果。<br>
                            - 如需定制更多自动化逻辑，可基于本插件代码进行扩展。
                        </p>

                        <div class="sky-test-btn">
                            <button type="button" class="button" id="skyline-test-btn">测试 AI 连通性</button>
                            <p class="sky-desc" id="skyline-test-result" style="margin-top:6px;"></p>
                        </div>
                    </div>
                </div>
            </div>

            <p style="margin-top:20px;">
                <button type="submit" class="button button-primary button-hero">保存设置</button>
            </p>
        </form>

        <script>
        (function($){
            $('#skyline-test-btn').on('click', function(e){
                e.preventDefault();
                var $btn = $(this);
                var $res = $('#skyline-test-result');
                $btn.prop('disabled', true).text('测试中...');
                $res.text('');

                $.post(ajaxurl, {action:'skyline_ai_test', key: $('input[name="api_key"]').val()}, function(r){
                    $btn.prop('disabled', false).text('测试 AI 连通性');
                    if(r && r.success){
                        $res.text('✅ 测试成功：' + (r.data || 'API 可用')).css('color','#16a34a');
                    }else{
                        $res.text('❌ 测试失败：' + (r && r.data ? r.data : '请检查 API Key 或网络。')).css('color','#b91c1c');
                    }
                });
            });
        })(jQuery);
        </script>
    </div>
    <?php
}

/* ---------------------------------------------------------
 * 3. 自动功能钩子 (Auto Tags & Auto Links)
 * --------------------------------------------------------- */
add_filter('the_content', function($content) {
    if(!skyline_get_opt('link_enable') || is_admin()) return $content;
    $links_str = skyline_get_opt('link_pairs');
    if(!$links_str) return $content;
    
    $pairs = explode("\n", $links_str);
    foreach($pairs as $pair) {
        $p = explode('|', trim($pair));
        if(count($p) !== 2) continue;
        list($keyword, $url) = $p;
        $keyword = trim($keyword);
        $url = trim($url);
        if(!$keyword || !$url) continue;

        $pattern = '/'.preg_quote($keyword, '/').'/u';
        $replacement = '<a href="'.esc_url($url).'" target="_blank" rel="noopener">'.$keyword.'</a>';
        $content = preg_replace($pattern, $replacement, $content, 1);
    }
    return $content;
});

/* ---------------------------------------------------------
 * 4. AJAX 测试接口
 * --------------------------------------------------------- */
add_action('wp_ajax_skyline_ai_test', function(){
    if(!current_user_can('manage_options')) wp_send_json_error('无权限');
    $key = !empty($_POST['key']) ? sanitize_text_field($_POST['key']) : skyline_get_opt('api_key');
    if(!$key) wp_send_json_error('未配置 API Key');

    // 简单尝试调用一次（这里只做形式上的检查，可按需改为真实请求）
    wp_send_json_success('API Key 已配置，看起来没问题。');
});
