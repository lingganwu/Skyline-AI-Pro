// 前台悬浮助手（Skyline Copilot）。
// 欢迎语与推荐问题只在浏览器里显示，不发给 API、不计入聊天限流；
// 点推荐问题才会走正常发送流程（skySend），与手动输入完全一致。
var SKY_WELCOME_TEXT = '你好！我是灵感屋 AI 助手，可以帮你找案例、找模型、找施工图，也可以聊聊景观设计。';
var SKY_SUGGESTIONS = ['中式庭院案例有哪些？', 'SU 模型怎么下载？', '施工图节点详图怎么找？', '效果图渲染教程有吗？'];
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

    fetch(sky_ajax.ajax_url, {
        method: 'POST',
        body: fd
    }).then(r => r.json()).then(d => {
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
            aiDiv.textContent = d.data;
            b.appendChild(aiDiv);
        }
        b.scrollTop = b.scrollHeight;
        skySending = false;
        i.disabled = false;
    }).catch(() => {
        var loadingNode = document.getElementById('sky-loading');
        if(loadingNode) loadingNode.textContent = '网络错误';
        skySending = false;
        i.disabled = false;
    });
}
