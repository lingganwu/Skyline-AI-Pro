setTimeout(function(){ 
    var t = document.getElementById('sky-waifu-tips'); 
    if(t) { 
        t.classList.remove('show'); 
        setTimeout(()=>t.style.display='none', 400); 
    } 
}, 120000);

function toggleSkyChat(){ 
    var b = document.getElementById('sky-chat-box'); 
    var t = document.getElementById('sky-waifu-tips'); 
    if(t) t.style.display='none'; 
    b.style.display = (b.style.display==='flex')?'none':'flex'; 
    if(b.style.display==='flex') document.getElementById('sky-chat-in').focus(); 
}

function skySend(){
    var i = document.getElementById('sky-chat-in'),
        m = i.value.trim(),
        b = document.getElementById('sky-chat-msgs'); 
    
    if(!m) return;
    
    b.innerHTML += '<div class="sky-msg user">' + m.replace(/</g,"&lt;") + '</div>';
    i.value = '';
    b.scrollTop = b.scrollHeight;
    
    var fd = new FormData();
    fd.append('action', 'sky_chat_front');
    fd.append('msg', m);
    // 修正：调用 PHP 传来的 sky_ajax 变量
    fd.append('_ajax_nonce', sky_ajax.nonce);
    
    var ld = document.createElement('div'); 
    ld.className = 'sky-msg ai'; 
    ld.id = 'sky-loading'; 
    ld.innerText = '思考中...'; 
    b.appendChild(ld); 
    b.scrollTop = b.scrollHeight;
    
    // 修正：使用正确的接口地址
    fetch(sky_ajax.url, {
        method: 'POST',
        body: fd
    }).then(r => r.json()).then(d => {
        var loadingNode = document.getElementById('sky-loading');
        if(loadingNode) loadingNode.remove();
        b.innerHTML += '<div class="sky-msg ai">' + (d.success ? d.data : 'Error: ' + d.data).replace(/\n/g, '<br>') + '</div>';
        b.scrollTop = b.scrollHeight;
    }).catch(() => { 
        var loadingNode = document.getElementById('sky-loading');
        if(loadingNode) loadingNode.innerText = '网络错误'; 
    });
}