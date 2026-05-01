=== Skyline AI Pro ===
Contributors: LingGanWu
Tags: ai, deepseek, content-automation, seo, productivity
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

灵感屋 (lgwu.net) 专属智能运营中台。集成顶尖 AI 模型，助力内容创作自动化。

== Description ==

Skyline AI Pro 是一款为专业内容创作者设计的 WordPress 智能中台。它不仅仅是一个 AI 写作助手，而是一个完整的内容生产管线。

== Features ==

* **多模型集成**：深度集成 DeepSeek V3, GPT-4, Claude 3 等顶尖大模型。
* **可视化无感同步**：强大的图片同步系统，支持智能去水印与图片处理。
* **AI 智能润色**：一键优化错别字、语法错误，提升文章专业度。
* **Redis 智能缓存**：利用 Redis 极大提升 AI 响应速度，减轻服务器压力。
* **SEO 自动化**：AI 自动生成吸引人的标题、英文 Slug 及 SEO 摘要。
* **前台智能助手**：可自定义的看板娘助手，实时响应访客咨询。

== Installation ==

1. Upload the `skyline-ai-pro` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to the 'Skyline Pro' settings page to configure your API keys.

== Changelog ==

= 2.0.1 =
* 安全加固：所有 AJAX 端点添加 Nonce 验证和速率限制
* 性能优化：实现多层配置缓存（内存 → Object Cache → Transient → 数据库）
* 性能优化：图片同步使用批量数据库查询
* 代码规范：所有用户可见字符串添加国际化支持
* 代码规范：修复版本号不一致问题
* UI/UX：Copilot 面板移至外部 JS/CSS 文件

= 2.0.0 =
* 企业级重构：毛玻璃效果 UI、侧边栏导航
* 集成 Redis 缓存与 OSS 云存储
* 新增 SEO 诊断与批量处理功能

= 1.4.1 =
* Refactored helper functions into `Skyline_Utils` class for better architecture.
* Updated plugin versioning.
* Improved code organization for WP store compliance.

= 1.3.2 =
* Initial release of the "Perfect Collection" version.
