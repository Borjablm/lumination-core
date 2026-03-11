<?php
/**
 * Lumination Core Bootstrap
 *
 * Singleton that loads all Core dependencies and registers WordPress hooks.
 * Extensions must NOT instantiate this class directly — it is loaded by the
 * main plugin file via lumination_core().
 *
 * @package    LuminationCore
 * @since      1.0.0
 * @license    GPL-3.0-or-later
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core bootstrap singleton.
 *
 * @since 1.0.0
 */
class Lumination_Core {

	/**
	 * Singleton instance.
	 *
	 * @var Lumination_Core|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance, creating it if necessary.
	 *
	 * @since 1.0.0
	 * @return Lumination_Core
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use get_instance().
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->register_hooks();
	}

	/**
	 * Require all Core class files.
	 *
	 * @since 1.0.0
	 */
	private function load_dependencies() {
		$includes = LUMINATION_CORE_DIR . 'includes/';

		require_once $includes . 'class-core-security.php';
		require_once $includes . 'class-core-api.php';
		require_once $includes . 'class-core-analytics.php';
		require_once $includes . 'class-core-math.php';
		require_once $includes . 'class-core-settings.php';
	}

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private function register_hooks() {
		// Activation.
		register_activation_hook( LUMINATION_CORE_FILE, array( $this, 'activate' ) );

		// Admin settings.
		add_action( 'admin_init', array( 'Lumination_Core_Settings', 'init' ) );
		add_action( 'admin_menu', array( 'Lumination_Core_Settings', 'add_menu' ) );

		// Admin scripts (Analytics chart + color picker).
		add_action( 'admin_enqueue_scripts', array( 'Lumination_Core_Analytics', 'enqueue_admin_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( 'Lumination_Core_Settings', 'enqueue_admin_color_picker' ) );

		// Frontend math scripts registration (extensions call Lumination_Core_Math::enqueue()).
		add_action( 'wp_enqueue_scripts', array( 'Lumination_Core_Math', 'register_scripts' ) );

		// DB version check on every admin load.
		add_action( 'admin_init', array( $this, 'check_db_version' ) );
	}

	/**
	 * Plugin activation: create DB table, store version.
	 *
	 * @since 1.0.0
	 */
	public function activate() {
		Lumination_Core_Analytics::create_table();
		update_option( 'lumination_core_db_version', LUMINATION_CORE_VERSION );
		update_option( 'lumination_core_activated', time() );
	}

	/**
	 * Run DB migrations when the stored version is behind the plugin version.
	 *
	 * Called on every admin_init so that upgrades via FTP are handled automatically.
	 *
	 * @since 1.0.0
	 */
	public function check_db_version() {
		$stored = get_option( 'lumination_core_db_version', '0.0.0' );
		if ( version_compare( $stored, LUMINATION_CORE_VERSION, '<' ) ) {
			Lumination_Core_Analytics::create_table(); // dbDelta handles ALTER.
			update_option( 'lumination_core_db_version', LUMINATION_CORE_VERSION );
		}
	}
}
