document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Skyline AI Pro v2.0 Enterprise Engine Loaded');

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