# Skyline AI Pro

这是一个用于 WordPress 的智能助手插件，由 **灵感屋 (lgwu.net)** 开发。

> 集成 DeepSeek AI、Redis 高速缓存、可视化蜘蛛采集、S3 云存储分发与前台智能客服。

## 功能概览

- DeepSeek AI 智能写作与对话支持  
- Redis 高速缓存，加速请求与响应  
- 可视化蜘蛛采集（内容抓取与处理）  
- S3 / OSS 云存储分发  
- 前台智能客服与自动回复  
- 后台可视化设置界面

## 目录结构

skyline-ai-pro-full/
├── skyline-ai-pro.php # 插件主文件（入口）
├── skyline-ai.php # AI 核心逻辑与工具函数
├── skyline-turbo.php # 加速 / Turbo 相关功能
├── skyline-redis.php # Redis 缓存支持
├── skyline-spider.php # 蜘蛛采集模块
├── skyline-oss.php # OSS / S3 云存储模块
├── README.md # 本说明文档（仅用于 GitHub 托管）
└── .gitignore # Git 仓库忽略规则


> **注意：** 为了保持与线上站点行为一致，本仓库中 PHP 文件的功能与 UI 完全未改动，仅新增了 `README.md` 和 `.gitignore` 以便在 GitHub 上进行版本管理和协作。

## 安装方式

1. 将整个 `skyline-ai-pro-full` 文件夹放入 WordPress 的插件目录：  

   `wp-content/plugins/`

2. 登录 WordPress 后台，在 **插件** 页面启用 **Skyline AI Pro** 插件。  
3. 在后台菜单中找到 **Skyline AI**，进入设置页面配置：
   - API Key
   - 模型选择（如：DeepSeek-V3）
   - Redis / OSS 等相关参数

## 开发与协作建议

在 GitHub 上协作时，推荐遵循以下约定：

- 所有改动通过 Pull Request 提交，方便代码审查（Code Review）
- 禁止直接提交包含真实敏感信息（如真实 API Key、密码、访问密钥等）
- 新增功能时：
  - 尽量拆分为独立模块文件，保持主文件简洁
  - 保持与现有代码风格、注释风格一致
- 如需修改 UI 或功能，请在 PR 中清晰说明变更点及影响范围

## 版本信息

- 当前版本：`0.0.2`
- Text Domain：`skyline-ai-pro`

如需调整版本号，请同步更新：

- `skyline-ai-pro.php` 文件头部的 `Version` 注释
- `SKYLINE_AI_VERSION` 常量

## 许可证（License）

> ⚠️ 本仓库尚未显式指定开源许可证。  
> 如需开源或对外协作，请根据你的实际需求添加合适的 License 文件（例如 MIT、GPL-2.0+、GPL-3.0 等）。
