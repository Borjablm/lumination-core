<?php
/**
 * Lumination Core Settings
 *
 * Admin panel with dynamic tab registry. Extensions register their own
 * tabs via the 'lumination_core_admin_tabs_init' action.
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
 * Admin settings page with extensible tab registry.
 *
 * @since 1.0.0
 */
class Lumination_Core_Settings {

	/**
	 * Registered tabs (Core + extensions).
	 *
	 * @var array[]
	 */
	private static $tabs = array();

	/**
	 * Initialize Core settings registration. Called on admin_init.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		// ── API settings ──
		register_setting(
			'lumination_core_settings',
			'lumination_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_section(
			'lumination_core_api_section',
			__( 'API Connection', 'lumination-core' ),
			array( __CLASS__, 'render_api_section_description' ),
			'lumination-core'
		);

		add_settings_field(
			'lumination_api_key',
			__( 'API Key', 'lumination-core' ),
			array( __CLASS__, 'render_api_key_field' ),
			'lumination-core',
			'lumination_core_api_section'
		);

		// ── Appearance (color) settings ──
		$color_options = array(
			'lumination_primary_color',
			'lumination_primary_hover_color',
			'lumination_button_text_color',
			'lumination_tool_background_color',
			'lumination_tool_text_color',
		);
		foreach ( $color_options as $option_name ) {
			register_setting(
				'lumination_appearance_settings',
				$option_name,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_hex_color',
					'default'           => '',
				)
			);
		}

		// Let extensions register their own settings groups on the same hook.
		do_action( 'lumination_core_settings_init' );
	}

	// -------------------------------------------------------------------------
	// Color API for extensions
	// -------------------------------------------------------------------------

	/**
	 * Get a saved brand color.
	 *
	 * Extensions should call this instead of reading options directly.
	 *
	 * @since 1.1.0
	 *
	 * @param string $name Color key: 'primary', 'primary_hover', 'button_text', 'tool_background', or 'tool_text'.
	 * @return string Hex color (e.g. '#ff0000') or empty string if not set.
	 */
	public static function get_color( $name ) {
		$map = array(
			'primary'         => 'lumination_primary_color',
			'primary_hover'   => 'lumination_primary_hover_color',
			'button_text'     => 'lumination_button_text_color',
			'tool_background' => 'lumination_tool_background_color',
			'tool_text'       => 'lumination_tool_text_color',
		);

		if ( ! isset( $map[ $name ] ) ) {
			return '';
		}

		return get_option( $map[ $name ], '' );
	}

	// -------------------------------------------------------------------------
	// Admin assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue color picker assets on the Lumination admin page.
	 *
	 * @since 1.1.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_admin_color_picker( $hook_suffix ) {
		if ( 'tools_page_' . LUMINATION_CORE_ADMIN_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script(
			'lumination-core-admin-color-picker',
			LUMINATION_CORE_URL . 'assets/js/admin-color-picker.js',
			array( 'wp-color-picker', 'jquery' ),
			LUMINATION_CORE_VERSION,
			true
		);
	}

	// -------------------------------------------------------------------------
	// Menu & page
	// -------------------------------------------------------------------------

	/**
	 * Register the Tools → Lumination admin menu page.
	 *
	 * @since 1.0.0
	 */
	public static function add_menu() {
		add_management_page(
			__( 'Lumination', 'lumination-core' ),
			__( 'Lumination', 'lumination-core' ),
			'manage_options',
			LUMINATION_CORE_ADMIN_SLUG,
			array( __CLASS__, 'render_main_page' )
		);
	}

	/**
	 * Register a tab from an extension.
	 *
	 * Call this inside a callback hooked to 'lumination_core_admin_tabs_init'.
	 *
	 * @since 1.0.0
	 *
	 * @param array $tab {
	 *   @type string   $id       URL-safe slug, e.g. 'homework-helper'.
	 *   @type string   $label    Translated display label.
	 *   @type callable $callback Function or [$class, 'method'] that renders the tab body.
	 *   @type int      $priority Sort order. Lower = further left. Default 10.
	 * }
	 */
	public static function register_tab( array $tab ) {
		$defaults       = array( 'priority' => 10 );
		self::$tabs[]   = array_merge( $defaults, $tab );

		// Keep tabs sorted by priority.
		usort( self::$tabs, function( $a, $b ) {
			return $a['priority'] <=> $b['priority'];
		} );
	}

	/**
	 * Get all registered tabs (Core tabs already included).
	 *
	 * @since 1.0.0
	 * @return array[]
	 */
	public static function get_tabs() {
		return self::$tabs;
	}

	/**
	 * Render the main admin page with dynamic tab bar.
	 *
	 * @since 1.0.0
	 */
	public static function render_main_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lumination-core' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'config';

		// Pre-register Core's own tabs before firing the extension hook.
		self::$tabs = array(
			array(
				'id'       => 'config',
				'label'    => __( 'API Configuration', 'lumination-core' ),
				'callback' => array( __CLASS__, 'render_config_tab' ),
				'priority' => 0,
			),
			array(
				'id'       => 'appearance',
				'label'    => __( 'Appearance', 'lumination-core' ),
				'callback' => array( __CLASS__, 'render_appearance_tab' ),
				'priority' => 3,
			),
			array(
				'id'       => 'analytics',
				'label'    => __( 'Usage Analytics', 'lumination-core' ),
				'callback' => array( 'Lumination_Core_Analytics', 'render_analytics_tab' ),
				'priority' => 5,
			),
		);

		/**
		 * Fires when the admin page is about to render, allowing extensions to register tabs.
		 *
		 * Extensions must hook into this action to call Lumination_Core_Settings::register_tab().
		 *
		 * @since 1.0.0
		 */
		do_action( 'lumination_core_admin_tabs_init' );

		$tabs = self::get_tabs();

		// Fallback: if active_tab not in any registered tab, use first tab.
		$valid_ids = array_column( $tabs, 'id' );
		if ( ! in_array( $active_tab, $valid_ids, true ) ) {
			$active_tab = $valid_ids[0] ?? 'config';
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab ) : ?>
					<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . LUMINATION_CORE_ADMIN_SLUG . '&tab=' . $tab['id'] ) ); ?>"
					   class="nav-tab <?php echo esc_attr( $active_tab === $tab['id'] ? 'nav-tab-active' : '' ); ?>">
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php
			foreach ( $tabs as $tab ) {
				if ( $tab['id'] === $active_tab && is_callable( $tab['callback'] ) ) {
					call_user_func( $tab['callback'] );
					break;
				}
			}
			?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Config tab
	// -------------------------------------------------------------------------

	/**
	 * Render the API Configuration tab.
	 *
	 * @since 1.0.0
	 */
	public static function render_config_tab() {
		settings_errors( 'lumination_core_messages' );
		?>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'lumination_core_settings' );
			do_settings_sections( 'lumination-core' );
			submit_button( __( 'Save Settings', 'lumination-core' ) );
			?>
		</form>

		<?php self::render_test_connection_card(); ?>
		<?php
	}

	/**
	 * Render API section description.
	 *
	 * @since 1.0.0
	 */
	public static function render_api_section_description() {
		?>
		<p>
			<?php
			printf(
				/* translators: %s: Lumination website URL */
				esc_html__( 'Enter your Lumination API key. Get it from %s.', 'lumination-core' ),
				'<a href="https://lumination.ai" target="_blank" rel="noopener">lumination.ai</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the API key field.
	 *
	 * @since 1.0.0
	 */
	public static function render_api_key_field() {
		$value = get_option( 'lumination_api_key', '' );
		?>
		<input
			type="password"
			id="lumination_api_key"
			name="lumination_api_key"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			autocomplete="off"
		/>
		<p class="description"><?php esc_html_e( 'Your Lumination API key.', 'lumination-core' ); ?></p>
		<?php
	}

	/**
	 * Render the Test Connection card.
	 *
	 * @since 1.0.0
	 */
	public static function render_test_connection_card() {
		?>
		<div class="card" style="margin-top: 20px;">
			<h2><?php esc_html_e( 'Test API Connection', 'lumination-core' ); ?></h2>
			<p><?php esc_html_e( 'Click the button below to verify your API credentials.', 'lumination-core' ); ?></p>
			<button type="button" class="button" id="lumination-test-connection">
				<?php esc_html_e( 'Test Connection', 'lumination-core' ); ?>
			</button>
			<div id="lumination-test-result" style="margin-top: 10px;"></div>
		</div>

		<script>
		jQuery( document ).ready( function ( $ ) {
			$( '#lumination-test-connection' ).on( 'click', function () {
				var $btn    = $( this );
				var $result = $( '#lumination-test-result' );

				$btn.prop( 'disabled', true ).text( '<?php echo esc_js( __( 'Testing…', 'lumination-core' ) ); ?>' );
				$result.html( '' );

				$.ajax( {
					url:  ajaxurl,
					type: 'POST',
					data: {
						action: 'lumination_core_test_connection',
						nonce:  '<?php echo esc_js( wp_create_nonce( 'lumination_core_test_connection' ) ); ?>'
					},
					success: function ( response ) {
						var cls = response.success ? 'notice-success' : 'notice-error';
						$result.html( '<div class="notice ' + cls + ' inline"><p>' + response.data.message + '</p></div>' );
					},
					error: function () {
						$result.html( '<div class="notice notice-error inline"><p><?php echo esc_js( __( 'Request failed.', 'lumination-core' ) ); ?></p></div>' );
					},
					complete: function () {
						$btn.prop( 'disabled', false ).text( '<?php echo esc_js( __( 'Test Connection', 'lumination-core' ) ); ?>' );
					}
				} );
			} );
		} );
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Appearance tab
	// -------------------------------------------------------------------------

	/**
	 * Render the Appearance tab.
	 *
	 * @since 1.1.0
	 */
	public static function render_appearance_tab() {
		settings_errors( 'lumination_appearance_messages' );
		?>
		<div style="max-width: 800px; margin-top: 20px;">
			<form action="options.php" method="post">
				<?php settings_fields( 'lumination_appearance_settings' ); ?>

				<div class="card">
					<h2><?php esc_html_e( 'Brand Colors', 'lumination-core' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Customise the button and accent colours used by all Lumination tools. Leave empty to inherit from your theme.', 'lumination-core' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="lumination_primary_color"><?php esc_html_e( 'Primary Color', 'lumination-core' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="lumination_primary_color"
									name="lumination_primary_color"
									value="<?php echo esc_attr( get_option( 'lumination_primary_color', '' ) ); ?>"
									class="lumination-color-picker"
									data-default-color="#2271b1"
								/>
								<p class="description"><?php esc_html_e( 'Button background and accent color.', 'lumination-core' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="lumination_primary_hover_color"><?php esc_html_e( 'Primary Hover Color', 'lumination-core' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="lumination_primary_hover_color"
									name="lumination_primary_hover_color"
									value="<?php echo esc_attr( get_option( 'lumination_primary_hover_color', '' ) ); ?>"
									class="lumination-color-picker"
									data-default-color="#135e96"
								/>
								<p class="description"><?php esc_html_e( 'Button color on hover.', 'lumination-core' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="lumination_button_text_color"><?php esc_html_e( 'Button Text Color', 'lumination-core' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="lumination_button_text_color"
									name="lumination_button_text_color"
									value="<?php echo esc_attr( get_option( 'lumination_button_text_color', '' ) ); ?>"
									class="lumination-color-picker"
									data-default-color="#ffffff"
								/>
								<p class="description"><?php esc_html_e( 'Text color on buttons.', 'lumination-core' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="lumination_tool_background_color"><?php esc_html_e( 'Tool Background', 'lumination-core' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="lumination_tool_background_color"
									name="lumination_tool_background_color"
									value="<?php echo esc_attr( get_option( 'lumination_tool_background_color', '' ) ); ?>"
									class="lumination-color-picker"
									data-default-color="#ffffff"
								/>
								<p class="description"><?php esc_html_e( 'Background color for tool containers (Homework Helper, Chatbot panel). Leave empty to use white.', 'lumination-core' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="lumination_tool_text_color"><?php esc_html_e( 'Tool Text Color', 'lumination-core' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="lumination_tool_text_color"
									name="lumination_tool_text_color"
									value="<?php echo esc_attr( get_option( 'lumination_tool_text_color', '' ) ); ?>"
									class="lumination-color-picker"
									data-default-color="#222222"
								/>
								<p class="description"><?php esc_html_e( 'Text color inside tool containers. Useful when your theme has light text on a dark background. Leave empty to inherit from theme.', 'lumination-core' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save Colors', 'lumination-core' ) ); ?>
				</div>
			</form>
		</div>
		<?php
	}
}

/**
 * Test connection AJAX handler.
 *
 * @since 1.0.0
 */
function lumination_core_test_connection_ajax() {
	check_ajax_referer( 'lumination_core_test_connection', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Access denied.', 'lumination-core' ) ) );
	}

	$result = Lumination_Core_API::test_connection();

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array( 'message' => __( 'Connection successful! API is working correctly.', 'lumination-core' ) ) );
}
add_action( 'wp_ajax_lumination_core_test_connection', 'lumination_core_test_connection_ajax' );
