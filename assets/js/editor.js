/* v2.2.9（WP.org 审核）：原 class-skylaipr-content.php 里直接输出的
   script 标签块移入此文件，随 wp_enqueue_script 加载；ajax 地址与
   nonce 改由 wp_localize_script() 传入（skylaiprEditorData 对象，见
   Skylaipr_Content::__construct 里的 admin_enqueue_scripts 钩子）。
   sTab 已改名 skylaiprCpTab（加前缀）；SkySpider / SkylaiprEditor
   本就带 Sky 前缀，保持不变。 */
var skylaiprEditorData = (typeof window.skylaiprEditorData !== 'undefined' && window.skylaiprEditorData) ? window.skylaiprEditorData : {};
var skylaiprAjaxUrl = skylaiprEditorData.ajaxUrl || ((typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php');

(function($){
    window.skylaiprCpTab = function(id, elem){ 
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
                var content = SkylaiprEditor.getContent();
                if(!content) return alert('请先输入或粘贴内容');
                
                var regex = /<img[^>]+(?:data-src|src)=['"]([^'"]+)['"]/g;
                var matches = [], found;
                var seen = new Set();
                // N-A1：预览缩略图用 DOM 建节点（.attr('src', …)），
                // 不拼 '<img src="'+url+'">' 字符串插进 .html()——
                // real 是经 HTML 实体解码后的 URL，可含引号，拼串
                // 会跳出 src 属性造成后台 XSS（与 M8 同款修法）。
                var previewNodes = [];
                var tempContent = content;
                var counter = 0;
                
                while ((found = regex.exec(content)) !== null) {
                    var raw = found[1];
                    if(raw.indexOf(window.location.hostname) === -1 && raw.indexOf('data:image') === -1 && raw.indexOf('sky-pending-') === -1) {
                        var d = document.createElement('div'); d.innerHTML = raw; var real = d.textContent || raw;
                        var key = real; 
                        if (!seen.has(key)) { 
                            var placeholder = 'sky-pending-' + counter + '-' + Math.floor(Math.random()*1000);
                            matches.push({ raw: raw, real: real, holder: placeholder }); 
                            seen.add(key);
                            previewNodes.push($('<img>').attr('src', real).addClass('sky-sp-thumb').attr('id', 'sp-thumb-'+counter));
                            counter++;
                        }
                    }
                }

                if(matches.length === 0) return alert('✅ 未发现外部图片！');

                for(var i=0; i<matches.length; i++) {
                    var m = matches[i];
                    tempContent = tempContent.split(m.raw).join(m.holder);
                    if(m.real !== m.raw) tempContent = tempContent.split(m.real).join(m.holder);
                }
                
                SkylaiprEditor.setContent(tempContent);

                $('#sky-spider-status').slideDown();
                $('#sky-sp-preview').empty().append(previewNodes);
                $('#btn-spider-start').prop('disabled', true).text('同步进行中...');
                $('#sp-total').text(matches.length);
                $('#sp-cur').text(0);
                $('#sky-sp-bar').css('width', '0%');
                $('#sky-sp-log').html('<div>🚀 锁定 ' + matches.length + ' 张图片，开始下载...</div>');
                
                this.processQueue(matches, 0);
            } catch(e) { alert('同步启动失败: ' + e.message); }
        },

        log: function(msg, type='normal') {
            // M8：msg 含服务器返回的错误串（不可信输入），用
            // textContent 插入，不再用 innerHTML 拼串。
            var color = type==='ok'?'#10b981':(type==='err'?'#ef4444':'#64748b');
            var d = document.createElement('div');
            d.textContent = msg;
            d.style.color = color;
            var box = document.getElementById('sky-sp-log');
            if(box) { box.appendChild(d); box.scrollTop = box.scrollHeight; }
        },

        processQueue: function(queue, idx, retryCount) {
            if (typeof retryCount === 'undefined') retryCount = 0;
            if (idx >= queue.length) {
                $('#sp-msg').text('全部完成').css('color', '#10b981');
                $('#btn-spider-start').prop('disabled', false).html('<i>🕷️</i> 一键同步图片 (可视版)');
                this.log('🏁 队列结束，编辑器内容已更新', 'ok');
                return;
            }

            var item = queue[idx];
            var pid = $('#post_ID').val() || 0;
            var self = this;
            var label = (retryCount > 0) ? '重试 ' + retryCount + '...' : '正在下载...';
            $('#sp-msg').text('第 ' + (idx+1) + ' 张: ' + label);
            
            $.post(skylaiprAjaxUrl, {
                action: 'skylaipr_spider_single', url: item.real, pid: pid, _ajax_nonce: skylaiprEditorData.taskNonce
            }).done(function(res) {
                if(res.success) {
                    var localUrl = res.data.url;
                    self.log('[' + (idx+1) + '] ✅ ' + (res.data.msg||'成功'), 'ok');
                    $('#sp-thumb-'+idx).addClass('done').attr('src', localUrl);

                    try {
                        var currentContent = SkylaiprEditor.getContent();
                        currentContent = currentContent.split(item.holder).join(localUrl);
                        currentContent = currentContent.replace(/src=["']data:image\/[^;]+;base64,[^"']*["']/g, '');
                        currentContent = currentContent.replace(/src=["']\s*["']/g, '');
                        function escReg(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
                        var dsReg = new RegExp('data-src=["\']' + escReg(localUrl) + '["\']', 'g');
                        currentContent = currentContent.replace(dsReg, 'src="' + localUrl + '"');
                        SkylaiprEditor.setContent(currentContent);
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
                        var currentContent = SkylaiprEditor.getContent();
                        currentContent = currentContent.split(item.holder).join(item.raw);
                        SkylaiprEditor.setContent(currentContent);
                        self.processQueue(queue, idx + 1, 0);
                    }
                }
            }).fail(function(xhr, status, error){ 
                if (retryCount < 2) { setTimeout(function(){ self.processQueue(queue, idx, retryCount + 1); }, 3000); } 
                else { self.log('[' + (idx+1) + '] ❌ 网络错误: ' + error, 'err'); self.processQueue(queue, idx + 1, 0); }
            });
        }
    };

    window.SkylaiprEditor = {
        isGutenberg: function() { return document.body.classList.contains('block-editor-page') && typeof wp !== 'undefined' && wp.data && wp.data.select; },
        isClassicVisual: function() { return typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden(); },
        getContent: function() { 
            try {
                if (this.isGutenberg()) return wp.data.select("core/editor").getEditedPostAttribute("content");
                else if (this.isClassicVisual()) return tinyMCE.activeEditor.getContent();
                else return $('#content').val();
            } catch(e) { return $('#content').val() || ''; }
        },
        setContent: function(html) { 
            try {
                if (this.isGutenberg()) { 
                    var blocks = wp.blocks.parse(html); 
                    wp.data.dispatch('core/editor').resetBlocks(blocks); 
                    setTimeout(function() {
                        if(wp.data && wp.data.dispatch && wp.data.dispatch('core/editor')) {
                            wp.data.dispatch('core/editor').savePost();
                        }
                    }, 500);
                } 
                else if (this.isClassicVisual()) {
                    tinyMCE.activeEditor.setContent(html);
                    tinyMCE.activeEditor.setDirty(false); 
                }
                else { 
                    $('#content').val(html).trigger('change'); 
                }
            } catch(e) {}
        },
        setTitle: function(newTitle) {
            if (this.isGutenberg()) { wp.data.dispatch("core/editor").editPost({title:newTitle}); } 
            else { $('#title').val(newTitle); $('#title-prompt-text').addClass('screen-reader-text'); }
        },
        linkNow: function() {
            var i = this.getContent(); if(!i) return alert('请先输入或粘贴内容');
            $('#sky-loading').show();
            $.post(skylaiprAjaxUrl, { action: 'skylaipr_link_now', content: i, _ajax_nonce: skylaiprEditorData.taskNonce }, function(r){
                $('#sky-loading').hide();
                if(r.success) { SkylaiprEditor.setContent(r.data.content); alert(r.data.msg); } 
                else { alert(r.data); }
            }).fail(function() { $('#sky-loading').hide(); alert('网络请求失败'); });
        },
        applyReplace: function() {
            var raw = $('#sky-res-content').data('raw');
            if(raw) { if(confirm('⚠️ 确定要用生成的内容覆盖当前编辑器中的所有内容吗？')) this.setContent(raw); } 
            else alert('没有可替换的内容');
        },
        task: function(t) {
            try {
                var i = this.getContent(); if(!i) return alert('请先输入或粘贴内容');
                $('#sky-loading').show();
                $.ajax({
                    url: skylaiprAjaxUrl, type: 'POST',
                    data: { action: 'skylaipr_ai_task', task: t, input: i, _ajax_nonce: skylaiprEditorData.taskNonce },
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
                                        // M8：AI 标题先转义再拼 HTML（按钮文字与
                                        // data-title 都转义）；不再把标题拼进
                                        // onclick='...' JS 字符串（引号可逃逸注入），
                                        // 改用 data-title + 事件绑定取值。
                                        var esc = SkylaiprEditor.escapeHtml(clean);
                                        html += '<button type="button" class="sky-btn-cp sky-title-choice" style="margin-bottom:5px;text-align:left" data-title="'+esc+'">👉 '+esc+'</button>';
                                    });
                                    html += '<button type="button" class="sky-btn-cp" style="margin-top:5px;color:#666" onclick="jQuery(\'#sky-res-box\').hide()">❌ 取消</button>';
                                    SkylaiprEditor.showResult(html);
                                    $('#sky-res-content').find('.sky-title-choice').on('click', function(){ SkylaiprEditor.setTitle($(this).attr('data-title')); });
                                    return;
                                }
                                var cleanRes = res.replace(/^["']|["']$/g, '');
                                if(confirm('建议标题：\n'+cleanRes+'\n\n是否替换？')) SkylaiprEditor.setTitle(cleanRes);
                            } 
                            else if(t==='slug_en') { 
                                res = res.replace(/[^a-z0-9-]/g, '-').toLowerCase();
                                if(SkylaiprEditor.isGutenberg()) wp.data.dispatch("core/editor").editPost({slug:res}); 
                                else { $('#post_name').val(res); $('#edit-slug-box').html(res); }
                                alert('Slug 已更新: '+res); 
                            }
                            else if(t==='rewrite' || t==='polish' || t==='continue' || t==='expand') {
                                var btns = '<button type="button" class="sky-btn-cp primary" onclick="SkylaiprEditor.applyReplace()">🔄 立即替换全文</button>';
                                btns += '<button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.insert()">📥 插入光标处</button>';
                                SkylaiprEditor.showResult(null, res, btns);
                            }
                            // M8：AI 返回的标签走 rawText 分支（.text() 插入），
                            // 不再拼进 '<b>…</b><br>'+res 的 .html() 串里。
                            else if(t==='tags') { SkylaiprEditor.showResult(null, '建议标签：\n' + res); }
                            else if(t==='desc') {
                                if(SkylaiprEditor.isGutenberg()) wp.data.dispatch("core/editor").editPost({excerpt:res});
                                else { $('#excerpt').val(res); }
                                alert('摘要已更新');
                            }
                            else if(t==='rewrite_full') { if(confirm('确认覆盖全文？')) SkylaiprEditor.setContent(res); }
                            // M8：其余 AI 任务的原文走 rawText 分支（.text() 插入）。
                            else { SkylaiprEditor.showResult(null, res); } 
                        } else alert('Error: ' + r.data); 
                    },
                    error: function(e) { $('#sky-loading').hide(); alert('Request Failed: ' + e.statusText); }
                });
            } catch(e) { $('#sky-loading').hide(); alert('执行错误: ' + e.message); }
        },
        genImg: function() {
            var p = prompt("请输入图片描述:"); if(!p) return;
            $('#sky-loading').show();
            $.post(skylaiprAjaxUrl, { action: 'skylaipr_gen_img', prompt: p, _ajax_nonce: skylaiprEditorData.taskNonce }, function(r){
                $('#sky-loading').hide();
                // M8：返回 URL 先校验为 http(s) 且不含引号/尖括号/
                // 空白，再用 DOM 建 <img>（.attr('src', …)），不拼
                // '<img src="'+url+'">' 字符串插进 .html()。
                if(r.success) {
                    var imgUrl = String(r.data || '');
                    if (!/^https?:\/\/[^"'<>\s]+$/i.test(imgUrl)) { alert('生图返回了无效的图片地址'); return; }
                    var $box = $('#sky-res-content');
                    $box.empty();
                    $('<img>').attr('src', imgUrl).css('max-width', '100%').appendTo($box);
                    $box.removeData('raw');
                    $('#sky-res-actions').hide().empty();
                    $('#sky-res-box').show();
                } else alert(r.data);
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
                    if(this.isClassicVisual()) tinyMCE.activeEditor.execCommand('mceInsertContent', false, h);
                    else { var ta = document.getElementById('content'); if(ta) ta.value += "\n\n" + h; }
                }
            } catch(e) { alert('插入失败: ' + e.message); }
        },
        seo: function() {
            var t = $('#title').val(); 
            if(this.isGutenberg()) t = wp.data.select("core/editor").getEditedPostAttribute("title");
            var c = this.getContent();
            $.post(skylaiprAjaxUrl, { action:'skylaipr_seo_score', title:t, content:c, _ajax_nonce:skylaiprEditorData.seoNonce }, function(r){
                 if(r.success) SkylaiprEditor.showResult('<b>SEO 得分: '+r.data.score+'</b><br>'+r.data.advice.join('<br>'));
            });
        },
        // M8：AI 派生片段拼 HTML 前的转义（& < > " ' 全转）。
        escapeHtml: function(s) {
            return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        },
        showResult: function(html, rawText, buttonsHtml) {
            // M8：AI 原文一律走 rawText 分支，用 .text() 插入
            // （#sky-res-content 已是 white-space:pre-wrap，换行
            // 直接保留，不用再转 <br>）。html 分支只接受本文件
            // 内部拼接的可信标记（标题选择按钮/SEO 得分/操作按钮），
            // 其中所有 AI 派生片段在拼接前已转义；直接插 AI 原文
            // 的调用已全部改为传 rawText。buttonsHtml 同样只接受
            // 内部静态按钮串。
            var $box = $('#sky-res-content');
            if (rawText !== undefined && rawText !== null) {
                $box.text(rawText);
                $box.data('raw', rawText);
            } else {
                $box.html(html || '');
                $box.removeData('raw');
            }
            if(buttonsHtml) $('#sky-res-actions').html(buttonsHtml).show(); else $('#sky-res-actions').hide().empty();
            $('#sky-res-box').show();
        }
    };
})(jQuery);
