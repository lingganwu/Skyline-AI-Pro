=== Skyline AI Pro ===
Contributors: LingGanWu
Tags: ai, deepseek, content-automation, seo, productivity
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.1
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

= 2.1.1 =
* 修复：审核遗留低优先级项全修——内容预览改 DOM 建节点防 XSS（N-A1）、手动单张同步补排除域名检查（N-A2）、英文 Slug 不再带中文人设（N-D2）、聊天每日上限按本地天重置（N-D3）、WebP 原图删失败保留重试记录（N-D5）
* 修复：生图端点给安全默认值、不再静默回退其他厂商地址（L8）、压缩质量说明挪进 schema（L15）、压缩不可用警告每天只记一次（L2）、WebP 路径拼接补 basename（L30 同款）
* 修复：重新保存已发布文章可复活因连续失败满 3 次而停止的自动 SEO（“下次保存时重试”的承诺兑现）；接口报错原样带出错误体里的 message（error.message / error 字符串 / 顶层 message），不再只报 Unknown API Error
* 修复：版本号统一为 2.1.1（README 徽标、admin.js 日志）

= 2.1.0 =
* 新功能：聊天面板美化（欢迎语、推荐问题、正在输入动画）、图片转 WebP（含批量转换旧图工具）、图片同步自动/手动切换开关、看板娘提示气泡移到右侧并改紫蓝渐变
* 严重修复：页面缓存不再饿死 wp-cron（init 优先级 0→20）、“保存所有配置”不再假成功、数据看板 API 错误计数接通
* 修复：9 个摆设设置项全部实现或下线（系统人设、排除域名、禁用 Emoji/oEmbeds/XML-RPC、文件名哈希化等）
* 修复：版本号三处统一为 2.1.0，静态资源改走插件版本号（不再被 WordPress 版本号钉死）
* 全面审核 61 项全部处理完毕（详见 SKYLINE-全面审核报告）

= 2.0.1 =
* 安全加固：所有 AJAX 端点添加 Nonce 验证和速率限制
* 性能优化：实现多层配置缓存（内存 → Object Cache → Transient → 数据库）
* 性能优化：图片同步使用批量数据库查询
* UI/UX：删除未被引用的 Copilot 外部文件（assets/js/copilot.js、admin/views/copilot.php），Copilot 面板仍为 add_copilot_box() 内联实现

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
