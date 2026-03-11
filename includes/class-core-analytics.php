<?php
/**
 * Lumination Core Analytics
 *
 * Unified usage tracking and admin dashboard for all extensions.
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
 * Unified analytics for all Lumination extensions.
 *
 * @since 1.0.0
 */
class Lumination_Core_Analytics {

	/**
	 * Get the unified usage table name.
	 *
	 * @since 1.0.0
	 * @return string Table name with WP prefix.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lumination_usage';
	}

	/**
	 * Create or upgrade the unified usage table via dbDelta.
	 *
	 * Called on Core activation and on version-check migration.
	 *
	 * @since 1.0.0
	 */
	public static function create_table() {
		global $wpdb;
		$table   = self::get_table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			tool         VARCHAR(50) NOT NULL DEFAULT 'homework_helper',
			page_url     VARCHAR(2048) NOT NULL DEFAULT '',
			input_type   ENUM('text','image','pdf','chat') NOT NULL DEFAULT 'text',
			session_uuid VARCHAR(64) NOT NULL DEFAULT '',
			tokens_in    INT UNSIGNED NOT NULL DEFAULT 0,
			tokens_out   INT UNSIGNED NOT NULL DEFAULT 0,
			credits      DECIMAL(10,4) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY idx_created (created_at),
			KEY idx_tool    (tool),
			KEY idx_page    (page_url(191)),
			KEY idx_type    (input_type)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log a usage event. Called by extensions after each successful API call.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tool         Tool identifier: 'homework_helper', 'chatbot', etc.
	 * @param string $page_url     URL of the page where the request originated.
	 * @param int    $tokens_in    Input token count.
	 * @param int    $tokens_out   Output token count.
	 * @param float  $credits      Credits charged.
	 * @param string $input_type   One of: 'text', 'image', 'pdf', 'chat'. Default 'text'.
	 * @param string $session_uuid Optional API session UUID.
	 */
	public static function log_usage(
		$tool,
		$page_url,
		$tokens_in,
		$tokens_out,
		$credits,
		$input_type = 'text',
		$session_uuid = ''
	) {
		global $wpdb;

		$allowed_types = array( 'text', 'image', 'pdf', 'chat' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, inserts don't need caching.
		$wpdb->insert(
			self::get_table_name(),
			array(
				'tool'         => sanitize_key( $tool ),
				'page_url'     => substr( $page_url, 0, 2048 ),
				'input_type'   => in_array( $input_type, $allowed_types, true ) ? $input_type : 'text',
				'session_uuid' => sanitize_text_field( $session_uuid ),
				'tokens_in'    => (int) $tokens_in,
				'tokens_out'   => (int) $tokens_out,
				'credits'      => (float) $credits,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%f' )
		);
	}

	/**
	 * Enqueue Chart.js only on the Core admin page.
	 *
	 * Hooked to admin_enqueue_scripts.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_admin_scripts( $hook ) {
		if ( 'tools_page_lumination-core' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'lumination-core-chart-js',
			LUMINATION_CORE_URL . 'assets/js/vendor/chart.umd.min.js',
			array(),
			'4.4.4',
			true
		);
	}

	/**
	 * Render the analytics tab content (called by Core Settings tab registry).
	 *
	 * @since 1.0.0
	 */
	public static function render_analytics_tab() {
		self::render_analytics_core( 'lumination-core', 'analytics' );
	}

	/**
	 * Core analytics rendering logic.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $page_slug Page slug for form hidden field.
	 * @param string|null $tab       Tab slug for form hidden field.
	 */
	private static function render_analytics_core( $page_slug, $tab = null ) {
		global $wpdb;
		$table = self::get_table_name();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin display filters.
		$days        = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		$tool_filter = isset( $_GET['tool'] ) ? sanitize_key( $_GET['tool'] ) : '';
		$page_filter = isset( $_GET['page_url'] ) ? sanitize_text_field( wp_unslash( $_GET['page_url'] ) ) : '';
		$type_filter = isset( $_GET['input_type'] ) ? sanitize_text_field( wp_unslash( $_GET['input_type'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$where = $wpdb->prepare( 'WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $days );

		if ( $tool_filter ) {
			$where .= $wpdb->prepare( ' AND tool = %s', $tool_filter );
		}
		if ( $page_filter ) {
			$where .= $wpdb->prepare( ' AND page_url LIKE %s', '%' . $wpdb->esc_like( $page_filter ) . '%' );
		}
		if ( $type_filter ) {
			$where .= $wpdb->prepare( ' AND input_type = %s', $type_filter );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
		$daily = $wpdb->get_results(
			"SELECT DATE(created_at) as day, COUNT(*) as requests,
			        SUM(tokens_in) as t_in, SUM(tokens_out) as t_out, SUM(credits) as credits
			 FROM $table $where GROUP BY DATE(created_at) ORDER BY day ASC"
		);
		$top_pages = $wpdb->get_results(
			"SELECT page_url, COUNT(*) as requests, SUM(tokens_in + tokens_out) as total_tokens
			 FROM $table $where GROUP BY page_url ORDER BY requests DESC LIMIT 20"
		);
		$by_type = $wpdb->get_results(
			"SELECT input_type, COUNT(*) as requests, SUM(tokens_in + tokens_out) as total_tokens
			 FROM $table $where GROUP BY input_type ORDER BY requests DESC"
		);
		$totals = $wpdb->get_row(
			"SELECT COUNT(*) as requests, SUM(tokens_in) as t_in, SUM(tokens_out) as t_out, SUM(credits) as credits
			 FROM $table $where"
		);
		$all_pages = $wpdb->get_col( "SELECT DISTINCT page_url FROM $table ORDER BY page_url ASC LIMIT 200" );
		$all_tools = $wpdb->get_col( "SELECT DISTINCT tool FROM $table ORDER BY tool ASC" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter

		$chart_labels   = wp_json_encode( array_map( function ( $r ) { return $r->day; }, $daily ) );
		$chart_tokens   = wp_json_encode( array_map( function ( $r ) { return (int) $r->t_in + (int) $r->t_out; }, $daily ) );
		$chart_requests = wp_json_encode( array_map( function ( $r ) { return (int) $r->requests; }, $daily ) );
		?>

		<form method="get" style="margin-bottom: 20px; display: flex; gap: 12px; align-items: end; flex-wrap: wrap;">
			<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
			<?php if ( $tab ) : ?>
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
			<?php endif; ?>

			<div>
				<label for="lum-days"><strong><?php esc_html_e( 'Period', 'lumination-core' ); ?></strong></label><br>
				<select name="days" id="lum-days">
					<?php foreach ( array( 7, 14, 30, 60, 90 ) as $d ) : ?>
						<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $days, $d ); ?>>
							<?php
							/* translators: %d: number of days */
							echo esc_html( sprintf( _n( '%d day', '%d days', $d, 'lumination-core' ), $d ) );
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<?php if ( count( $all_tools ) > 1 ) : ?>
			<div>
				<label for="lum-tool"><strong><?php esc_html_e( 'Extension', 'lumination-core' ); ?></strong></label><br>
				<select name="tool" id="lum-tool">
					<option value=""><?php esc_html_e( 'All extensions', 'lumination-core' ); ?></option>
					<?php foreach ( $all_tools as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tool_filter, $t ); ?>>
							<?php echo esc_html( ucwords( str_replace( '_', ' ', $t ) ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php endif; ?>

			<div>
				<label for="lum-type"><strong><?php esc_html_e( 'Input Type', 'lumination-core' ); ?></strong></label><br>
				<select name="input_type" id="lum-type">
					<option value=""><?php esc_html_e( 'All types', 'lumination-core' ); ?></option>
					<option value="text" <?php selected( $type_filter, 'text' ); ?>><?php esc_html_e( 'Text', 'lumination-core' ); ?></option>
					<option value="image" <?php selected( $type_filter, 'image' ); ?>><?php esc_html_e( 'Image', 'lumination-core' ); ?></option>
					<option value="pdf" <?php selected( $type_filter, 'pdf' ); ?>><?php esc_html_e( 'PDF', 'lumination-core' ); ?></option>
					<option value="chat" <?php selected( $type_filter, 'chat' ); ?>><?php esc_html_e( 'Chat', 'lumination-core' ); ?></option>
				</select>
			</div>

			<div>
				<label for="lum-page"><strong><?php esc_html_e( 'Page URL', 'lumination-core' ); ?></strong></label><br>
				<select name="page_url" id="lum-page">
					<option value=""><?php esc_html_e( 'All pages', 'lumination-core' ); ?></option>
					<?php foreach ( $all_pages as $p ) : ?>
						<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $page_filter, $p ); ?>>
							<?php echo esc_html( strlen( $p ) > 80 ? '...' . substr( $p, -77 ) : $p ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div><button type="submit" class="button"><?php esc_html_e( 'Filter', 'lumination-core' ); ?></button></div>
		</form>

		<!-- Summary cards -->
		<div style="display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap;">
			<?php
			$cards = array(
				array( __( 'Requests', 'lumination-core' ),   number_format_i18n( $totals->requests ?? 0 ) ),
				array( __( 'Tokens In', 'lumination-core' ),  number_format_i18n( $totals->t_in ?? 0 ) ),
				array( __( 'Tokens Out', 'lumination-core' ), number_format_i18n( $totals->t_out ?? 0 ) ),
				array( __( 'Credits', 'lumination-core' ),    number_format( $totals->credits ?? 0, 2 ) ),
			);
			foreach ( $cards as $card ) :
			?>
				<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px 24px;min-width:140px;">
					<div style="font-size:24px;font-weight:600;"><?php echo esc_html( $card[1] ); ?></div>
					<div style="color:#646970;font-size:13px;"><?php echo esc_html( $card[0] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<!-- Chart canvas -->
		<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin-bottom:24px;">
			<canvas id="lum-chart" height="80"></canvas>
		</div>

		<!-- By Input Type -->
		<h2><?php esc_html_e( 'By Input Type', 'lumination-core' ); ?></h2>
		<table class="widefat striped" style="margin-bottom:24px;">
			<thead><tr>
				<th><?php esc_html_e( 'Type', 'lumination-core' ); ?></th>
				<th style="width:100px"><?php esc_html_e( 'Requests', 'lumination-core' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'Total Tokens', 'lumination-core' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( $by_type ) : foreach ( $by_type as $row ) : ?>
					<tr>
						<td><?php echo esc_html( ucfirst( $row->input_type ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row->requests ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row->total_tokens ) ); ?></td>
					</tr>
				<?php endforeach; else : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No data yet.', 'lumination-core' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>

		<!-- Top Pages -->
		<h2><?php esc_html_e( 'Top Pages', 'lumination-core' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Page URL', 'lumination-core' ); ?></th>
				<th style="width:100px"><?php esc_html_e( 'Requests', 'lumination-core' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'Total Tokens', 'lumination-core' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( $top_pages ) : foreach ( $top_pages as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $row->page_url ); ?>" target="_blank"><?php echo esc_html( $row->page_url ); ?></a></td>
						<td><?php echo esc_html( number_format_i18n( $row->requests ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row->total_tokens ) ); ?></td>
					</tr>
				<?php endforeach; else : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No data yet.', 'lumination-core' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php
		// Chart init — output directly because this runs inside the render
		// callback, after admin_enqueue_scripts has already fired.
		// Chart.js loads in the footer; DOMContentLoaded fires after all
		// synchronous scripts (including footer scripts) have executed.
		$label_tokens   = esc_js( __( 'Total Tokens', 'lumination-core' ) );
		$label_requests = esc_js( __( 'Requests', 'lumination-core' ) );
		$label_y_tokens = esc_js( __( 'Tokens', 'lumination-core' ) );
		$label_y_reqs   = esc_js( __( 'Requests', 'lumination-core' ) );
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			if (typeof Chart === 'undefined') { return; }
			var ctx = document.getElementById('lum-chart');
			if (!ctx) { return; }
			new Chart(ctx.getContext('2d'), {
				type: 'bar',
				data: {
					labels: <?php echo $chart_labels; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output. ?>,
					datasets: [
						{
							label: '<?php echo $label_tokens; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above. ?>',
							data: <?php echo $chart_tokens; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output. ?>,
							backgroundColor: 'rgba(108,92,231,0.6)',
							borderRadius: 4,
							yAxisID: 'y'
						},
						{
							label: '<?php echo $label_requests; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above. ?>',
							data: <?php echo $chart_requests; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output. ?>,
							type: 'line',
							borderColor: '#e17055',
							backgroundColor: 'rgba(225,112,85,0.1)',
							fill: true,
							tension: 0.3,
							yAxisID: 'y1'
						}
					]
				},
				options: {
					responsive: true,
					interaction: { mode: 'index', intersect: false },
					scales: {
						y: {
							position: 'left',
							title: { display: true, text: '<?php echo $label_y_tokens; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above. ?>' },
							beginAtZero: true
						},
						y1: {
							position: 'right',
							title: { display: true, text: '<?php echo $label_y_reqs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above. ?>' },
							beginAtZero: true,
							grid: { drawOnChartArea: false }
						}
					}
				}
			});
		});
		</script>
		<?php
	}
}
