/**
 * Lumination Core Admin Color Picker
 *
 * Initialises WordPress color pickers on the Appearance tab.
 *
 * @package LuminationCore
 * @since 1.1.0
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		$('.lumination-color-picker').wpColorPicker();
	});
})(jQuery);
