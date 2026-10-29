/* global vmsosWidget */
( function () {
	'use strict';

	if ( typeof vmsosWidget === 'undefined' ) {
		return;
	}

	const state = { uuid: '', open: false, busy: false, lastId: 0, poller: null };

	function el( tag, className, text ) {
		const node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function build() {
		const root = document.getElementById( 'vmsos-widget-root' );
		if ( ! root ) {
			return null;
		}

		root.setAttribute( 'data-position', vmsosWidget.position || 'right' );
		root.style.setProperty( '--vmsos-accent', vmsosWidget.accent || '#c9a227' );

		const launcher = el( 'button', 'vmsos-launcher', vmsosWidget.strings.open );
		launcher.setAttribute( 'aria-expanded', 'false' );

		const panel = el( 'div', 'vmsos-panel' );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', vmsosWidget.agent );
		panel.hidden = true;

		const head = el( 'div', 'vmsos-head' );
		head.appendChild( el( 'span', 'vmsos-head__name', vmsosWidget.agent ) );

		const close = el( 'button', 'vmsos-head__close', '×' );
		close.setAttribute( 'aria-label', vmsosWidget.strings.close );
		head.appendChild( close );

		const log = el( 'div', 'vmsos-log' );
		log.setAttribute( 'aria-live', 'polite' );

		const form = el( 'form', 'vmsos-form' );
		const input = el( 'input', 'vmsos-input' );
		input.type = 'text';
		input.placeholder = vmsosWidget.strings.placeholder;
		input.autocomplete = 'off';
		input.maxLength = 1000;

		const honeypot = el( 'input', 'vmsos-hp' );
		honeypot.type = 'text';
		honeypot.name = 'company_website';
		honeypot.tabIndex = -1;
		honeypot.setAttribute( 'aria-hidden', 'true' );

		const send = el( 'button', 'vmsos-send', vmsosWidget.strings.send );
		send.type = 'submit';

		form.append( input, honeypot, send );
		panel.append( head, log, form );
		root.append( launcher, panel );

		return { root, launcher, panel, log, form, input, honeypot, close };
	}

	function bubble( log, role, text ) {
		const node = el( 'div', 'vmsos-msg is-' + role, text );
		log.appendChild( node );
		log.scrollTop = log.scrollHeight;
		return node;
	}

	function init() {
		const ui = build();
		if ( ! ui ) {
			return;
		}

		function toggle( open ) {
			state.open = open;
			ui.panel.hidden = ! open;
			ui.launcher.setAttribute( 'aria-expanded', String( open ) );

			if ( open ) {
				if ( ! ui.log.childElementCount ) {
					bubble( ui.log, 'agent', vmsosWidget.greeting );
				}
				ui.input.focus();
			}
		}

		ui.launcher.addEventListener( 'click', () => toggle( ! state.open ) );
		ui.close.addEventListener( 'click', () => toggle( false ) );

		document.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' === event.key && state.open ) {
				toggle( false );
			}
		} );

		ui.form.addEventListener( 'submit', async function ( event ) {
			event.preventDefault();

			const message = ui.input.value.trim();
			if ( ! message || state.busy ) {
				return;
			}

			state.busy = true;
			ui.input.value = '';
			bubble( ui.log, 'visitor', message );

			const pending = bubble( ui.log, 'agent is-pending', '…' );

			try {
				const response = await fetch( vmsosWidget.endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						message: message,
						uuid: state.uuid,
						page_url: window.location.href,
						company_website: ui.honeypot.value,
					} ),
				} );

				const payload = await response.json();
				const data = payload && payload.data ? payload.data : {};

				state.uuid = data.uuid || state.uuid;

				if ( data.reply ) {
					pending.classList.remove( 'is-pending' );
					pending.textContent = data.reply;
				} else {
					// A human owns the thread: no bot answer is coming, so
					// drop the placeholder and let polling deliver the reply.
					pending.remove();
					if ( data.handover ) {
						bubble( ui.log, 'agent is-system', vmsosWidget.strings.waiting );
					}
				}

				startPolling( ui );
			} catch ( error ) {
				pending.classList.remove( 'is-pending' );
				pending.textContent = vmsosWidget.strings.error;
			}

			state.busy = false;
			ui.log.scrollTop = ui.log.scrollHeight;
		} );
	}

	/**
	 * Pull anything said since our last known message id. Cheap, stateless,
	 * and it works behind every host and cache that would break websockets.
	 */
	function startPolling( ui ) {
		if ( state.poller || ! state.uuid ) {
			return;
		}

		state.poller = setInterval( async function () {
			if ( document.hidden || ! state.uuid ) {
				return;
			}

			try {
				const response = await fetch(
					vmsosWidget.poll + '?uuid=' + encodeURIComponent( state.uuid ) + '&after=' + state.lastId
				);

				if ( ! response.ok ) {
					return;
				}

				const payload = await response.json();
				const data = ( payload && payload.data ) || {};

				( data.messages || [] ).forEach( function ( message ) {
					state.lastId = Math.max( state.lastId, Number( message.id ) );
					bubble( ui.log, 'agent', message.content );
				} );

				if ( ( data.messages || [] ).length ) {
					ui.log.scrollTop = ui.log.scrollHeight;
				}
			} catch ( error ) {
				// A failed poll is not worth telling the visitor about.
			}
		}, 8000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
