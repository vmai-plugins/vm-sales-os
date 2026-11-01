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

	function build() {
		const root = document.getElementById( 'vmsos-widget-root' );
		if ( ! root ) {
			return null;
		}

		// Fast-path: If server-rendered PHP markup already exists in DOM, bind directly!
		const existingPanel = document.getElementById( 'vmsos-panel' );
		const existingLauncher = document.getElementById( 'vmsos-launcher' );
		const existingTeaser = document.getElementById( 'vmsos-teaser' );

		if ( existingPanel && existingLauncher ) {
			root.setAttribute( 'data-initialized', 'true' );
			root.setAttribute( 'data-position', vmsosWidget.position || 'right' );
			root.style.setProperty( '--vmsos-accent', vmsosWidget.accent || '#c9a227' );

			const teaserClose = document.getElementById( 'vmsos-teaser-close' );
			const teaserCta = document.getElementById( 'vmsos-teaser-cta' );
			const launcherIconChat = existingLauncher.querySelector( '.vmsos-launcher__icon-chat, .is-chat' );
			const launcherIconClose = existingLauncher.querySelector( '.vmsos-launcher__icon-close, .is-close' );
			const panelClose = document.getElementById( 'vmsos-panel-close' );
			const restartBtn = document.getElementById( 'vmsos-restart-btn' );
			const soundBtn = document.getElementById( 'vmsos-sound-toggle' );
			const log = document.getElementById( 'vmsos-log' );
			const chatForm = document.getElementById( 'vmsos-chat-form' );
			const input = document.getElementById( 'vmsos-chat-input' );
			const micBtn = document.getElementById( 'vmsos-chat-mic' );
			const send = document.getElementById( 'vmsos-chat-send' );
			const tabChat = document.getElementById( 'vmsos-tab-btn-chat' );
			const tabForm = document.getElementById( 'vmsos-tab-btn-form' );
			const tabVoice = document.getElementById( 'vmsos-tab-btn-voice' );
			const chatView = document.getElementById( 'vmsos-view-chat' );
			const formView = document.getElementById( 'vmsos-view-form' );
			const voiceView = document.getElementById( 'vmsos-view-voice' );
			const inquiryForm = document.getElementById( 'vmsos-inquiry-form' );
			const honeypot = inquiryForm ? inquiryForm.querySelector( '.vmsos-hp' ) : null;

			return {
				root,
				teaser: existingTeaser,
				teaserClose,
				teaserCta,
				launcher: existingLauncher,
				launcherIconChat,
				launcherIconClose,
				panel: existingPanel,
				close: panelClose,
				restartBtn,
				soundBtn,
				log,
				chatForm,
				input,
				micBtn,
				send,
				tabChat,
				tabForm,
				tabVoice,
				chatView,
				formView,
				voiceView,
				inquiryForm,
				honeypot,
			};
		}

		if ( root.hasAttribute( 'data-initialized' ) ) {
			return null;
		}
		root.setAttribute( 'data-initialized', 'true' );
		root.innerHTML = '';

		root.setAttribute( 'data-position', vmsosWidget.position || 'right' );
		root.style.setProperty( '--vmsos-accent', vmsosWidget.accent || '#c9a227' );

		// Proactive Teaser Bubble
		const teaser = el( 'div', 'vmsos-teaser' );
		teaser.style.display = 'none';

		const teaserHead = el( 'div', 'vmsos-teaser__head' );
		const teaserAvatarWrap = el( 'div', 'vmsos-teaser__avatar-wrap' );
		teaserAvatarWrap.innerHTML = '<div class="vmsos-teaser__avatar"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a8 8 0 0 0-8 8c0 3.3 2 6.2 5 7.4V20a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-2.6c3-1.2 5-4.1 5-7.4a8 8 0 0 0-8-8z"></path><circle cx="9" cy="10" r="1.2" fill="currentColor"></circle><circle cx="15" cy="10" r="1.2" fill="currentColor"></circle></svg></div><span class="vmsos-teaser__dot"></span>';

		const teaserMeta = el( 'div', 'vmsos-teaser__meta' );
		const teaserNameEl = el( 'div', 'vmsos-teaser__name' );
		teaserNameEl.innerHTML = ( vmsosWidget.agent || 'Ava' ) + ' <span class="vmsos-teaser__name-badge" title="Verified AI Specialist"><svg width="14" height="14" viewBox="0 0 24 24" fill="#10b981"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg></span>';

		const teaserSubEl = el( 'div', 'vmsos-teaser__sub' );
		teaserSubEl.innerHTML = '<span class="vmsos-teaser__sub-dot"></span>' + ( vmsosWidget.agentTitle || 'Live Growth Specialist' );

		teaserMeta.append( teaserNameEl, teaserSubEl );

		const teaserClose = el( 'button', 'vmsos-teaser__close', '×' );
		teaserClose.setAttribute( 'aria-label', 'Dismiss' );
		teaserClose.type = 'button';

		teaserHead.append( teaserAvatarWrap, teaserMeta, teaserClose );

		// Smart Context-Aware Hook Engine
		function getContextualHook() {
			try {
				const path = ( window.location.pathname || '' ).toLowerCase();
				if ( path.includes( 'pricing' ) || path.includes( 'cost' ) || path.includes( 'package' ) ) {
					return 'Looking for a custom project quote or pricing estimate? I can calculate it instantly right here.';
				}
				if ( path.includes( 'contact' ) || path.includes( 'about' ) || path.includes( 'consult' ) ) {
					return 'Have a question about team availability or project timelines? Let’s chat!';
				}
				if ( path.includes( 'service' ) || path.includes( 'work' ) || path.includes( 'portfolio' ) ) {
					return 'Interested in custom web development, AI automation, or brand growth? Let’s explore your scope.';
				}
			} catch ( e ) {}
			return vmsosWidget.teaserText || 'Looking to scale your business with custom web & AI automation? Let us chat!';
		}

		const teaserBody = el( 'div', 'vmsos-teaser__text', getContextualHook() );
		const teaserChipsWrap = el( 'div', 'vmsos-teaser__chips' );

		( vmsosWidget.chips || [] ).slice( 0, 3 ).forEach( function ( chipText ) {
			const chip = el( 'button', 'vmsos-chip', chipText );
			chip.type = 'button';
			chip.setAttribute( 'data-query', chipText );
			teaserChipsWrap.appendChild( chip );
		} );

		const teaserCta = el( 'button', 'vmsos-teaser__cta' );
		teaserCta.type = 'button';
		teaserCta.innerHTML = '<span>⚡ ' + ( vmsosWidget.strings.teaserCta || 'Start Instant Consultation' ) + '</span><span class="vmsos-teaser__cta-arrow">&rarr;</span>';

		teaser.append( teaserHead, teaserBody, teaserChipsWrap, teaserCta );

		// Launcher Floating Button
		const launcher = el( 'button', 'vmsos-launcher' );
		launcher.setAttribute( 'aria-expanded', 'false' );
		launcher.type = 'button';

		const launcherIconChat = el( 'span', 'vmsos-launcher__icon-chat' );
		launcherIconChat.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>';

		const launcherIconClose = el( 'span', 'vmsos-launcher__icon-close' );
		launcherIconClose.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
		launcherIconClose.style.display = 'none';

		const launcherPulse = el( 'span', 'vmsos-launcher__pulse' );
		const launcherLabel = el( 'span', 'vmsos-launcher__label', vmsosWidget.strings.open || 'Chat with us' );

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
		headAvatarWrap.innerHTML = '<div class="vmsos-head__avatar"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a8 8 0 0 0-8 8c0 3.3 2 6.2 5 7.4V20a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-2.6c3-1.2 5-4.1 5-7.4a8 8 0 0 0-8-8z"></path><circle cx="9" cy="10" r="1" fill="currentColor"></circle><circle cx="15" cy="10" r="1" fill="currentColor"></circle><path d="M9.5 15a3.5 3.5 0 0 0 5 0"></path></svg></div><span class="vmsos-head__dot"></span>';

		const headTitles = el( 'div' );
		headTitles.append(
			el( 'div', 'vmsos-head__name', vmsosWidget.agent ),
			el( 'div', 'vmsos-head__status', vmsosWidget.agentTitle || 'AI Growth Strategist • Live' )
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
			waBtn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766 0-3.187-2.59-5.771-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.312.045-.694.072-2.18-.545-1.898-.787-3.13-2.73-3.224-2.855-.094-.127-.775-1.03-.775-1.965 0-.934.489-1.393.663-1.583.175-.19.38-.238.508-.238.127 0 .254.002.365.008.117.006.273-.044.428.328.16.383.548 1.339.596 1.436.048.098.08.212.015.339-.064.127-.095.207-.19.317-.095.111-.2.248-.286.333-.095.096-.195.2-.084.391.111.19.493.813 1.056 1.314.726.645 1.338.845 1.53.94.19.096.302.08.413-.048.111-.127.476-.556.603-.746.127-.19.254-.159.429-.095.174.064 1.111.524 1.302.619.19.096.317.143.365.223.048.079.048.461-.096.866z"/></svg>';
			headControls.appendChild( waBtn );
		}

		// Phone Direct Call Icon
		if ( vmsosWidget.phone ) {
			const phoneBtn = el( 'a', 'vmsos-btn-action vmsos-btn-phone' );
			phoneBtn.href = 'tel:' + encodeURIComponent( vmsosWidget.phone );
			phoneBtn.title = vmsosWidget.strings.call || 'Call us';
			phoneBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>';
			headControls.appendChild( phoneBtn );
		}

		// Sound Toggle Button
		const soundBtn = el( 'button', 'vmsos-btn-action vmsos-btn-sound' );
		soundBtn.type = 'button';
		soundBtn.title = 'Mute notification sound';
		soundBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5L6 9H2v6h4l5 4V5z"></path><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>';
		soundBtn.addEventListener( 'click', function () {
			state.soundEnabled = ! state.soundEnabled;
			soundBtn.title = state.soundEnabled ? 'Mute notification sound' : 'Unmute notification sound';
			soundBtn.style.opacity = state.soundEnabled ? '1' : '0.45';
		} );
		headControls.appendChild( soundBtn );

		// Restart Button
		const restartBtn = el( 'button', 'vmsos-btn-action vmsos-btn-restart' );
		restartBtn.type = 'button';
		restartBtn.title = vmsosWidget.strings.restart || 'New conversation';
		restartBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"></path></svg>';
		headControls.appendChild( restartBtn );

		// Close Button
		const close = el( 'button', 'vmsos-head__close' );
		close.setAttribute( 'aria-label', vmsosWidget.strings.close || 'Close' );
		close.type = 'button';
		close.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
		headControls.appendChild( close );

		head.append( headInfo, headControls );

		// Tab Navigation
		const tabsNav = el( 'div', 'vmsos-tabs-nav' );
		const tabChat = el( 'button', 'vmsos-tab-btn is-active', vmsosWidget.strings.chatTab || '💬 Live AI Chat' );
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
		input.placeholder = vmsosWidget.strings.placeholder || 'Ask anything or choose a topic above…';
		input.autocomplete = 'off';
		input.maxLength = 1000;

		const micBtn = el( 'button', 'vmsos-mic' );
		micBtn.type = 'button';
		micBtn.title = vmsosWidget.strings.micTitle || 'Voice dictation';
		micBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>';

		// Honeypot: only in inquiry form, never in chat form

		const send = el( 'button', 'vmsos-send' );
		send.type = 'submit';
		send.title = vmsosWidget.strings.send || 'Send';
		send.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path></svg>';

		chatForm.append( input, micBtn, send );
		chatView.append( log, chatForm );

		// TAB 2: Fast Quote / Inquiry Form View
		const formView = el( 'div', 'vmsos-tab-view is-form-tab' );
		formView.style.display = 'none';

		const inquiryForm = el( 'form', 'vmsos-inquiry-form' );
		inquiryForm.innerHTML =
			'<div class="vmsos-q-heading"><h4>' + ( vmsosWidget.strings.quoteTab || 'Fast Inquiry' ) + '</h4><p>Leave your contact details and requirements. Our specialists will reach out promptly.</p></div>' +
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
			honeypot: inquiryForm ? inquiryForm.querySelector( '.vmsos-hp' ) : null,
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

		if ( role === 'agent' && typeof content === 'string' && content.length > 5 ) {
			const copyBtn = el( 'button', 'vmsos-msg-copy' );
			copyBtn.type = 'button';
			copyBtn.title = 'Copy message';
			copyBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
			copyBtn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( content ).then( function () {
						copyBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
						setTimeout( function () {
							copyBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
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
	function renderStarterChips( log, chips ) {
		if ( ! chips || ! chips.length ) {
			return;
		}
		const chipsWrap = el( 'div', 'vmsos-chips-container' );
		chipsWrap.id = 'vmsos-starter-chips';
		chips.forEach( function ( chipText ) {
			const chip = el( 'button', 'vmsos-chip', chipText );
			chip.type = 'button';
			chip.setAttribute( 'data-query', chipText );
			chipsWrap.appendChild( chip );
		} );
		log.appendChild( chipsWrap );
		log.scrollTop = log.scrollHeight;
	}

	// 8. In-Chat Interactive Action Cards
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
					'<div class="vmsos-action-card__desc">Chat directly with our team for real-time proposals and consultation.</div>' +
				'</div>' +
			'</div>' +
			'<a href="' + url + '" target="_blank" rel="noopener noreferrer" class="vmsos-action-card__btn">Connect on WhatsApp &rarr;</a>';

		log.appendChild( card );
		log.scrollTop = log.scrollHeight;
	}

	function renderPhoneCallbackCard( log, phoneStr, switchToFormFn ) {
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

	// 9. Generative UI: Interactive Strategy Consultation Booking Card
	function renderBookingCard( log, onConfirm ) {
		const card = el( 'div', 'vmsos-gen-card vmsos-booking-card' );

		const today = new Date();
		const tomorrow = new Date( today );
		tomorrow.setDate( tomorrow.getDate() + 1 );
		const nextWeek = new Date( today );
		nextWeek.setDate( nextWeek.getDate() + 3 );

		const fmt = ( d ) => d.toLocaleDateString( [], { weekday: 'short', month: 'short', day: 'numeric' } );

		const dates = [
			{ label: 'Today', val: 'Today' },
			{ label: 'Tomorrow (' + fmt( tomorrow ) + ')', val: 'Tomorrow', active: true },
			{ label: fmt( nextWeek ), val: fmt( nextWeek ) },
		];

		const slots = [ '10:30 AM', '02:00 PM', '04:30 PM', '06:00 PM' ];
		let selectedDate = 'Tomorrow';
		let selectedSlot = '02:00 PM';

		card.innerHTML =
			'<div class="vmsos-gen-card__head">' +
				'<span class="vmsos-gen-card__icon">📅</span>' +
				'<div class="vmsos-gen-card__titles">' +
					'<strong>Schedule a Strategy Session</strong>' +
					'<span>Pick an available 30-min discovery slot with our team:</span>' +
				'</div>' +
			'</div>' +
			'<div class="vmsos-gen-card__dates"></div>' +
			'<div class="vmsos-gen-card__slots"></div>' +
			'<button type="button" class="vmsos-gen-card__cta">⚡ Confirm Discovery Slot</button>';

		const datesWrap = card.querySelector( '.vmsos-gen-card__dates' );
		const slotsWrap = card.querySelector( '.vmsos-gen-card__slots' );
		const ctaBtn = card.querySelector( '.vmsos-gen-card__cta' );

		dates.forEach( function ( d ) {
			const b = el( 'button', 'vmsos-pill-btn' + ( d.active ? ' is-selected' : '' ), d.label );
			b.type = 'button';
			b.addEventListener( 'click', function () {
				datesWrap.querySelectorAll( '.vmsos-pill-btn' ).forEach( ( x ) => x.classList.remove( 'is-selected' ) );
				b.classList.add( 'is-selected' );
				selectedDate = d.val;
			} );
			datesWrap.appendChild( b );
		} );

		slots.forEach( function ( s, idx ) {
			const b = el( 'button', 'vmsos-pill-btn' + ( 1 === idx ? ' is-selected' : '' ), s );
			b.type = 'button';
			b.addEventListener( 'click', function () {
				slotsWrap.querySelectorAll( '.vmsos-pill-btn' ).forEach( ( x ) => x.classList.remove( 'is-selected' ) );
				b.classList.add( 'is-selected' );
				selectedSlot = s;
			} );
			slotsWrap.appendChild( b );
		} );

		ctaBtn.addEventListener( 'click', function () {
			ctaBtn.disabled = true;
			ctaBtn.textContent = '✓ Slot Reserved';
			card.classList.add( 'is-confirmed' );
			onConfirm( selectedDate, selectedSlot );
		} );

		log.appendChild( card );
		log.scrollTop = log.scrollHeight;
	}

	// 10. Generative UI: Interactive Custom Scope & Cost Estimator
	function renderScopeCalculator( log, onProceed ) {
		const card = el( 'div', 'vmsos-gen-card vmsos-quote-card' );

		const services = [
			{ name: 'High-Conversion Web Application', price: 1500, checked: true },
			{ name: 'Omnichannel AI Agent & CRM Sync', price: 800, checked: true },
			{ name: 'Brand Identity & Visual Creative Assets', price: 600, checked: false },
			{ name: 'Automated Lead Growth & Sequences', price: 500, checked: false },
		];

		function calculateTotal() {
			let sum = 0;
			card.querySelectorAll( 'input[type="checkbox"]:checked' ).forEach( function ( cb ) {
				sum += Number( cb.getAttribute( 'data-price' ) || 0 );
			} );
			return sum;
		}

		let html =
			'<div class="vmsos-gen-card__head">' +
				'<span class="vmsos-gen-card__icon">💡</span>' +
				'<div class="vmsos-gen-card__titles">' +
					'<strong>Custom Scope Estimator</strong>' +
					'<span>Toggle deliverables for an instant live investment estimate:</span>' +
				'</div>' +
			'</div>' +
			'<div class="vmsos-quote-options">';

		services.forEach( function ( s ) {
			html +=
				'<label class="vmsos-quote-option">' +
					'<input type="checkbox" data-price="' + s.price + '" ' + ( s.checked ? 'checked' : '' ) + ' />' +
					'<span>' + s.name + '</span>' +
					'<strong>$' + s.price.toLocaleString() + '</strong>' +
				'</label>';
		} );

		html +=
			'</div>' +
			'<div class="vmsos-quote-footer">' +
				'<div class="vmsos-quote-total-wrap">Estimated Total: <span class="vmsos-quote-total">$2,300</span></div>' +
				'<button type="button" class="vmsos-gen-card__cta">📋 Lock In Estimate & Inquire &rarr;</button>' +
			'</div>';

		card.innerHTML = html;

		const totalEl = card.querySelector( '.vmsos-quote-total' );
		const cta = card.querySelector( '.vmsos-gen-card__cta' );

		card.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				const tot = calculateTotal();
				totalEl.textContent = '$' + tot.toLocaleString();
			} );
		} );

		cta.addEventListener( 'click', function () {
			cta.disabled = true;
			const finalTotal = totalEl.textContent;
			const selectedItems = [];
			card.querySelectorAll( '.vmsos-quote-option' ).forEach( function ( label ) {
				if ( label.querySelector( 'input:checked' ) ) {
					selectedItems.push( label.querySelector( 'span' ).textContent );
				}
			} );
			onProceed( finalTotal, selectedItems );
		} );

		log.appendChild( card );
		log.scrollTop = log.scrollHeight;
	}

	// 11. Welcome Experience
	function renderWelcomeHero( ui ) {
		const msg = el( 'div', 'vmsos-msg is-agent' );
		const bubbleWrap = el( 'div', 'vmsos-msg__bubble' );
		const p = el( 'p', '', vmsosWidget.greeting || ( 'Hello! I am ' + ( vmsosWidget.agent || 'Ava' ) + '. How can we help scale your business today?' ) );
		bubbleWrap.appendChild( p );

		if ( vmsosWidget.whatsapp ) {
			const cleanWa = ( vmsosWidget.whatsapp || '' ).replace( /[^0-9]/g, '' );
			const waBtn = el( 'a', 'vmsos-wa-card-btn' );
			waBtn.href = 'https://wa.me/' + encodeURIComponent( cleanWa );
			waBtn.target = '_blank';
			waBtn.rel = 'noopener noreferrer';
			waBtn.innerHTML = '<span class="vmsos-wa-card-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm.01 18.06c-1.48 0-2.93-.4-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31c-.82-1.31-1.26-2.83-1.26-4.38 0-4.54 3.7-8.24 8.24-8.24 4.54 0 8.24 3.7 8.24 8.24 0 4.54-3.7 8.24-8.24 8.24zm4.52-6.17c-.25-.12-1.47-.72-1.7-.81-.23-.08-.39-.12-.56.12-.17.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.24-1.47-1.39-1.72-.14-.25-.02-.38.11-.5.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43s-.56-1.34-.76-1.84c-.2-.48-.41-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.23.25-.87.85-.87 2.07s.89 2.4 1.01 2.57c.12.17 1.75 2.67 4.24 3.75.59.26 1.05.41 1.41.53.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.21-.58.21-1.07.15-1.18-.06-.12-.22-.19-.47-.31z"/></svg></span><span class="vmsos-wa-card-text">Chat directly on WhatsApp &rarr;</span>';
			bubbleWrap.appendChild( waBtn );
		}

		msg.appendChild( bubbleWrap );
		ui.log.appendChild( msg );

		// Quick Action Pills
		renderStarterChips( ui.log, vmsosWidget.chips );
	}

	function init() {
		getAttribution();
		const ui = build();
		if ( ! ui ) {
			return;
		}

		function handleChipSelection( selectedPrompt ) {
			const starterChips = ui.log ? ui.log.querySelector( '#vmsos-starter-chips, .vmsos-chips-container, .vmsos-chips-wrap' ) : null;
			if ( starterChips ) {
				starterChips.remove();
			}

			if ( selectedPrompt.includes( 'Estimate' ) || selectedPrompt.includes( 'Quote' ) ) {
				renderScopeCalculator( ui.log, function ( total, items ) {
					switchTab( 'form' );
					const reqTextarea = ui.inquiryForm.querySelector( 'textarea[name="requirements"]' );
					if ( reqTextarea ) {
						reqTextarea.value = 'Selected Deliverables (' + total + '):\n- ' + items.join( '\n- ' );
					}
				} );
			} else if ( selectedPrompt.includes( 'Book' ) || selectedPrompt.includes( 'Discovery' ) || selectedPrompt.includes( 'Session' ) || selectedPrompt.includes( 'Call' ) ) {
				renderBookingCard( ui.log, function ( date, slot ) {
					bubble( ui.log, 'visitor', 'Requested discovery consultation for ' + date + ' at ' + slot );
					bubble( ui.log, 'agent', '✓ Perfect! Your discovery session for ' + date + ' at ' + slot + ' is confirmed. Our specialist team will prepare strategic audit notes for your session.' );
				} );
			} else {
				sendMessage( selectedPrompt );
			}
		}

		function setTeaserVisibility( visible ) {
			if ( visible && ! state.open ) {
				ui.teaser.style.display = 'flex';
				ui.launcher.classList.add( 'is-teaser-active' );
			} else {
				ui.teaser.style.display = 'none';
				ui.launcher.classList.remove( 'is-teaser-active' );
			}
		}

		function toggle( open ) {
			state.open = open;
			if ( ui.launcher ) {
				ui.launcher.setAttribute( 'aria-expanded', String( open ) );
			}

			if ( open ) {
				setTeaserVisibility( false );
				ui.root.classList.add( 'is-open' );
				ui.panel.classList.add( 'is-open' );
				ui.panel.style.display = 'flex';
				ui.panel.removeAttribute( 'hidden' );
				if ( ui.launcher ) {
					ui.launcher.classList.add( 'is-hidden' );
					ui.launcher.style.display = 'none';
				}
				if ( ui.launcherIconChat ) ui.launcherIconChat.style.display = 'none';
				if ( ui.launcherIconClose ) ui.launcherIconClose.style.display = 'none';

				if ( ui.log && ! ui.log.childElementCount ) {
					renderWelcomeHero( ui );
				}
				if ( state.activeTab === 'chat' && ui.input ) {
					ui.input.focus();
				}
			} else {
				ui.root.classList.remove( 'is-open' );
				ui.panel.classList.remove( 'is-open' );
				ui.panel.style.display = 'none';
				ui.panel.setAttribute( 'hidden', '' );
				if ( ui.launcher ) {
					ui.launcher.classList.remove( 'is-hidden' );
					ui.launcher.style.display = '';
					ui.launcher.classList.remove( 'is-teaser-active' );
				}
				if ( ui.launcherIconChat ) ui.launcherIconChat.style.display = 'inline-flex';
				if ( ui.launcherIconClose ) ui.launcherIconClose.style.display = 'none';
				stopVisualizerLoop();
				if ( isInVoiceCall ) endVoiceCall();
			}
		}

		// WebCall & Voice Visualizer State
		let animFrameId = null;
		let wavePhase = 0;
		let isInVoiceCall = false;
		let voiceRecInstance = null;
		const voiceRing = document.getElementById( 'vmsos-voice-ring' );
		const voiceStatus = document.getElementById( 'vmsos-voice-status' );
		const voiceCanvas = document.getElementById( 'vmsos-voice-visualizer' );
		const voiceTranscript = document.getElementById( 'vmsos-voice-transcript' );
		const voiceToggleBtn = document.getElementById( 'vmsos-voice-toggle-btn' );
		const directPhoneInput = document.getElementById( 'vmsos-direct-phone-input' );
		const triggerCallBtn = document.getElementById( 'vmsos-btn-trigger-mobile-call' );
		const phoneCallStatus = document.getElementById( 'vmsos-phone-call-status' );

		function stopVisualizerLoop() {
			if ( animFrameId ) {
				cancelAnimationFrame( animFrameId );
				animFrameId = null;
			}
			if ( voiceCanvas ) {
				const ctx = voiceCanvas.getContext( '2d' );
				if ( ctx ) ctx.clearRect( 0, 0, voiceCanvas.width, voiceCanvas.height );
			}
		}

		function startVisualizerLoop() {
			if ( ! voiceCanvas ) return;
			const ctx = voiceCanvas.getContext( '2d' );
			if ( ! ctx ) return;

			function draw() {
				const w = voiceCanvas.width;
				const h = voiceCanvas.height;
				ctx.clearRect( 0, 0, w, h );

				const bars = 22;
				const barWidth = w / bars - 4;

				for ( let i = 0; i < bars; i++ ) {
					let barHeight;
					if ( isInVoiceCall ) {
						barHeight = Math.sin( wavePhase + i * 0.4 ) * ( h * 0.35 ) + ( h * 0.45 );
					} else {
						barHeight = 4 + Math.sin( wavePhase + i * 0.2 ) * 3;
					}

					const x = i * ( barWidth + 4 ) + 4;
					const y = ( h - barHeight ) / 2;

					ctx.fillStyle = isInVoiceCall ? '#10b981' : '#c9a227';
					ctx.beginPath();
					if ( ctx.roundRect ) {
						ctx.roundRect( x, y, barWidth, barHeight, 3 );
					} else {
						ctx.rect( x, y, barWidth, barHeight );
					}
					ctx.fill();
				}

				wavePhase += isInVoiceCall ? 0.15 : 0.04;
				animFrameId = requestAnimationFrame( draw );
			}

			stopVisualizerLoop();
			draw();
		}

		function startVoiceCall() {
			const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
			if ( ! SpeechRec ) {
				if ( voiceStatus ) voiceStatus.textContent = 'Voice recognition not supported in this browser.';
				return;
			}
			isInVoiceCall = true;
			if ( voiceToggleBtn ) {
				const lbl = voiceToggleBtn.querySelector( '.vmsos-voice-btn-label' );
				const ico = voiceToggleBtn.querySelector( '.vmsos-voice-btn-icon' );
				if ( lbl ) lbl.textContent = 'End Voice Call';
				if ( ico ) ico.textContent = '🔴';
			}
			if ( voiceRing ) voiceRing.classList.add( 'active' );
			if ( voiceStatus ) voiceStatus.textContent = 'Listening... Speak about your project or growth goals';

			try {
				voiceRecInstance = new SpeechRec();
				voiceRecInstance.continuous = false;
				voiceRecInstance.interimResults = true;
				voiceRecInstance.lang = navigator.language || 'en-US';

				voiceRecInstance.onresult = function ( e ) {
					let interim = '';
					let finalTranscript = '';
					for ( let i = e.resultIndex; i < e.results.length; ++i ) {
						if ( e.results[ i ].isFinal ) {
							finalTranscript += e.results[ i ][ 0 ].transcript;
						} else {
							interim += e.results[ i ][ 0 ].transcript;
						}
					}
					if ( interim && voiceTranscript ) {
						voiceTranscript.textContent = '"' + interim + '..."';
					}
					if ( finalTranscript ) {
						if ( voiceTranscript ) voiceTranscript.textContent = '"' + finalTranscript + '"';
						if ( voiceStatus ) voiceStatus.textContent = 'Analyzing and generating response...';
						sendVoiceQuery( finalTranscript );
					}
				};

				voiceRecInstance.onerror = voiceRecInstance.onend = function () {
					if ( ! isInVoiceCall ) return;
				};

				voiceRecInstance.start();
			} catch ( err ) {}
		}

		function endVoiceCall() {
			isInVoiceCall = false;
			if ( voiceToggleBtn ) {
				const lbl = voiceToggleBtn.querySelector( '.vmsos-voice-btn-label' );
				const ico = voiceToggleBtn.querySelector( '.vmsos-voice-btn-icon' );
				if ( lbl ) lbl.textContent = 'Start Voice Call';
				if ( ico ) ico.textContent = '🎙️';
			}
			if ( voiceRing ) voiceRing.classList.remove( 'active' );
			if ( voiceStatus ) voiceStatus.textContent = 'Voice call paused. Tap to speak again.';
			if ( voiceRecInstance ) {
				try { voiceRecInstance.stop(); } catch ( e ) {}
				voiceRecInstance = null;
			}
			if ( window.speechSynthesis ) {
				window.speechSynthesis.cancel();
			}
		}

		async function sendVoiceQuery( queryText ) {
			try {
				const res = await fetch( vmsosWidget.endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						message: queryText,
						uuid: state.uuid,
						attribution: getAttribution()
					} )
				} );
				const payload = await res.json();
				const reply = ( payload && payload.data && payload.data.reply ) || 'Thank you! How else can we assist your business today?';
				if ( voiceTranscript ) {
					voiceTranscript.textContent = '"' + reply + '"';
				}
				if ( voiceStatus ) {
					voiceStatus.textContent = 'Speaking...';
				}
				if ( window.speechSynthesis ) {
					window.speechSynthesis.cancel();
					const utter = new SpeechSynthesisUtterance( reply );
					utter.onend = function () {
						if ( isInVoiceCall ) {
							if ( voiceStatus ) voiceStatus.textContent = 'Listening... Speak anytime.';
							try { voiceRecInstance && voiceRecInstance.start(); } catch ( e ) {}
						}
					};
					window.speechSynthesis.speak( utter );
				}
			} catch ( e ) {
				if ( voiceStatus ) voiceStatus.textContent = 'Could not process audio. Please retry.';
			}
		}

		if ( voiceToggleBtn ) {
			voiceToggleBtn.addEventListener( 'click', function () {
				if ( isInVoiceCall ) {
					endVoiceCall();
				} else {
					startVoiceCall();
				}
			} );
		}

		// Direct Mobile Telephony Call Me Trigger
		if ( triggerCallBtn && directPhoneInput ) {
			triggerCallBtn.addEventListener( 'click', async function () {
				const phone = directPhoneInput.value.trim();
				if ( ! phone || phone.length < 7 ) {
					alert( 'Please enter a valid phone number with area/country code.' );
					directPhoneInput.focus();
					return;
				}

				triggerCallBtn.disabled = true;
				triggerCallBtn.textContent = 'Connecting...';
				if ( phoneCallStatus ) {
					phoneCallStatus.style.display = 'block';
					phoneCallStatus.textContent = 'Requesting immediate callback...';
				}

				const formUrl = vmsosWidget.formSubmit || vmsosWidget.endpoint.replace( '/chat/message', '/forms/submit' );
				try {
					const res = await fetch( formUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( {
							email: 'call-' + phone.replace( /[^0-9]/g, '' ) + '@vmstudio.digital',
							phone: phone,
							requirements: 'Inbound WebCall Request: Priority callback requested for ' + phone,
							source: 'widget_call_me',
							consent: true,
							attribution: getAttribution()
						} )
					} );
					if ( phoneCallStatus ) {
						phoneCallStatus.textContent = '✓ Call requested! Our live specialist is dialing your mobile now.';
					}
					triggerCallBtn.textContent = '✓ Call Queued';
				} catch ( e ) {
					if ( phoneCallStatus ) {
						phoneCallStatus.textContent = 'Could not queue call. Please call us directly or use WhatsApp.';
					}
					triggerCallBtn.disabled = false;
					triggerCallBtn.textContent = '📞 Call Me';
				}
			} );
		}

		function switchTab( tab, prefillPhone ) {
			state.activeTab = tab;
			[ ui.tabChat, ui.tabForm, ui.tabVoice ].forEach( btn => {
				if ( btn ) btn.classList.remove( 'is-active' );
			} );

			if ( tab === 'chat' ) {
				if ( ui.tabChat ) ui.tabChat.classList.add( 'is-active' );
				if ( ui.chatView ) ui.chatView.style.display = 'flex';
				if ( ui.formView ) ui.formView.style.display = 'none';
				if ( ui.voiceView ) ui.voiceView.style.display = 'none';
				stopVisualizerLoop();
				if ( isInVoiceCall ) endVoiceCall();
				if ( ui.input ) ui.input.focus();
			} else if ( tab === 'form' ) {
				if ( ui.tabForm ) ui.tabForm.classList.add( 'is-active' );
				if ( ui.chatView ) ui.chatView.style.display = 'none';
				if ( ui.formView ) ui.formView.style.display = 'block';
				if ( ui.voiceView ) ui.voiceView.style.display = 'none';
				stopVisualizerLoop();
				if ( isInVoiceCall ) endVoiceCall();

				if ( prefillPhone ) {
					const phoneInput = ui.inquiryForm.querySelector( 'input[name="phone"]' );
					if ( phoneInput ) phoneInput.value = prefillPhone;
				}
			} else if ( tab === 'voice' ) {
				if ( ui.tabVoice ) ui.tabVoice.classList.add( 'is-active' );
				if ( ui.chatView ) ui.chatView.style.display = 'none';
				if ( ui.formView ) ui.formView.style.display = 'none';
				if ( ui.voiceView ) ui.voiceView.style.display = 'flex';
				startVisualizerLoop();
				if ( prefillPhone && directPhoneInput ) {
					directPhoneInput.value = prefillPhone;
				}
			}
		}

		if ( ui.tabChat ) ui.tabChat.addEventListener( 'click', () => switchTab( 'chat' ) );
		if ( ui.tabForm ) ui.tabForm.addEventListener( 'click', () => switchTab( 'form' ) );
		if ( ui.tabVoice ) ui.tabVoice.addEventListener( 'click', () => switchTab( 'voice' ) );

		if ( ui.launcher ) ui.launcher.addEventListener( 'click', () => toggle( ! state.open ) );
		if ( ui.close ) ui.close.addEventListener( 'click', () => toggle( false ) );

		// Proactive Sales Teaser Setup
		const isDismissed = sessionStorage.getItem( 'vmsos_teaser_dismissed' );
		if ( vmsosWidget.teaserEnabled && ! isDismissed && ui.teaser ) {
			const delay = ( Number( vmsosWidget.teaserDelay ) || 3 ) * 1000;
			state.teaserTimer = setTimeout( function () {
				if ( ! state.open ) {
					setTeaserVisibility( true );
				}
			}, delay );
		}

		if ( ui.teaserClose ) {
			ui.teaserClose.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				setTeaserVisibility( false );
				sessionStorage.setItem( 'vmsos_teaser_dismissed', '1' );
			} );
		}

		if ( ui.teaserCta ) {
			ui.teaserCta.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				setTeaserVisibility( false );
				sessionStorage.setItem( 'vmsos_teaser_dismissed', '1' );
				toggle( true );
			} );
		}

		if ( ui.teaser ) {
			// Entire teaser card is interactive - clicking body/avatar/head opens chat
			ui.teaser.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '#vmsos-teaser-close' ) || e.target.closest( '.vmsos-teaser__close' ) ) {
					return;
				}
				if ( e.target.closest( '.vmsos-chip, .vmsos-teaser__chip' ) ) {
					return;
				}
				e.preventDefault();
				setTeaserVisibility( false );
				sessionStorage.setItem( 'vmsos_teaser_dismissed', '1' );
				toggle( true );
			} );

			ui.teaser.querySelectorAll( '.vmsos-chip, .vmsos-teaser__chip' ).forEach( function ( chip ) {
				chip.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					const query = chip.getAttribute( 'data-chip' ) || chip.getAttribute( 'data-query' ) || chip.textContent;
					setTeaserVisibility( false );
					sessionStorage.setItem( 'vmsos_teaser_dismissed', '1' );
					toggle( true );
					handleChipSelection( query.trim() );
				} );
			} );
		}

		if ( ui.restartBtn ) {
			ui.restartBtn.addEventListener( 'click', function () {
				state.uuid = '';
				state.lastId = 0;
				if ( state.poller ) {
					clearInterval( state.poller );
					state.poller = null;
				}
				ui.log.innerHTML = '';
				renderWelcomeHero( ui );
				if ( ui.input ) ui.input.focus();
			} );
		}

		// Delegate chip clicks inside the chat log
		if ( ui.log ) {
			ui.log.addEventListener( 'click', function ( e ) {
				const chip = e.target.closest( '.vmsos-chip, .vmsos-chip-btn' );
				if ( chip && ! chip.closest( '#vmsos-teaser' ) ) {
					e.preventDefault();
					const query = ( chip.getAttribute( 'data-query' ) || chip.getAttribute( 'data-chip' ) || chip.textContent || '' ).trim();
					if ( query ) {
						handleChipSelection( query );
					}
				}
			} );
		}

		document.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' === event.key && state.open ) {
				toggle( false );
			}
		} );

		// Global JavaScript API for theme, Elementor, and custom triggers
		window.vmsosOpenChat = function ( initialMessage ) {
			toggle( true );
			if ( initialMessage && typeof initialMessage === 'string' ) {
				sendMessage( initialMessage );
			}
		};
		window.vmsosCloseChat = function () {
			toggle( false );
		};

		// Document-level delegated click interceptor in capture phase for site-wide buttons
		document.addEventListener( 'click', function ( e ) {
			const target = e.target;
			if ( ! target ) return;

			// Check for direct chat trigger links, classes, or attributes
			const trigger = target.closest( 'a[href*="/chatbot"], a[href="#chat"], a[href="#vmsos-chat"], .open-chat, .vmsos-trigger, [data-vmsos-chat]' );
			if ( trigger ) {
				e.preventDefault();
				e.stopPropagation();
				toggle( true );
				return;
			}

			// Intercept any page button or link with chat intent (e.g. pink header "ASK AI" or hero "Chat with us")
			const btn = target.closest( 'a, button' );
			if ( btn && ! btn.closest( '#vmsos-widget-root' ) ) {
				const text = ( btn.textContent || '' ).trim().toLowerCase();
				const href = ( btn.getAttribute( 'href' ) || '' ).toLowerCase();

				// Pink header button "ASK AI" or any element linking to /chatbot/
				if ( text === 'ask ai' || text.includes( 'ask ai' ) || href.includes( 'chatbot' ) ) {
					e.preventDefault();
					e.stopPropagation();
					toggle( true );
					return;
				}

				// Generic "Chat with us" buttons
				if ( text.includes( 'chat with us' ) || text.includes( 'chat now' ) ) {
					e.preventDefault();
					e.stopPropagation();
					toggle( true );
					return;
				}
			}
		}, true );

		// Auto-open chat if URL parameter ?open_chat=1 or hash #chat is detected
		try {
			const urlParams = new URLSearchParams( window.location.search );
			if ( urlParams.get( 'open_chat' ) === '1' || window.location.hash === '#chat' || window.location.hash === '#chatbot' ) {
				setTimeout( () => toggle( true ), 350 );
			}
		} catch ( e ) {}

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

		function postReplyActions( text ) {
			const lower = ( text || '' ).toLowerCase();

			if ( lower.includes( 'whatsapp' ) && ! ui.log.querySelector( '.vmsos-action-wa' ) ) {
				renderWhatsAppCard( ui.log, 'Hi ' + ( vmsosWidget.siteName || '' ) + ', following up on our chat inquiry.' );
			}

			// Generative UI: Discovery Calendar Card
			if ( ( lower.includes( 'book' ) || lower.includes( 'schedule' ) || lower.includes( 'consultation' ) || lower.includes( 'calendar' ) || lower.includes( 'demo' ) ) && ! ui.log.querySelector( '.vmsos-booking-card' ) ) {
				renderBookingCard( ui.log, function ( date, slot ) {
					bubble( ui.log, 'visitor', 'Requested discovery consultation for ' + date + ' at ' + slot );
					bubble( ui.log, 'agent', '✓ Perfect! Your discovery session for ' + date + ' at ' + slot + ' is confirmed. A calendar invitation has been prepared for your team.' );
				} );
			}

			// Generative UI: Custom Scope & Pricing Calculator
			if ( ( lower.includes( 'pricing' ) || lower.includes( 'quote' ) || lower.includes( 'package' ) || lower.includes( 'investment' ) || lower.includes( 'cost' ) ) && ! ui.log.querySelector( '.vmsos-quote-card' ) ) {
				renderScopeCalculator( ui.log, function ( total, items ) {
					switchTab( 'form' );
					const reqTextarea = ui.inquiryForm.querySelector( 'textarea[name="requirements"]' );
					if ( reqTextarea ) {
						reqTextarea.value = 'Selected Deliverables (' + total + '):\n- ' + items.join( '\n- ' );
					}
				} );
			}
		}

		// Real-Time Token Streaming Message Sender
		async function sendMessage( messageText ) {
			const message = ( messageText || ui.input.value ).trim();
			if ( ! message || state.busy ) {
				return;
			}

			// Clean up starter chips once conversation begins to prevent clutter
			const starterChips = ui.log ? ui.log.querySelector( '#vmsos-starter-chips, .vmsos-chips-container, .vmsos-chips-wrap' ) : null;
			if ( starterChips ) {
				starterChips.remove();
			}

			state.busy = true;
			ui.input.value = '';
			bubble( ui.log, 'visitor', message );

			// Check for in-chat phone detection
			const detectedPhone = detectPhoneNumber( message );
			if ( detectedPhone ) {
				renderPhoneCallbackCard( ui.log, detectedPhone, function ( p ) {
					switchTab( 'form', p );
				} );
			}

			const typingBubble = showTypingIndicator( ui.log );
			const attr = getAttribution();

			const streamUrl = vmsosWidget.streamEndpoint || vmsosWidget.endpoint;

			try {
				const response = await fetch( streamUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						message: message,
						uuid: state.uuid,
						page_url: window.location.href,
						company_website: ( ui.honeypot ? ui.honeypot.value : '' ),
						attribution: attr,
					} ),
				} );

				const contentType = response.headers.get( 'content-type' ) || '';

				// STREAMING BRANCH (Server-Sent Events)
				if ( contentType.includes( 'text/event-stream' ) && response.body && response.body.getReader ) {
					typingBubble.remove();
					playMessageTone();

					const assistantBubble = bubble( ui.log, 'agent', '' );
					const contentWrap = assistantBubble.querySelector( '.vmsos-msg-content' ) || assistantBubble;

					const reader = response.body.getReader();
					const decoder = new TextDecoder( 'utf-8' );
					let fullReply = '';
					let streamBuffer = '';

					while ( true ) {
						const { value, done } = await reader.read();
						if ( done ) {
							break;
						}
						streamBuffer += decoder.decode( value, { stream: true } );
						const events = streamBuffer.split( '\n\n' );
						streamBuffer = events.pop();

						for ( const ev of events ) {
							const lines = ev.split( '\n' );
							for ( const l of lines ) {
								const line = l.trim();
								if ( line.startsWith( 'data: ' ) ) {
									try {
										const payload = JSON.parse( line.slice( 6 ) );
										if ( payload.token ) {
											fullReply += payload.token;
											contentWrap.innerHTML = renderMarkdown( fullReply ).innerHTML;
											ui.log.scrollTop = ui.log.scrollHeight;
										}
										if ( payload.uuid ) {
											state.uuid = payload.uuid;
										}
										if ( payload.done ) {
											if ( payload.reply && ! fullReply ) {
												fullReply = payload.reply;
												contentWrap.innerHTML = renderMarkdown( fullReply ).innerHTML;
											}
										}
									} catch ( err ) {}
								}
							}
						}
					}

					if ( fullReply ) {
						postReplyActions( fullReply );
					}
					startPolling( ui );

				} else {
					// NON-STREAMING FALLBACK BRANCH
					const payload = await response.json();
					const data = payload && payload.data ? payload.data : {};
					state.uuid = data.uuid || state.uuid;
					typingBubble.remove();

					if ( data.reply ) {
						playMessageTone();
						bubble( ui.log, 'agent', data.reply );
						postReplyActions( data.reply );
					} else if ( data.handover ) {
						bubble( ui.log, 'agent is-system', vmsosWidget.strings.waiting );
						renderWhatsAppCard( ui.log, 'Hi ' + ( vmsosWidget.siteName || '' ) + ', I requested an agent handover from chat.' );
					}
					startPolling( ui );
				}
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

			const formUrl = vmsosWidget.formSubmit || vmsosWidget.endpoint.replace( '/chat/message', '/leads/capture' );

			try {
				const res = await fetch( formUrl, {
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

					setTimeout( function () {
						switchTab( 'chat' );
						bubble( ui.log, 'visitor', 'Submitted inquiry: ' + ( requirements || 'General Consultation' ) );
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
			if ( document.hidden || ! state.open || ! state.uuid ) {
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
