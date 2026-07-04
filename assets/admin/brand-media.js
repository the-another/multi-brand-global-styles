/**
 * Media pickers for the Brand edit screen: the identity logo/icon pickers
 * and the Image Replacements repeating rows. Plain JS over wp.media — no
 * build step; this file ships as-is.
 */
( function () {
	'use strict';

	function openPicker( picker ) {
		var frame = window.wp.media( {
			title: picker.querySelector( '.mbgs-media-select' ).textContent,
			multiple: false,
			library: { type: 'image' },
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var sizes = attachment.sizes || {};
			var thumb = ( sizes.thumbnail || sizes.full || { url: attachment.url } ).url;

			picker.querySelector( 'input[type="hidden"]' ).value = String( attachment.id );

			var img = picker.querySelector( 'img' );
			img.src = thumb;
			img.style.display = '';

			var remove = picker.querySelector( '.mbgs-media-remove' );
			if ( remove ) {
				remove.style.display = '';
			}
		} );

		frame.open();
	}

	function clearPicker( picker ) {
		picker.querySelector( 'input[type="hidden"]' ).value = '0';
		picker.querySelector( 'img' ).style.display = 'none';

		var remove = picker.querySelector( '.mbgs-media-remove' );
		if ( remove ) {
			remove.style.display = 'none';
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( target.classList.contains( 'mbgs-media-select' ) ) {
			openPicker( target.closest( '.mbgs-media-picker' ) );
			return;
		}

		if ( target.classList.contains( 'mbgs-media-remove' ) ) {
			clearPicker( target.closest( '.mbgs-media-picker' ) );
			return;
		}

		if ( target.classList.contains( 'mbgs-image-map-add' ) ) {
			var box = target.closest( '.inside' ) || document;
			var template = box.querySelector( '.mbgs-image-map-template' );
			box.querySelector( '.mbgs-image-map-rows' ).appendChild(
				template.content.cloneNode( true )
			);
			return;
		}

		if ( target.classList.contains( 'mbgs-image-map-remove' ) ) {
			target.closest( '.mbgs-image-map-row' ).remove();
		}
	} );
} )();
