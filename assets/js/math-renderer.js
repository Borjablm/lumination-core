/**
 * Math Renderer - LaTeX/MathJax Support
 *
 * Based on Azvai Experiments pattern: Protect → Parse → Restore
 * Prevents markdown parser from stripping backslashes in LaTeX.
 *
 * @package Lumination
 * @since 1.0.0
 */

(function(window) {
	'use strict';

	/**
	 * Protect LaTeX blocks before markdown parsing
	 *
	 * Replaces LaTeX blocks with unique placeholders to prevent
	 * markdown parser from stripping backslashes.
	 *
	 * Based on Azvai Experiments pattern with proper delimiter handling.
	 *
	 * @param {string} text Markdown text with LaTeX
	 * @returns {object} { text: protected text, blocks: LaTeX blocks }
	 */
	function protectLatex(text) {
		const blocks = [];

		function stash(match) {
			const id = 'XLATEXBLOCK' + blocks.length + 'XLATEXEND';
			blocks.push(match);
			return id;
		}

		// Protect display math first (greedy, multi-line)
		text = text.replace(/\$\$([\s\S]*?)\$\$/g, stash);  // $$...$$
		text = text.replace(/\\\[([\s\S]*?)\\\]/g, stash);  // \[...\]

		// Protect inline math (single $, not adjacent to another $)
		text = text.replace(/\\\(([\s\S]*?)\\\)/g, stash);  // \(...\)
		// Match $ not preceded/followed by $ (negative lookahead/behind)
		text = text.replace(/(?<!\$)\$(?!\$)([^\n$]+?)\$(?!\$)/g, stash);

		return { text: text, blocks: blocks };
	}

	/**
	 * Restore LaTeX blocks after markdown parsing
	 *
	 * Replaces placeholders with original LaTeX strings.
	 *
	 * @param {string} html HTML with placeholders
	 * @param {array} blocks Array of LaTeX blocks
	 * @returns {string} HTML with restored LaTeX
	 */
	function restoreLatex(html, blocks) {
		for (var i = 0; i < blocks.length; i++) {
			// Use split().join() to replace ALL occurrences (not just first)
			var placeholder = 'XLATEXBLOCK' + i + 'XLATEXEND';
			html = html.split(placeholder).join(blocks[i]);
		}
		return html;
	}

	/**
	 * Ensure MathJax is ready
	 *
	 * MathJax is loaded by WordPress (via wp_enqueue_script).
	 * This function waits for MathJax.startup.promise to complete.
	 *
	 * @returns {Promise} Resolves when MathJax is ready
	 */
	function ensureMathJax() {
		// MathJax should be loaded by WordPress, but check anyway
		if (!window.MathJax) {
			console.error('Lumination: MathJax not loaded by WordPress');
			return Promise.reject(new Error('MathJax not loaded'));
		}

		// Wait for MathJax startup to complete
		if (window.MathJax.startup && window.MathJax.startup.promise) {
			return window.MathJax.startup.promise.catch(function(err) {
				console.error('MathJax startup failed:', err);
				return Promise.resolve(); // Don't block rendering on MathJax errors
			});
		}

		// Already ready
		if (window.MathJax.typesetPromise) {
			return Promise.resolve();
		}

		// Fallback: wait a bit for MathJax to initialize
		return new Promise(function(resolve) {
			var attempts = 0;
			var maxAttempts = 50; // 5 seconds max
			var checkInterval = setInterval(function() {
				attempts++;
				if (window.MathJax && window.MathJax.typesetPromise) {
					clearInterval(checkInterval);
					resolve();
				} else if (attempts >= maxAttempts) {
					clearInterval(checkInterval);
					console.error('Lumination: MathJax typesetPromise not available after timeout');
					resolve(); // Resolve anyway to not block rendering
				}
			}, 100);
		});
	}

	/**
	 * Render markdown with math support
	 *
	 * Full rendering pipeline:
	 * 1. Protect LaTeX blocks
	 * 2. Parse markdown with marked.js
	 * 3. Sanitize HTML with DOMPurify
	 * 4. Restore LaTeX blocks
	 * 5. Typeset math with MathJax
	 *
	 * @param {HTMLElement} container Container element
	 * @param {string} markdownText Markdown text with LaTeX
	 * @returns {Promise} Resolves when rendering is complete
	 */
	async function render(container, markdownText) {
		try {
			// Check dependencies
			if (typeof marked === 'undefined') {
				console.error('Lumination: marked.js not loaded');
				container.innerHTML = '<p>Error: Markdown parser not available</p>';
				return;
			}

			if (typeof DOMPurify === 'undefined') {
				console.error('Lumination: DOMPurify not loaded');
				container.innerHTML = '<p>Error: HTML sanitizer not available</p>';
				return;
			}

			// Configure marked
			if (typeof marked.setOptions === 'function') {
				marked.setOptions({
					gfm: true,
					breaks: true
				});
			}

			// 1. Protect LaTeX
			const protectedText = protectLatex(markdownText || '');

			// 2. Parse markdown
			const html = marked.parse(protectedText.text);

			// 3. Sanitize HTML
			const clean = DOMPurify.sanitize(html, {
				USE_PROFILES: { html: true }
			});

			// 4. Restore LaTeX
			const restored = restoreLatex(clean, protectedText.blocks);

			// 5. Update DOM
			container.innerHTML = restored;

			// 6. Typeset math
			await ensureMathJax();
			if (window.MathJax && window.MathJax.typesetPromise) {
				await window.MathJax.typesetPromise([container]).catch(function(err) {
					console.error('MathJax typeset error:', err);
				});
			}

		} catch (error) {
			console.error('Lumination render error:', error);
			container.innerHTML = '<p>Error rendering content</p>';
		}
	}

	// Export to global scope
	window.LuminationMathRenderer = {
		protectLatex: protectLatex,
		restoreLatex: restoreLatex,
		ensureMathJax: ensureMathJax,
		render: render
	};

})(window);
