
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Skyline AI Pro v2.0 Enterprise Engine Loaded');

    // 1. 真·健康检查逻辑
    const healthItems = document.querySelectorAll('.sky-health-val');
    
    async function runHealthCheck() {
        // 模拟一个加载状态
        healthItems.forEach(item => {
            item.innerHTML = '<span class="sky-status-loading">⏳ 诊断中...</span>';
        });

        try {
            const response = await fetch(ajaxurl + '?action=skyline_health_check');
            const data = await response.json();
            
            if (data.success) {
                updateHealthUI(data.data);
            } else {
                console.error('Health check failed:', data.data);
            }
        } catch (e) {
            console.error('Network error during health check:', e);
        }
    }

    function updateHealthUI(results) {
        // 假设 results 是 { 'redis': 'ok', 'oss': 'error', ... }
        const mapping = {
            'redis': 'Redis 扩展',
            'curl': 'CURL 扩展',
            'gd': 'GD 库 (去水印)',
            'php': 'PHP 版本'
        };

        healthItems.forEach(item => {
            const label = item.previousElementSibling.innerText;
            for (let key in mapping) {
                if (label.includes(mapping[key])) {
                    const status = results[key];
                    if (status === 'ok') {
                        item.innerHTML = '<span class="sky-status-ok">✓ 正常</span>';
                    } else {
                        item.innerHTML = `<span class="sky-status-error">✗ ${status}</span>`;
                    }
                }
            }
        });
    }

    // 2. 表单保存增强
    const saveBtn = document.querySelector('.sky-save-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            this.innerHTML = '同步中...';
            this.style.opacity = '0.7';
            this.style.pointerEvents = 'none';
        });
    }

    // 3. 启动健康检查
    runHealthCheck();
});
