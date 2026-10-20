/* global vmsosForm */
( function () {
	'use strict';

	if ( typeof vmsosForm === 'undefined' ) {
		return;
	}

	function serialise( form ) {
		const data = {};
		new FormData( form ).forEach( function ( value, key ) {
			data[ key ] = value;
		} );
		data.source = form.dataset.source || 'form';
		return data;
	}

	function bind( form ) {
		const status = form.querySelector( '.vmsos-form__status' );
		const submit = form.querySelector( '.vmsos-form__submit' );

		form.addEventListener( 'submit', async function ( event ) {
			event.preventDefault();

			if ( ! form.checkValidity() ) {
				form.reportValidity();
				return;
			}

			submit.disabled = true;
			status.textContent = vmsosForm.strings.sending;

			try {
				const response = await fetch( vmsosForm.endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( serialise( form ) ),
				} );

				const payload = await response.json().catch( function () {
					return null;
				} );

				if ( ! response.ok ) {
					throw new Error( ( payload && payload.message ) || vmsosForm.strings.error );
				}

				form.reset();
				status.textContent = form.dataset.success || '';
				status.className = 'vmsos-form__status is-ok';
			} catch ( error ) {
				status.textContent = error.message || vmsosForm.strings.error;
				status.className = 'vmsos-form__status is-error';
			} finally {
				submit.disabled = false;
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.vmsos-form' ).forEach( bind );
	} );
} )();
