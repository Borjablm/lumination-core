<?php
/**
 * Lumination Core Math Rendering
 *
 * Shared MathJax 3 + math-renderer.js (Protect→Parse→Restore) support.
 * Any extension that renders Lumination API responses containing LaTeX
 * should call Lumination_Core_Math::enqueue() in its wp_enqueue_scripts hook.
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
 * MathJax integration and math-renderer registration.
 *
 * Extensions call Lumination_Core_Math::enqueue( $dependent_handle ) to opt in.
 *
 * @since 1.0.0
 */
class Lumination_Core_Math {

	/**
	 * Whether the wp_head config hook has already been registered.
	 *
	 * @var bool
	 */
	private static $head_hooked = false;

	/**
	 * Register MathJax and math-renderer.js scripts (does not enqueue them).
	 *
	 * Called once on wp_enqueue_scripts. Extensions call enqueue() to
	 * actually load them on a given page.
	 *
	 * @since 1.0.0
	 */
	public static function register_scripts() {
		// Only load locally if not already registered by another plugin (e.g. Simple MathJax).
		if ( ! wp_script_is( 'mathjax', 'registered' ) ) {
			wp_register_script(
				'mathjax',
				LUMINATION_CORE_URL . 'assets/js/vendor/mathjax/tex-chtml.js',
				array(),
				'3.2.2',
				true // Load in footer so config runs first in wp_head.
			);
		}

		wp_register_script(
			'lumination-core-math-renderer',
			LUMINATION_CORE_URL . 'assets/js/math-renderer.js',
			array( 'mathjax' ),
			LUMINATION_CORE_VERSION,
			true
		);
	}

	/**
	 * Enqueue MathJax and math-renderer.js for an extension.
	 *
	 * Extensions call this inside their own wp_enqueue_scripts callback.
	 * Safe to call from multiple extensions — scripts are registered once.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dependent_handle Handle of the extension script that depends on math-renderer.
	 *                                 Used to add math-renderer as a dependency.
	 *                                 Pass empty string to just enqueue without adding a dependency.
	 */
	public static function enqueue( $dependent_handle = '' ) {
		wp_enqueue_script( 'mathjax' );
		wp_enqueue_script( 'lumination-core-math-renderer' );

		// Hook MathJax config into wp_head exactly once across all extensions.
		if ( ! self::$head_hooked ) {
			add_action( 'wp_head', array( __CLASS__, 'output_config' ) );
			self::$head_hooked = true;
		}
	}

	/**
	 * Output the MathJax window.MathJax configuration in <head>.
	 *
	 * Must run BEFORE MathJax script loads in the footer.
	 * Uses the wp_head hook (NOT wp_add_inline_script which runs too late).
	 *
	 * @since 1.0.0
	 */
	public static function output_config() {
		?>
		<script id="lumination-core-mathjax-config">
		window.MathJax = {
			tex: {
				inlineMath: [['$', '$'], ['\\(', '\\)']],
				displayMath: [['$$', '$$'], ['\\[', '\\]']],
				processEscapes: true,
				processEnvironments: true
			},
			chtml: {
				scale: 1,
				minScale: 0.5,
				mtextInheritFont: true,
				merrorInheritFont: true
			},
			startup: {
				pageReady: function() {
					return MathJax.startup.defaultPageReady();
				}
			}
		};
		</script>
		<?php
	}
}
