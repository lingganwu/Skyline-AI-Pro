document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Skyline AI Pro v2.2.10 Enterprise Engine Loaded');

    // 表单保存按钮动画增强
    const saveBtn = document.querySelector('.sky-save-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            this.innerHTML = '同步配置中...';
            this.style.opacity = '0.7';
            this.style.pointerEvents = 'none';
        });
    }
});

// v2.2.1：三张批量卡片（WebP / 蜘蛛 / SEO）共用的批量进度驱动。
// 各卡片只留一个薄壳（见 admin.php 内联 script 标签）：扫描解析、结果
// 计数与文案由回调传入；驱动负责状态机与进度——
// ① 发每批请求前先写“正在处理第 N/M 批（第 X–Y 项）…”，一批 10–60 秒
//    时界面不再像冻住；② 停止时 abort 在途请求（旧的软停只在批间生
//    效，在途一批跑完后还会把“已停止”文案冲回“处理中”）；③ 失败与
//    跳过都逐条列出原因（回调把 it.reason 拼成 <li>，只用 textContent）。
window.SkyBatchRunner = (function() {
    function el(id) { return document.getElementById(id); }
    function setText(id, txt) { var n = el(id); if (n) n.textContent = txt; }
    function setDisabled(id, disabled) { var n = el(id); if (n) n.disabled = disabled; }
    function setBar(cfg, done, total) {
        var bar = el(cfg.bar);
        if (bar) bar.style.width = (total ? Math.round(done / total * 100) : 0) + '%';
        var wrap = el(cfg.progress);
        if (wrap) wrap.style.display = total ? 'block' : 'none';
    }
    // v2.2.2：失败文案带上 HTTP 状态——403（登录过期/nonce 失效）、
    // 504/超时、500、0（断网）之前全被一句“网络错误”混在一起，没法定位。
    function skylaiprAjaxFailDetail(xhr, textStatus) {
        var status = (xhr && xhr.status) ? xhr.status : 0;
        if (status === 403) return 'HTTP 403（可能是登录过期或 nonce 失效，请刷新页面重试）';
        if (status === 504 || textStatus === 'timeout') return 'HTTP 504/超时（请求超时，请稍后重试）';
        if (status === 500) return 'HTTP 500（服务端错误，请看服务器日志）';
        if (status) return 'HTTP ' + status;
        return '网络中断或超时（HTTP 0）';
    }
    function addLine(cfg, text) {
        var ul = el(cfg.failures);
        if (!ul) return;
        var li = document.createElement('li');
        li.textContent = text;
        ul.appendChild(li);
    }
    function create(cfg) {
        var st = { ids: [], pos: 0, total: 0, done: 0, stopping: false, xhr: null };
        function restoreButtons() {
            setDisabled(cfg.scanBtn, false);
            setDisabled(cfg.startBtn, false);
            setDisabled(cfg.stopBtn, true);
        }
        function summary() { return cfg.summaryText(st); }
        // 扫描成功后的收尾（单次扫描与分页扫描共用）：把解析出的 id 列表
        // 交给驱动、刷新状态区与进度条、启用开始按钮。
        function finishScan(parsed) {
            st.ids = parsed.ids || [];
            st.total = st.ids.length;
            st.pos = 0; st.done = 0;
            cfg.resetCounts(st);
            setText(cfg.status, parsed.statusText);
            if (parsed.costText !== undefined) setText(cfg.cost, parsed.costText);
            var ul = el(cfg.failures);
            if (ul) ul.innerHTML = '';
            setBar(cfg, 0, st.total);
            setDisabled(cfg.startBtn, st.total === 0);
        }
        // v2.2.3（P0）：分页扫描。cfg.scanPaged 为 true 时扫描阶段循环
        // 发请求（每次带 offset），累积结果，状态区显示进度“正在扫描…
        // 已扫 X/Y <单位>”，直到服务端 done 才把累积结果交给现有的
        // parseScan。两种累积形态（由 cfg.scanItems 区分）：
        // ① WebP（scanItems 未设）：累积 ids 与 skipped（converted/
        //    missing/toolarge/pending_delete/invalid），parseScan 收
        //    {supported:true, total:累积 ids 数, ids:累积 ids,
        //    skipped:累积 skipped}（total 语义与旧单次扫描相同，是可转
        //    ID 数；服务端每页返回的 total 是符合条件的附件总数，只用于
        //    进度显示与判断 done）；
        // ② v2.2.8 C1/S1（scanItems 为 true）：累积 items（文章清单）
        //    与 total_posts/total_images/truncated，parseScan 收
        //    {total_scanned:累积已扫篇数, total_posts:累积含外链/缺项篇
        //    数, total_images:累积外链图张数, truncated:任一页被清单
        //    上限截断, items:累积清单}——与旧单次扫描的返回字段一致，
        //    parseScan 不用改。每次请求带 already=已累积清单篇数，服务
        //    端只补足 SCAN_MAX_ITEMS 的剩余名额（计数仍全量）。
        // 中途某页请求失败走现有 .fail() 处理（带 HTTP 状态）；服务端
        // supported=false 时直接收尾、不翻页（仅 WebP 有此字段）。
        function scanPaged(offset, acc) {
            var params = { action: cfg.scanAction, _ajax_nonce: cfg.nonce, offset: offset, page_size: cfg.scanPageSize || 2000 };
            if (cfg.scanItems) params.already = acc.items.length;
            jQuery.post(ajaxurl, params, function(r) {
                if (!r.success) { setText(cfg.status, '❌ 扫描失败：' + (r.data || '未知错误')); return; }
                var d = r.data || {};
                if (d.supported === false) { finishScan(cfg.parseScan(d)); return; }
                var unit = cfg.scanUnit || '张';
                if (cfg.scanItems) {
                    var items = d.items || [];
                    for (var i = 0; i < items.length; i++) acc.items.push(items[i]);
                    acc.posts += (d.total_posts || 0);
                    acc.images += (d.total_images || 0);
                    if (d.truncated) acc.truncated = true;
                } else {
                    var ids = d.ids || [];
                    var skipped = d.skipped || {};
                    for (var i = 0; i < ids.length; i++) acc.ids.push(ids[i]);
                    var keys = ['converted', 'missing', 'toolarge', 'pending_delete', 'invalid'];
                    for (var j = 0; j < keys.length; j++) acc.skipped[keys[j]] = (acc.skipped[keys[j]] || 0) + (skipped[keys[j]] || 0);
                }
                acc.scanned += (typeof d.scanned === 'number' ? d.scanned : (d.ids || []).length);
                acc.total = d.total || acc.total;
                if (!d.done) {
                    setText(cfg.status, '正在扫描…已扫 ' + acc.scanned + '/' + acc.total + ' ' + unit);
                    scanPaged(d.offset, acc);
                    return;
                }
                if (cfg.scanItems) {
                    finishScan(cfg.parseScan({ total_scanned: acc.scanned, total_posts: acc.posts, total_images: acc.images, truncated: acc.truncated, items: acc.items }));
                } else {
                    finishScan(cfg.parseScan({ supported: true, total: acc.ids.length, ids: acc.ids, skipped: acc.skipped }));
                }
            }).fail(function(xhr, textStatus) { setText(cfg.status, '❌ 扫描请求失败（' + skylaiprAjaxFailDetail(xhr, textStatus) + '）。'); });
        }
        function next() {
            if (st.stopping) return;
            if (st.pos >= st.ids.length) {
                setText(cfg.status, '✅ 全部完成：' + summary());
                restoreButtons();
                return;
            }
            var size = cfg.batchSize();
            if (!(size >= 1)) size = 1;
            var batch = st.ids.slice(st.pos, st.pos + size);
            var totalBatches = Math.max(1, Math.ceil(st.total / size));
            var batchNo = Math.floor(st.pos / size) + 1;
            setText(cfg.status, '正在处理第 ' + batchNo + '/' + totalBatches + ' 批（第 ' + (st.pos + 1) + '–' + (st.pos + batch.length) + ' 项，共 ' + st.total + ' 项）…请稍候。' + summary());
            var params = { action: cfg.batchAction, ids: batch, _ajax_nonce: cfg.nonce };
            if (cfg.batchParams) {
                var extra = cfg.batchParams(batch, size);
                for (var k in extra) params[k] = extra[k];
            }
            st.xhr = jQuery.post(ajaxurl, params, function(r) {
                st.xhr = null;
                if (st.stopping) return;
                if (!r.success) {
                    setText(cfg.status, '❌ ' + cfg.batchFailLabel + '失败：' + (r.data || '未知错误'));
                    restoreButtons();
                    return;
                }
                var results = (r.data && r.data.results) || [];
                results.forEach(function(it) {
                    st.done++;
                    cfg.onResult(it, st, function(text) { addLine(cfg, text); });
                });
                st.pos += results.length;
                setBar(cfg, st.done, st.total);
                setText(cfg.status, '处理中：' + summary());
                next();
            }).fail(function(xhr, textStatus) {
                st.xhr = null;
                if (st.stopping || textStatus === 'abort') {
                    setText(cfg.status, '已停止：' + summary());
                    restoreButtons();
                    return;
                }
                setText(cfg.status, '❌ 批量请求失败（' + skylaiprAjaxFailDetail(xhr, textStatus) + '），已停止。');
                restoreButtons();
            });
        }
        return {
            scan: function() {
                st.stopping = false;
                st.pos = 0; st.done = 0;
                setText(cfg.status, cfg.scanText || '正在扫描…');
                if (cfg.scanPaged) {
                    scanPaged(0, { ids: [], skipped: { converted: 0, missing: 0, toolarge: 0, pending_delete: 0, invalid: 0 }, items: [], posts: 0, images: 0, truncated: false, scanned: 0, total: 0 });
                    return;
                }
                jQuery.post(ajaxurl, { action: cfg.scanAction, _ajax_nonce: cfg.nonce }, function(r) {
                    if (!r.success) { setText(cfg.status, '❌ 扫描失败：' + (r.data || '未知错误')); return; }
                    finishScan(cfg.parseScan(r.data || {}));
                }).fail(function(xhr, textStatus) { setText(cfg.status, '❌ 扫描请求失败（' + skylaiprAjaxFailDetail(xhr, textStatus) + '）。'); });
            },
            start: function() {
                var ids = cfg.collectIds ? cfg.collectIds(st) : st.ids;
                if (!ids || !ids.length) { setText(cfg.status, cfg.emptyStartText || '没有可处理的项目。'); return; }
                st.ids = ids;
                st.total = ids.length;
                st.pos = 0; st.done = 0;
                st.stopping = false;
                cfg.resetCounts(st);
                setDisabled(cfg.startBtn, true);
                setDisabled(cfg.scanBtn, true);
                setDisabled(cfg.stopBtn, false);
                next();
            },
            stop: function() {
                st.stopping = true;
                if (st.xhr && st.xhr.abort) st.xhr.abort();
                setText(cfg.status, '已停止：' + summary());
                restoreButtons();
            }
        };
    }
    return { create: create };
})();

// v2.2.0：模型厂商下拉联动。厂商表由 wp_localize_script() 以
// skylaiprApiVendors 对象传入（见 skyline-ai-pro.php 的 admin_enqueue_scripts）。
// v2.2.9（WP.org 审核）：页面里不再输出内联 JSON 数据块
// 块；旧的 DOM 节点读法保留作回退（例如缓存的旧页面）。只在用户主动切
// 厂商时改值：页面加载时不写任何字段，不覆盖已存的自定义值。
document.addEventListener('DOMContentLoaded', function() {
    var vendors = null;
    if (typeof window.skylaiprApiVendors !== 'undefined' && window.skylaiprApiVendors) {
        vendors = window.skylaiprApiVendors;
    } else {
        var dataEl = document.getElementById('sky-api-vendors');
        if (!dataEl) return;
        try { vendors = JSON.parse(dataEl.textContent); } catch (e) { return; }
    }
    var vendorSel = document.getElementById('sky-field-api_vendor');
    if (!vendorSel) return;

    // 重建 datalist：有预设就挂 list 属性、无预设就摘掉（纯自由输入）。
    function refreshList(listEl, inputEl, models) {
        if (!listEl || !inputEl) return;
        while (listEl.firstChild) listEl.removeChild(listEl.firstChild);
        if (!models || !models.length) { inputEl.removeAttribute('list'); return; }
        models.forEach(function(m) {
            var opt = document.createElement('option');
            opt.value = m;
            listEl.appendChild(opt);
        });
        inputEl.setAttribute('list', listEl.id);
    }
    // 模型值：已在新厂商清单里的保留，否则取清单首个；无清单则清空。
    function pickModel(inputEl, models) {
        if (!inputEl) return;
        if (models.indexOf(inputEl.value) !== -1) return;
        inputEl.value = models.length ? models[0] : '';
    }

    // v2.2.1：厂商切换逻辑抽成具名函数（window.skylaiprApplyVendor），供
    // node 测试直接调；change 监听只负责传值。行为：
    // ① 切“自定义”时不改任何已存值（Endpoint、生图根地址、生图模型
    //    都原样保留），只把预设下拉清空、模型恢复纯自由输入——用户要
    //    填 FreeLLMAPI 的生图地址与模型（如 @cf/...）时不会被清空；
    // ② 切预设且该厂商有生图根地址时才写 api_img_base；无生图根地址
    //    的厂商（如 DeepSeek / Moonshot）不清空已存的生图根地址与生
    //    图密钥（密钥字段 JS 从不碰）；③ 无生图模型的厂商只清空生图
    //    模型名（pickModel 的无清单分支）。
    window.skylaiprApplyVendor = function(vendorKey) {
        var v = vendors[vendorKey];
        if (!v) return;
        var apiBase = document.getElementById('sky-field-api_base');
        var imgBase = document.getElementById('sky-field-api_img_base');
        var chatInput = document.getElementById('sky-field-chat_model');
        var imageInput = document.getElementById('sky-field-image_model');
        var chatList = document.getElementById('sky-list-chat_model');
        var imageList = document.getElementById('sky-list-image_model');
        if (vendorKey === 'custom') {
            // 自定义：不改任何已存值（Endpoint、生图根地址与模型都原样
            // 保留），只把预设下拉清空，模型字段恢复纯自由输入。
            refreshList(chatList, chatInput, []);
            refreshList(imageList, imageInput, []);
            return;
        }
        if (apiBase) apiBase.value = v.chat_endpoint || '';
        if (imgBase && v.img_base) imgBase.value = v.img_base;
        refreshList(chatList, chatInput, v.chat_models || []);
        refreshList(imageList, imageInput, v.image_models || []);
        pickModel(chatInput, v.chat_models || []);
        pickModel(imageInput, v.image_models || []);
    };
    vendorSel.addEventListener('change', function() {
        window.skylaiprApplyVendor(vendorSel.value);
    });
});
// v2.2.0 收口：密码字段显示切换。按钮与输入框由 render_field 的 password
// 分支成对输出（.sky-password-toggle + .sky-password-input）；点一次在
// password/text 间切换，只改呈现、不改提交值。已存密钥的 value 恒为空
// （M21：永不回显），切换只用于核对刚键入的新值；已配置与否看旁边的
// “已配置/未配置”角标，不靠读值。
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.sky-password-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var wrap = btn.closest('.sky-password-wrap');
            var input = wrap ? wrap.querySelector('.sky-password-input') : null;
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? '🙈 隐藏' : '👁 显示';
            btn.setAttribute('aria-label', show ? '隐藏密钥' : '显示密钥');
        });
    });
});

/* ────────────────────────────────────────────────────────────────
   v2.2.9（WP.org 审核）：原 admin/class-skylaipr-admin.php 里直接输出的
   script 标签块（测试连接 / 日志清理 / 模板库 / 缓存清理 / OSS 操作）
   全部移入此文件，随 wp_enqueue_script 加载；nonce 改由
   wp_localize_script() 传入（skylaiprAdminData 对象，见
   skyline-ai-pro.php 的 admin_enqueue_scripts），页面正文不再输出
   script 标签。原全局函数统一收进 window.SkylaiprAdmin 命名空间，
   页面上的 onclick 全部改为 SkylaiprAdmin.xxx(...)。
   ──────────────────────────────────────────────────────────────── */
window.SkylaiprAdmin = (function () {
    var D = (typeof window.skylaiprAdminData !== 'undefined' && window.skylaiprAdminData) ? window.skylaiprAdminData : {};
    var AJAX_URL = D.ajax_url || ((typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php');
    var TEST_NONCE = D.test_nonce || '';
    var PROMPT_NONCE = D.prompt_nonce || '';
    var CLEAR_LOGS_NONCE = D.clear_logs_nonce || '';
    // skylaiprOssPost / 三张批量卡片用的 skylaipr_ajax_nonce 已由 WP 本地化
    // 为 skylaipr_ajax.nonce（同在 admin_enqueue_scripts 里挂出）。
    function ajaxNonce() {
        return (typeof window.skylaipr_ajax !== 'undefined' && window.skylaipr_ajax && window.skylaipr_ajax.nonce) ? window.skylaipr_ajax.nonce : '';
    }

    // M19：成功分支原来直接拼 r.data，但 API 测试成功时 r.data 是
    // {reply: "..."} 对象，拼出来是 [object Object]。
    // 改为取 r.data.reply || r.data；另加 loading/禁用态（防重复点击）
    // 与请求失败处理（.fail()）。
    function testService(actionName, btn) {
        var $btn = btn ? jQuery(btn) : null;
        if ($btn && $btn.prop('disabled')) return; // 防重复点击
        var origText = $btn ? $btn.html() : '';
        if ($btn) $btn.prop('disabled', true).html('⏳ 诊断中…');
        jQuery.post(AJAX_URL, {
            action: actionName,
            _ajax_nonce: TEST_NONCE
        }, function(r) {
            if (r.success) {
                // r.data 可能是字符串（Redis/OSS 测试）或对象（API 测试
                // 返回 {reply: "..."}）；对象无 reply 时用 JSON 兜底，
                // 绝不再直接拼出 [object Object]。
                var msg = r.data;
                if (msg && typeof msg === 'object') msg = msg.reply || JSON.stringify(msg);
                alert('✅ 诊断成功: ' + msg);
            } else {
                alert('❌ 诊断失败: ' + r.data);
            }
        }).fail(function() {
            alert('❌ 请求失败：网络错误或服务器无响应，请稍后重试。');
        }).always(function() {
            if ($btn) $btn.prop('disabled', false).html(origText);
        });
    }

    // v2.2.3：OSS/缓存卡片共用的失败文案。带 HTTP 状态，403/504/500/
    // 断网可区分（与 admin.js 顶部 SkyBatchRunner 内的 skylaiprAjaxFailDetail 同款）。
    function skylaiprFailText(xhr, textStatus) {
        var status = (xhr && xhr.status) ? xhr.status : 0;
        if (status === 403) return 'HTTP 403（可能是登录过期或 nonce 失效，请刷新页面重试）';
        if (status === 504 || textStatus === 'timeout') return 'HTTP 504/超时（请求超时，请稍后重试）';
        if (status === 500) return 'HTTP 500（服务端错误，请看服务器日志）';
        if (status) return 'HTTP ' + status;
        return '网络中断或超时（HTTP 0）';
    }

    // 单次操作：点后禁用给定按钮（可传一个元素或一组选择器）、请求
    // 结束恢复；成功/失败文案写状态区；失败带 HTTP 状态。data 可能是
    // 字符串或 {message, ...} 对象，两种都认。
    function skylaiprOssPost(action, extra, btns, statusId, workingText) {
        var $btns = (typeof btns === 'string') ? jQuery(btns) : (btns ? jQuery(btns) : jQuery());
        if ($btns.length && $btns.first().prop('disabled')) return;
        $btns.prop('disabled', true);
        var $st = statusId ? jQuery('#' + statusId) : jQuery();
        if ($st.length) $st.text(workingText || '处理中…');
        var params = { action: action, _ajax_nonce: ajaxNonce() };
        if (extra) for (var k in extra) params[k] = extra[k];
        jQuery.post(AJAX_URL, params, function(r) {
            var msg = '';
            if (r.success) {
                var d = r.data;
                if (d && typeof d === 'object') msg = d.message || JSON.stringify(d);
                else msg = d || '操作成功';
                if ($st.length) $st.text('✅ ' + msg);
            } else {
                if ($st.length) $st.text('❌ 操作失败：' + (r.data || '未知错误'));
            }
        }).fail(function(xhr, textStatus) {
            if ($st.length) $st.text('❌ 操作失败（' + skylaiprFailText(xhr, textStatus) + '）');
        }).always(function() {
            $btns.prop('disabled', false);
        });
    }

    // P-6：缓存清理（只清 Redis 层，不碰 EdgeOne）。
    function skylaiprClearCache(scope, btn) {
        skylaiprOssPost('skylaipr_clear_cache', { scope: scope }, '#sky-cache-clear-page-btn, #sky-cache-clear-all-btn', 'sky-cache-clear-status', '正在清理缓存…');
    }

    // O-7：手动重试全部上传失败尺寸。
    function skylaiprOssRetryFailed(btn) {
        skylaiprOssPost('skylaipr_oss_retry_failed', null, btn, 'sky-oss-failed-status', '正在重试全部失败尺寸…');
    }

    // O-6/O-8：待删队列单行重试校验 / 取消登记 / 立即校验一次。
    function skylaiprPurgeRetry(id, btn) {
        skylaiprOssPost('skylaipr_oss_purge_retry', { attachment_id: id }, btn, 'sky-purge-queue-status', '正在校验附件 #' + id + '…');
    }
    function skylaiprPurgeCancel(id, btn) {
        if (!confirm('确定取消该附件的待删登记吗？取消后本地文件将保留，不再删除。')) return;
        skylaiprOssPost('skylaipr_oss_purge_cancel', { attachment_id: id }, btn, 'sky-purge-queue-status', '正在取消登记…');
    }
    function skylaiprPurgeNow(btn) {
        skylaiprOssPost('skylaipr_oss_purge_now', null, btn, 'sky-purge-queue-status', '正在校验待删队列…');
    }

    function savePromptTemplate() {
        let name = jQuery('#new-prompt-name').val().trim();
        let template = jQuery('#new-prompt-template').val().trim();
        if (!name || !template) return alert('请填写模板名称和内容');

        jQuery.post(AJAX_URL, {
            action: 'skylaipr_save_prompt',
            name: name,
            template: template,
            _ajax_nonce: PROMPT_NONCE
        }, function(r) {
            if (r.success) {
                alert('✅ 模板保存成功');
                loadPrompts();
                jQuery('#new-prompt-name, #new-prompt-template').val('');
            }
        });
    }

    // L6：模板名/内容先转义再拼 HTML（原代码直接插值后 .html()，
    // 是管理员之间的存储型 XSS 面）；模板不足 100 字时不加 "..."。
    function skylaiprEscapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    // v2.2.3：模板库缓存（供“填入系统人设”按 id 取全文，避免把
    // 模板内容拼进 onclick 属性里转义）；模板只能存/看/删的断链
    // 由此补上——点“填入系统人设”即把模板写进 system_prompt，
    // 下一次 AI 调用（call_api 拼 system 消息）就会用上。
    var skylaiprPromptCache = {};

    function loadPrompts() {
        jQuery.post(AJAX_URL, {
            action: 'skylaipr_get_prompts',
            _ajax_nonce: PROMPT_NONCE
        }, function(r) {
            if (r.success) {
                skylaiprPromptCache = r.data || {};
                let html = '<table class="widefat"><thead><tr><th>名称</th><th style="width:60%">模板预览</th><th>操作</th></tr></thead><tbody>';
                Object.keys(r.data || {}).forEach(id => {
                    let p = r.data[id];
                    let tpl = p.template || '';
                    let preview = tpl.length > 100 ? tpl.substring(0, 100) + '...' : tpl;
                    let safeId = skylaiprEscapeHtml(id).replace(/'/g, "\\'");
                    html += `<tr><td>${skylaiprEscapeHtml(p.name)}</td><td style="max-width:400px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${skylaiprEscapeHtml(preview)}</td><td><button class="button button-small" onclick="SkylaiprAdmin.skylaiprUsePrompt('${safeId}')">填入系统人设</button> <button class="button button-small" onclick="SkylaiprAdmin.deletePrompt('${safeId}')">删除</button></td></tr>`;
                });
                html += '</tbody></table>';
                jQuery('#sky-prompt-list').html(html);
            }
        });
    }

    // v2.2.3：把模板全文填进系统人设 textarea（#sky-field-system_prompt，
    // render_field 的 textarea 分支所给 id）。人设已有非空内容时先
    // confirm，取消则不填；填入后滚动到该字段并短暂高亮。
    function skylaiprUsePrompt(id) {
        var p = skylaiprPromptCache[id];
        if (!p || !p.template) { alert('模板内容为空，无法填入。'); return; }
        var ta = document.getElementById('sky-field-system_prompt');
        if (!ta) { alert('未找到系统人设字段，请先打开“智能核心”页再试。'); return; }
        if (ta.value.trim() !== '' && !confirm('系统人设已有内容，确定要用模板覆盖吗？')) return;
        ta.value = p.template;
        if (ta.dispatchEvent) ta.dispatchEvent(new Event('input', { bubbles: true }));
        ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
        ta.style.backgroundColor = '#fef9c3';
        ta.style.outline = '2px solid #6366f1';
        setTimeout(function() { ta.style.backgroundColor = ''; ta.style.outline = ''; }, 2000);
    }

    function deletePrompt(id) {
        if (confirm('确定删除此模板？')) {
            jQuery.post(AJAX_URL, {
                action: 'skylaipr_delete_prompt',
                id: id,
                _ajax_nonce: PROMPT_NONCE
            }, function(r) {
                if (r.success) {
                    alert('✅ 模板已删除');
                    loadPrompts();
                } else {
                    alert('❌ 删除失败: ' + (r.data || '未知错误'));
                }
            }).fail(function() { alert('网络请求失败'); });
        }
    }

    function clearLogs() {
        jQuery.post(AJAX_URL, {
            action: 'skylaipr_clear_logs',
            _ajax_nonce: CLEAR_LOGS_NONCE
        }, function(r) {
            if (r.success) {
                alert('日志已清空');
                location.reload();
            }
        });
    }

    // 页面上的按钮 onclick 统一走这个命名空间。
    return {
        testService: testService,
        skylaiprClearCache: skylaiprClearCache,
        skylaiprOssRetryFailed: skylaiprOssRetryFailed,
        skylaiprPurgeRetry: skylaiprPurgeRetry,
        skylaiprPurgeCancel: skylaiprPurgeCancel,
        skylaiprPurgeNow: skylaiprPurgeNow,
        savePromptTemplate: savePromptTemplate,
        loadPrompts: loadPrompts,
        skylaiprUsePrompt: skylaiprUsePrompt,
        deletePrompt: deletePrompt,
        clearLogs: clearLogs
    };
})();

/* ────────────────────────────────────────────────────────────────
   v2.2.9（WP.org 审核）：三张批量卡片（WebP/蜘蛛/SEO）的初始化脚本
   原来是各卡片后直接输出的 script 标签块（admin/class-skylaipr-admin.php
   约 1310 / 1410 / 1525 行），改到这里：DOMContentLoaded 后按卡片
   DOM 节点是否存在来初始化（各卡片只在自己的设置页出现），nonce
   取已本地化的 skylaipr_ajax.nonce；按钮入口挂到 SkylaiprAdmin 上。
   ──────────────────────────────────────────────────────────────── */
(function () {
    var skylaiprWebpRunner = null, skylaiprSpiderRunner = null, skylaiprSeoRunner = null;
    function ajaxNonce() {
        return (typeof window.skylaipr_ajax !== 'undefined' && window.skylaipr_ajax && window.skylaipr_ajax.nonce) ? window.skylaipr_ajax.nonce : '';
    }

    document.addEventListener('DOMContentLoaded', function() {
        // 模板库：原内联脚本在当前 tab 为 ai 时调 loadPrompts()；
        // #sky-prompt-list 只在智能核心页存在，等价判定。
        if (document.getElementById('sky-prompt-list')) window.SkylaiprAdmin.loadPrompts();

        // ── WebP 批量转换卡片 ──
        if (document.getElementById('sky-webp-status')) {
            // v2.2.1：批量驱动抽到本文件的 SkyBatchRunner，这里只留
            // 卡片专属配置（扫描解析、结果计数与文案）。失败与跳过都
            // 逐条列原因（服务端已带 reason）。
            var skylaiprWebpOk = 0, skylaiprWebpFail = 0, skylaiprWebpSkip = 0;
            skylaiprWebpRunner = SkyBatchRunner.create({
                status: 'sky-webp-status', progress: 'sky-webp-progress', bar: 'sky-webp-bar', failures: 'sky-webp-failures',
                scanBtn: 'sky-webp-scan-btn', startBtn: 'sky-webp-start-btn', stopBtn: 'sky-webp-stop-btn',
                scanAction: 'skylaipr_webp_scan', batchAction: 'skylaipr_webp_batch', batchFailLabel: '批量转换',
                scanText: '正在扫描…',
                // v2.2.3：扫描改分页循环（SkyBatchRunner 实现）——
                // 单请求遍历全部约 53,000 个附件会撞 EdgeOne 60 秒回源超时
                // （HTTP 524），分页后每请求只扫一段、带 offset 直到 done。
                scanPaged: true,
                nonce: ajaxNonce(),
                batchSize: function() { return 20; },
                resetCounts: function(st) { skylaiprWebpOk = 0; skylaiprWebpFail = 0; skylaiprWebpSkip = 0; },
                summaryText: function(st) { return '已处理 ' + st.done + ' / ' + st.total + ' 张，成功 ' + skylaiprWebpOk + '，失败 ' + skylaiprWebpFail + '，跳过 ' + skylaiprWebpSkip + '。'; },
                parseScan: function(d) {
                    if (!d.supported) return { ids: [], statusText: '❌ 当前服务器 GD 不支持 WebP，无法转换。' };
                    var sk = d.skipped || {};
                    return { ids: d.ids || [], statusText: '可转换 ' + (d.ids || []).length + ' 张（已跳过：已转过 ' + (sk.converted || 0) + '、本地缺失 ' + (sk.missing || 0) + '、超 10MB ' + (sk.toolarge || 0) + '、待删登记中 ' + (sk.pending_delete || 0) + '）。' };
                },
                onResult: function(it, st, addLine) {
                    if (it.status === 'converted') { skylaiprWebpOk++; }
                    else if (it.status === 'failed') { skylaiprWebpFail++; addLine('附件 #' + it.id + '：' + (it.reason || '转换失败')); }
                    else if (it.status === 'skipped') { skylaiprWebpSkip++; addLine('附件 #' + it.id + '：跳过（' + (it.reason || '已处理过') + '）'); }
                }
            });
        }

        // ── 蜘蛛（外链图片同步）卡片 ──
        if (document.getElementById('sky-spider-status')) {
            var skylaiprSpiderOk = 0, skylaiprSpiderFail = 0, skylaiprSpiderSkip = 0, skylaiprSpiderOkImgs = 0, skylaiprSpiderFailImgs = 0;
            skylaiprSpiderRunner = SkyBatchRunner.create({
                status: 'sky-spider-status', progress: 'sky-spider-progress', bar: 'sky-spider-bar', failures: 'sky-spider-failures',
                scanBtn: 'sky-spider-scan-btn', startBtn: 'sky-spider-start-btn', stopBtn: 'sky-spider-stop-btn',
                scanAction: 'skylaipr_spider_scan', batchAction: 'skylaipr_spider_batch', batchFailLabel: '批量同步',
                // v2.2.8：扫描改分页循环（与 WebP 同款）：单请求遍历全站
                // 3640 篇、无预算会撞 EdgeOne 60 秒回源超时（“网络错误”），
                // 分页后每请求只扫 500 篇、带 offset 直到 done。
                scanPaged: true, scanPageSize: 500, scanUnit: '篇', scanItems: true,
                scanText: '正在扫描全站文章…',
                nonce: ajaxNonce(),
                batchSize: function() { return 5; },
                resetCounts: function(st) { skylaiprSpiderOk = 0; skylaiprSpiderFail = 0; skylaiprSpiderSkip = 0; skylaiprSpiderOkImgs = 0; skylaiprSpiderFailImgs = 0; },
                summaryText: function(st) { return '已处理 ' + st.done + ' / ' + st.total + ' 篇，同步成功 ' + skylaiprSpiderOk + ' 篇（成功 ' + skylaiprSpiderOkImgs + ' 张 / 失败 ' + skylaiprSpiderFailImgs + ' 张），失败 ' + skylaiprSpiderFail + ' 篇，跳过 ' + skylaiprSpiderSkip + ' 篇。'; },
                parseScan: function(d) {
                    var tbody = document.getElementById('sky-spider-list');
                    tbody.innerHTML = '';
                    (d.items || []).forEach(function(it) {
                        var tr = document.createElement('tr');
                        var tdT = document.createElement('td');
                        var a = document.createElement('a');
                        a.href = 'post.php?post=' + it.id + '&action=edit';
                        a.textContent = it.title || ('文章 #' + it.id);
                        tdT.appendChild(a);
                        var tdC = document.createElement('td'); tdC.textContent = it.count + ' 张';
                        var tdD = document.createElement('td'); tdD.textContent = (it.domains || []).join(', ');
                        tr.appendChild(tdT); tr.appendChild(tdC); tr.appendChild(tdD);
                        tbody.appendChild(tr);
                    });
                    document.getElementById('sky-spider-list-wrap').style.display = (d.items || []).length ? 'block' : 'none';
                    var extra = d.truncated ? '（仅列出前 ' + (d.items || []).length + ' 篇，处理完再重扫）' : '';
                    return { ids: (d.items || []).map(function(it) { return it.id; }), statusText: '共扫描 ' + (d.total_scanned || 0) + ' 篇文章，发现 ' + (d.total_posts || 0) + ' 篇含外链图、共 ' + (d.total_images || 0) + ' 张。' + extra };
                },
                onResult: function(it, st, addLine) {
                    var title = '《' + (it.title || ('文章 #' + it.id)) + '》';
                    if (it.status === 'done') {
                        skylaiprSpiderOk++;
                        skylaiprSpiderOkImgs += (it.success || 0);
                        skylaiprSpiderFailImgs += (it.failed || 0);
                    } else if (it.status === 'failed') {
                        skylaiprSpiderFail++;
                        addLine(title + '：' + (it.reason || '同步失败（未返回原因）'));
                    } else if (it.status === 'skipped') {
                        skylaiprSpiderSkip++;
                        addLine(title + '：跳过（' + (it.reason || '正在同步中') + '）');
                    }
                }
            });
        }

        // ── SEO 批量补全卡片 ──
        if (document.getElementById('sky-seo-status')) {
            var skylaiprSeoOk = 0, skylaiprSeoPartial = 0, skylaiprSeoFail = 0, skylaiprSeoSkip = 0;
            var skylaiprSeoMissingLabel = {slug: '缺 Slug', excerpt: '缺摘要', tags: '缺标签'};
            function skylaiprSeoCheckedIds() {
                var ids = [];
                document.querySelectorAll('.sky-seo-check:checked').forEach(function(cb) { ids.push(parseInt(cb.value, 10)); });
                return ids;
            }
            skylaiprSeoRunner = SkyBatchRunner.create({
                status: 'sky-seo-status', cost: 'sky-seo-cost', progress: 'sky-seo-progress', bar: 'sky-seo-bar', failures: 'sky-seo-failures',
                scanBtn: 'sky-seo-scan-btn', startBtn: 'sky-seo-start-btn', stopBtn: 'sky-seo-stop-btn',
                scanAction: 'skylaipr_seo_scan', batchAction: 'skylaipr_seo_batch', batchFailLabel: '批量补全',
                // v2.2.8：扫描改分页循环（与 C1 同款）。
                scanPaged: true, scanPageSize: 500, scanUnit: '篇', scanItems: true,
                scanText: '正在扫描全站文章…',
                nonce: ajaxNonce(),
                batchSize: function() {
                    var sizeInput = document.getElementById('sky-seo-batch-size');
                    var size = sizeInput ? parseInt(sizeInput.value, 10) : 5;
                    if (isNaN(size) || size < 1) size = 1;
                    if (size > 20) size = 20;
                    return size;
                },
                batchParams: function(batch, size) { return { batch_size: size }; },
                collectIds: function(st) { return skylaiprSeoCheckedIds(); },
                emptyStartText: '请先勾选要补全的文章（默认勾选前 20 篇）。',
                resetCounts: function(st) { skylaiprSeoOk = 0; skylaiprSeoPartial = 0; skylaiprSeoFail = 0; skylaiprSeoSkip = 0; },
                summaryText: function(st) { return '已处理 ' + st.done + ' / ' + st.total + ' 篇，全部补全 ' + skylaiprSeoOk + ' 篇，部分补全 ' + skylaiprSeoPartial + ' 篇，失败 ' + skylaiprSeoFail + ' 篇，跳过 ' + skylaiprSeoSkip + ' 篇。'; },
                parseScan: function(d) {
                    var items = d.items || [];
                    var tbody = document.getElementById('sky-seo-list');
                    tbody.innerHTML = '';
                    items.forEach(function(it, idx) {
                        var tr = document.createElement('tr');
                        var tdCheck = document.createElement('td');
                        var cb = document.createElement('input');
                        cb.type = 'checkbox'; cb.className = 'sky-seo-check'; cb.value = it.id;
                        // 默认只勾选前 20 篇试跑（与 Skylaipr_Content::SEO_TRIAL_COUNT 一致），不默认全选。
                        cb.checked = idx < 20;
                        tdCheck.appendChild(cb);
                        var tdT = document.createElement('td');
                        var a = document.createElement('a');
                        a.href = 'post.php?post=' + it.id + '&action=edit';
                        a.textContent = it.title || ('文章 #' + it.id);
                        tdT.appendChild(a);
                        var tdM = document.createElement('td');
                        tdM.textContent = (it.missing || []).map(function(m) { return skylaiprSeoMissingLabel[m] || m; }).join('、');
                        tr.appendChild(tdCheck); tr.appendChild(tdT); tr.appendChild(tdM);
                        tbody.appendChild(tr);
                    });
                    document.getElementById('sky-seo-list-wrap').style.display = items.length ? 'block' : 'none';
                    var extra = d.truncated ? '（仅列出前 ' + items.length + ' 篇，处理完再重扫）' : '';
                    var checkAll = document.getElementById('sky-seo-check-all');
                    if (checkAll) checkAll.checked = false;
                    return {
                        ids: items.map(function(it) { return it.id; }),
                        statusText: '共扫描 ' + (d.total_scanned || 0) + ' 篇文章，发现 ' + (d.total_posts || 0) + ' 篇缺 SEO 项。' + extra,
                        costText: '本次缺项 ' + (d.total_posts || 0) + ' 篇，全部补全约需最多 ' + ((d.total_posts || 0) * 3) + ' 次 AI 调用（每篇最多 3 次）；默认已勾选前 20 篇，先试效果。'
                    };
                },
                onResult: function(it, st, addLine) {
                    var title = '《' + (it.title || ('文章 #' + it.id)) + '》';
                    if (it.status === 'done') { skylaiprSeoOk++; }
                    else if (it.status === 'partial') {
                        skylaiprSeoPartial++;
                        addLine(title + '：部分补全，仍缺 ' + (it.missing || []).map(function(m) { return skylaiprSeoMissingLabel[m] || m; }).join('、') + (it.reason ? '（' + it.reason + '）' : ''));
                    }
                    else if (it.status === 'failed') {
                        skylaiprSeoFail++;
                        addLine(title + '：' + (it.reason || '补全失败（未返回原因）'));
                    }
                    else if (it.status === 'skipped') {
                        skylaiprSeoSkip++;
                        addLine(title + '：跳过（' + (it.reason || '已无缺失项') + '）');
                    }
                }
            });
            // 全选复选框（原全局函数 skylaiprSeoToggleAll）。
            window.SkylaiprAdmin.skylaiprSeoToggleAll = function(checked) {
                document.querySelectorAll('.sky-seo-check').forEach(function(cb) { cb.checked = checked; });
            };
        }
    });

    // 按钮 onclick 入口（原全局函数名不变，统一挂到 SkylaiprAdmin 上）。
    window.SkylaiprAdmin.skylaiprWebpScan = function() { if (skylaiprWebpRunner) skylaiprWebpRunner.scan(); };
    window.SkylaiprAdmin.skylaiprWebpStart = function() { if (skylaiprWebpRunner) skylaiprWebpRunner.start(); };
    window.SkylaiprAdmin.skylaiprWebpStop = function() { if (skylaiprWebpRunner) skylaiprWebpRunner.stop(); };
    window.SkylaiprAdmin.skylaiprSpiderScan = function() { if (skylaiprSpiderRunner) skylaiprSpiderRunner.scan(); };
    window.SkylaiprAdmin.skylaiprSpiderStart = function() { if (skylaiprSpiderRunner) skylaiprSpiderRunner.start(); };
    window.SkylaiprAdmin.skylaiprSpiderStop = function() { if (skylaiprSpiderRunner) skylaiprSpiderRunner.stop(); };
    window.SkylaiprAdmin.skylaiprSeoScan = function() { if (skylaiprSeoRunner) skylaiprSeoRunner.scan(); };
    window.SkylaiprAdmin.skylaiprSeoStart = function() { if (skylaiprSeoRunner) skylaiprSeoRunner.start(); };
    window.SkylaiprAdmin.skylaiprSeoStop = function() { if (skylaiprSeoRunner) skylaiprSeoRunner.stop(); };
})();
