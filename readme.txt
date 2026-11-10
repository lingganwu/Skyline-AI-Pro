=== Skyline AI Pro ===
Contributors: lingganwu
Tags: ai, content-automation, seo, productivity, image-optimization
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.8
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

An AI operations hub for WordPress: a visitor chat assistant, AI image generation, automatic SEO completion, image syncing, Redis caching, and cloud storage.

== Description ==

Skyline AI Pro is an AI operations hub for WordPress. It brings together the day-to-day AI tools a content site needs — chat, image generation, SEO, image handling, caching, and cloud storage — in one plugin, behind one settings screen.

**What it does**

* **AI chat (Copilot):** a front-end chat panel that answers visitor questions using the AI provider and model you configure. Replies are rendered as formatted Markdown (headings, lists, code blocks, links).
* **AI image generation:** generate images from a text prompt with the image model of your chosen provider.
* **Automatic SEO:** when a post is saved, the plugin can fill in missing SEO fields automatically — an English slug, a summary/excerpt, and tags. Article pages can also output SEO meta (description / Open Graph) when your theme does not already do so; this is off by default.
* **Image sync (Content Sync):** downloads external images used in your posts, uploads them to your own WordPress media library, and rewrites the post to use the local copy, so articles no longer depend on third-party image links. Sync can run automatically on publish, manually in bulk, or for a single image from the editor.
* **Redis caching:** configuration and AI responses can be cached through Redis/Object Cache to reduce repeated API calls and speed up the site.
* **Cloud storage (OSS/COS):** uploaded media can be synced to Tencent Cloud COS (or another S3-compatible object store) so attachments are served from the cloud instead of the server disk.
* **Performance checkup:** a built-in health page that scans your media library for images that can be converted to WebP, checks cache status, and reports site performance items.

The plugin works with any OpenAI-compatible API provider: pick a preset provider (SiliconFlow, OpenRouter, DeepSeek, OpenAI, and others) or enter a custom API endpoint. No provider is hard-coded as the default — you choose your own provider and enter your own API key.

Interface language is Chinese. The admin screens (Dashboard, AI Core, Content Sync, Cloud Storage, Search Optimization, Performance Checkup, System Logs) are in Chinese; this readme is in English for the WordPress.org plugin directory.

== Features ==

* **AI chat assistant for visitors** — a front-end Copilot panel with Markdown-formatted replies, a welcome message, suggested questions, and a typing indicator.
* **AI image generation** — generate images from prompts; a separate image API key can be set when chat and image generation use different providers.
* **Automatic SEO completion** — English slugs, summaries, and tags generated on save, with per-post retry for failed items and batch completion for older posts.
* **Image sync** — external images in posts are downloaded to the media library and the post is rewritten to use the local copy; automatic, bulk, and single-image modes.
* **WebP conversion** — scan the media library and convert images to WebP in paginated batches, with progress and per-item results.
* **Redis caching** — multi-layer configuration cache (memory, Object Cache, transient, database) and cache-clearing controls that only touch the Redis layer.
* **Cloud storage (OSS/COS)** — sync media uploads to Tencent Cloud COS; includes an upload-failure list and a pending-deletion queue with manual retry.
* **Performance checkup** — media scan, cache status, and site health checks in one place.
* **Prompt template library** — reusable system-prompt templates, with one-click fill into the system persona field.
* **Dashboard** — at-a-glance stats for posts, images, cache, and recent AI activity.
* **Provider presets** — one-click presets for popular AI providers, plus a custom endpoint option for any OpenAI-compatible API.

== Installation ==

1. Upload the `skyline-ai-pro` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress admin (Plugins > Add New > Upload Plugin).
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to the 'Skyline Pro' settings page.
4. In the AI Core settings, pick a provider preset (or choose Custom and enter your own API Endpoint), then enter your API Key and choose your chat and image models. Save the settings.
5. Optional: if you use Redis, make sure a Redis object-cache drop-in is active on your site; if you use cloud storage, configure it on the Cloud Storage tab (see "Cloud Storage (Tencent COS / OSS)" below).

== Cloud Storage (Tencent COS / OSS) ==

Enabling OSS/COS cloud storage requires the Tencent Cloud COS PHP SDK, installed via Composer (`qcloud/cos-sdk-v5`, see `composer.json`). The SDK is not bundled with the plugin. If the SDK is not installed, the plugin shows a clear error message on the Cloud Storage settings page and safely degrades — cloud sync is simply unavailable — instead of causing a fatal error.

== Frequently Asked Questions ==

= How do I configure a provider and API key? =

Go to the Skyline Pro settings page, open the AI Core tab, and pick a provider preset (SiliconFlow, OpenRouter, DeepSeek, OpenAI, or others) or choose Custom and enter your own API Endpoint URL. Then enter your API Key and select your chat model and image model. Your key is stored only in your own WordPress database and is only sent to the provider you configured. If chat and image generation use different providers, you can set a separate image API key; leave it empty to reuse the main key.

= Where does my data go? =

See "Privacy and Data Disclosure" below. In short: AI requests go only to the provider you choose, using the API key you enter. Chat history is stored on your own site (at most 50 messages per conversation, with visitor IPs stored as a SHA-256 hash). The plugin itself collects no telemetry and sends nothing to the plugin author.

= How does image sync work? =

When image sync runs (automatically on publish, in bulk, or for a single image), the plugin downloads each external image found in the post, uploads it to your WordPress media library, and rewrites the post content to use the local copy. If cloud storage is configured and enabled, the uploaded file is then synced to your COS/OSS bucket. Original external URLs are not kept in the post once sync succeeds.

= Do I need to install anything extra for OSS/COS cloud storage? =

Yes. Cloud storage needs the Tencent Cloud COS PHP SDK (`qcloud/cos-sdk-v5`) installed via Composer; it is not bundled with the plugin. Without it, the Cloud Storage page shows a clear error and cloud sync stays disabled — the rest of the plugin keeps working.

= What is stored for chat history? =

The plugin stores recent chat messages on your own site so the conversation can continue: the message text, the model used, and a SHA-256 hash of the visitor's IP address (the raw IP is not stored). At most 50 messages are kept per conversation; older messages are discarded. No chat data is sent anywhere except to the AI provider you configured, as part of the chat request itself.

= Why is the admin interface in Chinese? =

The plugin was originally built for a Chinese-language audience, and its admin screens are currently in Chinese. This readme is in English so the plugin can be listed on WordPress.org; an English admin interface is planned for a future release.

= Can I use a custom or self-hosted AI endpoint? =

Yes. Choose the Custom provider preset and enter any OpenAI-compatible API endpoint URL (chat completions and image generation). A bare base URL ending in `/v1` is accepted and the chat path is appended automatically.

== Screenshots ==

1. Dashboard — the overview screen with site stats, plugin capability summary, and recent AI activity.
2. AI Core settings — provider presets, API Endpoint and API Key fields, chat and image model selection, system persona, and the prompt template library above the recent chat history.
3. Content Sync — image sync controls (automatic sync on publish, bulk sync, single-image sync) with live batch progress and per-item success/failure reasons.
4. Front-end Copilot chat panel — the visitor chat widget with Markdown-formatted AI replies, suggested questions, and a typing indicator.
5. Performance Checkup — WebP conversion scan with paginated progress, cache status, and cache-clearing controls.
6. Cloud Storage (OSS) — COS/OSS bucket settings, sync status, upload-failure list, and the pending-deletion queue.

== Privacy and Data Disclosure ==

This plugin does not collect, store, or transmit any telemetry, usage statistics, or personal data to the plugin author or to any third party on its own. Data leaves your site only where you configure it to do so, as described below.

* **AI providers:** AI chat, image generation, and SEO requests are sent to the AI provider you choose in the settings, using the API key you enter. The plugin ships with no provider pre-selected and no built-in API key — you must choose a provider and supply your own key. What the provider does with your prompts and generated content is governed by that provider's own terms and privacy policy; please review them before use:
  * OpenAI — Terms: https://openai.com/policies/terms-of-use — Privacy: https://openai.com/policies/privacy-policy
  * OpenRouter — Terms: https://openrouter.ai/terms — Privacy: https://openrouter.ai/privacy
  * SiliconFlow — https://siliconflow.cn/ (terms and privacy policy are linked from the provider's site)
  * DeepSeek — https://www.deepseek.com/ (terms and privacy policy are linked from the provider's site)
  * If you use a custom endpoint, the data goes to the endpoint you entered, under that service's terms.
* **Chat history:** recent chat messages are stored in your own WordPress database so a conversation can continue across requests. At most 50 messages are kept per conversation. Visitor IP addresses are stored only as a SHA-256 hash, never in plain text. Chat data is sent to your configured AI provider as part of the chat request and nowhere else.
* **Image sync:** when image sync runs, the plugin downloads images referenced in your own posts from their original URLs and uploads them to your media library. No image is downloaded or uploaded except as part of a sync you trigger or enable.
* **Cloud storage (COS/OSS):** media files are uploaded to your Tencent Cloud COS (or S3-compatible) bucket only if you configure cloud storage credentials and enable it. Without configuration, no file is sent to any cloud storage.
* **Caching:** Redis/Object Cache, transients, and local configuration caches live on your own server.
* **No telemetry:** the plugin makes no requests to the plugin author's servers and collects no analytics.

== Support ==

For help, please use the WordPress.org plugin support forum for Skyline AI Pro — that is the default support channel, and questions and answers there help other users too.

You can also contact the author by email at admin@lgwu.net. Please include your WordPress version, PHP version, the plugin version, and the steps to reproduce any problem.

== Changelog ==

= 2.2.8 =
* WordPress.org automated-check remediation: replaced parse_url with wp_parse_url, rename with WP_Filesystem::move, unlink with wp_delete_file, date with wp_date, strip_tags with wp_strip_all_tags, raw filesystem calls with WP_Filesystem methods, escaped all admin output, and removed the deprecated wp_get_sites fallback (87 ERROR + 1 WARNING fixed).
* Spider batch sync and SEO scan + batch now run with a ~20-second wall-clock budget and resume (paged scans, 500 posts per page, per-article deadline in the spider batch), modeled on the WebP paged scan — no more HTTP 554 / network-error timeouts on large sites; every post and image is still processed, only chunked.
= 2.2.7 =
* Security: all `$_POST` / `$_GET` reads now pass through `wp_unslash()` before sanitization (WordPress.org compliance).
* Tests: new assertions cover slashed input for settings save, chat, templates, WebP scan, OSS purge and spider/SEO endpoints.

= 2.2.6 =
* Docs: readme.txt rewritten in English for the WordPress.org directory; admin interface remains Chinese (noted in the readme).
* Removed: the watermark-removal feature has been removed from the plugin.
* Changed: the default provider is now neutral (left blank so you choose your own provider); the default system persona is now a generic version.
* Compliance: plugin header now includes Plugin URI and Author URI.
* Added: GPL-2.0 LICENSE file.

= 2.2.5 =
* Fixed: removed the broken placeholder image assets/images/robot.png (a corrupt file missing its PNG signature byte); the front-end now uses the animated mascot entry instead.
* Compliance: plugin header completed with Requires at least / Requires PHP / License fields; description rewritten in generic wording with no site brand name or version suffix.
* Fixed: missing COS SDK no longer causes a fatal error — cloud storage shows a clear message and degrades safely.
* Added: uninstalling the plugin now cleans up leftover settings and scheduled tasks.
* Improved: admin and readme copy generalized; site-specific wording removed.

= 2.2.4 =
* Added: Markdown rendering for front-end AI replies (headings, lists, code blocks, links).
* Improved: default system persona rewritten from a visitor's perspective — it welcomes visiting designers, students, and enthusiasts and helps them find site resources, instead of describing content-creation work.

= 2.2.3 =
* Fixed: WebP scan no longer hits HTTP 524 — scanning is now paginated (2,000 images per page) with a 20-second wall-clock budget and resume support.
* Fixed: the provider dropdown was a write-only dead switch — the api_vendor option key was removed and replaced with a preset selector that is not stored (selection is inferred from the saved endpoint host).
* Added: "Fill into system persona" button on each prompt template; cache-clear buttons on the Performance page (Redis layer only); upload-failure list and pending-deletion queue actions on the Cloud Storage page.
* Added: article SEO meta output (off by default, only when the theme does not output description/OG tags); chat endpoint normalization adds /v4 and /v1/chat; empty endpoints report "not configured" instead of sending empty requests.
* Fixed: the Content Sync master switch now actually stops automatic, bulk, and single-image sync; partially completed SEO no longer marks a post as done.

= 2.2.2 =
* Fixed: batch cards were completely broken — admin.js now loads in the page header so the batch runner is defined before inline scripts call it.
* Fixed: batch/scan failure messages now include the HTTP status (403, 504/timeout, 500, offline) instead of a generic "network error".
* Improved: the dashboard "capability overview" now describes features instead of naming the current provider/model settings.

= 2.2.1 =
* Added: live batch progress for WebP conversion, image sync, and SEO completion, with per-item failure/skip reasons.
* Added: a separate image API key (api_img_key); empty means "follow the main key".
* Fixed: browser autofill no longer pollutes settings — password fields are disabled until "Change" is checked.
* Fixed: switching providers no longer clears image settings.
* Fixed: chat endpoints accept a bare base URL (auto-appends /chat/completions when it ends in /v1).
* Improved: prompt template library moved directly above the recent chat history card.

= 2.2.0 =
* Added: provider preset dropdown (SiliconFlow, OpenRouter, DeepSeek, OpenAI, and others, plus Custom); selecting a preset fills the endpoint and lists that provider's chat and image models.
* Fixed: front-end chat errors now show actionable messages by HTTP status/timeout instead of a single "network error".

= 2.1.1 =
* Fixed: review leftovers — content preview built via DOM nodes (XSS hardening), single-image sync checks the excluded-domains list, English slugs no longer carry persona text, chat daily limits reset on local day, failed WebP original deletion keeps a retry record.
* Fixed: image endpoint gets a safe default; re-saving a published post revives automatic SEO that had stopped after 3 consecutive failures; API error messages are passed through verbatim.
* Fixed: version number unified to 2.1.1.

= 2.1.0 =
* Added: chat panel polish (welcome message, suggested questions, typing animation), WebP image conversion with a bulk tool, image sync auto/manual switch, mascot bubble restyled.
* Fixed: page cache no longer starves wp-cron; "Save all settings" no longer reports false success; dashboard API error counter connected.
* Fixed: 9 decorative settings either implemented or removed.

= 2.0.1 =
* Security: nonce verification and rate limiting added to all AJAX endpoints.
* Performance: multi-layer configuration cache (memory, Object Cache, transient, database); image sync uses batched database queries.
* UI: removed unreferenced Copilot external files; the Copilot panel remains an inline implementation.

= 2.0.0 =
* Major refactor: glassmorphism UI with sidebar navigation.
* Integrated Redis caching and OSS cloud storage.
* Added SEO diagnostics and batch processing.

= 1.4.1 =
* Refactored helper functions into the `Skyline_Utils` class.
* Updated plugin versioning and code organization for WordPress.org compliance.

= 1.3.2 =
* Initial release.
