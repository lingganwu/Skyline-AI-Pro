<?php if (!defined('ABSPATH')) exit; ?>
<div class="sky-cp-wrap">
    <button type="button" class="sky-btn-cp primary" id="btn-spider-start" onclick="SkylineCopilot.spider.init()">
        🕷️ <?php _e('一键同步图片', 'skyline-ai-pro'); ?>
    </button>
    
    <div id="sky-spider-status" style="display:none;">
        <div class="sky-sp-header">
            <span><?php _e('进度', 'skyline-ai-pro'); ?>: <span id="sp-cur">0</span>/<span id="sp-total">0</span></span>
            <span id="sp-msg" style="color:#6366f1;"><?php _e('就绪', 'skyline-ai-pro'); ?></span>
        </div>
        <div class="sky-sp-progress-bg">
            <div class="sky-sp-progress-bar" id="sky-sp-bar"></div>
        </div>
        <div id="sky-sp-preview"></div>
        <div id="sky-sp-log"></div>
    </div>
    
    <div class="sky-cp-tabs">
        <div class="sky-cp-tab active" onclick="sTab('create', this)"><?php _e('创作', 'skyline-ai-pro'); ?></div>
        <div class="sky-cp-tab" onclick="sTab('rewrite', this)"><?php _e('润色', 'skyline-ai-pro'); ?></div>
        <div class="sky-cp-tab" onclick="sTab('seo', this)"><?php _e('SEO', 'skyline-ai-pro'); ?></div>
        <div class="sky-cp-tab" onclick="sTab('tools', this)"><?php _e('工具', 'skyline-ai-pro'); ?></div>
    </div>
    
    <div id="cp-p-create" class="sky-cp-pane active">
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.task('title')">
            <i>📖</i> <?php _e('优化标题', 'skyline-ai-pro'); ?>
        </button>
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.task('outline')">
            <i>📑</i> <?php _e('生成大纲', 'skyline-ai-pro'); ?>
        </button>
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.task('continue')">
            <i>✍️</i> <?php _e('续写段落', 'skyline-ai-pro'); ?>
        </button>
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.task('expand')">
            <i>➕</i> <?php _e('扩写内容', 'skyline-ai-pro'); ?>
        </button>
    </div>
    
    <div id="cp-p-rewrite" class="sky-cp-pane">
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.task('rewrite')">
            <i>♻️</i> <?php _e('伪原创重写', 'skyline-ai-pro'); ?>
        </button>
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.task('polish')">
            <i>✨</i> <?php _e('智能润色', 'skyline-ai-pro'); ?>
        </button>
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.task('shorten')">
            <i>➖</i> <?php _e('精简缩写', 'skyline-ai-pro'); ?>
        </button>
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.task('trans')">
            <i>🌐</i> <?php _e('中英互译', 'skyline-ai-pro'); ?>
        </button>
    </div>
    
    <div id="cp-p-seo" class="sky-cp-pane">
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.task('desc')">
            <i>📝</i> <?php _e('生成摘要', 'skyline-ai-pro'); ?>
        </button>
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.task('tags')">
            <i>🏷️</i> <?php _e('提取标签', 'skyline-ai-pro'); ?>
        </button>
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.linkNow()">
            <i>🔗</i> <?php _e('自动内链', 'skyline-ai-pro'); ?>
        </button>
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.task('slug_en')">
            <i>🔤</i> <?php _e('英文 Slug', 'skyline-ai-pro'); ?>
        </button>
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.seo()">
            <i>🩺</i> <?php _e('SEO 诊断', 'skyline-ai-pro'); ?>
        </button>
    </div>
    
    <div id="cp-p-tools" class="sky-cp-pane">
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.genImg()">
            <i>🎨</i> <?php _e('AI 生成配图', 'skyline-ai-pro'); ?>
        </button>
        <button type="button" class="sky-btn-cp" onclick="SkylineCopilot.insert()">
            <i>📥</i> <?php _e('插入生成结果', 'skyline-ai-pro'); ?>
        </button>
    </div>
    
    <div id="sky-loading" style="display:none; color:#666; margin:10px 0; font-size:12px; text-align:center;">
        <span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span>
        <span id="sky-loading-txt"><?php _e('AI 思考中...', 'skyline-ai-pro'); ?></span>
    </div>
    
    <div id="sky-res-box" style="display:none;">
        <div id="sky-res-content"></div>
        <div id="sky-res-actions" class="sky-res-actions" style="display:none;"></div>
    </div>
</div>
