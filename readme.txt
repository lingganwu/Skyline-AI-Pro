=== Skyline AI Pro ===
Contributors: LingGanWu
Tags: ai, deepseek, content-automation, seo, productivity
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

灵感屋 (lgwu.net) 专属智能运营中台。集成顶尖 AI 模型，助力内容创作自动化。

== Description ==

Skyline AI Pro 是一款为专业内容创作者设计的 WordPress 智能中台。它不仅仅是一个 AI 写作助手，而是一个完整的内容生产管线。

== Features ==

* **多模型集成**：兼容 OpenAI、DeepSeek、硅基流动等主流大模型。
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

= 2.2.3 =
* 修复：WebP 扫描必现 HTTP 524——扫描改分页（每页 2000 张）+ 20 秒墙钟预算 + 续扫，单次请求不再遍历全部约 53,000 个附件；进度条显示“正在扫描…已扫 X/Y 张”
* 修复：模型厂商下拉是写而不读的空开关——删掉 api_vendor 配置键，改成不入库的厂商预设选择器（选中态仍由 API Endpoint 地址反推）
* 新功能：Prompt 模板库每行加“填入系统人设”按钮（人设已有内容时先确认再覆盖）；性能体检页加“清空页面缓存 / 清空全部 Skyline 缓存”按钮（只清 Redis 层，不碰 EdgeOne）；云端存储页加“上传失败清单”与待删队列的手动重试/取消登记/立即校验
* 新功能：文章页 SEO Meta 输出（默认关闭，仅主题不输出 description/OG 时开启）；对话端点归一化补 /v4 与 /v1/chat，空端点直接报“未配置”不再发空请求
* 修复：内容同步主开关真正生效——关闭后发布自动同步、批量同步、编辑器单张同步全部停止，已排期的自动任务入口再查一次开关；部分成功的 SEO 不再整篇标完成、失败项下次保存重试；直接写库后显式清理该篇页面缓存
* 修复：定时任务回调改到插件主文件加载期直接注册（不再依赖模块实例化），停用插件时清理已排期的单次事件；文案与实现不符处按实现改写（去水印、图片压缩、SVG 开关）

= 2.2.2 =
* 修复：批量卡片全瘫——admin.js 改页头加载（$in_footer=false）。v2.2.1 把批量驱动抽进 admin.js（页脚加载），而三张卡片的内联脚本在正文里直接调 SkyBatchRunner.create()，执行时驱动尚不存在 → ReferenceError，整段内联脚本中断、点击静默失败；页头加载后内联脚本执行前驱动已定义
* 修复：批量/扫描失败文案带 HTTP 状态——403（登录过期或 nonce 失效）、504/超时、500、断网不再混成一句“网络错误”
* 优化：数据看板“插件能力概览”改成功能介绍，不再写当前厂商/模型名（那些是用户可改的设置，在“智能核心”里看）

= 2.2.1 =
* 新功能：批量进度实时显示——WebP 转换、图片同步、SEO 补全共用一个批量进度组件：发每批前显示“正在处理第 N/M 批”，停止立即中断在途请求；失败与跳过的文章逐条列出原因，不再只写“补全失败”
* 新功能：独立的“生图 API Key”（api_img_key），留空时跟随主 API Key；对话与生图分属不同厂商时可各用各的密钥，整站切同一家时留空即可
* 修复：浏览器自动填充不再污染配置——密码框默认禁用（勾选“更改”才启用提交）、autocomplete 提示收紧；URL 字段提交无 scheme 的假值时拒绝保存并保留原值
* 修复：厂商切换不再清空生图配置——切“自定义”时不改任何已存值，切无生图模型的厂商时只清空生图模型名，生图根地址与生图密钥保留
* 修复：对话端点支持只填裸根地址（如 https://www.lgwu.de:3001/v1，以 /v1 结尾时自动补 /chat/completions）；生图根地址留空时界面显示真实生效值
* 优化：Prompt 模板库移到“最近聊天记录”卡片正上方；S1 成本说明的全站篇数改为动态取数

= 2.2.0 =
* 新功能：API Endpoint 改厂商预设下拉——硅基流动、OpenRouter、DeepSeek、OpenAI、智谱AI、Moonshot、通义千问，末位自定义；选定厂商自动填入地址，对话模型、绘图模型联动列出该厂商可用模型（可打字下拉，也能手填新模型）；已存配置按 Endpoint host 反推厂商，未知地址落自定义并保留原值
* 修复：前台聊天“网络错误”不再一句话吞掉所有失败——按 HTTP 状态、超时、断网给可定位文案（504 回源超时、403、60 秒超时等）；根因多为 EdgeOne 12 秒回源超时撞上模型长回复，治本需调大回源超时

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
