<?php
if (!defined('ABSPATH')) exit;

class Skyline_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'save_settings']);
    }

    public function add_menu() {
        add_menu_page('Skyline AI Pro', 'Skyline AI Pro', 'manage_options', 'skyline-pro', [$this, 'render_ui'], 'dashicons-superhero', 58);
    }

    public function save_settings() {
        if(isset($_POST['sky_save']) && check_admin_referer('sky_save_action')) {
            $data = get_option('skyline_ai_settings', []);
            if(!class_exists('Skyline_Core')) return;
            $schema = Skyline_Core::instance()->get_config_schema();
            
            foreach($schema as $key => $field) {
                if(!isset($_POST[$key]) && $field['type']!='bool') continue;
                $val = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : false;
                
                if($field['type'] == 'bool') $val = (bool)$val;
                elseif($field['type'] == 'number') $val = intval($val);
                elseif($field['type'] == 'textarea') $val = sanitize_textarea_field((string)$val);
                else $val = sanitize_text_field((string)$val);
                
                $data[$key] = $val;
            }
            update_option('skyline_ai_settings', $data);
            set_transient('sky_settings_saved', 1, 45);
            wp_redirect(add_query_arg('page', 'skyline-pro', admin_url('admin.php')));
            exit;
        }
    }

    public function render_ui() {
        if(!class_exists('Skyline_Core')) { echo esc_html('Core Class Missing', 'skyline-ai-pro'); return; }
        $core = Skyline_Core::instance();
        $schema = $core->get_config_schema();
        
        if(get_transient('sky_settings_saved')) {
            echo '<div class="notice notice-success is-dismissible"><p>✨ 配置已成功保存！</p></div>';
            delete_transient('sky_settings_saved');
        }
        
        $rf = function($k, $f) use ($core) {
            $val = $core->get_opt($k);
            $desc = isset($f['desc']) ? '<div class="sky-desc">'.$f['desc'].'</div>' : '';
            
            if($f['type']=='bool') {
                $chk = $val ? 'checked' : '';
                echo "<div class='sky-toggle-item'><span class='sky-toggle-label'>{$f['label']}</span><label class='sky-switch'><input type='checkbox' name='{$k}' {$chk}><span class='sky-slider'></span></label>{$desc}</div>";
            } elseif($f['type']=='select') {
                echo '<div class="sky-field"><label class="sky-label">' . esc_html($f['label'], 'skyline-ai-pro') . '</label><select name="' . esc_attr($k) . '" class="sky-select">';
                foreach($f['options'] as $ok=>$ov) { $s=selected($val,$ok,false); echo "<option value='{$ok}' {$s}>{$ov}</option>"; }
                echo '</select>' . wp_kses_post($desc) . '</div>';
            } else {
                echo '<div class="sky-field"><label class="sky-label">' . esc_html($f['label'], 'skyline-ai-pro') . '</label>';
                $dv = esc_attr((string)$val);
                if($f['type']=='textarea') echo "<textarea name='{$k}' class='sky-textarea' rows='3'>".esc_textarea((string)$val)."</textarea>";
                else echo "<input type='".($f['type']=='password'?'password':'text')."' name='{$k}' value='{$dv}' class='sky-input' placeholder='".($f['placeholder']??'')."'>";
                echo wp_kses_post($desc) . '</div>';
            }
        };

        $nonces = [
            'bulk' => wp_create_nonce('sky_bulk_nonce'),
            'redis' => wp_create_nonce('sky_redis_nonce'),
            'oss' => wp_create_nonce('sky_oss_nonce'),
            'save' => wp_create_nonce('sky_save_action'),
            'ai_test' => wp_create_nonce('sky_ai_test_nonce'),
            'clear_logs' => wp_create_nonce('sky_clear_logs_nonce')
        ];
        ?>
        <div class="sky-wrap">
            <style>
                :root { --sky-pri: #4f46e5; --sky-pri-grad: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); --sky-bg: #f8fafc; --sky-card: rgba(255, 255, 255, 0.9); --sky-text: #1e293b; --sky-border: #e2e8f0; --sky-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.06); }
                .sky-wrap { display:flex; gap:25px; color:var(--sky-text); max-width:1200px; margin:25px auto; font-family:'Inter',system-ui,-apple-system,sans-serif; background-image: radial-gradient(#e5e7eb 1px, transparent 1px); background-size: 20px 20px; }
                .sky-nav { width:240px; background:var(--sky-card); backdrop-filter:blur(12px); border-radius:16px; border:1px solid rgba(255,255,255,0.6); box-shadow:var(--sky-shadow); padding:25px 0; flex-shrink:0; height:fit-content; }
                .sky-brand { padding:0 25px 25px; border-bottom:1px solid var(--sky-border); margin-bottom:15px; text-align:center; }
                .sky-logo { width:56px; height:56px; background:var(--sky-pri-grad); border-radius:14px; display:inline-flex; align-items:center; justify-content:center; margin-bottom:12px; color:#fff; box-shadow:0 10px 25px rgba(99,102,241,0.3); }
                .sky-menu-item { display:flex; align-items:center; padding:12px 25px; color:#64748b; cursor:pointer; font-weight:600; border-left:4px solid transparent; transition:all .2s; font-size:14px; letter-spacing:0.5px; }
                .sky-menu-item:hover { color:var(--sky-pri); background:rgba(241,245,249,0.8); padding-left:30px; }
                .sky-menu-item.active { color:var(--sky-pri); background:#eff6ff; border-left-color:var(--sky-pri); }
                .sky-main { flex:1; background:var(--sky-card); backdrop-filter:blur(12px); border-radius:16px; border:1px solid rgba(255,255,255,0.6); box-shadow:var(--sky-shadow); padding:35px; min-height:700px; position:relative; }
                .sky-pane { display:none; animation:fadeIn .4s cubic-bezier(0.4, 0, 0.2, 1); } .sky-pane.active { display:block; }
                @keyframes fadeIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
                .sky-sec-title { font-size:20px; font-weight:800; margin:0 0 25px; padding-bottom:15px; border-bottom:2px solid #f1f5f9; color:#0f172a; letter-spacing:-0.5px; }
                .sky-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:20px; margin-bottom:30px; }
                .sky-stat-box { background:#fff; padding:20px; border-radius:12px; border:1px solid var(--sky-border); text-align:center; transition:.3s; cursor:default; }
                .sky-stat-box:hover { border-color:var(--sky-pri); transform:translateY(-4px); box-shadow:0 10px 30px rgba(0,0,0,0.05); }
                .sky-stat-num { font-size:28px; font-weight:800; color:var(--sky-pri); display:block; margin-bottom:5px; }
                .sky-stat-label { font-size:12px; color:#94a3b8; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
                .sky-field { margin-bottom:25px; }
                .sky-label { display:block; font-size:14px; font-weight:700; margin-bottom:8px; color:#334155; }
                .sky-input, .sky-textarea, .sky-select { width:100%; padding:10px 15px; border:1px solid #cbd5e1; border-radius:10px; background:#fff; transition:.2s; font-size:14px; color:#334155; }
                .sky-input:focus, .sky-textarea:focus, .sky-select:focus { border-color:var(--sky-pri); outline:none; box-shadow:0 0 0 3px rgba(99,102,241,0.1); }
                .sky-toggle-group { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:15px; margin-bottom:25px; }
                .sky-toggle-item { display:flex; flex-direction:column; justify-content:center; background:#fff; padding:15px 20px; border-radius:10px; border:1px solid var(--sky-border); transition:.2s; position:relative; min-height:50px; }
                .sky-toggle-item:hover { border-color:#94a3b8; }
                .sky-toggle-label { font-size:14px; font-weight:600; color:#334155; margin-bottom:5px; display:block; }
                .sky-switch { position:absolute; right:15px; top:20px; width:44px; height:24px; }
                .sky-switch input { opacity:0; width:0; height:0; }
                .sky-slider { position:absolute; cursor:pointer; top:0;left:0;right:0;bottom:0; background:#cbd5e1; border-radius:34px; transition:.3s; }
                .sky-slider:before { position:absolute; content:""; height:18px; width:18px; left:3px; bottom:3px; background:white; border-radius:50%; transition:.3s; }
                input:checked+.sky-slider { background:var(--sky-pri-grad); }
                input:checked+.sky-slider:before { transform:translateX(20px); }
                .sky-btn { background:var(--sky-pri-grad); color:#fff; border:none; padding:12px 28px; border-radius:10px; cursor:pointer; font-weight:700; width:100%; transition:.2s; font-size:14px; letter-spacing:0.5px; }
                .sky-btn:hover { opacity:0.9; transform:translateY(-1px); box-shadow:0 5px 15px rgba(99,102,241,0.3); }
                .sky-btn.sec { background:#fff; color:#475569; border:1px solid #cbd5e1; width:auto; box-shadow:none; }
                .sky-btn.sec:hover { border-color:var(--sky-pri); color:var(--sky-pri); }
                .sky-desc { font-size:12px; color:#64748b; margin-top:5px; line-height:1.4; }
                .sky-changelog { font-size:13px; color:#64748b; background:#fff; padding:15px; border-radius:10px; border:1px solid #e2e8f0; margin-top:20px; }
                .sky-cl-item { margin-bottom:8px; display:flex; gap:10px; border-bottom:1px dashed #eee; padding-bottom:8px;}
                .sky-cl-ver { font-weight:bold; color:var(--sky-pri); min-width:60px; }
                .sky-info-box { background:#fff; padding:20px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:20px; }
            </style>

            <form method="post" action="<?php echo admin_url('admin.php?page=skyline-pro'); ?>" style="display:contents;">
                <?php wp_nonce_field('sky_save_action'); ?>
                <div class="sky-nav">
                    <div class="sky-brand">
                        <div class="sky-logo">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M2 20h20"/><path d="M4 16c1-2 2-6 3-6s2 3 4 3 3-5 5-5 4 8 4 8"/><circle cx="16" cy="6" r="2" fill="currentColor" fill-opacity="0.3"/></svg>
                        </div>
                        <h2 style="margin:0;font-size:18px;background:var(--sky-pri-grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">Skyline AI Pro</h2>
                        <p style="margin:5px 0 0;font-size:11px;color:#94a3b8;font-weight:600;">V<?php echo esc_html(SKY_VERSION, 'skyline-ai-pro'); ?></p>
                    </div>
                    
                    <div class="sky-menu-item active" onclick="SkyTab('dash')">📊 数据看板</div>
                    <div class="sky-menu-item" onclick="SkyTab('ai')">🤖 智能核心</div>
                    <div class="sky-menu-item" onclick="SkyTab('spider')">🕷️ 同步水印</div>
                    <div class="sky-menu-item" onclick="SkyTab('seo')">📈 搜索优化</div>
                    <div class="sky-menu-item" onclick="SkyTab('speed')">🚀 性能体检</div>
                    <div class="sky-menu-item" onclick="SkyTab('log')">📜 系统日志</div>
                    
                   <div style="padding:25px 25px 15px;"><button type="submit" name="sky_save" class="sky-btn">保存所有配置</button></div>
                    
                    <div style="margin:0 20px 25px; padding:15px; background:rgba(255,255,255,0.6); border-radius:12px; border:1px solid rgba(99,102,241,0.2); text-align:center;">
                        <div style="font-size:10px; color:#94a3b8; font-weight:800; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">POWERED BY</div>
                        <a href="https://www.lgwu.net" target="_blank" style="display:block; text-decoration:none; transition:.2s;">
                            <div style="font-weight:800; font-size:15px; color:#334155; display:flex; align-items:center; justify-content:center; gap:8px;">
                                <img src="https://www.lgwu.net/wp-content/uploads/2023/10/favicon.png" style="width:20px; height:20px; object-fit:contain; display:block;"> <span>灵感屋</span>
                            </div>
                            <div style="font-size:11px; color:#6366f1; margin-top:2px;">www.lgwu.net</div>
                        </a>
                        <div style="margin-top:8px; font-size:11px; color:#64748b; line-height:1.4;">
                            专注景观设计与工程<br>获取更多灵感资源
                        </div>
                    </div>
                    </div>

                <div class="sky-main">
                    <div id="pane-dash" class="sky-pane active">
                        <h3 class="sky-sec-title">👋 欢迎回来，站长</h3>
                        
                        <div class="sky-info-box">
                            <h4 style="margin:0 0 10px 0;color:#334155;">🚀 插件介绍</h4>
                            <p style="margin:0;line-height:1.6;color:#64748b;font-size:13px;">
                                Skyline AI Pro 是为您量身打造的 WordPress 智能中台。它集成了 <b>DeepSeek V3</b> 强力模型，提供 AI 写作、润色、生图全流程支持；内置 <b>Visual Spider</b> 可视化同步系统，支持微信/网页图片一键集成与去水印；底层搭载 <b>Redis Object Cache</b> 与 <b>OSS 云存储</b> 加速引擎，让您的站点快如闪电。
                            </p>
                        </div>

                        <div class="sky-grid">
                            <div class="sky-stat-box"><span class="sky-stat-num"><?php echo esc_html($core->stat_get('api_calls'), 'skyline-ai-pro'); ?></span><span class="sky-stat-label">AI 调用次数</span></div>
                            <div class="sky-stat-box"><span class="sky-stat-num"><?php echo esc_html($core->stat_get('spider_count'), 'skyline-ai-pro'); ?></span><span class="sky-stat-label">同步图片</span></div>
                            <div class="sky-stat-box"><span class="sky-stat-num"><?php echo esc_html($core->stat_get('oss_count'), 'skyline-ai-pro'); ?></span><span class="sky-stat-label">云上传</span></div>
                            <div class="sky-stat-box"><span class="sky-stat-num"><?php echo esc_html(round($core->stat_get('saved_kb'), 1), 'skyline-ai-pro'); ?> MB</span><span class="sky-stat-label">节省带宽</span></div>
                        </div>
                        
                        <div class="sky-grid" style="grid-template-columns: 1fr 1fr;">
                            <div class="sky-info-box" style="margin-bottom:0;">
                                <h4 style="margin:0 0 15px 0;">🏥 系统健康度</h4>
                                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed #e2e8f0;padding-bottom:10px;margin-bottom:10px;">
                                    <span>PHP 版本</span> <b><?php echo esc_html(PHP_VERSION, 'skyline-ai-pro'); ?></b> (建议 ≥7.4)
                                </div>
                                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed #e2e8f0;padding-bottom:10px;margin-bottom:10px;">
                                    <span>Redis 扩展</span> <b><?php echo class_exists('Redis')?'✅ 已安装':'❌ 未安装'; ?></b>
                                </div>
                                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed #e2e8f0;padding-bottom:10px;margin-bottom:10px;">
                                    <span>CURL 扩展</span> <b><?php echo function_exists('curl_init')?'✅ 已启用':'❌ 未启用'; ?></b>
                                </div>
                                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed #e2e8f0;padding-bottom:10px;margin-bottom:10px;">
                                    <span>GD 库 (去水印)</span> <b><?php echo extension_loaded('gd')?'✅ 支持':'❌ 不支持'; ?></b>
                                </div>
                                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed #e2e8f0;padding-bottom:10px;margin-bottom:10px;">
                                    <span>Uploads 权限</span> <b><?php echo is_writable(WP_CONTENT_DIR.'/uploads')?'✅ 可写':'❌ 不可写'; ?></b>
                                </div>
                                <div style="margin-top:15px; display:flex; gap:10px; align-items:center;">
                                    <button type="button" class="sky-btn sec" onclick="SkyTest('ai_test_conn', this)">🤖 AI 测试</button>
                                    <button type="button" class="sky-btn sec" onclick="SkyTest('redis', this)">⚡ Redis 测试</button> 
                                    <button type="button" class="sky-btn sec" onclick="SkyTest('oss', this)">☁️ OSS 测试</button>
                                </div>
                                <div id="sky-api-test-res" style="margin-top:10px;font-size:12px;color:#6366f1;display:none;"></div>
                            </div>

                            <div class="sky-info-box" style="margin-bottom:0;">
                                <h4 style="margin:0 0 15px 0;">📅 版本更新历史</h4>
                                <div class="sky-changelog" style="margin-top:0;border:none;padding:0;background:transparent;">
                                    <div class="sky-cl-item"><div class="sky-cl-ver">1.3.1</div><div>完美体验：修复替换问题，限制 Slug 长度，增强 Copilot。</div></div>
                                    <div class="sky-cl-item"><div class="sky-cl-ver">1.3.0</div><div>旗舰进化：新增可视化同步(JS)，修复微信同步。</div></div>
                                    <div class="sky-cl-item"><div class="sky-cl-ver">1.2.1</div><div>体验优化：修复配置保存，Redis 下拉化。</div></div>
                                    <div class="sky-cl-item"><div class="sky-cl-ver">1.2.0</div><div>AI增强：新增AI润色、去水印、懒加载。</div></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="pane-ai" class="sky-pane">
                        <h3 class="sky-sec-title">🤖 AI 核心设置</h3>
                        <?php foreach($schema as $k=>$f) if($f['group']=='ai') $rf($k, $f); ?>
                    </div>

                    <div id="pane-spider" class="sky-pane">
                        <h3 class="sky-sec-title">🕷️ 同步与去水印</h3>
                        <div class="sky-toggle-group">
                            <?php foreach($schema as $k=>$f) if($f['group']=='spider' && $f['type']=='bool') $rf($k, $f); ?>
                        </div>
                        <?php foreach($schema as $k=>$f) if($f['group']=='spider' && $f['type']!='bool') $rf($k, $f); ?>
                        
                        <h3 class="sky-sec-title" style="margin-top:30px;">☁️ OSS 云存储</h3>
                        <div class="sky-toggle-group">
                            <?php foreach($schema as $k=>$f) if($f['group']=='oss' && $f['type']=='bool') $rf($k, $f); ?>
                        </div>
                        <div class="sky-grid" style="grid-template-columns:1fr 1fr;">
                            <?php foreach($schema as $k=>$f) if($f['group']=='oss' && $f['type']!='bool') $rf($k, $f); ?>
                        </div>
                    </div>

                    <div id="pane-seo" class="sky-pane">
                        <h3 class="sky-sec-title">📈 SEO 自动化 & 智能润色</h3>
                        <div class="sky-toggle-group">
                            <?php foreach($schema as $k=>$f) if($f['group']=='seo' && $f['type']=='bool') $rf($k, $f); ?>
                        </div>
                        <?php foreach($schema as $k=>$f) if($f['group']=='seo' && $f['type']!='bool') $rf($k, $f); ?>
                        
                        <div style="margin-top:20px; padding:20px; background:#fff; border-radius:12px; border:1px solid #e2e8f0;">
                            <h4 style="margin-top:0;">⚡ 批量任务中心</h4>
                            <textarea id="bulk_ids" class="sky-textarea" placeholder="输入文章ID，逗号分隔 (如: 101, 102)"></textarea>
                            <div style="display:flex; gap:10px; margin-top:10px;">
                                <button type="button" class="sky-btn sec" onclick="SkyBulk('tags', this)">🏷️ 生成标签</button>
                                <button type="button" class="sky-btn sec" onclick="SkyBulk('excerpt', this)">📝 生成摘要</button>
                                <button type="button" class="sky-btn sec" onclick="SkyBulk('rewrite', this)">🔄 伪原创</button>
                            </div>
                            <div id="bulk_res" style="margin-top:10px; font-size:12px;"></div>
                        </div>
                    </div>

                    <div id="pane-speed" class="sky-pane">
                        <h3 class="sky-sec-title">🚀 性能加速 (Turbo)</h3>
                        <div class="sky-toggle-group">
                            <?php foreach($schema as $k=>$f) if($f['group']=='speed' && strpos($k,'turbo')===0 && $f['type']=='bool') $rf($k, $f); ?>
                        </div>
                        <?php foreach($schema as $k=>$f) if($f['group']=='speed' && strpos($k,'turbo')===0 && $f['type']!='bool') $rf($k, $f); ?>

                        <h3 class="sky-sec-title" style="margin-top:30px;">⚡ Redis 高级缓存</h3>
                        <div class="sky-toggle-group">
                            <?php foreach($schema as $k=>$f) if($f['group']=='speed' && strpos($k,'redis')===0 && $f['type']=='bool') $rf($k, $f); ?>
                        </div>
                        <div class="sky-grid" style="grid-template-columns:1fr 1fr;">
                            <?php foreach($schema as $k=>$f) if($f['group']=='speed' && strpos($k,'redis')===0 && $f['type']!='bool') $rf($k, $f); ?>
                        </div>
                    </div>

                    <div id="pane-log" class="sky-pane">
                        <h3 class="sky-sec-title">📜 系统工作日志</h3>
                        <div class="sky-info-box" style="padding:0; overflow:hidden;">
                            <div id="sky-system-log-view" style="max-height:500px; overflow-y:auto; padding:15px; font-family:monospace; font-size:12px; line-height:1.6; color:#475569;">
                                <?php
                                $logs = get_option('skyline_ai_logs', []);
                                if(empty($logs)) {
                                    echo '<div style="text-align:center;color:#94a3b8;padding:20px;">暂无日志记录</div>';
                                } else {
                                    foreach($logs as $log) {
                                        $time = isset($log['time']) ? $log['time'] : '--';
                                        $type = isset($log['type']) ? $log['type'] : 'info';
                                        $msg = isset($log['msg']) ? $log['msg'] : '';
                                        $color = ($type=='error') ? '#ef4444' : (($type=='warn') ? '#f59e0b' : '#10b981');
                                        echo "<div style='border-bottom:1px dashed #f1f5f9; padding-bottom:4px; margin-bottom:4px;'>";
                                        echo "<span style='color:#94a3b8; margin-right:8px;'>[{$time}]</span>";
                                        echo "<span style='color:{$color}; font-weight:bold; margin-right:8px;'>[{$type}]</span>";
                                        echo esc_html($msg);
                                        echo "</div>";
                                    }
                                }
                                ?>
                            </div>
                            <div style="padding:10px; background:#f8fafc; border-top:1px solid #e2e8f0; text-align:right;">
                                <button type="button" class="sky-btn sec" style="font-size:12px; padding:6px 12px;" onclick="SkyClearLogs(this)">🗑️ 清空日志</button>
                            </div>
                        </div>
                    </div>

                </div>
            </form>

            <script>
            var nonces = <?php echo json_encode($nonces); ?>;
            function SkyTab(id) {
                document.querySelectorAll('.sky-menu-item').forEach(e=>e.classList.remove('active'));
                document.querySelectorAll('.sky-pane').forEach(e=>e.classList.remove('active'));
                document.querySelector('[onclick*="'+id+'"]').classList.add('active');
                document.getElementById('pane-'+id).classList.add('active');
                localStorage.setItem('sky_tab_v5', id);
            }
            if(localStorage.getItem('sky_tab_v5')) SkyTab(localStorage.getItem('sky_tab_v5'));

            function SkyTest(type, btn) {
                var t = btn.innerHTML; btn.disabled=true; btn.innerHTML='Testing...';
                
                if(type === 'ai_test_conn') {
                    jQuery.post(ajaxurl, {action:'sky_test_api', _ajax_nonce:nonces.ai_test}, function(r){
                        btn.disabled=false; btn.innerHTML=t;
                        var resBox = document.getElementById('sky-api-test-res');
                        resBox.style.display = 'block';
                        if(r.success) {
                            resBox.innerHTML = '✅ 连接成功! 延迟: ' + r.data.time + '<br>回复: ' + r.data.reply;
                            resBox.style.color = '#10b981';
                        } else {
                            resBox.innerHTML = '❌ 连接失败: ' + r.data;
                            resBox.style.color = '#ef4444';
                        }
                    });
                    return;
                }

                var action = (type === 'oss') ? 'sky_oss_test' : 'sky_'+type+'_test';
                var nonce = (type === 'oss') ? nonces.oss : nonces[type];

                jQuery.post(ajaxurl, {action:action, _ajax_nonce:nonce}, function(r){
                    btn.disabled=false; btn.innerHTML=t;
                    alert(r.success ? '✅ '+r.data : '❌ '+r.data);
                });
            }

            function SkyBulk(type, btn) {
                var ids = document.getElementById('bulk_ids').value;
                if(!ids) return alert('请输入ID');
                var res = document.getElementById('bulk_res');
                res.innerHTML = '⏳ 正在处理... (请勿关闭页面)';
                jQuery.post(ajaxurl, {action:'sky_bulk_action', type:type, ids:ids.split(','), _ajax_nonce:nonces.bulk}, function(r){
                    if(r.success) res.innerHTML = '<span style="color:green">✅ 成功: '+r.data.success+' | 失败: '+r.data.fail+'</span>';
                    else res.innerHTML = '<span style="color:red">❌ '+r.data+'</span>';
                });
            }

            function SkyClearLogs(btn) {
                if(!confirm('确定要清空所有日志吗？')) return;
                var t = btn.innerHTML; btn.disabled=true; btn.innerHTML='Clearing...';
                jQuery.post(ajaxurl, {action:'sky_clear_logs', _ajax_nonce:nonces.clear_logs}, function(r){
                    btn.disabled=false; btn.innerHTML=t;
                    if(r.success) {
                        document.getElementById('sky-system-log-view').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:20px;">日志已清空</div>';
                    } else {
                        alert('清空失败: ' + r.data);
                    }
                });
            }
            </script>
        </div>
        <?php
    }
}
// 文件结束
