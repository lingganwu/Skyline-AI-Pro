// 前台悬浮助手（Skyline Copilot）。
// 欢迎语与推荐问题只在浏览器里显示，不发给 API、不计入聊天限流；
// 点推荐问题才会走正常发送流程（skySend），与手动输入完全一致。
var SKY_WELCOME_TEXT = '你好！我是本站的 AI 助手，可以帮你找文章、找资源，也可以聊聊相关问题。';
var SKY_SUGGESTIONS = ['这个插件能帮我做什么？', '如何写一篇好文章？', '怎么生成文章配图？', '如何补全文章的 SEO 信息？'];
var skyWelcomeShown = false;

setTimeout(function(){
    var t = document.getElementById('sky-waifu-tips');
    if(t) {
        t.classList.remove('show');
        setTimeout(()=>t.style.display='none', 400);
    }
}, 120000);

// 发送中标志：连续点发送或连按回车不再并发多条请求；输入框在发送期间禁用。
var skySending = false;

// 前台聊天请求客户端超时：60 秒。WP 端 wp_remote_post 是 30 秒、
// EdgeOne 回源 60 秒，60 秒仍无响应基本是链路卡住，不再无限等下去。
var SKY_CHAT_TIMEOUT_MS = 60000;

// 聊天请求失败时显示独立错误气泡（.sky-msg.sky-error）：报错不再改写
// loading 节点的文字，避免把错误伪装成 AI 回复（与 d.success=false
// 的失败分支同一样式）。
function skyShowChatError(text) {
    var b = document.getElementById('sky-chat-msgs');
    if (!b) return;
    var loadingNode = document.getElementById('sky-loading');
    if (loadingNode) loadingNode.remove();
    var errDiv = document.createElement('div');
    errDiv.className = 'sky-msg sky-error';
    errDiv.textContent = text;
    b.appendChild(errDiv);
    b.scrollTop = b.scrollHeight;
}

// 首次打开面板时显示欢迎语 + 推荐问题（整个页面生命周期只显示一次）。
// 打开面板本身不发任何请求；聊天区已有内容时不再重复插入。
function showSkyWelcome() {
    if (skyWelcomeShown) return;
    var b = document.getElementById('sky-chat-msgs');
    if (!b) { skyWelcomeShown = true; return; }
    if (b.childNodes.length > 0) { skyWelcomeShown = true; return; }
    skyWelcomeShown = true;

    var w = document.createElement('div');
    w.className = 'sky-msg ai';
    w.textContent = SKY_WELCOME_TEXT;
    b.appendChild(w);

    var sug = document.createElement('div');
    sug.className = 'sky-suggest';
    SKY_SUGGESTIONS.forEach(function(q) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'sky-suggest-btn';
        btn.textContent = q;
        btn.onclick = function() { skyAskSuggestion(q); };
        sug.appendChild(btn);
    });
    b.appendChild(sug);
}

// 点推荐问题：填入输入框并直接发送（走正常 skySend 流程，计入限流）。
function skyAskSuggestion(q) {
    var i = document.getElementById('sky-chat-in');
    if (!i) return;
    i.value = q;
    skySend();
}

function toggleSkyChat(){
    var b = document.getElementById('sky-chat-box');
    var t = document.getElementById('sky-waifu-tips');
    if(b.style.display==='flex') {
        // 关闭面板：气泡重新出现。之前这里是永久 display:none，120 秒定时器
        // 藏掉气泡后也没有任何方式让它再出现，访客第二次打开面板时连气泡都没有。
        b.style.display='none';
        if(t) { t.style.display=''; t.classList.add('show'); }
        return;
    }
    if(t) t.style.display='none';
    b.style.display='flex';
    showSkyWelcome();
    document.getElementById('sky-chat-in').focus();
}

// AI 回复 Markdown 渲染（SkyMarkdown.render）。
// 轻量手写、无第三方依赖：先转义 HTML 再转换 Markdown，AI 输出里的
// <script>、<img onerror> 等只会原样显示成文本，绝不会执行。
// 只用于 AI 回复气泡（.sky-msg.ai）：用户输入、欢迎语、报错文案仍用
// textContent，绝不走这里。
function skyMdEscape(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// 行内转换：行内代码 → Markdown 链接 → 裸网址 → 加粗 → 斜体。
// 代码与链接先占位，避免里面的 *、[、] 被当成 Markdown 处理；
// 链接只认 http(s)/相对路径，javascript:/data:/vbscript: 一律只留文字。
function skyMdInline(s) {
    var codes = [], links = [];
    s = s.replace(/`([^`\n]+)`/g, function (m, c) {
        codes.push('<code>' + c + '</code>');
        return '\u0001' + (codes.length - 1) + '\u0001';
    });
    s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (m, text, url) {
        if (/^(javascript|data|vbscript):/i.test(url)) return text;
        links.push('<a href="' + url + '" target="_blank" rel="noopener">' + text + '</a>');
        return '\u0002' + (links.length - 1) + '\u0002';
    });
    // 裸网址自动变链接：末尾的中英文标点不算进链接。
    s = s.replace(/https?:\/\/[^\s<>"'\u0001\u0002]+/g, function (m) {
        var trail = '';
        var url = m.replace(/[.,;:!?。、，；：！？）)]+$/, function (p) { trail = p; return ''; });
        return '<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>' + trail;
    });
    s = s.replace(/\*\*([^*]+?)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/\*([^*]+?)\*/g, '<em>$1</em>');
    s = s.replace(/\u0001(\d+)\u0001/g, function (m, i) { return codes[+i]; });
    s = s.replace(/\u0002(\d+)\u0002/g, function (m, i) { return links[+i]; });
    return s;
}

// 块级转换：``` 代码块、##/### 标题、段落（空行分段）、-/ * 无序列表、
// 1. 有序列表、--- 分隔线。代码块先占位，块内不做任何行内转换。
function skyRenderMarkdown(md) {
    if (md == null) return '';
    var text = skyMdEscape(String(md).replace(/\r\n?/g, '\n'));
    var codeBlocks = [];
    text = text.replace(/(^|\n)```[^\n]*\n([\s\S]*?)(?:```|$)/g, function (m, pre, code) {
        codeBlocks.push('<pre><code>' + code.replace(/\n+$/, '') + '</code></pre>');
        return pre + '\n' + '\u0000' + (codeBlocks.length - 1) + '\u0000' + '\n';
    });
    var out = [], buf = [], lines = text.split('\n'), i = 0;
    function flushPara() {
        if (!buf.length) return;
        out.push('<p>' + buf.map(skyMdInline).join('<br>') + '</p>');
        buf = [];
    }
    function flushList(tag, items) {
        out.push('<' + tag + '>' + items.map(function (it) {
            return '<li>' + skyMdInline(it) + '</li>';
        }).join('') + '</' + tag + '>');
    }
    function trimLine(l) { return l.replace(/^\s+|\s+$/g, ''); }
    while (i < lines.length) {
        var line = trimLine(lines[i]);
        if (!line) { flushPara(); i++; continue; }
        var mBlock = line.match(/^\u0000(\d+)\u0000$/);
        if (mBlock) { flushPara(); out.push(codeBlocks[+mBlock[1]]); i++; continue; }
        var mHead = line.match(/^(#{2,3})\s+(.*)$/);
        if (mHead) {
            flushPara();
            out.push('<h' + mHead[1].length + '>' + skyMdInline(mHead[2]) + '</h' + mHead[1].length + '>');
            i++; continue;
        }
        if (/^-{3,}$/.test(line)) { flushPara(); out.push('<hr>'); i++; continue; }
        var mUl = line.match(/^[-*]\s+(.*)$/);
        if (mUl) {
            flushPara();
            var ulItems = [];
            while (i < lines.length) {
                var lu = trimLine(lines[i]).match(/^[-*]\s+(.*)$/);
                if (!lu) break;
                ulItems.push(lu[1]); i++;
            }
            flushList('ul', ulItems);
            continue;
        }
        var mOl = line.match(/^\d+\.\s+(.*)$/);
        if (mOl) {
            flushPara();
            var olItems = [];
            while (i < lines.length) {
                var lo = trimLine(lines[i]).match(/^\d+\.\s+(.*)$/);
                if (!lo) break;
                olItems.push(lo[1]); i++;
            }
            flushList('ol', olItems);
            continue;
        }
        buf.push(line);
        i++;
    }
    flushPara();
    return out.join('\n');
}

// 导出给 Node 测试直接调用；浏览器里挂 window.SkyMarkdown。
// 注意 waifu.js 是浏览器脚本：module 在浏览器里不存在，window 在
// Node 里不存在，两边都做 typeof 守卫，别一加载就报错。
var SkyMarkdown = { render: skyRenderMarkdown };
if (typeof window !== 'undefined') { window.SkyMarkdown = SkyMarkdown; }
if (typeof module !== 'undefined' && module.exports) { module.exports = SkyMarkdown; }

function skySend(){
    // 发送中直接返回：连续点发送、连按回车、点推荐问题都走这里，不再并发多条。
    if (skySending) return;
    var i = document.getElementById('sky-chat-in'),
        m = i.value.trim(),
        b = document.getElementById('sky-chat-msgs');

    if(!m) return;
    skySending = true;
    i.disabled = true;

    var userDiv = document.createElement('div');
    userDiv.className = 'sky-msg user';
    userDiv.textContent = m;
    b.appendChild(userDiv);
    i.value = '';
    b.scrollTop = b.scrollHeight;

    var fd = new FormData();
    fd.append('action', 'sky_chat_front');
    fd.append('msg', m);
    fd.append('_ajax_nonce', sky_ajax.nonce);

    // “正在输入…”三个点动画（纯 CSS，不发请求；收到回复后移除）。
    var ld = document.createElement('div');
    ld.className = 'sky-msg ai sky-typing';
    ld.id = 'sky-loading';
    for (var k = 0; k < 3; k++) ld.appendChild(document.createElement('span'));
    b.appendChild(ld);
    b.scrollTop = b.scrollHeight;

    // 客户端超时：60 秒仍无响应就 abort，不再无限等下去；超时后显示
    // “请求超时”，不再笼统报“网络错误”。
    var skyController = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var skyTimeoutId = null;
    if (skyController) {
        skyTimeoutId = setTimeout(function() { skyController.abort(); }, SKY_CHAT_TIMEOUT_MS);
    }

    fetch(sky_ajax.ajax_url, {
        method: 'POST',
        body: fd,
        signal: skyController ? skyController.signal : undefined
    }).then(function(r) {
        if (skyTimeoutId) clearTimeout(skyTimeoutId);
        // 先看 HTTP 状态再解析 JSON：EdgeOne 504/502、PHP Fatal 500、
        // nonce 校验失败的 403（响应体是纯文本 -1）都不是 JSON，
        // 旧代码直接 r.json()，解析抛错后一律显示“网络错误”，无法定位。
        if (!r.ok) {
            var httpErr = new Error('HTTP ' + r.status);
            httpErr.isSkyHttp = true;
            if (r.status === 504 || r.status === 502) {
                httpErr.message = '服务器响应超时（HTTP ' + r.status + '），请稍后再试或换个简短的问题。';
            } else if (r.status === 403) {
                httpErr.message = '请求被拒绝（HTTP 403），请刷新页面后再试。';
            } else {
                httpErr.message = '服务器返回异常（HTTP ' + r.status + '），请稍后再试。';
            }
            throw httpErr;
        }
        return r.json().then(function(d) { return d; }, function() {
            // 200 但响应体不是 JSON：nonce 失败的 -1、PHP Fatal 的 HTML 等。
            var badErr = new Error('bad json');
            badErr.isSkyHttp = true;
            badErr.message = '服务器返回了无法解析的响应，请刷新页面后再试。';
            throw badErr;
        });
    }).then(function(d) {
        var loadingNode = document.getElementById('sky-loading');
        if(loadingNode) loadingNode.remove();
        // 报错不再伪装成 AI 回复：失败时用独立的错误样式气泡，而不是
        // '.sky-msg.ai' 里写 'Error: ...'，让限流/超长/API 失败一眼可辨。
        var errDiv = null;
        if (!d.success) {
            errDiv = document.createElement('div');
            errDiv.className = 'sky-msg sky-error';
            errDiv.textContent = (d && d.data) ? String(d.data) : '请求失败，请稍后再试。';
            b.appendChild(errDiv);
        } else {
            var aiDiv = document.createElement('div');
            aiDiv.className = 'sky-msg ai';
            aiDiv.innerHTML = SkyMarkdown.render(d.data);
            b.appendChild(aiDiv);
        }
        b.scrollTop = b.scrollHeight;
        skySending = false;
        i.disabled = false;
    }).catch(function(err) {
        if (skyTimeoutId) clearTimeout(skyTimeoutId);
        var msg;
        if (err && err.name === 'AbortError') {
            msg = '请求超时（60 秒无响应），请稍后再试或换个简短的问题。';
        } else if (err && err.isSkyHttp) {
            msg = err.message;
        } else {
            // fetch 本身抛错：断网、DNS 解析失败、连接被重置等——
            // 只有这种情况才显示“网络错误”。
            msg = '网络错误，请检查网络连接后重试。';
        }
        skyShowChatError(msg);
        skySending = false;
        i.disabled = false;
    });
}
