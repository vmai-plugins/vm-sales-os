/* global vmsosWidget */
( function () {
	'use strict';

	if ( typeof vmsosWidget === 'undefined' ) {
		return;
	}

	const state = {
		uuid: '',
		open: false,
		busy: false,
		lastId: 0,
		poller: null,
		activeTab: 'chat',
		teaserTimer: null,
		attribution: null,
		soundEnabled: true,
	};

	// 1. First-Touch Attribution Tracking
	function getAttribution() {
		if ( state.attribution ) {
			return state.attribution;
		}
		let stored = null;
		try {
			stored = JSON.parse( sessionStorage.getItem( 'vmsos_attribution' ) || 'null' );
		} catch ( e ) {}

		if ( stored ) {
			state.attribution = stored;
			return stored;
		}

		const q = new URLSearchParams( window.location.search );
		const attr = {
			utm_source: q.get( 'utm_source' ) || '',
			utm_medium: q.get( 'utm_medium' ) || '',
			utm_campaign: q.get( 'utm_campaign' ) || '',
			utm_term: q.get( 'utm_term' ) || '',
			utm_content: q.get( 'utm_content' ) || '',
			gclid: q.get( 'gclid' ) || '',
			fbclid: q.get( 'fbclid' ) || '',
			referrer: document.referrer || '',
			landing_page: window.location.href,
			captured_at: new Date().toISOString(),
		};

		try {
			sessionStorage.setItem( 'vmsos_attribution', JSON.stringify( attr ) );
		} catch ( e ) {}

		state.attribution = attr;
		return attr;
	}

	// 2. Client-Synthesized Audio Chime (Zero External Files)
	function playMessageTone() {
		if ( ! state.soundEnabled ) {
			return;
		}
		try {
			const AudioCtx = window.AudioContext || window.webkitAudioContext;
			if ( ! AudioCtx ) {
				return;
			}
			const ctx = new AudioCtx();
			if ( ctx.state === 'suspended' ) {
				ctx.resume();
			}
			const osc = ctx.createOscillator();
			const gain = ctx.createGain();
			osc.type = 'sine';
			osc.frequency.setValueAtTime( 587.33, ctx.currentTime ); // D5
			osc.frequency.exponentialRampToValueAtTime( 880, ctx.currentTime + 0.12 ); // A5
			gain.gain.setValueAtTime( 0.05, ctx.currentTime );
			gain.gain.exponentialRampToValueAtTime( 0.001, ctx.currentTime + 0.18 );
			osc.connect( gain );
			gain.connect( ctx.destination );
			osc.start();
			osc.stop( ctx.currentTime + 0.18 );
		} catch ( e ) {}
	}

	function el( tag, className, text ) {
		const node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( typeof text === 'string' ) {
			node.textContent = text;
		}
		return node;
	}

	function formatTime() {
		try {
			return new Date().toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
		} catch ( e ) {
			return '';
		}
	}

	// 3. Rich Markdown & List Renderer
	function renderMarkdown( text ) {
		const container = document.createElement( 'div' );
		container.className = 'vmsos-msg-content';

		if ( ! text ) {
			return container;
		}

		// Escape HTML
		let src = text
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );

		// Code blocks ```lang ... ```
		src = src.replace( /```(?:[a-zA-Z0-9_-]+)?\n([\s\S]*?)```/g, function ( _, code ) {
			return '<pre class="vmsos-code-block"><code>' + code + '</code></pre>';
		} );

		// Inline code `code`
		src = src.replace( /`([^`]+)`/g, '<code class="vmsos-code-inline">$1</code>' );

		// Process by lines for lists and blockquotes
		const rawLines = src.split( '\n' );
		const parsedLines = [];
		let inUl = false;
		let inOl = false;

		for ( let i = 0; i < rawLines.length; i++ ) {
			const line = rawLines[ i ];
			const ulMatch = line.match( /^\s*[-*•]\s+(.*)$/ );
			const olMatch = line.match( /^\s*(\d+)\.\s+(.*)$/ );

			if ( ulMatch ) {
				if ( ! inUl ) {
					if ( inOl ) {
						parsedLines.push( '</ol>' );
						inOl = false;
					}
					parsedLines.push( '<ul class="vmsos-list">' );
					inUl = true;
				}
				parsedLines.push( '<li>' + ulMatch[ 1 ] + '</li>' );
				continue;
			} else if ( inUl ) {
				parsedLines.push( '</ul>' );
				inUl = false;
			}

			if ( olMatch ) {
				if ( ! inOl ) {
					if ( inUl ) {
						parsedLines.push( '</ul>' );
						inUl = false;
					}
					parsedLines.push( '<ol class="vmsos-list">' );
					inOl = true;
				}
				parsedLines.push( '<li>' + olMatch[ 2 ] + '</li>' );
				continue;
			} else if ( inOl ) {
				parsedLines.push( '</ol>' );
				inOl = false;
			}

			// Blockquote >
			const bqMatch = line.match( /^\s*&gt;\s+(.*)$/ );
			if ( bqMatch ) {
				parsedLines.push( '<blockquote class="vmsos-blockquote">' + bqMatch[ 1 ] + '</blockquote>' );
				continue;
			}

			parsedLines.push( line );
		}

		if ( inUl ) {
			parsedLines.push( '</ul>' );
		}
		if ( inOl ) {
			parsedLines.push( '</ol>' );
		}

		let html = parsedLines.join( '\n' );

		// Bold **text** or __text__
		html = html.replace( /\*\*(.*?)\*\*/g, '<strong>$1</strong>' );
		html = html.replace( /__(.*?)__/g, '<strong>$1</strong>' );

		// Italic *text* or _text_
		html = html.replace( /\*(.*?)\*/g, '<em>$1</em>' );
		html = html.replace( /_([^_]+)_/g, '<em>$1</em>' );

		// Markdown links [text](url)
		html = html.replace( /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>' );

		// Auto-link raw URLs if not already linked
		html = html.replace( /(^|[^"'])(https?:\/\/[^\s<]+)/g, '$1<a href="$2" target="_blank" rel="noopener noreferrer">$2</a>' );

		// Convert non-list newlines to <br>
		html = html.replace( /([^>])\n([^<])/g, '$1<br />$2' );

		container.innerHTML = html;
		return container;
	}

	// 4. In-Chat Phone & Contact Extraction
	function detectPhoneNumber( message ) {
		if ( ! message || typeof message !== 'string' ) {
			return null;
		}
		// Match international and local mobile patterns
		const match = message.match( /(?:(?:\+|00)\d{1,3}[\s.-]?)?(?:\(?\d{2,4}\)?[\s.-]?)?\d{3,4}[\s.-]?\d{3,4}/ );
		if ( match ) {
			const raw = match[ 0 ];
			const digitsOnly = raw.replace( /[^0-9]/g, '' );
			if ( digitsOnly.length >= 8 && digitsOnly.length <= 15 ) {
				return raw.trim();
			}
		}
		return null;
	}

	function detectEmail( message ) {
		if ( ! message || typeof message !== 'string' ) {
			return null;
		}
		const match = message.match( /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/ );
		return match ? match[ 0 ] : null;
	}

	function build() {
		const root = document.getElementById( 'vmsos-widget-root' );
		if ( ! root ) {
			return null;
		}

		root.setAttribute( 'data-position', vmsosWidget.position || 'right' );
		root.style.setProperty( '--vmsos-accent', vmsosWidget.accent || '#c9a227' );

		// Proactive Teaser Bubble
		const teaser = el( 'div', 'vmsos-teaser' );
		teaser.style.display = 'none';

		const teaserHead = el( 'div', 'vmsos-teaser__head' );
		const teaserAvatarWrap = el( 'div', 'vmsos-teaser__avatar-wrap' );
		const teaserAvatar = el( 'span', 'vmsos-teaser__avatar', '✨' );
		const teaserDot = el( 'span', 'vmsos-teaser__dot' );
		teaserAvatarWrap.append( teaserAvatar, teaserDot );

		const teaserMeta = el( 'div', 'vmsos-teaser__meta' );
		teaserMeta.append(
			el( 'div', 'vmsos-teaser__name', vmsosWidget.agent ),
			el( 'div', 'vmsos-teaser__sub', vmsosWidget.agentTitle || 'Online • Live Specialist' )
		);

		const teaserClose = el( 'button', 'vmsos-teaser__close', '×' );
		teaserClose.setAttribute( 'aria-label', 'Dismiss' );
		teaserClose.type = 'button';

		teaserHead.append( teaserAvatarWrap, teaserMeta, teaserClose );

		const teaserBody = el( 'div', 'vmsos-teaser__text', vmsosWidget.teaserText );
		const teaserChipsWrap = el( 'div', 'vmsos-teaser__chips' );

		( vmsosWidget.chips || [] ).slice( 0, 3 ).forEach( function ( chipText ) {
			const chip = el( 'button', 'vmsos-chip', chipText );
			chip.type = 'button';
			chip.setAttribute( 'data-query', chipText );
			teaserChipsWrap.appendChild( chip );
		} );

		const teaserCta = el( 'button', 'vmsos-teaser__cta' );
		teaserCta.type = 'button';
		teaserCta.innerHTML = '<span>💬 ' + ( vmsosWidget.strings.open || 'Chat Now' ) + '</span><span>&rarr;</span>';

		teaser.append( teaserHead, teaserBody, teaserChipsWrap, teaserCta );

		// Launcher Floating Button
		const launcher = el( 'button', 'vmsos-launcher' );
		launcher.setAttribute( 'aria-expanded', 'false' );
		launcher.type = 'button';

		const launcherIconChat = el( 'span', 'vmsos-launcher__icon-chat' );
		launcherIconChat.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>';

		const launcherIconClose = el( 'span', 'vmsos-launcher__icon-close', '×' );
		launcherIconClose.style.display = 'none';

		const launcherPulse = el( 'span', 'vmsos-launcher__pulse' );
		const launcherLabel = el( 'span', 'vmsos-launcher__label', vmsosWidget.strings.open );

		launcher.append( launcherIconChat, launcherIconClose, launcherPulse, launcherLabel );

		// Main Chat Panel
		const panel = el( 'div', 'vmsos-panel' );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', vmsosWidget.agent );
		panel.hidden = true;

		// Head with Action Controls
		const head = el( 'div', 'vmsos-head' );
		const headInfo = el( 'div', 'vmsos-head__info' );
		const headAvatarWrap = el( 'div', 'vmsos-head__avatar-wrap' );
		headAvatarWrap.append( el( 'span', 'vmsos-head__avatar', '💬' ), el( 'span', 'vmsos-head__dot' ) );

		const headTitles = el( 'div' );
		headTitles.append(
			el( 'div', 'vmsos-head__name', vmsosWidget.agent ),
			el( 'div', 'vmsos-head__status', vmsosWidget.agentTitle || 'Online • Live Specialist' )
		);
		headInfo.append( headAvatarWrap, headTitles );

		const headControls = el( 'div', 'vmsos-head__controls' );

		// WhatsApp Direct Icon
		if ( vmsosWidget.whatsapp ) {
			const waBtn = el( 'a', 'vmsos-btn-action vmsos-btn-wa' );
			waBtn.href = 'https://wa.me/' + encodeURIComponent( vmsosWidget.whatsapp ) + '?text=' + encodeURIComponent( 'Hi ' + ( vmsosWidget.siteName || '' ) + ', I am inquiring from your website chat.' );
			waBtn.target = '_blank';
			waBtn.rel = 'noopener noreferrer';
			waBtn.title = vmsosWidget.strings.whatsapp || 'Chat on WhatsApp';
			waBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766 0-3.187-2.59-5.771-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.312.045-.694.072-2.18-.545-1.898-.787-3.13-2.73-3.224-2.855-.094-.127-.775-1.03-.775-1.965 0-.934.489-1.393.663-1.583.175-.19.38-.238.508-.238.127 0 .254.002.365.008.117.006.273-.044.428.328.16.383.548 1.339.596 1.436.048.098.08.212.015.339-.064.127-.095.207-.19.317-.095.111-.2.248-.286.333-.095.096-.195.2-.084.391.111.19.493.813 1.056 1.314.726.645 1.338.845 1.53.94.19.096.302.08.413-.048.111-.127.476-.556.603-.746.127-.19.254-.159.429-.095.174.064 1.111.524 1.302.619.19.096.317.143.365.223.048.079.048.461-.096.866z"/></svg>';
			headControls.appendChild( waBtn );
		}

		// Phone Direct Call Icon
		if ( vmsosWidget.phone ) {
			const phoneBtn = el( 'a', 'vmsos-btn-action vmsos-btn-phone' );
			phoneBtn.href = 'tel:' + encodeURIComponent( vmsosWidget.phone );
			phoneBtn.title = vmsosWidget.strings.call || 'Call us';
			phoneBtn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>';
			headControls.appendChild( phoneBtn );
		}

		// Sound Toggle Button
		const soundBtn = el( 'button', 'vmsos-btn-action vmsos-btn-sound', '🔔' );
		soundBtn.type = 'button';
		soundBtn.title = 'Mute notification sound';
		soundBtn.addEventListener( 'click', function () {
			state.soundEnabled = ! state.soundEnabled;
			soundBtn.textContent = state.soundEnabled ? '🔔' : '🔕';
			soundBtn.title = state.soundEnabled ? 'Mute notification sound' : 'Unmute notification sound';
		} );
		headControls.appendChild( soundBtn );

		// Restart Button
		const restartBtn = el( 'button', 'vmsos-btn-action vmsos-btn-restart', '↻' );
		restartBtn.type = 'button';
		restartBtn.title = vmsosWidget.strings.restart || 'New conversation';
		headControls.appendChild( restartBtn );

		// Close Button
		const close = el( 'button', 'vmsos-head__close', '×' );
		close.setAttribute( 'aria-label', vmsosWidget.strings.close );
		close.type = 'button';
		headControls.appendChild( close );

		head.append( headInfo, headControls );

		// Tab Navigation
		const tabsNav = el( 'div', 'vmsos-tabs-nav' );
		const tabChat = el( 'button', 'vmsos-tab-btn is-active', vmsosWidget.strings.chatTab || '💬 Live Chat' );
		tabChat.type = 'button';
		tabChat.setAttribute( 'data-tab', 'chat' );

		const tabForm = el( 'button', 'vmsos-tab-btn', vmsosWidget.strings.quoteTab || '📋 Fast Inquiry' );
		tabForm.type = 'button';
		tabForm.setAttribute( 'data-tab', 'form' );

		tabsNav.append( tabChat, tabForm );

		// TAB 1: Chat Stream View
		const chatView = el( 'div', 'vmsos-tab-view is-chat-tab' );
		const log = el( 'div', 'vmsos-log' );
		log.setAttribute( 'aria-live', 'polite' );

		const chatForm = el( 'form', 'vmsos-chat-form' );
		const input = el( 'input', 'vmsos-input' );
		input.type = 'text';
		input.placeholder = vmsosWidget.strings.placeholder || 'Type message or tap mic…';
		input.autocomplete = 'off';
		input.maxLength = 1000;

		const micBtn = el( 'button', 'vmsos-mic' );
		micBtn.type = 'button';
		micBtn.title = vmsosWidget.strings.micTitle || 'Voice input';
		micBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>';

		const honeypot = el( 'input', 'vmsos-hp' );
		honeypot.type = 'text';
		honeypot.name = 'company_website';
		honeypot.tabIndex = -1;
		honeypot.setAttribute( 'aria-hidden', 'true' );

		const send = el( 'button', 'vmsos-send', vmsosWidget.strings.send || 'Send' );
		send.type = 'submit';

		chatForm.append( input, micBtn, honeypot, send );
		chatView.append( log, chatForm );

		// TAB 2: Fast Quote / Inquiry Form View
		const formView = el( 'div', 'vmsos-tab-view is-form-tab' );
		formView.style.display = 'none';

		const inquiryForm = el( 'form', 'vmsos-inquiry-form' );
		inquiryForm.innerHTML =
			'<div class="vmsos-q-heading"><h4>' + ( vmsosWidget.strings.quoteTab || 'Fast Inquiry' ) + '</h4><p>Leave your contact details and project requirements. Our specialists will reach out shortly.</p></div>' +
			'<div class="vmsos-q-field"><label>Your Name *</label><input type="text" name="name" required placeholder="e.g. Alex Smith" autocomplete="name" /></div>' +
			'<div class="vmsos-q-field"><label>Email Address *</label><input type="email" name="email" required placeholder="alex@company.com" autocomplete="email" /></div>' +
			'<div class="vmsos-q-field"><label>Phone / WhatsApp</label><input type="tel" name="phone" placeholder="+1 (555) 000-0000" autocomplete="tel" /></div>' +
			'<div class="vmsos-q-field"><label>Project / Requirements</label><textarea name="requirements" rows="3" placeholder="Tell us what you are looking for..."></textarea></div>' +
			'<input type="text" name="company_website" class="vmsos-hp" tabindex="-1" style="display:none;" />' +
			'<button type="submit" class="vmsos-q-submit">⚡ Submit Inquiry</button>' +
			'<div class="vmsos-q-status" style="display:none;"></div>';

		formView.appendChild( inquiryForm );

		panel.append( head, tabsNav, chatView, formView );
		root.append( teaser, launcher, panel );

		return {
			root,
			teaser,
			teaserClose,
			teaserCta,
			launcher,
			launcherIconChat,
			launcherIconClose,
			panel,
			log,
			chatForm,
			input,
			micBtn,
			honeypot,
			close,
			restartBtn,
			soundBtn,
			tabChat,
			tabForm,
			chatView,
			formView,
			inquiryForm,
		};
	}

	// 5. Typing Wave Bubble
	function showTypingIndicator( log ) {
		const node = el( 'div', 'vmsos-msg is-agent is-typing' );
		const dots = el( 'div', 'vmsos-typing-dots' );
		dots.innerHTML = '<span></span><span></span><span></span>';
		node.appendChild( dots );
		log.appendChild( node );
		log.scrollTop = log.scrollHeight;
		return node;
	}

	// 6. Message Bubble Builder
	function bubble( log, role, content ) {
		const node = el( 'div', 'vmsos-msg is-' + role );

		if ( typeof content === 'string' ) {
			node.appendChild( renderMarkdown( content ) );
		} else if ( content instanceof HTMLElement ) {
			node.appendChild( content );
		}

		// Subtle copy button on agent bubbles
		if ( role === 'agent' && typeof content === 'string' && content.length > 5 ) {
			const copyBtn = el( 'button', 'vmsos-msg-copy', '📋' );
			copyBtn.type = 'button';
			copyBtn.title = 'Copy message';
			copyBtn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( content ).then( function () {
						copyBtn.textContent = '✓';
						setTimeout( function () {
							copyBtn.textContent = '📋';
						}, 1600 );
					} );
				}
			} );
			node.appendChild( copyBtn );
		}

		const timeEl = el( 'span', 'vmsos-msg-time', formatTime() );
		node.appendChild( timeEl );

		log.appendChild( node );
		log.scrollTop = log.scrollHeight;
		return node;
	}

	// 7. Starter & Follow-Up Chips
	function renderStarterChips( log, chips, onSelect ) {
		if ( ! chips || ! chips.length ) {
			return;
		}
		const chipsWrap = el( 'div', 'vmsos-chips-container' );
		chips.forEach( function ( chipText ) {
			const chip = el( 'button', 'vmsos-chip', chipText );
			chip.type = 'button';
			chip.addEventListener( 'click', function () {
				onSelect( chipText );
			} );
			chipsWrap.appendChild( chip );
		} );
		log.appendChild( chipsWrap );
		log.scrollTop = log.scrollHeight;
	}

	// 8. In-Chat Interactive Action Cards (WhatsApp Handoff / Callback)
	function renderWhatsAppCard( log, prefillText ) {
		if ( ! vmsosWidget.whatsapp ) {
			return;
		}
		const text = prefillText || 'Hi ' + ( vmsosWidget.siteName || '' ) + ', I am inquiring from your website chat.';
		const url = 'https://wa.me/' + encodeURIComponent( vmsosWidget.whatsapp ) + '?text=' + encodeURIComponent( text );

		const card = el( 'div', 'vmsos-action-card vmsos-action-wa' );
		card.innerHTML =
			'<div class="vmsos-action-card__inner">' +
				'<div class="vmsos-action-card__icon">' +
					'<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766 0-3.187-2.59-5.771-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.312.045-.694.072-2.18-.545-1.898-.787-3.13-2.73-3.224-2.855-.094-.127-.775-1.03-.775-1.965 0-.934.489-1.393.663-1.583.175-.19.38-.238.508-.238.127 0 .254.002.365.008.117.006.273-.044.428.328.16.383.548 1.339.596 1.436.048.098.08.212.015.339-.064.127-.095.207-.19.317-.095.111-.2.248-.286.333-.095.096-.195.2-.084.391.111.19.493.813 1.056 1.314.726.645 1.338.845 1.53.94.19.096.302.08.413-.048.111-.127.476-.556.603-.746.127-.19.254-.159.429-.095.174.064 1.111.524 1.302.619.19.096.317.143.365.223.048.079.048.461-.096.866z"/></svg>' +
				'</div>' +
				'<div class="vmsos-action-card__body">' +
					'<div class="vmsos-action-card__title">Direct WhatsApp Support</div>' +
					'<div class="vmsos-action-card__desc">Chat directly with our senior consultant for real-time proposals.</div>' +
				'</div>' +
			'</div>' +
			'<a href="' + url + '" target="_blank" rel="noopener noreferrer" class="vmsos-action-card__btn">Connect on WhatsApp &rarr;</a>';

		log.appendChild( card );
		log.scrollTop = log.scrollHeight;
	}

	function renderPhoneCallbackCard( log, phoneStr, switchToFormFn ) {
		const cleanDigits = phoneStr.replace( /[^0-9+]/g, '' );
		const card = el( 'div', 'vmsos-action-card vmsos-action-phone' );

		const inner = el( 'div', 'vmsos-action-card__inner' );
		const icon = el( 'div', 'vmsos-action-card__icon', '📞' );
		const body = el( 'div', 'vmsos-action-card__body' );
		body.append(
			el( 'div', 'vmsos-action-card__title', 'Contact Number Captured' ),
			el( 'div', 'vmsos-action-card__desc', 'Detected ' + phoneStr + '. Choose how you prefer to connect:' )
		);
		inner.append( icon, body );

		const btns = el( 'div', 'vmsos-action-card__btns' );

		if ( vmsosWidget.phone ) {
			const callLink = el( 'a', 'vmsos-action-card__btn is-secondary', '📞 Call Now' );
			callLink.href = 'tel:' + encodeURIComponent( vmsosWidget.phone );
			btns.appendChild( callLink );
		}

		if ( vmsosWidget.whatsapp ) {
			const waLink = el( 'a', 'vmsos-action-card__btn is-wa', '🟢 WhatsApp' );
			waLink.href = 'https://wa.me/' + encodeURIComponent( vmsosWidget.whatsapp ) + '?text=' + encodeURIComponent( 'Hi ' + ( vmsosWidget.siteName || '' ) + ', my number is ' + phoneStr + '. Looking for a quote.' );
			waLink.target = '_blank';
			waLink.rel = 'noopener noreferrer';
			btns.appendChild( waLink );
		}

		const formBtn = el( 'button', 'vmsos-action-card__btn', '📋 Submit Details' );
		formBtn.type = 'button';
		formBtn.addEventListener( 'click', function () {
			switchToFormFn( phoneStr );
		} );
		btns.appendChild( formBtn );

		card.append( inner, btns );
		log.appendChild( card );
		log.scrollTop = log.scrollHeight;
	}

	function init() {
		getAttribution();
		const ui = build();
		if ( ! ui ) {
			return;
		}

		function toggle( open ) {
			state.open = open;
			ui.launcher.setAttribute( 'aria-expanded', String( open ) );

			if ( open ) {
				ui.panel.classList.add( 'is-open' );
				ui.panel.removeAttribute( 'hidden' );
				ui.launcherIconChat.style.display = 'none';
				ui.launcherIconClose.style.display = 'inline-block';
				ui.teaser.style.display = 'none';

				if ( ! ui.log.childElementCount ) {
					bubble( ui.log, 'agent', vmsosWidget.greeting );
					renderStarterChips( ui.log, vmsosWidget.chips, function ( selectedPrompt ) {
						sendMessage( selectedPrompt );
					} );
				}
				if ( state.activeTab === 'chat' ) {
					ui.input.focus();
				}
			} else {
				ui.panel.classList.remove( 'is-open' );
				ui.panel.setAttribute( 'hidden', '' );
				ui.launcherIconChat.style.display = 'inline-block';
				ui.launcherIconClose.style.display = 'none';
			}
		}

		function switchTab( tab, prefillPhone ) {
			state.activeTab = tab;
			if ( tab === 'chat' ) {
				ui.tabChat.classList.add( 'is-active' );
				ui.tabForm.classList.remove( 'is-active' );
				ui.chatView.style.display = 'flex';
				ui.formView.style.display = 'none';
				ui.input.focus();
			} else {
				ui.tabForm.classList.add( 'is-active' );
				ui.tabChat.classList.remove( 'is-active' );
				ui.chatView.style.display = 'none';
				ui.formView.style.display = 'block';

				if ( prefillPhone ) {
					const phoneInput = ui.inquiryForm.querySelector( 'input[name="phone"]' );
					if ( phoneInput ) {
						phoneInput.value = prefillPhone;
					}
				}
			}
		}

		ui.tabChat.addEventListener( 'click', () => switchTab( 'chat' ) );
		ui.tabForm.addEventListener( 'click', () => switchTab( 'form' ) );

		ui.launcher.addEventListener( 'click', () => toggle( ! state.open ) );
		ui.close.addEventListener( 'click', () => toggle( false ) );

		// Proactive Sales Teaser Setup
		const isDismissed = sessionStorage.getItem( 'vmsos_teaser_dismissed' );
		if ( vmsosWidget.teaserEnabled && ! isDismissed ) {
			const delay = ( Number( vmsosWidget.teaserDelay ) || 3 ) * 1000;
			state.teaserTimer = setTimeout( function () {
				if ( ! state.open ) {
					ui.teaser.style.display = 'flex';
				}
			}, delay );
		}

		ui.teaserClose.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			ui.teaser.style.display = 'none';
			sessionStorage.setItem( 'vmsos_teaser_dismissed', '1' );
		} );

		ui.teaserCta.addEventListener( 'click', function () {
			ui.teaser.style.display = 'none';
			sessionStorage.setItem( 'vmsos_teaser_dismissed', '1' );
			toggle( true );
		} );

		// Clicking a teaser chip opens widget and immediately asks the question
		ui.teaser.querySelectorAll( '.vmsos-chip' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				const query = chip.getAttribute( 'data-query' ) || chip.textContent;
				ui.teaser.style.display = 'none';
				sessionStorage.setItem( 'vmsos_teaser_dismissed', '1' );
				toggle( true );
				sendMessage( query );
			} );
		} );

		// Restart conversation thread
		ui.restartBtn.addEventListener( 'click', function () {
			state.uuid = '';
			state.lastId = 0;
			if ( state.poller ) {
				clearInterval( state.poller );
				state.poller = null;
			}
			ui.log.innerHTML = '';
			bubble( ui.log, 'agent', vmsosWidget.greeting );
			renderStarterChips( ui.log, vmsosWidget.chips, function ( selectedPrompt ) {
				sendMessage( selectedPrompt );
			} );
			ui.input.focus();
		} );

		document.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' === event.key && state.open ) {
				toggle( false );
			}
		} );

		// Speech-to-Text Microphone Integration
		const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
		if ( SpeechRecognition ) {
			const recognizer = new SpeechRecognition();
			recognizer.continuous = false;
			recognizer.interimResults = false;
			recognizer.lang = navigator.language || 'en-US';

			let listening = false;
			ui.micBtn.addEventListener( 'click', function () {
				if ( listening ) {
					recognizer.stop();
					return;
				}
				try {
					recognizer.start();
					listening = true;
					ui.micBtn.classList.add( 'is-listening' );
				} catch ( err ) {}
			} );

			recognizer.onresult = function ( ev ) {
				const transcript = ev.results[ 0 ][ 0 ].transcript;
				if ( transcript ) {
					ui.input.value = ui.input.value ? ui.input.value + ' ' + transcript : transcript;
					ui.input.focus();
				}
			};

			recognizer.onend = recognizer.onerror = function () {
				listening = false;
				ui.micBtn.classList.remove( 'is-listening' );
			};
		} else {
			ui.micBtn.style.display = 'none';
		}

		async function sendMessage( messageText ) {
			const message = ( messageText || ui.input.value ).trim();
			if ( ! message || state.busy ) {
				return;
			}

			state.busy = true;
			ui.input.value = '';
			bubble( ui.log, 'visitor', message );

			// Check for in-chat phone detection
			const detectedPhone = detectPhoneNumber( message );
			const detectedMail = detectEmail( message );

			if ( detectedPhone ) {
				renderPhoneCallbackCard( ui.log, detectedPhone, function ( p ) {
					switchTab( 'form', p );
				} );
			}

			const typingBubble = showTypingIndicator( ui.log );
			const attr = getAttribution();

			try {
				const response = await fetch( vmsosWidget.endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						message: message,
						uuid: state.uuid,
						page_url: window.location.href,
						company_website: ui.honeypot.value,
						attribution: attr,
					} ),
				} );

				const payload = await response.json();
				const data = payload && payload.data ? payload.data : {};

				state.uuid = data.uuid || state.uuid;

				// Remove typing indicator before appending reply
				typingBubble.remove();

				if ( data.reply ) {
					playMessageTone();
					bubble( ui.log, 'agent', data.reply );

					// Check if reply references WhatsApp or handover
					const lowerReply = ( data.reply || '' ).toLowerCase();
					if ( lowerReply.includes( 'whatsapp' ) || data.handover ) {
						renderWhatsAppCard( ui.log, 'Hi ' + ( vmsosWidget.siteName || '' ) + ', following up on our chat inquiry.' );
					}
				} else {
					if ( data.handover ) {
						bubble( ui.log, 'agent is-system', vmsosWidget.strings.waiting );
						renderWhatsAppCard( ui.log, 'Hi ' + ( vmsosWidget.siteName || '' ) + ', I requested an agent handover from chat.' );
					}
				}

				startPolling( ui );
			} catch ( error ) {
				typingBubble.remove();
				bubble( ui.log, 'agent is-system', vmsosWidget.strings.error );
			}

			state.busy = false;
			ui.log.scrollTop = ui.log.scrollHeight;
		}

		ui.chatForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			sendMessage();
		} );

		// Fast Inquiry / Lead Form Handler
		ui.inquiryForm.addEventListener( 'submit', async function ( ev ) {
			ev.preventDefault();
			const statusEl = ui.inquiryForm.querySelector( '.vmsos-q-status' );
			const submitBtn = ui.inquiryForm.querySelector( '.vmsos-q-submit' );
			const formData = new FormData( ui.inquiryForm );

			const name = ( formData.get( 'name' ) || '' ).toString().trim();
			const email = ( formData.get( 'email' ) || '' ).toString().trim();
			const phone = ( formData.get( 'phone' ) || '' ).toString().trim();
			const requirements = ( formData.get( 'requirements' ) || '' ).toString().trim();
			const honeypotVal = ( formData.get( 'company_website' ) || '' ).toString();

			if ( ! email ) {
				return;
			}

			submitBtn.disabled = true;
			submitBtn.textContent = 'Submitting…';
			statusEl.style.display = 'none';

			const parts = name.split( ' ' );
			const firstName = parts[ 0 ] || '';
			const lastName = parts.slice( 1 ).join( ' ' ) || '';
			const attr = getAttribution();

			try {
				const res = await fetch( vmsosWidget.formSubmit, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						first_name: firstName,
						last_name: lastName,
						email: email,
						phone: phone,
						requirements: requirements,
						source: 'widget_form',
						company_website: honeypotVal,
						consent: true,
						attribution: attr,
					} ),
				} );

				const json = await res.json();
				if ( res.ok ) {
					statusEl.className = 'vmsos-q-status is-ok';
					statusEl.textContent = vmsosWidget.strings.formSuccess || 'Inquiry received!';
					statusEl.style.display = 'block';

					// Also post into chat stream so continuity is preserved
					setTimeout( function () {
						switchTab( 'chat' );
						bubble( ui.log, 'visitor', 'Submitted inquiry for: ' + ( requirements || 'General Consultation' ) );
						bubble( ui.log, 'agent', 'Thank you, ' + ( firstName || 'there' ) + '! We have received your inquiry. A specialist will review it and follow up shortly.' );
					}, 1200 );
				} else {
					statusEl.className = 'vmsos-q-status is-error';
					statusEl.textContent = ( json && json.message ) || vmsosWidget.strings.error;
					statusEl.style.display = 'block';
				}
			} catch ( err ) {
				statusEl.className = 'vmsos-q-status is-error';
				statusEl.textContent = vmsosWidget.strings.error;
				statusEl.style.display = 'block';
			}

			submitBtn.disabled = false;
			submitBtn.textContent = '⚡ Submit Inquiry';
		} );
	}

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
					playMessageTone();
					bubble( ui.log, 'agent', message.content );
				} );

				if ( ( data.messages || [] ).length ) {
					ui.log.scrollTop = ui.log.scrollHeight;
				}
			} catch ( error ) {}
		}, 8000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
