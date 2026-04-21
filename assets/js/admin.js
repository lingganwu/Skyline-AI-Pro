
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.sky-nav-item');
    const contents = document.querySelectorAll('.sky-tab-content');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            const target = this.getAttribute('href').split('tab=')[1];
            
            tabs.forEach(t => t.classList.remove('active'));
            contents.forEach(c => c.classList.remove('active'));
            
            this.classList.add('active');
            const activeContent = document.getElementById('tab-' + target);
            if(activeContent) activeContent.classList.add('active');
        });
    });
});
