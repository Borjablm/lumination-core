=== Lumination Core ===
Contributors: luminationteam
Tags: lumination, ai, core, api, analytics
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Shared infrastructure for Lumination extensions — API gateway, analytics dashboard, MathJax rendering, and admin settings panel.

== Description ==

Lumination Core is the foundation plugin required by all Lumination feature extensions. It provides:

* **API Gateway** — A single authenticated connection to the Lumination AI API. Extensions never handle credentials directly.
* **Analytics Dashboard** — Unified usage tracking across all installed extensions, with a built-in admin chart.
* **Admin Settings Panel** — A tabbed Tools → Lumination page. Extensions register their own tabs via a simple hook.
* **MathJax Rendering** — Bundled MathJax 3.2.2 with a shared math renderer. Extensions opt in with one method call.
* **Security Utilities** — File upload validation, per-user rate limiting, base64 sanitization, and capability gating.

= For Developers =

Lumination Core exposes a public PHP API for extensions:

* `Lumination_Core_API::request( $endpoint, $body )` — Make authenticated API calls.
* `Lumination_Core_Analytics::log_usage( $tool, ... )` — Log usage events.
* `Lumination_Core_Math::enqueue( $handle )` — Activate MathJax on the current page.
* `Lumination_Core_Security::validate_file_upload( $file )` — Validate uploaded files.
* `Lumination_Core_Settings::register_tab( $tab )` — Add a tab to the admin panel.

See the [extension development guide](https://lumination.ai) for full documentation.

= Available Extensions =

* **Lumination AI Homework Helper** — Step-by-step AI solutions for math and science problems, with image upload support.

== Installation ==

1. Upload the `lumination-core` folder to `/wp-content/plugins/`.
2. Activate **Lumination Core** via the Plugins screen.
3. Go to **Tools → Lumination** and enter your API key and base URL.
4. Install and activate any Lumination extension plugin.

== Frequently Asked Questions ==

= Do I need this plugin if I only use one Lumination extension? =

Yes. All Lumination extensions require Lumination Core. Install and activate Core first, then install the extension.

= Where do I get an API key? =

Visit [lumination.ai](https://lumination.ai) to sign up and obtain your API key.

= Is my API key stored securely? =

Your API key is stored in the WordPress options table using the standard WordPress settings API. It is never exposed in JavaScript or public HTML.

= Can I build my own extension? =

Yes — see the extension development guide linked above. The public API is stable and documented.

== Changelog ==

= 1.0.0 =
* Initial release.
* API gateway with configurable base URL.
* Unified analytics table shared across all extensions.
* Dynamic admin tab registry.
* MathJax 3.2.2 bundled locally.
* Security utilities: file validation, rate limiting, base64 sanitization.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
