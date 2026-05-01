/**
 * Skyline Copilot - 编辑器集成
 * Version: 2.0.1
 */
(function($) {
    'use strict';
    
    const config = window.skylineCopilot || {};
    const ajaxUrl = config.ajax_url || '';
    const nonce = config.nonce || '';
    const i18n = config.i18n || {};
    
    // ═══ 编辑器工具 ═══
    const Editor = {
        isGutenberg() {
            return document.body.classList.contains('block-editor-page') && 
                   typeof wp !== 'undefined' && wp.data && wp.data.select;
        },
        
        isClassicVisual() {
            return typeof tinyMCE !== 'undefined' && 
                   tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden();
        },
        
        getContent() {
            try {
                if (this.isGutenberg()) {
                    return wp.data.select('core/editor').getEditedPostAttribute('content');
                } else if (this.isClassicVisual()) {
                    return tinyMCE.activeEditor.getContent();
                }
                return $('#content').val() || '';
            } catch (e) {
                return $('#content').val() || '';
            }
        },
        
        setContent(html) {
            try {
                if (this.isGutenberg()) {
                    const blocks = wp.blocks.parse(html);
                    wp.data.dispatch('core/editor').resetBlocks(blocks);
                } else if (this.isClassicVisual()) {
                    tinyMCE.activeEditor.setContent(html);
                } else {
                    $('#content').val(html).trigger('change');
                }
            } catch (e) {
                console.error('setContent error:', e);
            }
        },
        
        setTitle(title) {
            if (this.isGutenberg()) {
                wp.data.dispatch('core/editor').editPost({ title });
            } else {
                $('#title').val(title);
                $('#title-prompt-text').addClass('screen-reader-text');
            }
        },
        
        insert(html) {
            try {
                if (!html) return;
                if (this.isGutenberg()) {
                    const blocks = wp.data.select('core/editor').getBlocks();
                    const newBlock = wp.blocks.createBlock('core/html', { content: html });
                    wp.data.dispatch('core/editor').insertBlocks(newBlock, blocks.length);
                } else if (this.isClassicVisual()) {
                    tinyMCE.activeEditor.execCommand('mceInsertContent', false, html);
                } else {
                    const ta = document.getElementById('content');
                    if (ta) ta.value += '\n\n' + html;
                }
            } catch (e) {
                alert('插入失败: ' + e.message);
            }
        }
    };
    
    // ═══ 图片同步器 ═══
    const Spider = {
        queue: [],
        current: 0,
        
        init() {
            const content = Editor.getContent();
            if (!content) return alert(i18n.no_content || '请先输入内容');
            
            // 提取外部图片
            const regex = /<img[^>]+(?:data-src|src)=['"]([^'"]+)['"]/g;
            const seen = new Set();
            const matches = [];
            let found;
            
            while ((found = regex.exec(content)) !== null) {
                const raw = found[1];
                if (raw.indexOf(window.location.hostname) === -1 && 
                    raw.indexOf('data:image') === -1 &&
                    raw.indexOf('sky-pending-') === -1) {
                    
                    const d = document.createElement('div');
                    d.innerHTML = raw;
                    const real = d.textContent || raw;
                    
                    if (!seen.has(real)) {
                        const placeholder = 'sky-pending-' + matches.length + '-' + Math.random().toString(36).substr(2, 4);
                        matches.push({ raw, real, holder: placeholder });
                        seen.add(real);
                    }
                }
            }
            
            if (matches.length === 0) {
                return alert(i18n.no_external_images || '✅ 未发现外部图片！');
            }
            
            this.queue = matches;
            this.current = 0;
            
            // 替换为占位符
            let tempContent = content;
            matches.forEach(m => {
                tempContent = tempContent.split(m.raw).join(m.holder);
                if (m.real !== m.raw) {
                    tempContent = tempContent.split(m.real).join(m.holder);
                }
            });
            Editor.setContent(tempContent);
            
            // 显示进度 UI
            this.showProgress();
            this.processNext();
        },
        
        showProgress() {
            const previewHtml = this.queue.map((m, i) => 
                `<img src="${m.real}" class="sky-sp-thumb" id="sp-thumb-${i}">`
            ).join('');
            
            $('#sky-spider-status').slideDown();
            $('#sky-sp-preview').html(previewHtml);
            $('#btn-spider-start').prop('disabled', true).text('同步进行中...');
            $('#sp-total').text(this.queue.length);
            $('#sp-cur').text(0);
            $('#sky-sp-bar').css('width', '0%');
            this.log('🚀 锁定 ' + this.queue.length + ' 张图片，开始下载...', 'info');
        },
        
        log(msg, type = 'normal') {
            const colors = { ok: '#10b981', err: '#ef4444', warn: '#f59e0b', info: '#64748b' };
            const color = colors[type] || colors.info;
            const d = document.createElement('div');
            d.innerHTML = `<span style="color:${color}">${msg}</span>`;
            const box = document.getElementById('sky-sp-log');
            if (box) {
                box.appendChild(d);
                box.scrollTop = box.scrollHeight;
            }
        },
        
        processNext(retryCount = 0) {
            if (this.current >= this.queue.length) {
                this.complete();
                return;
            }
            
            const item = this.queue[this.current];
            const pid = $('#post_ID').val() || 0;
            const label = retryCount > 0 ? `重试 ${retryCount}...` : '正在下载...';
            
            $('#sp-msg').text(`第 ${this.current + 1} 张: ${label}`);
            
            $.post(ajaxUrl, {
                action: 'sky_sync_single',
                url: item.real,
                pid: pid,
                nonce: nonce
            }).done(res => {
                if (res.success) {
                    this.onSuccess(item, res.data);
                } else {
                    this.onError(item, res.data, retryCount);
                }
            }).fail((xhr, status, error) => {
                this.onError(item, error, retryCount);
            });
        },
        
        onSuccess(item, data) {
            this.log(`[${this.current + 1}] ✅ ${data.msg || '成功'}`, 'ok');
            $(`#sp-thumb-${this.current}`).addClass('done').attr('src', data.url);
            
            // 替换占位符为本地 URL
            try {
                let content = Editor.getContent();
                content = content.split(item.holder).join(data.url);
                Editor.setContent(content);
            } catch (e) {
                console.error('Content update error:', e);
            }
            
            // 更新进度
            const pct = Math.round(((this.current + 1) / this.queue.length) * 100);
            $('#sp-cur').text(this.current + 1);
            $('#sky-sp-bar').css('width', pct + '%');
            
            this.current++;
            this.processNext();
        },
        
        onError(item, error, retryCount) {
            if (retryCount < 2) {
                this.log(`[${this.current + 1}] ⚠️ 失败: ${error}，3秒后重试...`, 'warn');
                setTimeout(() => this.processNext(retryCount + 1), 3000);
            } else {
                this.log(`[${this.current + 1}] ❌ 放弃: ${error}`, 'err');
                // 恢复原始 URL
                try {
                    let content = Editor.getContent();
                    content = content.split(item.holder).join(item.raw);
                    Editor.setContent(content);
                } catch (e) {}
                
                this.current++;
                this.processNext();
            }
        },
        
        complete() {
            $('#sp-msg').text(i18n.sync_complete || '全部完成').css('color', '#10b981');
            $('#btn-spider-start').prop('disabled', false).html('🕷️ 一键同步图片');
            this.log('🏁 队列结束，编辑器内容已更新', 'ok');
        }
    };
    
    // ═══ AI 任务 ═══
    const AI = {
        task(type) {
            const content = Editor.getContent();
            if (!content) return alert(i18n.no_content || '请先输入内容');
            
            this.showLoading();
            
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sky_ai_task',
                    task: type,
                    input: content,
                    nonce: nonce
                },
                success: res => {
                    this.hideLoading();
                    if (res.success) {
                        this.handleResult(type, res.data.trim());
                    } else {
                        alert('Error: ' + res.data);
                    }
                },
                error: e => {
                    this.hideLoading();
                    alert('Request Failed: ' + e.statusText);
                }
            });
        },
        
        handleResult(type, result) {
            switch (type) {
                case 'title':
                    this.showTitleOptions(result);
                    break;
                case 'slug_en':
                    this.applySlug(result);
                    break;
                case 'desc':
                    this.applyExcerpt(result);
                    break;
                case 'tags':
                    this.showResult('<b>建议标签:</b><br>' + result);
                    break;
                case 'rewrite':
                case 'polish':
                case 'continue':
                case 'expand':
                    this.showReplaceableResult(result);
                    break;
                default:
                    this.showResult(result);
            }
        },
        
        showTitleOptions(result) {
            const lines = result.split('\n').filter(l => l.trim().length > 0);
            
            if (lines.length > 1) {
                let html = '<p><b>🤖 AI 提供了多个标题，请选择：</b></p>';
                lines.forEach(line => {
                    const clean = line.replace(/^\d+[\.\、]\s*/, '').replace(/^["']|["']$/g, '').trim();
                    const safe = clean.replace(/'/g, "\\'").replace(/"/g, '&quot;');
                    html += `<button type="button" class="sky-btn-cp" style="margin-bottom:5px;text-align:left" 
                             onclick="SkylineCopilot.editor.setTitle('${safe}')">👉 ${clean}</button>`;
                });
                html += `<button type="button" class="sky-btn-cp" style="margin-top:5px;color:#666" 
                         onclick="jQuery('#sky-res-box').hide()">❌ 取消</button>`;
                this.showResult(html);
            } else {
                const clean = result.replace(/^["']|["']$/g, '');
                if (confirm('建议标题：\n' + clean + '\n\n是否替换？')) {
                    Editor.setTitle(clean);
                }
            }
        },
        
        applySlug(result) {
            let slug = result.replace(/[^a-z0-9-]/g, '-').toLowerCase();
            if (Editor.isGutenberg()) {
                wp.data.dispatch('core/editor').editPost({ slug });
            } else {
                $('#post_name').val(slug);
                $('#edit-slug-box').html(slug);
            }
            alert('Slug 已更新: ' + slug);
        },
        
        applyExcerpt(result) {
            if (Editor.isGutenberg()) {
                wp.data.dispatch('core/editor').editPost({ excerpt: result });
            } else {
                $('#excerpt').val(result);
            }
            alert('摘要已更新');
        },
        
        showReplaceableResult(result) {
            const btns = `<button type="button" class="sky-btn-cp primary" onclick="SkylineCopilot.applyReplace()">🔄 立即替换全文</button>
                          <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.insert()">📥 插入光标处</button>`;
            this.showResult(result.replace(/\n/g, '<br>'), result, btns);
        },
        
        showResult(html, rawText, buttonsHtml) {
            $('#sky-res-content').html(html);
            if (rawText) $('#sky-res-content').data('raw', rawText);
            if (buttonsHtml) {
                $('#sky-res-actions').html(buttonsHtml).show();
            } else {
                $('#sky-res-actions').hide().empty();
            }
            $('#sky-res-box').show();
        },
        
        showLoading() {
            $('#sky-loading').show();
        },
        
        hideLoading() {
            $('#sky-loading').hide();
        }
    };
    
    // ═══ 公开 API ═══
    window.SkylineCopilot = {
        editor: Editor,
        spider: Spider,
        ai: AI,
        
        task(type) { AI.task(type); },
        
        linkNow() {
            const content = Editor.getContent();
            if (!content) return alert(i18n.no_content);
            
            AI.showLoading();
            
            $.post(ajaxUrl, {
                action: 'sky_link_now',
                content: content,
                nonce: nonce
            }).done(res => {
                AI.hideLoading();
                if (res.success) {
                    Editor.setContent(res.data.content);
                    alert(res.data.msg);
                } else {
                    alert(res.data);
                }
            }).fail(() => {
                AI.hideLoading();
                alert('网络请求失败');
            });
        },
        
        genImg() {
            const p = prompt('请输入图片描述:');
            if (!p) return;
            
            AI.showLoading();
            
            $.post(ajaxUrl, {
                action: 'sky_gen_img',
                prompt: p,
                nonce: nonce
            }).done(res => {
                AI.hideLoading();
                if (res.success) {
                    AI.showResult(`<img src="${res.data}" style="max-width:100%">`);
                } else {
                    alert(res.data);
                }
            });
        },
        
        applyReplace() {
            const raw = $('#sky-res-content').data('raw');
            if (raw) {
                if (confirm(i18n.confirm_replace || '确定替换？')) {
                    Editor.setContent(raw);
                }
            }
        },
        
        insert() {
            const raw = $('#sky-res-content').data('raw');
            const html = raw || $('#sky-res-content').html();
            if (html) Editor.insert(html);
        },
        
        seo() {
            let title = $('#title').val();
            if (Editor.isGutenberg()) {
                title = wp.data.select('core/editor').getEditedPostAttribute('title');
            }
            
            $.post(ajaxUrl, {
                action: 'sky_seo_score',
                title: title,
                content: Editor.getContent(),
                nonce: nonce
            }).done(res => {
                if (res.success) {
                    AI.showResult(`<b>SEO 得分: ${res.data.score}</b><br>${res.data.advice.join('<br>')}`);
                }
            });
        }
    };
    
    // Tab 切换
    window.sTab = function(id, elem) {
        $('.sky-cp-tab').removeClass('active');
        if (elem) $(elem).addClass('active');
        $('.sky-cp-pane').hide();
        $('#cp-p-' + id).show();
    };
    
})(jQuery);
