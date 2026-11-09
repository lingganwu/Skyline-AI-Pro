document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Skyline AI Pro v2.2.0 Enterprise Engine Loaded');

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
    var apiBase = document.getElementById('sky-field-api_base');
    var imgBase = document.getElementById('sky-field-api_img_base');
    var chatInput = document.getElementById('sky-field-chat_model');
    var imageInput = document.getElementById('sky-field-image_model');
    var chatList = document.getElementById('sky-list-chat_model');
    var imageList = document.getElementById('sky-list-image_model');

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

    vendorSel.addEventListener('change', function() {
        var v = vendors[vendorSel.value];
        if (!v) return;
        if (vendorSel.value === 'custom') {
            // 自定义：不改任何已存值（Endpoint、生图根地址与模型都原样
            // 保留），只把预设下拉清空，模型字段恢复纯自由输入。
            refreshList(chatList, chatInput, []);
            refreshList(imageList, imageInput, []);
            return;
        }
        if (apiBase) apiBase.value = v.chat_endpoint || '';
        if (imgBase) imgBase.value = v.img_base || '';
        refreshList(chatList, chatInput, v.chat_models || []);
        refreshList(imageList, imageInput, v.image_models || []);
        pickModel(chatInput, v.chat_models || []);
        pickModel(imageInput, v.image_models || []);
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
