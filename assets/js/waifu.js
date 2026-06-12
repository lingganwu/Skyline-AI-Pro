setTimeout(function(){ 
    var t = document.getElementById('sky-waifu-tips'); 
    if(t) { 
        t.classList.remove('show'); 
        setTimeout(()=>t.style.display='none', 400); 
    } 
}, 120000);

function skyEscapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

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
    
    var ld = document.createElement('div'); 
    ld.className = 'sky-msg ai'; 
    ld.id = 'sky-loading'; 
    ld.textContent = '思考中...'; 
    b.appendChild(ld); 
    b.scrollTop = b.scrollHeight;
    
    fetch(sky_ajax.ajax_url, {
        method: 'POST',
        body: fd
    }).then(r => r.json()).then(d => {
        var loadingNode = document.getElementById('sky-loading');
        if(loadingNode) loadingNode.remove();
        var aiDiv = document.createElement('div');
        aiDiv.className = 'sky-msg ai';
        var msgText = d.success ? d.data : 'Error: ' + d.data;
        aiDiv.textContent = msgText;
        b.appendChild(aiDiv);
        b.scrollTop = b.scrollHeight;
    }).catch(() => { 
        var loadingNode = document.getElementById('sky-loading');
        if(loadingNode) loadingNode.textContent = '网络错误'; 
    });
}
