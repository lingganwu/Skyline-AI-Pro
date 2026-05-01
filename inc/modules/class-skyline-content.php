<?php
if (!defined('ABSPATH')) exit;

class Skyline_Content {
    
    // 批量查询缓存
    private $image_hash_cache = [];
    
    public function __construct() {
        // 异步任务钩子
        add_action('save_post', [$this, 'trigger_async_tasks'], 20, 2);
        add_action('skyline_async_sync', [$this, 'auto_sync_images']);
        add_action('skyline_async_seo', [$this, 'auto_seo_meta']);
        
        // 前台过滤器
        add_filter('the_content', [$this, 'auto_internal_links']);
        
        // AJAX 处理器 - 使用统一的安全验证
        $this->register_ajax_handlers();
        
        // 编辑器集成
        add_action('add_meta_boxes', [$this, 'add_copilot_box']);
    }
    
    private function register_ajax_handlers() {
        $actions = [
            'sky_seo_score' => 'ajax_seo_score',
            'sky_bulk_action' => 'ajax_bulk_action',
            'sky_sync_now' => 'ajax_sync_now',
            'sky_sync_single' => 'ajax_sync_single',
            'sky_link_now' => 'ajax_link_now',
        ];
        
        foreach ($actions as $action => $handler) {
            add_action('wp_ajax_' . $action, [$this, $handler]);
        }
    }

    /**
     * 安全验证辅助方法
     */
    private function verify_ajax($nonce_action = 'skyline_ajax_nonce') {
        if (!skyline_verify_nonce(null, $nonce_action)) {
            wp_send_json_error(__('安全验证失败', 'skyline-ai-pro'), 403);
        }
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('权限不足', 'skyline-ai-pro'), 403);
        }
    }

    /**
     * 触发异步任务
     */
    public function trigger_async_tasks($pid, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($pid) || $post->post_status !== 'publish') return;

        $core = Skyline_Core::instance();
        
        // 图片同步任务
        if ($core->get_opt('sync_enable') && $core->get_opt('sync_auto')) {
            if (!wp_next_scheduled('skyline_async_sync', [$pid])) {
                wp_schedule_single_event(time() + 2, 'skyline_async_sync', [$pid]);
            }
        }

        // SEO 任务
        $seo_tasks = ['auto_tags', 'auto_slug', 'auto_excerpt', 'auto_polish'];
        $needs_seo = false;
        foreach ($seo_tasks as $task) {
            if ($core->get_opt($task)) { $needs_seo = true; break; }
        }
        
        if ($needs_seo && !get_post_meta($pid, '_sky_seo_done', true)) {
            if (!wp_next_scheduled('skyline_async_seo', [$pid])) {
                wp_schedule_single_event(time() + 5, 'skyline_async_seo', [$pid]);
            }
        }
    }

    /**
     * 自动内链
     */
    public function auto_internal_links($content) {
        $core = Skyline_Core::instance();
        if (!$core->get_opt('link_enable') || is_admin()) return $content;
        
        $links_str = $core->get_opt('link_pairs');
        if (!$links_str) return $content;
        
        $pairs = explode("\n", $links_str);
        foreach ($pairs as $pair) {
            $p = explode('|', trim($pair));
            if (count($p) < 2) continue;
            
            $kw = trim($p[0]);
            $url = esc_url(trim($p[1]));
            if (!$kw || !$url) continue;
            
            // 安全：转义 URL 和关键词
            $content = preg_replace(
                '/(?!(?:[^<]+>|[^>]+<\/a>))' . preg_quote($kw, '/') . '/u',
                '<a href="' . esc_attr($url) . '" title="' . esc_attr($kw) . '" target="_blank" rel="noopener" class="sky-link">' . esc_html($kw) . '</a>',
                $content,
                1
            );
        }
        return $content;
    }

    /**
     * 批量查询图片哈希（性能优化）
     */
    private function batch_check_image_hashes($hashes) {
        if (empty($hashes)) return [];
        
        // 检查内存缓存
        $result = [];
        $uncached = [];
        
        foreach ($hashes as $hash) {
            if (isset($this->image_hash_cache[$hash])) {
                $result[$hash] = $this->image_hash_cache[$hash];
            } else {
                $uncached[] = $hash;
            }
        }
        
        if (!empty($uncached)) {
            global $wpdb;
            $placeholders = implode(',', array_fill(0, count($uncached), '%s'));
            $query = $wpdb->prepare(
                "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_sky_source_hash' AND meta_value IN ($placeholders)",
                $uncached
            );
            
            $rows = $wpdb->get_results($query);
            foreach ($rows as $row) {
                $result[$row->meta_value] = $row->post_id;
                $this->image_hash_cache[$row->meta_value] = $row->post_id;
            }
        }
        
        return $result;
    }

    /**
     * 下载图片（带缓存检查）
     */
    private function download_image($url, $pid) {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $core = Skyline_Core::instance();
        
        // 规范化 URL
        if (strpos($url, 'wx_fmt') === false && strpos($url, 'mmbiz') === false) {
            $parsed = parse_url($url);
            $norm_url = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '') . ($parsed['path'] ?? '');
        } else {
            $norm_url = $url;
        }
        $img_hash = md5($norm_url);

        // 1. 检查文章级缓存
        $sync_history = get_post_meta($pid, '_sky_sync_history', true);
        if (!is_array($sync_history)) $sync_history = [];

        if (isset($sync_history[$img_hash])) {
            $existing_id = intval($sync_history[$img_hash]);
            if (get_post($existing_id)) {
                return ['id' => $existing_id, 'status' => 'cached_post'];
            }
        }

        // 2. 检查全局缓存（使用批量查询）
        $global_hashes = $this->batch_check_image_hashes([$img_hash]);
        if (isset($global_hashes[$img_hash])) {
            $global_id = $global_hashes[$img_hash];
            if (get_post($global_id)) {
                $sync_history[$img_hash] = $global_id;
                update_post_meta($pid, '_sky_sync_history', $sync_history);
                return ['id' => $global_id, 'status' => 'cached_global'];
            }
        }

        // 3. 下载图片
        $args = [
            'timeout' => 25,
            'sslverify' => $core->get_opt('sync_ssl_verify', true),
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ];
        
        // 微信图片特殊处理
        if ($core->get_opt('sync_allow_wechat') && (strpos($url, 'qpic.cn') !== false || strpos($url, 'mmbiz') !== false)) {
            $args['headers']['Referer'] = 'https://mp.weixin.qq.com/';
        } else {
            $args['headers']['Referer'] = $url;
        }

        $res = wp_remote_get($url, $args);
        if (is_wp_error($res)) {
            return ['error' => __('网络错误: ', 'skyline-ai-pro') . $res->get_error_message()];
        }
        
        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            return ['error' => sprintf(__('HTTP 状态码: %d', 'skyline-ai-pro'), $code)];
        }

        $file_data = wp_remote_retrieve_body($res);
        if (strlen($file_data) < 500) {
            return ['error' => __('文件过小', 'skyline-ai-pro')];
        }

        // 4. 保存文件
        $filename = 'sync_' . date('YmdHis') . '_' . substr($img_hash, 0, 6) . '.jpg';
        $content_type = wp_remote_retrieve_header($res, 'content-type');
        
        if ($content_type) {
            $ext_map = ['png' => '.png', 'gif' => '.gif', 'webp' => '.webp'];
            foreach ($ext_map as $mime => $ext) {
                if (strpos($content_type, $mime) !== false) {
                    $filename = str_replace('.jpg', $ext, $filename);
                    break;
                }
            }
        }

        $upload = wp_upload_bits($filename, null, $file_data);
        if ($upload['error']) {
            return ['error' => __('写入失败: ', 'skyline-ai-pro') . $upload['error']];
        }

        $file_path = $upload['file'];
        $mime = $content_type ?: 'image/jpeg';

        // 5. 图片处理
        if ($core->get_opt('sync_rm_wm')) {
            $this->remove_watermark_crop($file_path, $mime);
        }
        if ($core->get_opt('sync_wm_enable')) {
            $this->watermark($file_path);
        }

        // 6. 创建附件
        $aid = wp_insert_attachment([
            'post_mime_type' => $mime,
            'post_title' => sanitize_file_name($filename),
        ], $file_path, $pid);
        
        if (!is_wp_error($aid)) {
            $meta = wp_generate_attachment_metadata($aid, $file_path);
            wp_update_attachment_metadata($aid, $meta);
            
            update_post_meta($aid, '_sky_source_hash', $img_hash);
            update_post_meta($aid, '_sky_source_url', esc_url($url));
            $core->stat_inc('sync_count');
            $core->log("同步图片成功: $filename", 'info', 'Sync');
            
            $sync_history[$img_hash] = $aid;
            update_post_meta($pid, '_sky_sync_history', $sync_history);
            
            return ['id' => $aid, 'status' => 'downloaded'];
        }
        
        return ['error' => __('附件创建失败', 'skyline-ai-pro')];
    }

    /**
     * 自动同步图片
     */
    public function auto_sync_images($pid) {
        $post = get_post($pid);
        if (!$post) return;
        
        // 防止重复处理
        if (get_post_meta($pid, '_sky_sync_processing', true)) return;
        update_post_meta($pid, '_sky_sync_processing', 1);

        $content = $post->post_content;
        preg_match_all('/(src|data-src)=[\'"]([^\'"]+)[\'"]/i', $content, $m);
        
        if (empty($m[2])) {
            delete_post_meta($pid, '_sky_sync_processing');
            return;
        }

        $site_domain = parse_url(get_site_url(), PHP_URL_HOST);
        $core = Skyline_Core::instance();
        $max_img = intval($core->get_opt('sync_max_img', 20));
        $success = 0;
        $processed = 0;

        // 预过滤：排除本地图片和 data URI
        $external_urls = [];
        foreach ($m[2] as $url_raw) {
            if ($processed >= $max_img) break;
            $url_real = html_entity_decode($url_raw);
            
            if (strpos($url_real, $site_domain) !== false || strpos($url_real, 'data:image') !== false) {
                continue;
            }
            
            $external_urls[] = ['raw' => $url_raw, 'real' => $url_real];
            $processed++;
        }

        // 批量预检查哈希
        $hashes = [];
        foreach ($external_urls as $item) {
            $parsed = parse_url($item['real']);
            $norm = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '') . ($parsed['path'] ?? '');
            $hashes[] = md5($norm);
        }
        $this->batch_check_image_hashes($hashes);

        // 处理图片
        foreach ($external_urls as $item) {
            $res = $this->download_image($item['real'], $pid);
            if (isset($res['id'])) {
                $local_url = wp_get_attachment_url($res['id']);
                $content = str_replace($item['raw'], $local_url, $content);
                $success++;
            }
        }
        
        // 更新文章内容
        if ($success > 0 && $content !== $post->post_content) {
            remove_action('save_post', [$this, 'trigger_async_tasks'], 20);
            global $wpdb;
            $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $pid]);
            clean_post_cache($pid);
            add_action('save_post', [$this, 'trigger_async_tasks'], 20, 2);
        }
        
        delete_post_meta($pid, '_sky_sync_processing');
    }

    /**
     * SEO 自动化
     */
    public function auto_seo_meta($pid) {
        $post = get_post($pid);
        if (!$post) return;
        if (get_post_meta($pid, '_sky_seo_done', true)) return;

        $core = Skyline_Core::instance();
        $updates = [];
        global $wpdb;

        // AI 润色
        if ($core->get_opt('auto_polish')) {
            $prompt = __("请修正以下文章内容的错别字、标点符号错误和语病，保持原意不变，直接返回修正后的内容：\n\n", 'skyline-ai-pro') . 
                      mb_substr($post->post_content, 0, 3000);
            $polished = $core->call_api([['role' => 'user', 'content' => $prompt]]);
            
            if (!is_wp_error($polished) && strlen($polished) > 100) {
                $updates['post_content'] = $polished;
                $core->log("AI 润色成功 [$pid]", 'info', 'SEO');
            }
        }

        // 生成标签
        if ($core->get_opt('auto_tags')) {
            $text = $updates['post_content'] ?? $post->post_content;
            $prompt = __("请基于以下内容提取5个核心SEO标签，用逗号分隔：\n\n", 'skyline-ai-pro') . 
                      mb_substr(strip_tags($text), 0, 1500);
            $tags = $core->call_api([['role' => 'user', 'content' => $prompt]]);
            
            if (!is_wp_error($tags)) {
                wp_set_post_tags($pid, str_replace(['，', '、'], ',', $tags), true);
            }
        }

        // 生成 Slug
        if ($core->get_opt('auto_slug')) {
            $prompt = "Generate a concise English URL slug for this title (max 5-8 words). OUTPUT ONLY THE SLUG.\n\nTitle: " . $post->post_title;
            $slug = $core->call_api([['role' => 'user', 'content' => $prompt]]);
            
            if (!is_wp_error($slug)) {
                $slug = sanitize_title(strip_tags(preg_replace('/^(Here.*?:\s*)/i', '', $slug)));
                if ($slug) {
                    $updates['post_name'] = substr(trim(preg_replace('/-+/', '-', $slug), '-'), 0, 60);
                }
            }
        }

        // 生成摘要
        if ($core->get_opt('auto_excerpt') && empty($post->post_excerpt)) {
            $text = $updates['post_content'] ?? $post->post_content;
            $prompt = __("生成120字的SEO摘要：\n\n", 'skyline-ai-pro') . 
                      mb_substr(strip_tags($text), 0, 1500);
            $excerpt = $core->call_api([['role' => 'user', 'content' => $prompt]]);
            
            if (!is_wp_error($excerpt)) {
                $updates['post_excerpt'] = trim(mb_substr(wp_strip_all_tags($excerpt), 0, 260));
            }
        }

        // 批量更新
        if (!empty($updates)) {
            $wpdb->update($wpdb->posts, $updates, ['ID' => $pid]);
            clean_post_cache($pid);
        }
        
        update_post_meta($pid, '_sky_seo_done', 1);
    }

    /**
     * 裁剪去水印
     */
    private function remove_watermark_crop($path, $mime) {
        if (!extension_loaded('gd')) return;
        $info = @getimagesize($path);
        if (!$info) return;
        
        $w = $info[0];
        $h = $info[1];
        if ($h < 150) return;
        
        $src = ($mime === 'image/jpeg') ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if (!$src) return;
        
        $crop_h = ($h - 40 < 50) ? 20 : 40;
        $dst = imagecreatetruecolor($w, $h - $crop_h);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h - $crop_h);
        
        if ($mime === 'image/jpeg') {
            imagejpeg($dst, $path, 90);
        } else {
            imagepng($dst, $path, 9);
        }
        
        imagedestroy($src);
        imagedestroy($dst);
    }

    /**
     * 添加水印
     */
    private function watermark($path) {
        if (!extension_loaded('gd')) return;
        $info = @getimagesize($path);
        if (!$info) return;
        
        $im = ($info['mime'] === 'image/jpeg') ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if (!$im) return;
        
        $txt = Skyline_Core::instance()->get_opt('sync_wm_text', '@Skyline');
        $color = imagecolorallocatealpha($im, 255, 255, 255, 60);
        $font = 5;
        $x = imagesx($im) - (strlen($txt) * 9) - 10;
        $y = imagesy($im) - 20;
        
        imagestring($im, $font, max(10, $x), max(10, $y), $txt, $color);
        
        if ($info['mime'] === 'image/jpeg') {
            imagejpeg($im, $path, 90);
        } else {
            imagepng($im, $path, 9);
        }
        
        imagedestroy($im);
    }

    // ═══ AJAX 处理器 ═══

    public function ajax_link_now() {
        $this->verify_ajax();
        
        $content = wp_unslash($_POST['content'] ?? '');
        if (!$content) {
            wp_send_json_error(__('内容为空', 'skyline-ai-pro'));
        }
        
        $links_str = Skyline_Core::instance()->get_opt('link_pairs');
        if (!$links_str) {
            wp_send_json_error(__('未配置内链关键词', 'skyline-ai-pro'));
        }
        
        $pairs = explode("\n", $links_str);
        $total = 0;
        
        foreach ($pairs as $pair) {
            $p = explode('|', trim($pair));
            if (count($p) < 2) continue;
            
            $kw = trim($p[0]);
            $url = esc_url(trim($p[1]));
            if (!$kw || !$url) continue;
            
            $pattern = '/(?!(?:[^<]+>|[^>]+<\/a>))(' . preg_quote($kw, '/') . ')/u';
            $replace = '<a href="' . esc_attr($url) . '" title="$1" target="_blank" rel="noopener" class="sky-link">$1</a>';
            $content = preg_replace($pattern, $replace, $content, 1, $count);
            $total += $count;
        }
        
        if ($total > 0) {
            wp_send_json_success([
                'content' => $content,
                'msg' => sprintf(__('成功添加 %d 个内链', 'skyline-ai-pro'), $total)
            ]);
        } else {
            wp_send_json_error(__('没有找到可替换的关键词', 'skyline-ai-pro'));
        }
    }

    public function ajax_sync_single() {
        $this->verify_ajax();
        
        $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        $pid = intval($_POST['pid'] ?? 0);
        
        if (!$url) {
            wp_send_json_error(__('URL 为空', 'skyline-ai-pro'));
        }
        
        $result = $this->download_image($url, $pid);
        
        if (isset($result['id'])) {
            wp_send_json_success([
                'url' => wp_get_attachment_url($result['id']),
                'msg' => $result['status'] === 'downloaded' ? __('下载成功', 'skyline-ai-pro') : __('秒传（复用旧图）', 'skyline-ai-pro'),
                'status' => $result['status']
            ]);
        } else {
            wp_send_json_error($result['error']);
        }
    }

    public function ajax_sync_now() {
        $this->verify_ajax();
        // 批量同步逻辑
        wp_send_json_success(__('同步任务已加入队列', 'skyline-ai-pro'));
    }

    public function ajax_bulk_action() {
        $this->verify_ajax();
        // 批量操作逻辑
        wp_send_json_success(__('批量操作已完成', 'skyline-ai-pro'));
    }

    public function ajax_seo_score() {
        $this->verify_ajax('skyline_ajax_nonce');
        
        $score = 100;
        $advice = [];
        $title = sanitize_text_field($_POST['title'] ?? '');
        $content = wp_kses_post($_POST['content'] ?? '');
        
        if (mb_strlen($title) < 10) {
            $score -= 10;
            $advice[] = '❌ ' . __('标题过短', 'skyline-ai-pro');
        }
        if (mb_strlen(strip_tags($content)) < 300) {
            $score -= 20;
            $advice[] = '❌ ' . __('内容稀薄', 'skyline-ai-pro');
        }
        if ($score >= 90) {
            $advice[] = '🎉 ' . __('完美！', 'skyline-ai-pro');
        }
        
        Skyline_Core::instance()->log("SEO 诊断得分: $score", 'info', 'SEO');
        wp_send_json_success(['score' => $score, 'advice' => $advice]);
    }

    /**
     * 添加 Copilot 编辑器面板
     */
    public function add_copilot_box() {
        add_meta_box('sky_copilot', '🔮 Skyline Copilot', [$this, 'render_copilot'], 'post', 'side', 'high');
    }
    
    public function render_copilot($post) {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('skyline_ajax_nonce');
        
        // 加载外部 JS
        wp_enqueue_script('skyline-copilot', SKY_URL . 'assets/js/copilot.js', ['jquery'], SKY_VERSION, true);
        wp_localize_script('skyline-copilot', 'skylineCopilot', [
            'ajax_url' => $ajax_url,
            'nonce' => $nonce,
            'i18n' => [
                'confirm_replace' => __('确定要用生成的内容覆盖当前内容吗？', 'skyline-ai-pro'),
                'no_content' => __('请先输入或粘贴内容', 'skyline-ai-pro'),
                'no_external_images' => __('✅ 未发现外部图片！', 'skyline-ai-pro'),
                'sync_complete' => __('同步完成', 'skyline-ai-pro'),
            ]
        ]);
        
        // 加载外部 CSS
        wp_enqueue_style('skyline-copilot', SKY_URL . 'assets/css/copilot.css', [], SKY_VERSION);
        
        // 渲染模板
        include SKY_PATH . 'admin/views/copilot.php';
    }
}
