<?php
/**
 * Lumination Core
 *
 * Shared infrastructure plugin for all Lumination extensions.
 * Provides a single API gateway, unified analytics, admin settings panel,
 * MathJax rendering, and common security utilities.
 *
 * @package           LuminationCore
 * @author            Lumination Team
 * @license           GPL-3.0-or-later
 * @link              https://lumination.ai
 * @copyright         2026 Lumination Team
 *
 * @wordpress-plugin
 * Plugin Name:       Lumination Core
 * Description:       Shared infrastructure for Lumination extensions. Provides the API gateway, analytics, admin settings, MathJax rendering, and security utilities required by all Lumination feature plugins.
 * Version:           1.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Lumination Team
 * Author URI:        https://lumination.ai
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       lumination-core
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ────────────────────────────────────────────────────────────────

define( 'LUMINATION_CORE_VERSION',      '1.2.0' );
define( 'LUMINATION_CORE_FILE',         __FILE__ );
define( 'LUMINATION_CORE_DIR',          plugin_dir_path( __FILE__ ) );
define( 'LUMINATION_CORE_URL',          plugin_dir_url( __FILE__ ) );
define( 'LUMINATION_CORE_ADMIN_SLUG',   'lumination-core' );
// AI Tutor API base. Includes the /api/v1 prefix; endpoints are appended (e.g. '/tutor').
// To move off staging later, drop the "stage." subdomain (or override via the
// 'lumination_core_api_base_url' filter) — the rest of the path stays the same.
define( 'LUMINATION_API_BASE_URL',      'https://stage.ai-tutor.ai/api/v1' );

// ── Auto-update via GitHub releases ──────────────────────────────────────────

require_once LUMINATION_CORE_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
	'https://github.com/Borjablm/lumination-core/',
	__FILE__,
	'lumination-core'
);

// ── Bootstrap ────────────────────────────────────────────────────────────────

require_once LUMINATION_CORE_DIR . 'includes/class-lumination-core.php';

/**
 * Return the Core singleton instance.
 *
 * Extensions can call lumination_core() after checking is_active() to confirm
 * that Core is loaded, but they must not depend on the return value — use the
 * static class methods (Lumination_Core_API::request() etc.) instead.
 *
 * @since 1.1.0
 * @return Lumination_Core
 */
function lumination_core() {
	return Lumination_Core::get_instance();
}

// Initialise on plugins_loaded at priority 10, before extensions (priority 20).
add_action( 'plugins_loaded', 'lumination_core', 10 );
