/* Marketing Department admin JS.
 * Progressive enhancement only — every screen works without JavaScript.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		// Live AI cost estimate on the run-AI screen.
		var estBox = document.getElementById( 'abcmd-live-estimate' );
		if ( estBox && window.ABCMD ) {
			var form = estBox.closest( 'form' );
			var refresh = function () {
				var bookId = form.querySelector( '[name="book_id"]' );
				var runType = form.querySelector( '[name="run_type"]' );
				var model = form.querySelector( '[name="model"]' );
				if ( ! bookId || ! runType || ! model ) { return; }
				var web = form.querySelector( '[name="web_search"]' );
				var cats = Array.prototype.map.call(
					form.querySelectorAll( '[name="categories[]"]:checked' ),
					function ( el ) { return el.value; }
				);
				estBox.textContent = 'Estimating…';
				fetch( window.ABCMD.restBase + '/ai/estimate', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': window.ABCMD.nonce
					},
					body: JSON.stringify( {
						book_id: parseInt( bookId.value, 10 ),
						run_type: runType.value,
						model: model.value,
						web_search: web ? web.checked : false,
						categories: cats
					} )
				} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
					if ( d && typeof d.cost !== 'undefined' ) {
						estBox.innerHTML = 'Estimated cost: <strong>' + d.currency + ' ' +
							Number( d.cost ).toFixed( 4 ) + '</strong> (~' + d.input_tokens +
							' in / ' + d.output_tokens + ' out tokens)' +
							( d.over_threshold ? ' — <span style="color:#8a1f11">above your confirmation threshold</span>' : '' );
					} else {
						estBox.textContent = 'Estimate unavailable.';
					}
				} ).catch( function () { estBox.textContent = 'Estimate unavailable.'; } );
			};
			form.addEventListener( 'change', refresh );
			refresh();
		}

		// Confirm destructive submits.
		Array.prototype.forEach.call( document.querySelectorAll( '[data-confirm]' ), function ( el ) {
			el.addEventListener( 'submit', function ( e ) {
				if ( ! window.confirm( el.getAttribute( 'data-confirm' ) ) ) {
					e.preventDefault();
				}
			} );
		} );
	} );
}() );
