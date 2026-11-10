document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Skyline AI Pro v2.2.5 Enterprise Engine Loaded');

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
// 各卡片只留一个薄壳（见 admin.php 内联 <script>）：扫描解析、结果
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
    function skyAjaxFailDetail(xhr, textStatus) {
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
        // 发请求（每次带 offset），累积 ids 与 skipped，状态区显示进度
        // “正在扫描…已扫 X/Y 张”，直到服务端 done 才把累积结果
        // {supported:true, total:累积 ids 数, ids:累积 ids, skipped:累积
        // skipped} 交给现有的 parseScan（total 语义与旧单次扫描相同，
        // 是可转 ID 数；服务端每页返回的 total 是符合条件的附件总数，
        // 只用于进度显示与判断 done）。中途某页请求失败走现有 .fail()
        // 处理（带 HTTP 状态）；服务端 supported=false 时直接收尾、不翻页。
        function scanPaged(offset, accIds, accSkipped, accScanned, total) {
            jQuery.post(ajaxurl, { action: cfg.scanAction, _ajax_nonce: cfg.nonce, offset: offset, page_size: cfg.scanPageSize || 2000 }, function(r) {
                if (!r.success) { setText(cfg.status, '❌ 扫描失败：' + (r.data || '未知错误')); return; }
                var d = r.data || {};
                if (d.supported === false) { finishScan(cfg.parseScan(d)); return; }
                var ids = d.ids || [];
                var skipped = d.skipped || {};
                for (var i = 0; i < ids.length; i++) accIds.push(ids[i]);
                var keys = ['converted', 'missing', 'toolarge', 'pending_delete', 'invalid'];
                for (var j = 0; j < keys.length; j++) accSkipped[keys[j]] = (accSkipped[keys[j]] || 0) + (skipped[keys[j]] || 0);
                accScanned += (d.scanned || ids.length);
                total = d.total || total;
                if (!d.done) {
                    setText(cfg.status, '正在扫描…已扫 ' + accScanned + '/' + total + ' 张');
                    scanPaged(d.offset, accIds, accSkipped, accScanned, total);
                    return;
                }
                finishScan(cfg.parseScan({ supported: true, total: accIds.length, ids: accIds, skipped: accSkipped }));
            }).fail(function(xhr, textStatus) { setText(cfg.status, '❌ 扫描请求失败（' + skyAjaxFailDetail(xhr, textStatus) + '）。'); });
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
                setText(cfg.status, '❌ 批量请求失败（' + skyAjaxFailDetail(xhr, textStatus) + '），已停止。');
                restoreButtons();
            });
        }
        return {
            scan: function() {
                st.stopping = false;
                st.pos = 0; st.done = 0;
                setText(cfg.status, cfg.scanText || '正在扫描…');
                if (cfg.scanPaged) {
                    scanPaged(0, [], { converted: 0, missing: 0, toolarge: 0, pending_delete: 0, invalid: 0 }, 0, 0);
                    return;
                }
                jQuery.post(ajaxurl, { action: cfg.scanAction, _ajax_nonce: cfg.nonce }, function(r) {
                    if (!r.success) { setText(cfg.status, '❌ 扫描失败：' + (r.data || '未知错误')); return; }
                    finishScan(cfg.parseScan(r.data || {}));
                }).fail(function(xhr, textStatus) { setText(cfg.status, '❌ 扫描请求失败（' + skyAjaxFailDetail(xhr, textStatus) + '）。'); });
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

// v2.2.0：模型厂商下拉联动。厂商表由 render_ai_tab 以 JSON 嵌在智能核心
// 页里（#sky-api-vendors），其他页没有该节点、直接返回。只在用户主动切
// 厂商时改值：页面加载时不写任何字段，不覆盖已存的自定义值。
document.addEventListener('DOMContentLoaded', function() {
    var dataEl = document.getElementById('sky-api-vendors');
    if (!dataEl) return;
    var vendors;
    try { vendors = JSON.parse(dataEl.textContent); } catch (e) { return; }
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

    // v2.2.1：厂商切换逻辑抽成具名函数（window.skyApplyVendor），供
    // node 测试直接调；change 监听只负责传值。行为：
    // ① 切“自定义”时不改任何已存值（Endpoint、生图根地址、生图模型
    //    都原样保留），只把预设下拉清空、模型恢复纯自由输入——用户要
    //    填 FreeLLMAPI 的生图地址与模型（如 @cf/...）时不会被清空；
    // ② 切预设且该厂商有生图根地址时才写 api_img_base；无生图根地址
    //    的厂商（如 DeepSeek / Moonshot）不清空已存的生图根地址与生
    //    图密钥（密钥字段 JS 从不碰）；③ 无生图模型的厂商只清空生图
    //    模型名（pickModel 的无清单分支）。
    window.skyApplyVendor = function(vendorKey) {
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
        window.skyApplyVendor(vendorSel.value);
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
