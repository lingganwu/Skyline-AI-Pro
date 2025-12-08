<?php
if (!defined('ABSPATH')) exit;

class Skyline_Content {
    public function __construct() {
        // 自动任务
        add_action('save_post', [$this, 'auto_spider'], 20, 2);
        add_action('save_post', [$this, 'auto_seo_meta'], 21, 2); 
        add_filter('the_content', [$this, 'auto_internal_links']);
        
        // Ajax 接口
        add_action('wp_ajax_sky_seo_score', [$this, 'ajax_seo_score']);
        add_action('wp_ajax_sky_bulk_action', [$this, 'ajax_bulk_action']);
        add_action('wp_ajax_sky_spider_now', [$this, 'ajax_spider_now']); 
        add_action('wp_ajax_sky_spider_single', [$this, 'ajax_spider_single']); 
        add_action('wp_ajax_sky_link_now', [$this, 'ajax_link_now']); 
        
        // Copilot 面板
        add_action('add_meta_boxes', [$this, 'add_copilot_box']);
    }

    public function ajax_link_now() {
        check_ajax_referer('sky_ai_task_nonce');
        if(!current_user_can('edit_posts')) wp_send_json_error('权限不足');
        
        $content = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
        if(!$content) wp_send_json_error('内容为空');

        $links_str = Skyline_Core::instance()->get_opt('link_pairs');
        if(!$links_str) wp_send_json_error('未配置内链关键词，请去设置页添加');

        $pairs = explode("\n", $links_str);
        $total_replaced = 0;

        foreach($pairs as $pair) {
            $p = explode('|', trim($pair));
            if(count($p) < 2) continue;
            $kw = trim($p[0]); 
            $url = trim($p[1]);
            if(!$kw || !$url) continue;

            $pattern = '/(?!(?:[^<]+>|[^>]+<\/a>))(' . preg_quote($kw, '/') . ')/u';
            $replace = '<a href="'.$url.'" title="$1" target="_blank" class="sky-link">$1</a>';
            
            $content = preg_replace($pattern, $replace, $content, 1, $count);
            $total_replaced += $count;
        }

        if($total_replaced > 0) {
            wp_send_json_success(['content' => $content, 'msg' => "成功添加 {$total_replaced} 个内链"]);
        } else {
            wp_send_json_error('没有找到可替换的关键词，或关键词已存在链接');
        }
    }

    // --- 核心：下载单张图片 ---
    private function download_image($url, $pid) {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
        }

        $core = Skyline_Core::instance();
        
        // 预处理 URL
        if (strpos($url, 'wx_fmt') === false && strpos($url, 'mmbiz') === false) {
             $parsed = parse_url($url);
             $norm_url = ($parsed['scheme']??'http') . '://' . ($parsed['host']??'') . ($parsed['path']??'');
        } else {
             $norm_url = $url;
        }
        $img_hash = md5($norm_url);

        $spider_history = get_post_meta($pid, '_sky_spider_history', true);
        if (!is_array($spider_history)) $spider_history = [];

        if (isset($spider_history[$img_hash])) {
            $existing_id = intval($spider_history[$img_hash]);
            if (get_post($existing_id)) return ['id' => $existing_id, 'status' => 'cached_post'];
        }

        global $wpdb;
        $global_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sky_source_hash' AND meta_value = %s LIMIT 1", 
            $img_hash
        ));

        if ($global_id && get_post($global_id)) {
            $spider_history[$img_hash] = $global_id;
            update_post_meta($pid, '_sky_spider_history', $spider_history);
            return ['id' => $global_id, 'status' => 'cached_global'];
        }

        $args = [
            'timeout' => 25, 
            'sslverify' => $core->get_opt('spider_ssl_verify', true),
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ];
        
        if($core->get_opt('spider_allow_wechat') && (strpos($url,'qpic.cn')!==false || strpos($url,'mmbiz')!==false)) {
            $args['headers']['Referer'] = 'https://mp.weixin.qq.com/'; 
        } else {
            $args['headers']['Referer'] = $url; 
        }

        $res = wp_remote_get($url, $args);
        
        if(is_wp_error($res)) return ['error' => '网络错误: ' . $res->get_error_message()];
        $code = wp_remote_retrieve_response_code($res);
        if($code != 200) return ['error' => "HTTP状态 $code"];

        $file_data = wp_remote_retrieve_body($res);
        if(strlen($file_data) < 500) return ['error' => '文件太小(<500B)'];

        $filename = 'sp_' . date('YmdHis') . '_' . substr($img_hash, 0, 6) . '.jpg'; 
        $content_type = wp_remote_retrieve_header($res, 'content-type');
        
        if($content_type) {
            if(strpos($content_type, 'png')!==false) $filename = str_replace('.jpg', '.png', $filename);
            elseif(strpos($content_type, 'gif')!==false) $filename = str_replace('.jpg', '.gif', $filename);
            elseif(strpos($content_type, 'webp')!==false) $filename = str_replace('.jpg', '.webp', $filename);
        }

        $upload = wp_upload_bits($filename, null, $file_data);
        if($upload['error']) return ['error' => '写入失败: ' . $upload['error']];

        $file_path = $upload['file'];
        $mime = $content_type ?: 'image/jpeg';

        if($core->get_opt('spider_rm_wm')) $this->remove_watermark_crop($file_path, $mime);
        if($core->get_opt('spider_wm_enable')) $this->watermark($file_path);

        $aid = wp_insert_attachment(['post_mime_type'=>$mime, 'post_title'=>'Spider Img'], $file_path, $pid);
        if (!is_wp_error($aid)) {
            wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $file_path));
            update_post_meta($aid, '_sky_source_hash', $img_hash);
            update_post_meta($aid, '_sky_source_url', $url);
            $core->stat_inc('spider_count');
            $core->log("采集图片成功: $filename", 'info', 'Spider');
            $spider_history[$img_hash] = $aid;
            update_post_meta($pid, '_sky_spider_history', $spider_history);
            return ['id' => $aid, 'status' => 'downloaded'];
        }
        return ['error' => '数据库插入失败'];
    }

    public function ajax_spider_single() {
        check_ajax_referer('sky_ai_task_nonce');
        if(!current_user_can('edit_posts')) wp_send_json_error('权限不足');

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $pid = isset($_POST['pid']) ? intval($_POST['pid']) : 0;

        if(!$url) wp_send_json_error('URL为空');

        $result = $this->download_image($url, $pid);
        
        if(isset($result['id'])) {
            $local_url = wp_get_attachment_url($result['id']);
            $msg = ($result['status'] === 'downloaded') ? '下载成功' : '秒传(复用旧图)';
            wp_send_json_success(['url' => $local_url, 'msg' => $msg, 'status' => $result['status']]);
        } else {
            $fails = get_post_meta($pid, '_sky_spider_fail_log', true);
            if(!is_array($fails)) $fails = [];
            $fails[] = ['time'=>date('H:i:s'), 'url'=>$url, 'err'=>$result['error']];
            if(count($fails)>50) array_shift($fails); 
            update_post_meta($pid, '_sky_spider_fail_log', $fails);
            wp_send_json_error($result['error']);
        }
    }

    private function run_spider_logic($content, $pid) {
        $content = (string)$content;
        preg_match_all('/(src|data-src)=[\'"]([^\'"]+)[\'"]/i', $content, $m);
        if(empty($m[2])) return false;

        $site_domain = parse_url(get_site_url(), PHP_URL_HOST);
        $success = 0;
        $tasks = [];
        foreach($m[2] as $k => $url_raw) {
            $url_real = html_entity_decode($url_raw);
            if(strpos($url_real, $site_domain)!==false || strpos($url_real, 'data:image')!==false) continue;
            $tasks[$url_real] = ['raw_url' => $url_raw, 'attr' => $m[1][$k]];
        }
        $tasks = array_slice($tasks, 0, Skyline_Core::instance()->get_opt('spider_max_img', 20));

        foreach($tasks as $dl_url => $info) {
            $res = $this->download_image($dl_url, $pid);
            if(isset($res['id'])) {
                $local_url = wp_get_attachment_url($res['id']);
                
                $content = str_replace($info['raw_url'], $local_url, $content);
                if ($info['raw_url'] !== $dl_url) $content = str_replace($dl_url, $local_url, $content);

                if(strtolower($info['attr']) === 'data-src') {
                    $content = str_replace('data-src="' . $local_url . '"', 'src="' . $local_url . '"', $content);
                    $content = str_replace("data-src='" . $local_url . "'", "src='" . $local_url . "'", $content);
                    $content = preg_replace('/src=["\']data:image\/[^;]+;base64,[^"\']*["\']/', '', $content);
                    $content = str_replace(['src=""', "src=''"], '', $content);
                }
                $success++;
            }
        }
        return ($success > 0) ? $content : false;
    }

    public function auto_spider($pid, $post) {
        $core = Skyline_Core::instance();
        if(!$core->get_opt('spider_enable') || !$core->get_opt('spider_auto')) return;
        if(wp_is_post_revision($pid) || $post->post_status!='publish') return;
        
        if(get_post_meta($pid, '_sky_spider_processing', true)) return;
        update_post_meta($pid, '_sky_spider_processing', 1);

        $new_content = $this->run_spider_logic($post->post_content, $pid);
        
        if($new_content && $new_content !== $post->post_content) {
            remove_action('save_post', [$this, 'auto_spider'], 20);
            global $wpdb; 
            $wpdb->update($wpdb->posts, ['post_content'=>$new_content], ['ID'=>$pid]);
            clean_post_cache($pid);
            add_action('save_post', [$this, 'auto_spider'], 20, 2);
        }
        delete_post_meta($pid, '_sky_spider_processing');
    }

    public function ajax_spider_now() { /* 批量接口备用 */ }

    // --- SEO / 润色 ---
    public function auto_seo_meta($pid, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($pid) || $post->post_status != 'publish') return;
        
        // 注意：如果希望每次更新都跑一次，可以注释掉下面这行
        if (get_post_meta($pid, '_sky_seo_done', true)) return;

        $core = Skyline_Core::instance();
        $do_tags = $core->get_opt('auto_tags');
        $do_slug = $core->get_opt('auto_slug');
        $do_excerpt = $core->get_opt('auto_excerpt');
        $do_polish = $core->get_opt('auto_polish');

        if (!$do_tags && !$do_slug && !$do_excerpt && !$do_polish) return;
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        $updates = [];
        global $wpdb;

        if ($do_polish) {
            $prompt = "请修正以下文章内容的错别字、标点符号错误和语病，保持原意不变，直接返回修正后的 Markdown 内容：\n\n" . mb_substr($post->post_content, 0, 3000);
            $polished = $core->call_api([['role'=>'user', 'content'=>$prompt]]);
            if ($polished && stripos($polished, 'Error') === false && strlen($polished) > 100) {
                $updates['post_content'] = $polished;
                $core->log("AI 润色成功 [$pid]", 'info', 'SEO');
            }
        }
        if ($do_tags) {
            $text = isset($updates['post_content']) ? $updates['post_content'] : $post->post_content;
            $prompt = "请基于以下内容提取5个核心SEO标签，用逗号分隔，不要其他文字和编号：\n\n" . mb_substr(strip_tags($text), 0, 1500);
            $tags = $core->call_api([['role'=>'user', 'content'=>$prompt]]);
            if ($tags && stripos($tags, 'Error') === false) wp_set_post_tags($pid, str_replace(['，', '、'], ',', $tags), true);
        }
        
        if ($do_slug) {
            $prompt = "Generate a concise English URL slug for this title (max 5-8 words). STRICTLY OUTPUT ONLY THE SLUG (lowercase, hyphens). Do not output 'Here is', 'Sure', or any explanation. Title: " . $post->post_title;
            $slug = $core->call_api([['role'=>'user', 'content'=>$prompt]]);
            if ($slug && stripos($slug, 'Error') === false) {
                $slug = preg_replace('/^(Here\'s|Here is|Sure|Okay|Certainly).*?:\s*/i', '', $slug);
                $slug = preg_replace('/^The slug is\s*/i', '', $slug);
                $slug = strip_tags($slug);
                $slug = sanitize_title($slug);
                $slug = preg_replace('/-+/', '-', $slug);
                $slug = trim($slug, '-');
                $slug = substr($slug, 0, 60);
                $slug = rtrim($slug, '-');
                if ($slug) $updates['post_name'] = $slug;
            }
        }
        
        if ($do_excerpt && empty($post->post_excerpt)) {
            $text = isset($updates['post_content']) ? $updates['post_content'] : $post->post_content;
            $prompt = "Generate a 120-word SEO summary for this content (in Chinese): \n\n" . mb_substr(strip_tags($text), 0, 1500);
            $excerpt = $core->call_api([['role'=>'user', 'content'=>$prompt]]);
           if ($excerpt && stripos($excerpt, 'Error') === false) {
                $excerpt = wp_strip_all_tags($excerpt);
                
                // [新增优化] 正则去除 AI 产生的前缀 (如 "**120字SEO摘要:**", "摘要：", "Summary:" 等)
                $excerpt = preg_replace('/^(\*\*|")?.*?(摘要|Summary|简介).*?[:：]\s*(\*\*|")?/iu', '', $excerpt);
                // [新增优化] 清理可能残留的 markdown 加粗符号
                $excerpt = str_replace(['**', "''"], '', $excerpt);
                
                $excerpt = mb_substr($excerpt, 0, 260); 
                $updates['post_excerpt'] = trim($excerpt); // 加上 trim 去除首尾空格
            }
        }

        if (!empty($updates)) {
            $wpdb->update($wpdb->posts, $updates, ['ID' => $pid]);
            clean_post_cache($pid);
        }
        update_post_meta($pid, '_sky_seo_done', 1);
    }

    private function remove_watermark_crop($path, $mime) {
        if(!extension_loaded('gd')) return;
        $info = @getimagesize($path); if(!$info) return;
        $w = $info[0]; $h = $info[1];
        if($h < 150) return;
        $src = ($mime == 'image/jpeg') ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if(!$src) return;
        $crop_h = 40; if($h - $crop_h < 50) $crop_h = 20;
        $dst = imagecreatetruecolor($w, $h - $crop_h);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h - $crop_h);
        if($mime == 'image/jpeg') imagejpeg($dst, $path, 90); else imagepng($dst, $path, 9);
        imagedestroy($src); imagedestroy($dst);
    }

    private function watermark($path) {
        if(!extension_loaded('gd')) return;
        $i=@getimagesize($path); if(!$i) return;
        $im = ($i['mime']=='image/jpeg') ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if(!$im) return;
        $txt = Skyline_Core::instance()->get_opt('spider_wm_text', '@Skyline');
        $color = imagecolorallocatealpha($im, 255, 255, 255, 60);
        $font = 5; 
        $x = imagesx($im) - (strlen($txt)*9) - 10; $y = imagesy($im) - 20;
        imagestring($im, $font, max(10,$x), max(10,$y), $txt, $color);
        if($i['mime']=='image/jpeg') imagejpeg($im, $path, 90); else imagepng($im, $path, 9);
        imagedestroy($im);
    }

    public function auto_internal_links($content) {
        if(!Skyline_Core::instance()->get_opt('link_enable') || is_admin()) return $content;
        $links_str = Skyline_Core::instance()->get_opt('link_pairs');
        if(!$links_str) return $content;
        $pairs = explode("\n", $links_str);
        foreach($pairs as $pair) {
            $p = explode('|', trim($pair));
            if(count($p) < 2) continue;
            $kw = trim($p[0]); $url = trim($p[1]);
            if(!$kw || !$url) continue;
            $content = preg_replace('/(?!(?:[^<]+>|[^>]+<\/a>))'.preg_quote($kw, '/').'/u', '<a href="'.$url.'" title="'.$kw.'" target="_blank" class="sky-link">'.$kw.'</a>', $content, 1);
        }
        return $content;
    }

    public function ajax_seo_score() {
        check_ajax_referer('sky_seo_nonce');
        $score = 100; $advice = [];
        $title = sanitize_text_field($_POST['title'] ?? ''); 
        $content = wp_kses_post($_POST['content'] ?? '');
        if(mb_strlen($title)<10) { $score-=10; $advice[]='❌ 标题过短'; }
        if(mb_strlen(strip_tags($content))<300) { $score-=20; $advice[]='❌ 内容稀薄'; }
        if($score>=90) $advice[]='🎉 完美！';
        
        Skyline_Core::instance()->log("SEO 诊断得分: $score", 'info', 'SEO');
        wp_send_json_success(['score'=>$score, 'advice'=>$advice]);
    }

    public function ajax_bulk_action() { /* ...保留... */ }

    // --- 前台 Copilot (终极修复版：占位符策略解决 HTML 变动问题) ---
    public function add_copilot_box() {
        add_meta_box('sky_copilot', '🔮 Skyline Copilot', function($post) {
            $ajax_url = admin_url('admin-ajax.php');
            $nonce = wp_create_nonce('sky_ai_task_nonce');
            $seo_nonce = wp_create_nonce('sky_seo_nonce');
            ?>
            <style>
            .sky-cp-wrap { font-family: sans-serif; position: relative; }
            .sky-btn-cp { width:100%; margin-top:6px; padding:8px; border:1px solid #ddd; background:#fff; border-radius:4px; cursor:pointer; text-align:left; display:flex; align-items:center; color:#333; transition:all .2s; }
            .sky-btn-cp:hover { background:#f5f5f5; border-color:#6366f1; color:#6366f1; }
            .sky-btn-cp i { margin-right:8px; width:16px; text-align:center; }
            .sky-btn-cp.primary { background:linear-gradient(135deg, #6366f1, #4f46e5); color:#fff; border:none; justify-content:center; text-align:center; font-weight:bold; }
            .sky-btn-cp.primary:disabled { opacity:0.7; cursor:not-allowed; }
            .sky-cp-tabs{display:flex; background:#f1f5f9; padding:3px; border-radius:6px; gap:2px; margin-bottom:10px;}
            .sky-cp-tab{flex:1; text-align:center; padding:6px 0; cursor:pointer; font-size:11px; color:#64748b; border-radius:4px; font-weight:600; transition:all .2s;}
            .sky-cp-tab.active{color:#6366f1; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.05);}
            .sky-cp-pane{display:none; animation:fadeIn .2s;}.sky-cp-pane.active{display:block}
            #sky-spider-status { display:none; margin-bottom:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px; }
            .sky-sp-header { display:flex; justify-content:space-between; font-size:12px; font-weight:bold; color:#334155; margin-bottom:5px; }
            .sky-sp-progress-bg { height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden; margin-bottom:8px; }
            .sky-sp-progress-bar { height:100%; background:#10b981; width:0%; transition:width .3s; }
            #sky-sp-log { height:120px; overflow-y:auto; border:1px solid #e2e8f0; background:#fff; padding:5px; font-size:11px; line-height:1.6; color:#64748b; border-radius:4px; font-family:monospace; }
            .sp-log-ok { color:#10b981; }
            .sp-log-err { color:#ef4444; }
            #sky-sp-preview { display:grid; grid-template-columns: repeat(4, 1fr); gap:4px; margin-top:8px; max-height:100px; overflow-y:auto; }
            .sky-sp-thumb { width:100%; aspect-ratio:1/1; object-fit:cover; border-radius:4px; border:1px solid #e2e8f0; opacity:0.6; }
            .sky-sp-thumb.done { opacity:1; border-color:#10b981; border-width:2px; }
            
            /* 结果框优化：美化滚动条与布局 */
            #sky-res-box { display:none; border:1px solid #e2e8f0; background:#fff; border-radius:6px; margin-top:10px; overflow:hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
            #sky-res-content { max-height: 250px; overflow-y: auto; padding: 12px; white-space: pre-wrap; font-size: 13px; line-height: 1.6; color: #334155; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
            #sky-res-content::-webkit-scrollbar { width: 5px; }
            #sky-res-content::-webkit-scrollbar-track { background: transparent; }
            #sky-res-content::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
            #sky-res-content::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
            .sky-res-actions { padding: 8px; background: #fff; display: flex; flex-direction: column; gap: 5px; }
            </style>

            <div class="sky-cp-wrap">
                <button type="button" class="sky-btn-cp primary" id="btn-spider-start" onclick="SkySpider.init()"><i>🕷️</i> 一键采集图片 (可视版)</button>
                <div id="sky-spider-status">
                    <div class="sky-sp-header">
                        <span>进度: <span id="sp-cur">0</span>/<span id="sp-total">0</span></span>
                        <span id="sp-msg" style="color:#6366f1;">就绪</span>
                    </div>
                    <div class="sky-sp-progress-bg"><div class="sky-sp-progress-bar" id="sky-sp-bar"></div></div>
                    <div id="sky-sp-preview"></div>
                    <div id="sky-sp-log"></div>
                </div>
                
                <div class="sky-cp-tabs">
                    <div class="sky-cp-tab active" onclick="sTab('create', this)">创作</div>
                    <div class="sky-cp-tab" onclick="sTab('rewrite', this)">润色</div>
                    <div class="sky-cp-tab" onclick="sTab('seo', this)">SEO</div>
                    <div class="sky-cp-tab" onclick="sTab('tools', this)">工具</div>
                </div>
                
                <div id="cp-p-create" class="sky-cp-pane active">
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('title')"><i>📖</i> 优化标题</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('outline')"><i>📑</i> 生成大纲</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('continue')"><i>✍️</i> 续写段落</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('expand')"><i>➕</i> 扩写内容</button>
                </div>
                <div id="cp-p-rewrite" class="sky-cp-pane">
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('rewrite')"><i>♻️</i> 伪原创重写</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('polish')"><i>✨</i> 智能润色</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('shorten')"><i>➖</i> 精简缩写</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('trans')"><i>🌐</i> 中英互译</button>
                </div>
                <div id="cp-p-seo" class="sky-cp-pane">
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('desc')"><i>📝</i> 生成摘要</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('tags')"><i>🏷️</i> 提取标签</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.linkNow()"><i>🔗</i> 自动内链 (写回)</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('slug_en')"><i>🔤</i> 英文 Slug</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.seo()"><i>🩺</i> SEO 诊断</button>
                </div>
                <div id="cp-p-tools" class="sky-cp-pane">
                    <button type="button" class="sky-btn-cp purple" onclick="SkylineEditor.genImg()"><i>🎨</i> AI 生成配图</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.insert()"><i>📥</i> 插入生成结果</button>
                </div>
                <div id="sky-loading" style="display:none; color:#666; margin:10px 0;font-size:12px;text-align:center;"><span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> <span id="sky-loading-txt">AI 思考中...</span></div>
                
                <div id="sky-res-box">
                    <div id="sky-res-content"></div>
                    <div id="sky-res-actions" class="sky-res-actions" style="display:none;"></div>
                </div>
            </div>

            <script>
            var skyAjaxUrl = '<?php echo esc_url($ajax_url); ?>';
            
            (function($){
                window.sTab = function(id, elem){ 
                    try {
                        $('.sky-cp-tab').removeClass('active'); 
                        if(elem) $(elem).addClass('active'); 
                        $('.sky-cp-pane').hide(); 
                        $('#cp-p-'+id).show(); 
                    } catch(e) { console.error(e); }
                };

                window.SkySpider = {
                    init: function() {
                        try {
                            var content = SkylineEditor.getContent();
                            if(!content) return alert('请先输入或粘贴内容');
                            
                            // 修复：使用更严谨的正则，兼容 data-src 和 src，并避免重复
                            var regex = /<img[^>]+(?:data-src|src)=['"]([^'"]+)['"]/g;
                            var matches = [], found;
                            var seen = new Set();
                            var previewHtml = '';
                            
                            // 步骤 1: 扫描所有外部图片，并生成占位符映射
                            var tempContent = content;
                            var counter = 0;
                            
                            // 注意：必须循环匹配。为了防止修改 content 后导致 regex 错位，我们先收集所有信息
                            while ((found = regex.exec(content)) !== null) {
                                var raw = found[1]; // 提取到的 URL
                                
                                if(raw.indexOf(window.location.hostname) === -1 && raw.indexOf('data:image') === -1 && raw.indexOf('sky-pending-') === -1) {
                                    // 模拟解码
                                    var d = document.createElement('div'); d.innerHTML = raw; var real = d.textContent || raw;
                                    var key = real; 
                                    
                                    if (!seen.has(key)) { 
                                        var placeholder = 'sky-pending-' + counter + '-' + Math.floor(Math.random()*1000);
                                        matches.push({ raw: raw, real: real, holder: placeholder }); 
                                        seen.add(key);
                                        previewHtml += '<img src="'+real+'" class="sky-sp-thumb" id="sp-thumb-'+counter+'">';
                                        counter++;
                                    }
                                }
                            }

                            if(matches.length === 0) return alert('✅ 未发现外部图片！');

                            // 步骤 2: 预先将编辑器中的图片 URL 替换为占位符
                            // 这一步至关重要：它防止了后续编辑器自动格式化 HTML 后导致的匹配失败
                            for(var i=0; i<matches.length; i++) {
                                var m = matches[i];
                                // 全局替换该 URL 为占位符
                                tempContent = tempContent.split(m.raw).join(m.holder);
                                if(m.real !== m.raw) tempContent = tempContent.split(m.real).join(m.holder);
                            }
                            
                            // 立即更新编辑器内容为“占位符版本”
                            SkylineEditor.setContent(tempContent);

                            $('#sky-spider-status').slideDown();
                            $('#sky-sp-preview').html(previewHtml);
                            $('#btn-spider-start').prop('disabled', true).text('采集进行中...');
                            $('#sp-total').text(matches.length);
                            $('#sp-cur').text(0);
                            $('#sky-sp-bar').css('width', '0%');
                            $('#sky-sp-log').html('<div>🚀 锁定 ' + matches.length + ' 张图片，开始下载...</div>');
                            
                            this.processQueue(matches, 0);
                        } catch(e) {
                            alert('采集启动失败: ' + e.message);
                        }
                    },

                    log: function(msg, type='normal') {
                        var c = type==='ok'?'color:#10b981':(type==='err'?'color:#ef4444':'color:#64748b');
                        var d = document.createElement('div'); d.innerHTML = '<span style=\"'+c+'\">'+msg+'</span>';
                        var box = document.getElementById('sky-sp-log');
                        if(box) { box.appendChild(d); box.scrollTop = box.scrollHeight; }
                    },

                    processQueue: function(queue, idx, retryCount) {
                        if (typeof retryCount === 'undefined') retryCount = 0;
                        if (idx >= queue.length) {
                            $('#sp-msg').text('全部完成').css('color', '#10b981');
                            $('#btn-spider-start').prop('disabled', false).html('<i>🕷️</i> 一键采集图片 (可视版)');
                            this.log('🏁 队列结束，编辑器内容已更新', 'ok');
                            // 最终清理：尝试将 data-src 占位符翻转为 src
                            // 注意：此时内容里已经是 localUrl 了，这里做一个兜底检查
                            return;
                        }

                        var item = queue[idx];
                        var pid = $('#post_ID').val() || 0;
                        var self = this;
                        
                        var label = (retryCount > 0) ? '重试 ' + retryCount + '...' : '正在下载...';
                        $('#sp-msg').text('第 ' + (idx+1) + ' 张: ' + label);
                        
                        $.post(skyAjaxUrl, {
                            action: 'sky_spider_single', url: item.real, pid: pid,
                            _ajax_nonce: '<?php echo $nonce; ?>'
                        }).done(function(res) {
                            if(res.success) {
                                var localUrl = res.data.url;
                                self.log('[' + (idx+1) + '] ✅ ' + (res.data.msg||'成功'), 'ok');
                                $('#sp-thumb-'+idx).addClass('done').attr('src', localUrl);

                                try {
                                    // 核心修复：基于占位符进行精确替换
                                    var currentContent = SkylineEditor.getContent();
                                    
                                    // 1. 将占位符替换为本地 URL
                                    currentContent = currentContent.split(item.holder).join(localUrl);
                                    
                                    // 2. 清理微信/懒加载产生的垃圾代码，并将 data-src 翻转为 src
                                    // 移除 base64 占位图
                                    currentContent = currentContent.replace(/src=["']data:image\/[^;]+;base64,[^"']*["']/g, '');
                                    // 移除空 src
                                    currentContent = currentContent.replace(/src=["']\s*["']/g, '');
                                    
                                    // 智能翻转：如果此时图片是 <img data-src="localUrl">，我们需要把它变成 <img src="localUrl">
                                    // 构造正则：匹配 data-src="localUrl" 或 data-src='localUrl'
                                    function escReg(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
                                    var dsReg = new RegExp('data-src=["\']' + escReg(localUrl) + '["\']', 'g');
                                    currentContent = currentContent.replace(dsReg, 'src="' + localUrl + '"');
                                    
                                    SkylineEditor.setContent(currentContent);
                                } catch(e) { console.log('Update content error:', e); }

                                var pct = Math.round(((idx + 1) / queue.length) * 100);
                                $('#sp-cur').text(idx + 1);
                                $('#sky-sp-bar').css('width', pct + '%');
                                self.processQueue(queue, idx + 1, 0); 
                            } else { 
                                if (retryCount < 2) {
                                    self.log('[' + (idx+1) + '] ⚠️ 失败: ' + res.data + '，3秒后重试...', 'warn');
                                    setTimeout(function(){ self.processQueue(queue, idx, retryCount + 1); }, 3000);
                                } else {
                                    self.log('[' + (idx+1) + '] ❌ 放弃: ' + res.data, 'err');
                                    // 即使失败，也要把占位符还原回原图，否则图片会挂掉
                                    var currentContent = SkylineEditor.getContent();
                                    currentContent = currentContent.split(item.holder).join(item.raw);
                                    SkylineEditor.setContent(currentContent);
                                    
                                    self.processQueue(queue, idx + 1, 0);
                                }
                            }
                        }).fail(function(xhr, status, error){ 
                            if (retryCount < 2) {
                                setTimeout(function(){ self.processQueue(queue, idx, retryCount + 1); }, 3000);
                            } else {
                                self.log('[' + (idx+1) + '] ❌ 网络错误: ' + error, 'err');
                                self.processQueue(queue, idx + 1, 0);
                            }
                        });
                    }
                };

                window.SkylineEditor = {
                    isGutenberg: function() { 
                        return document.body.classList.contains('block-editor-page') && typeof wp !== 'undefined' && wp.data && wp.data.select; 
                    },
                    isClassicVisual: function() { return typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden(); },
                    
                    getContent: function() { 
                        try {
                            if (this.isGutenberg()) {
                                return wp.data.select("core/editor").getEditedPostAttribute("content");
                            } else if (this.isClassicVisual()) {
                                return tinyMCE.activeEditor.getContent();
                            } else {
                                return $('#content').val();
                            }
                        } catch(e) { 
                            var raw = $('#content').val();
                            if(raw) return raw;
                            console.error('获取内容失败: ' + e.message); return ''; 
                        }
                    },
                    
                    setContent: function(html) { 
                        try {
                            if (this.isGutenberg()) { 
                                var blocks = wp.blocks.parse(html); 
                                wp.data.dispatch('core/editor').resetBlocks(blocks); 
                            } else if (this.isClassicVisual()) {
                                 tinyMCE.activeEditor.setContent(html);
                            } else {
                                $('#content').val(html).trigger('change');
                            }
                        } catch(e) { console.error('Set content error:', e); }
                    },
                    
                    setTitle: function(newTitle) {
                        if (this.isGutenberg()) { wp.data.dispatch("core/editor").editPost({title:newTitle}); } 
                        else { $('#title').val(newTitle); $('#title-prompt-text').addClass('screen-reader-text'); }
                    },

                    linkNow: function() {
                        var i = this.getContent(); if(!i) return alert('请先输入或粘贴内容');
                        $('#sky-loading').show();
                        $.post(skyAjaxUrl, { 
                            action: 'sky_link_now', 
                            content: i, 
                            _ajax_nonce: '<?php echo $nonce; ?>' 
                        }, function(r){
                            $('#sky-loading').hide();
                            if(r.success) {
                                SkylineEditor.setContent(r.data.content);
                                alert(r.data.msg);
                            } else {
                                alert(r.data);
                            }
                        }).fail(function() { $('#sky-loading').hide(); alert('网络请求失败'); });
                    },
                    
                    applyReplace: function() {
                        var raw = $('#sky-res-content').data('raw');
                        if(raw) {
                            if(confirm('⚠️ 确定要用生成的内容覆盖当前编辑器中的所有内容吗？')) {
                                this.setContent(raw);
                            }
                        } else {
                            alert('没有可替换的内容');
                        }
                    },

                    task: function(t) {
                        try {
                            var i = this.getContent(); if(!i) return alert('请先输入或粘贴内容');
                            $('#sky-loading').show();
                            
                            $.ajax({
                                url: skyAjaxUrl,
                                type: 'POST',
                                data: { action: 'sky_ai_task', task: t, input: i, _ajax_nonce: '<?php echo $nonce; ?>' },
                                success: function(r) {
                                    $('#sky-loading').hide();
                                    if(r.success) { 
                                        var res = r.data.trim();
                                        if(t==='title') { 
                                            var lines = res.split('\n').filter(function(l){ return l.trim().length > 0; });
                                            if (lines.length > 1) {
                                                var html = '<p><b>🤖 AI 提供了多个标题，请选择一个替换：</b></p>';
                                                lines.forEach(function(line){
                                                    var clean = line.replace(/^\d+[\.\、]\s*/, '').replace(/^["']|["']$/g, '').trim();
                                                    var safe = clean.replace(/"/g, '&quot;');
                                                    html += '<button type="button" class="sky-btn-cp" style="margin-bottom:5px;text-align:left" onclick="SkylineEditor.setTitle(\''+safe+'\')">👉 '+clean+'</button>';
                                                });
                                                html += '<button type="button" class="sky-btn-cp" style="margin-top:5px;color:#666" onclick="jQuery(\'#sky-res-box\').hide()">❌ 取消</button>';
                                                SkylineEditor.showResult(html);
                                                return;
                                            }
                                            var cleanRes = res.replace(/^["']|["']$/g, '');
                                            if(confirm('建议标题：\n'+cleanRes+'\n\n是否替换？')) { 
                                                SkylineEditor.setTitle(cleanRes);
                                            } 
                                        } 
                                        else if(t==='slug_en') { 
                                            res = res.replace(/[^a-z0-9-]/g, '-').toLowerCase();
                                            if(SkylineEditor.isGutenberg()) wp.data.dispatch("core/editor").editPost({slug:res}); 
                                            else { $('#post_name').val(res); $('#edit-slug-box').html(res); }
                                            alert('Slug 已更新: '+res); 
                                        }
                                        else if(t==='rewrite' || t==='polish' || t==='continue' || t==='expand') {
                                            // 关键逻辑优化：分开显示内容和按钮
                                            var btns = '';
                                            btns += '<button type="button" class="sky-btn-cp primary" onclick="SkylineEditor.applyReplace()">🔄 立即替换全文</button>';
                                            btns += '<button type="button" class="sky-btn-cp" onclick="SkylineEditor.insert()">📥 插入光标处</button>';
                                            
                                            SkylineEditor.showResult(res, res, btns);
                                        }
                                        else if(t==='tags') { SkylineEditor.showResult('<b>建议标签:</b><br>'+res); }
                                        else if(t==='desc') {
                                            if(SkylineEditor.isGutenberg()) wp.data.dispatch("core/editor").editPost({excerpt:res});
                                            else { $('#excerpt').val(res); }
                                            alert('摘要已更新');
                                        }
                                        else if(t==='rewrite_full') { if(confirm('确认覆盖全文？')) SkylineEditor.setContent(res); }
                                        else { SkylineEditor.showResult(res); } 
                                    } else alert('Error: ' + r.data); 
                                },
                                error: function(e) {
                                    $('#sky-loading').hide();
                                    alert('Request Failed: ' + e.statusText);
                                }
                            });
                        } catch(e) {
                            $('#sky-loading').hide();
                            alert('执行错误: ' + e.message);
                        }
                    },
                    
                    genImg: function() {
                        var p = prompt("请输入图片描述:"); if(!p) return;
                        $('#sky-loading').show();
                        $.post(skyAjaxUrl, { action: 'sky_gen_img', prompt: p, _ajax_nonce: '<?php echo $nonce; ?>' }, function(r){
                            $('#sky-loading').hide();
                            if(r.success) SkylineEditor.showResult('<img src="'+r.data+'" style="max-width:100%">'); else alert(r.data);
                        });
                    },
                    
                    insert: function() {
                        try {
                            var h = $('#sky-res-content').html();
                            var raw = $('#sky-res-content').data('raw');
                            if(raw) h = raw;
                            
                            if(!h) return;
                            if(this.isGutenberg()) { 
                                var b = wp.data.select('core/editor').getBlocks(); 
                                var nb = wp.blocks.createBlock('core/html', {content: h}); 
                                wp.data.dispatch('core/editor').insertBlocks(nb, b.length); 
                            } else { 
                                if(this.isClassicVisual()) {
                                     tinyMCE.activeEditor.execCommand('mceInsertContent', false, h);
                                } else {
                                     var ta = document.getElementById('content'); 
                                     if(ta) ta.value += "\n\n" + h; 
                                }
                            }
                        } catch(e) { alert('插入失败: ' + e.message); }
                    },
                    
                    seo: function() {
                        var t = $('#title').val(); 
                        if(this.isGutenberg()) t = wp.data.select("core/editor").getEditedPostAttribute("title");
                        var c = this.getContent();
                        $.post(skyAjaxUrl, { action:'sky_seo_score', title:t, content:c, _ajax_nonce:'<?php echo $seo_nonce; ?>' }, function(r){
                             if(r.success) SkylineEditor.showResult('<b>SEO 得分: '+r.data.score+'</b><br>'+r.data.advice.join('<br>'));
                        });
                    },

                    showResult: function(html, rawText, buttonsHtml) { 
                        var displayHtml = rawText ? rawText.replace(/\n/g, '<br>') : html;
                        $('#sky-res-content').html(displayHtml); 
                        
                        if(rawText) $('#sky-res-content').data('raw', rawText);
                        else $('#sky-res-content').removeData('raw');
                        
                        // 处理按钮区域
                        if(buttonsHtml) {
                            $('#sky-res-actions').html(buttonsHtml).show();
                        } else {
                            $('#sky-res-actions').hide().empty();
                        }
                        
                        $('#sky-res-box').show(); 
                    }
                };
                
                console.log('Skyline Copilot JS Loaded');
            })(jQuery);
            </script>
            <?php
        }, 'post', 'side', 'high');
    }
}