/* global vmsosAdmin, wp */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element ) {
		return;
	}

	const { createElement: h, useState, useEffect, useCallback, render, Fragment } = wp.element;

	async function api( path, options = {} ) {
		const response = await fetch( vmsosAdmin.root + path, {
			method: options.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': vmsosAdmin.nonce },
			body: options.body ? JSON.stringify( options.body ) : undefined,
		} );

		const payload = await response.json().catch( () => null );

		if ( ! response.ok ) {
			throw new Error( ( payload && ( payload.message || payload.code ) ) || 'Request failed' );
		}

		return payload && payload.data ? payload.data : payload;
	}

	function useAsync( loader, deps = [] ) {
		const [ state, setState ] = useState( { loading: true, error: '', data: null } );

		const reload = useCallback( () => {
			setState( ( s ) => ( { ...s, loading: true } ) );
			loader()
				.then( ( data ) => setState( { loading: false, error: '', data } ) )
				.catch( ( error ) => setState( { loading: false, error: error.message, data: null } ) );
		}, deps ); // eslint-disable-line react-hooks/exhaustive-deps

		useEffect( reload, deps ); // eslint-disable-line react-hooks/exhaustive-deps

		return [ state, reload ];
	}

	const Panel = ( { title, action, children } ) =>
		h( 'section', { className: 'vmsos-panel' },
			h( 'header', { className: 'vmsos-panel__head' },
				h( 'h2', null, title ),
				action || null
			),
			h( 'div', { className: 'vmsos-panel__body' }, children )
		);

	const Empty = ( { children } ) => h( 'p', { className: 'vmsos-empty' }, children );

	const Stat = ( { label, value, tone } ) =>
		h( 'div', { className: 'vmsos-stat' + ( tone ? ' is-' + tone : '' ) },
			h( 'span', { className: 'vmsos-stat__value' }, value ),
			h( 'span', { className: 'vmsos-stat__label' }, label )
		);

	function Dashboard() {
		const [ health ] = useAsync( () => api( 'system/health' ) );
		const [ crm ] = useAsync( () => api( 'crm/summary' ) );

		if ( health.loading ) {
			return h( Empty, null, 'Loading…' );
		}
		if ( health.error ) {
			return h( Empty, null, health.error );
		}

		const data = health.data;
		const stages = ( crm.data && crm.data.stages ) || {};
		const spend = ( data.ai_usage || [] ).reduce( ( total, row ) => total + Number( row.cost_usd || 0 ), 0 );

		return h( Fragment, null,
			h( 'div', { className: 'vmsos-stats' },
				h( Stat, { label: 'Hot leads', value: stages.hot || 0, tone: 'gold' } ),
				h( Stat, { label: 'Qualified', value: stages.qualified || 0 } ),
				h( Stat, { label: 'New', value: stages.new || 0 } ),
				h( Stat, { label: 'Jobs waiting', value: ( data.queue && data.queue.pending ) || 0 } ),
				h( Stat, { label: 'AI spend, 7 days', value: '$' + spend.toFixed( 2 ) } )
			),

			h( Panel, { title: 'AI providers' },
				h( 'table', { className: 'vmsos-table' },
					h( 'thead', null, h( 'tr', null,
						h( 'th', null, 'Provider' ),
						h( 'th', null, 'Configured' ),
						h( 'th', null, 'Tools' ),
						h( 'th', null, 'State' ),
						h( 'th', null, 'In chain' )
					) ),
					h( 'tbody', null, ( data.providers || [] ).map( ( provider ) =>
						h( 'tr', { key: provider.id },
							h( 'td', null, provider.label ),
							h( 'td', null, provider.configured ? 'Yes' : 'No key' ),
							h( 'td', null, provider.tools ? 'Yes' : '—' ),
							h( 'td', { className: provider.healthy ? 'is-ok' : 'is-bad' }, provider.healthy ? 'Healthy' : 'Cooling down' ),
							h( 'td', null, provider.in_chain ? String( ( data.providers || [] ).findIndex( ( p ) => p.id === provider.id ) + 1 ) : '—' )
						)
					) )
				)
			),

			h( Panel, { title: 'System' },
				h( 'dl', { className: 'vmsos-facts' },
					h( 'dt', null, 'Plugin' ), h( 'dd', null, data.version ),
					h( 'dt', null, 'Schema' ), h( 'dd', null, data.schema + ' of ' + data.schema_target ),
					h( 'dt', null, 'PHP' ), h( 'dd', null, data.php ),
					h( 'dt', null, 'Object cache' ), h( 'dd', null, data.object_cache ? 'On' : 'Off' ),
					h( 'dt', null, 'Failed jobs' ), h( 'dd', null, ( data.queue && data.queue.failed ) || 0 )
				)
			)
		);
	}

	function LeadDetail( { id, onClose, onChanged } ) {
		const [ state, reload ] = useAsync( () => api( 'contacts/' + id ), [ id ] );
		const [ draft, setDraft ] = useState( null );
		const [ busy, setBusy ] = useState( false );

		useEffect( () => {
			if ( state.data && state.data.contact ) {
				const c = state.data.contact;
				setDraft( {
					first_name: c.first_name || '',
					last_name: c.last_name || '',
					email: c.email || '',
					phone: c.phone || '',
					stage: c.stage || 'new',
					requirements: c.requirements || '',
				} );
			}
		}, [ state.data ] );

		async function save() {
			setBusy( true );
			try {
				await api( 'contacts/' + id, { method: 'POST', body: draft } );
				reload();
				onChanged();
			} catch ( error ) {
				window.alert( error.message );
			} finally {
				setBusy( false );
			}
		}

		async function remove() {
			if ( ! window.confirm( 'Delete this lead permanently?' ) ) {
				return;
			}
			try {
				await api( 'contacts/' + id, { method: 'DELETE' } );
				onChanged();
				onClose();
			} catch ( error ) {
				window.alert( error.message );
			}
		}

		if ( state.loading || ! draft ) {
			return h( Panel, { title: 'Lead' }, h( Empty, null, 'Loading…' ) );
		}
		if ( state.error ) {
			return h( Panel, { title: 'Lead' }, h( Empty, null, state.error ) );
		}

		const field = ( key, label, type ) =>
			h( 'label', { className: 'vmsos-form-row' },
				h( 'span', null, label ),
				type === 'textarea'
					? h( 'textarea', {
						rows: 3,
						value: draft[ key ],
						onChange: ( e ) => setDraft( { ...draft, [ key ]: e.target.value } ),
					} )
					: h( 'input', {
						type: type || 'text',
						value: draft[ key ],
						onChange: ( e ) => setDraft( { ...draft, [ key ]: e.target.value } ),
					} )
			);

		return h( Panel, {
			title: ( draft.first_name + ' ' + draft.last_name ).trim() || draft.email || 'Lead',
			action: h( 'span', { className: 'vmsos-row' },
				h( 'button', { className: 'button', onClick: onClose }, 'Close' ),
				h( 'button', { className: 'button', onClick: remove }, 'Delete' ),
				h( 'button', { className: 'button button-primary', disabled: busy, onClick: save }, 'Save' )
			),
		},
			h( 'div', { className: 'vmsos-detail' },
				h( 'div', null,
					field( 'first_name', 'First name' ),
					field( 'last_name', 'Last name' ),
					field( 'email', 'Email', 'email' ),
					field( 'phone', 'Phone', 'tel' ),
					h( 'label', { className: 'vmsos-form-row' },
						h( 'span', null, 'Stage' ),
						h( 'select', {
							value: draft.stage,
							onChange: ( e ) => setDraft( { ...draft, stage: e.target.value } ),
						}, [ 'new', 'contacted', 'qualified', 'hot', 'customer', 'lost' ].map( ( stage ) =>
							h( 'option', { key: stage, value: stage }, stage )
						) )
					),
					field( 'requirements', 'What they want', 'textarea' )
				),

				h( 'div', null,
					state.data.memory
						? h( 'div', { className: 'vmsos-memory' },
							h( 'h3', null, 'What we remember' ),
							h( 'pre', null, state.data.memory )
						)
						: h( 'p', { className: 'vmsos-muted' }, 'No cross-session memory yet — it builds after a few turns.' ),

					h( 'h3', null, 'Timeline' ),
					h( 'ul', { className: 'vmsos-timeline' }, ( state.data.timeline || [] ).map( ( item ) =>
						h( 'li', { key: item.id },
							h( 'strong', null, item.type ),
							' ',
							h( 'span', null, item.body ),
							h( 'time', null, timeAgo( item.created_at ) )
						)
					) ),
					! ( state.data.timeline || [] ).length ? h( 'p', { className: 'vmsos-muted' }, 'Nothing logged yet.' ) : null
				)
			)
		);
	}

	function Leads() {
		const [ search, setSearch ] = useState( '' );
		const [ openId, setOpenId ] = useState( 0 );
		const [ state, reload ] = useAsync( () => api( 'contacts?search=' + encodeURIComponent( search ) ), [ search ] );

		const rescore = async ( id ) => {
			await api( 'contacts/' + id + '/score', { method: 'POST' } );
			reload();
		};

		const rows = ( state.data && state.data.contacts ) || [];

		if ( openId ) {
			return h( LeadDetail, {
				id: openId,
				onClose: () => setOpenId( 0 ),
				onChanged: reload,
			} );
		}

		return h( Panel, {
			title: 'Leads',
			action: h( 'input', {
				className: 'vmsos-search',
				type: 'search',
				placeholder: 'Search name, email, phone',
				onChange: ( event ) => setSearch( event.target.value ),
			} ),
		},
			state.loading ? h( Empty, null, 'Loading…' ) :
			! rows.length ? h( Empty, null, 'No leads yet. They appear here the moment the assistant captures one.' ) :
			h( 'table', { className: 'vmsos-table' },
				h( 'thead', null, h( 'tr', null,
					h( 'th', null, 'Name' ),
					h( 'th', null, 'Contact' ),
					h( 'th', null, 'Wants' ),
					h( 'th', null, 'Stage' ),
					h( 'th', null, 'Score' ),
					h( 'th', null, '' )
				) ),
				h( 'tbody', null, rows.map( ( contact ) =>
					h( 'tr', { key: contact.id },
						h( 'td', null, ( contact.first_name + ' ' + contact.last_name ).trim() || '—' ),
						h( 'td', null, contact.email || contact.phone || '—' ),
						h( 'td', { className: 'vmsos-clip' }, contact.requirements || '—' ),
						h( 'td', null, h( 'span', { className: 'vmsos-tag is-' + contact.stage }, contact.stage ) ),
						h( 'td', null, h( 'div', { className: 'vmsos-score' },
							h( 'span', { style: { width: Math.max( 4, Number( contact.score ) ) + '%' } } ),
							h( 'b', null, contact.score )
						) ),
						h( 'td', null,
							h( 'button', {
								className: 'button-link',
								onClick: () => setOpenId( Number( contact.id ) ),
							}, 'Open' ),
							' ',
							h( 'button', {
								className: 'button-link',
								onClick: () => rescore( contact.id ),
							}, 'Rescore' )
						)
					)
				) )
			)
		);
	}

	function Conversations() {
		const [ state ] = useAsync( () => api( 'chat/conversations' ) );
		const rows = ( state.data && state.data.conversations ) || [];

		return h( Panel, { title: 'Conversations' },
			state.loading ? h( Empty, null, 'Loading…' ) :
			! rows.length ? h( Empty, null, 'Nothing yet. Turn on the widget and the first chat lands here.' ) :
			h( 'table', { className: 'vmsos-table' },
				h( 'thead', null, h( 'tr', null,
					h( 'th', null, 'Started' ),
					h( 'th', null, 'Channel' ),
					h( 'th', null, 'Status' ),
					h( 'th', null, 'Needs a human' ),
					h( 'th', null, 'Summary' )
				) ),
				h( 'tbody', null, rows.map( ( row ) =>
					h( 'tr', { key: row.id },
						h( 'td', null, row.created_at ),
						h( 'td', null, row.channel ),
						h( 'td', null, row.status ),
						h( 'td', null, Number( row.handover ) ? 'Yes' : '—' ),
						h( 'td', { className: 'vmsos-clip' }, row.summary || '—' )
					)
				) )
			)
		);
	}

	function Knowledge() {
		const [ state, reload ] = useAsync( () => api( 'knowledge/documents' ) );
		const [ title, setTitle ] = useState( '' );
		const [ content, setContent ] = useState( '' );
		const [ notice, setNotice ] = useState( '' );

		const save = async () => {
			try {
				await api( 'knowledge/documents', { method: 'POST', body: { title, content } } );
				setTitle( '' );
				setContent( '' );
				setNotice( 'Queued for indexing.' );
				reload();
			} catch ( error ) {
				setNotice( error.message );
			}
		};

		const importPosts = async () => {
			const result = await api( 'knowledge/import-posts', { method: 'POST', body: { post_types: [ 'page', 'post' ] } } );
			setNotice( 'Queued ' + result.queued + ' pages.' );
			reload();
		};

		const rows = ( state.data && state.data.documents ) || [];

		return h( Fragment, null,
			h( Panel, { title: 'Teach the assistant' },
				h( 'input', {
					className: 'vmsos-input',
					placeholder: 'Title — for example, Pricing and delivery',
					value: title,
					onChange: ( e ) => setTitle( e.target.value ),
				} ),
				h( 'textarea', {
					className: 'vmsos-input vmsos-textarea',
					rows: 6,
					placeholder: 'Paste the facts the assistant must get right.',
					value: content,
					onChange: ( e ) => setContent( e.target.value ),
				} ),
				h( 'div', { className: 'vmsos-actions' },
					h( 'button', { className: 'button button-primary', onClick: save }, 'Add to knowledge' ),
					h( 'button', { className: 'button', onClick: importPosts }, 'Import pages and posts' ),
					notice ? h( 'span', { className: 'vmsos-notice' }, notice ) : null
				)
			),

			h( Panel, { title: 'Indexed sources' },
				! rows.length ? h( Empty, null, 'Empty. The assistant will say it needs to check with a human until you add something.' ) :
				h( 'table', { className: 'vmsos-table' },
					h( 'thead', null, h( 'tr', null,
						h( 'th', null, 'Title' ),
						h( 'th', null, 'Source' ),
						h( 'th', null, 'Chunks' ),
						h( 'th', null, 'Status' )
					) ),
					h( 'tbody', null, rows.map( ( doc ) =>
						h( 'tr', { key: doc.id },
							h( 'td', null, doc.title ),
							h( 'td', null, doc.source_type ),
							h( 'td', null, doc.chunk_count ),
							h( 'td', { className: doc.status === 'indexed' ? 'is-ok' : '' }, doc.status )
						)
					) )
				)
			)
		);
	}

	function Agents() {
		const [ state ] = useAsync( () => api( 'agents' ) );
		const [ brain ] = useAsync( () => api( 'agents/brain' ) );
		const [ slug, setSlug ] = useState( 'research' );
		const [ input, setInput ] = useState( '' );
		const [ output, setOutput ] = useState( null );
		const [ running, setRunning ] = useState( false );

		const run = async () => {
			setRunning( true );
			setOutput( null );
			try {
				const result = await api( 'agents/' + slug + '/run', { method: 'POST', body: { input } } );
				setOutput( result );
			} catch ( error ) {
				setOutput( { ok: false, output: error.message, steps: [] } );
			}
			setRunning( false );
		};

		const agents = ( state.data && state.data.agents ) || [];
		const directive = ( brain.data && brain.data.brain ) || {};

		return h( Fragment, null,
			directive.directive ? h( Panel, { title: 'This hour' },
				h( 'p', { className: 'vmsos-directive' }, directive.directive ),
				h( 'p', { className: 'vmsos-muted' }, directive.insight )
			) : null,

			h( Panel, { title: 'Run an agent' },
				h( 'select', {
					className: 'vmsos-input',
					value: slug,
					onChange: ( e ) => setSlug( e.target.value ),
				}, agents.map( ( agent ) => h( 'option', { key: agent.slug, value: agent.slug }, agent.name ) ) ),

				h( 'textarea', {
					className: 'vmsos-input vmsos-textarea',
					rows: 3,
					placeholder: 'What do you want it to do?',
					value: input,
					onChange: ( e ) => setInput( e.target.value ),
				} ),

				h( 'button', {
					className: 'button button-primary',
					disabled: running || ! input.trim(),
					onClick: run,
				}, running ? 'Working…' : 'Run' ),

				output ? h( 'div', { className: 'vmsos-run' },
					h( 'p', { className: 'vmsos-run__output' }, output.output ),
					( output.steps || [] ).filter( ( step ) => step.type === 'tool' ).map( ( step, index ) =>
						h( 'code', { key: index, className: 'vmsos-step' }, step.tool + '(' + JSON.stringify( step.args ) + ')' )
					)
				) : null
			)
		);
	}

	function timeAgo( value ) {
		if ( ! value ) {
			return '';
		}
		const then = Date.parse( value.replace( ' ', 'T' ) + 'Z' );
		if ( isNaN( then ) ) {
			return value;
		}
		const seconds = Math.max( 1, Math.floor( ( Date.now() - then ) / 1000 ) );
		const units = [ [ 86400, 'd' ], [ 3600, 'h' ], [ 60, 'm' ] ];
		for ( const [ size, suffix ] of units ) {
			if ( seconds >= size ) {
				return Math.floor( seconds / size ) + suffix + ' ago';
			}
		}
		return 'just now';
	}

	function money( amount, currency ) {
		const value = Number( amount || 0 );
		return ( currency || 'INR' ) + ' ' + value.toLocaleString( undefined, { maximumFractionDigits: 0 } );
	}

	/* ---------------------------------------------------------------- Inbox */

	function Inbox() {
		const [ filter, setFilter ] = useState( 'needs_reply' );
		const [ openId, setOpenId ] = useState( 0 );
		const [ draft, setDraft ] = useState( '' );
		const [ noteMode, setNoteMode ] = useState( false );
		const [ busy, setBusy ] = useState( false );
		const [ thread, setThread ] = useState( null );

		const [ list, reloadList ] = useAsync(
			() => api( 'inbox?status=' + encodeURIComponent( filter ) ),
			[ filter ]
		);

		const loadThread = useCallback( ( id ) => {
			if ( ! id ) {
				setThread( null );
				return;
			}
			api( 'inbox/' + id )
				.then( setThread )
				.catch( () => setThread( null ) );
		}, [] );

		useEffect( () => loadThread( openId ), [ openId, loadThread ] );

		// Live-ish without websockets: refresh the open thread on a timer.
		useEffect( () => {
			if ( ! openId ) {
				return undefined;
			}
			const timer = setInterval( () => loadThread( openId ), 10000 );
			return () => clearInterval( timer );
		}, [ openId, loadThread ] );

		async function act( path, body ) {
			setBusy( true );
			try {
				await api( 'inbox/' + openId + '/' + path, { method: 'POST', body: body || {} } );
				loadThread( openId );
				reloadList();
			} catch ( error ) {
				window.alert( error.message );
			} finally {
				setBusy( false );
			}
		}

		async function send() {
			const body = draft.trim();
			if ( ! body ) {
				return;
			}
			setDraft( '' );
			await act( noteMode ? 'note' : 'reply', { body } );
		}

		const rows = ( list.data && list.data.conversations ) || [];
		const counts = ( list.data && list.data.counts ) || {};
		const conversation = thread && thread.conversation;

		const filters = [
			[ 'needs_reply', 'Needs reply', counts.needs_reply ],
			[ 'open', 'With assistant', counts.open ],
			[ 'closed', 'Closed', counts.closed ],
			[ '', 'All', null ],
		];

		return h( 'div', { className: 'vmsos-inbox' },
			h( 'aside', { className: 'vmsos-inbox__list' },
				h( 'div', { className: 'vmsos-filters' }, filters.map( ( [ key, label, count ] ) =>
					h( 'button', {
						key: key || 'all',
						className: 'vmsos-chip' + ( key === filter ? ' is-active' : '' ),
						onClick: () => setFilter( key ),
					}, label + ( count ? ' (' + count + ')' : '' ) )
				) ),

				list.loading ? h( Empty, null, 'Loading…' ) : null,
				! list.loading && ! rows.length ? h( Empty, null, 'Nothing here.' ) : null,

				rows.map( ( row ) =>
					h( 'button', {
						key: row.id,
						className: 'vmsos-thread' + ( Number( row.id ) === openId ? ' is-active' : '' ),
						onClick: () => setOpenId( Number( row.id ) ),
					},
						h( 'span', { className: 'vmsos-thread__top' },
							h( 'strong', null, row.channel === 'whatsapp' ? 'WhatsApp' : 'Web' ),
							h( 'em', null, timeAgo( row.last_message_at ) )
						),
						h( 'span', { className: 'vmsos-thread__preview' }, row.preview || '—' ),
						h( 'span', { className: 'vmsos-thread__meta' },
							row.handover === '1' || row.handover === 1 ? h( 'span', { className: 'vmsos-pill is-gold' }, 'Human' ) : null,
							row.assignee ? h( 'span', { className: 'vmsos-pill' }, row.assignee ) : null
						)
					)
				)
			),

			h( 'section', { className: 'vmsos-inbox__thread' },
				! conversation ? h( Empty, null, 'Pick a conversation.' ) : h( Fragment, null,
					h( 'header', { className: 'vmsos-thread__head' },
						h( 'div', null,
							h( 'strong', null, '#' + conversation.id + ' · ' + conversation.channel ),
							h( 'span', { className: 'vmsos-muted' }, ' ' + conversation.status )
						),
						h( 'div', { className: 'vmsos-actions' },
							Number( conversation.handover )
								? h( 'button', { className: 'button', disabled: busy, onClick: () => act( 'release' ) }, 'Give back to AI' )
								: h( 'button', { className: 'button button-primary', disabled: busy, onClick: () => act( 'takeover' ) }, 'Take over' ),
							conversation.status === 'closed'
								? h( 'button', { className: 'button', disabled: busy, onClick: () => act( 'reopen' ) }, 'Reopen' )
								: h( 'button', { className: 'button', disabled: busy, onClick: () => act( 'close' ) }, 'Close' )
						)
					),

					h( 'div', { className: 'vmsos-messages' }, ( thread.messages || [] ).map( ( message ) =>
						h( 'div', { key: message.id, className: 'vmsos-message is-' + message.role },
							h( 'span', { className: 'vmsos-message__role' },
								message.role === 'agent' ? 'You' : ( message.role === 'note' ? 'Note' : message.role )
							),
							h( 'p', null, message.content ),
							h( 'time', null, timeAgo( message.created_at ) )
						)
					) ),

					h( 'div', { className: 'vmsos-composer' },
						h( 'textarea', {
							value: draft,
							rows: 3,
							placeholder: noteMode ? 'Internal note — the visitor never sees this' : 'Write a reply…',
							onChange: ( event ) => setDraft( event.target.value ),
							onKeyDown: ( event ) => {
								if ( event.key === 'Enter' && ( event.metaKey || event.ctrlKey ) ) {
									send();
								}
							},
						} ),
						h( 'div', { className: 'vmsos-composer__row' },
							h( 'label', { className: 'vmsos-toggle' },
								h( 'input', {
									type: 'checkbox',
									checked: noteMode,
									onChange: ( event ) => setNoteMode( event.target.checked ),
								} ),
								' Internal note'
							),
							h( 'button', { className: 'button button-primary', disabled: busy || ! draft.trim(), onClick: send },
								noteMode ? 'Save note' : 'Send reply'
							)
						)
					)
				)
			)
		);
	}

	/* ---------------------------------------------------------------- Deals */

	function Deals() {
		const [ state, reload ] = useAsync( () => api( 'deals' ) );
		const [ dragging, setDragging ] = useState( null );
		const [ title, setTitle ] = useState( '' );
		const [ value, setValue ] = useState( '' );

		async function move( dealId, stage ) {
			try {
				await api( 'deals/' + dealId + '/stage', { method: 'POST', body: { stage } } );
				reload();
			} catch ( error ) {
				window.alert( error.message );
			}
		}

		async function create() {
			if ( ! title.trim() ) {
				return;
			}
			try {
				await api( 'deals', { method: 'POST', body: { title: title.trim(), value: Number( value ) || 0 } } );
				setTitle( '' );
				setValue( '' );
				reload();
			} catch ( error ) {
				window.alert( error.message );
			}
		}

		if ( state.loading ) {
			return h( Empty, null, 'Loading…' );
		}
		if ( state.error ) {
			return h( Empty, null, state.error );
		}

		const columns = state.data.columns || [];
		const wonLost = state.data.won_lost || {};
		const weighted = ( state.data.forecast || [] ).reduce( ( total, row ) => total + Number( row.weighted || 0 ), 0 );

		return h( Fragment, null,
			h( 'div', { className: 'vmsos-stats' },
				h( Stat, { label: 'Weighted pipeline', value: money( weighted, 'INR' ), tone: 'gold' } ),
				h( Stat, { label: 'Won, 30 days', value: wonLost.won || 0 } ),
				h( Stat, { label: 'Lost, 30 days', value: wonLost.lost || 0 } ),
				h( Stat, { label: 'Won value', value: money( wonLost.won_value, 'INR' ) } )
			),

			h( Panel, { title: 'New deal' },
				h( 'div', { className: 'vmsos-row' },
					h( 'input', { type: 'text', placeholder: 'Deal title', value: title, onChange: ( e ) => setTitle( e.target.value ) } ),
					h( 'input', { type: 'number', placeholder: 'Value', value, onChange: ( e ) => setValue( e.target.value ) } ),
					h( 'button', { className: 'button button-primary', onClick: create }, 'Add' )
				)
			),

			h( 'div', { className: 'vmsos-board' }, columns.map( ( column ) =>
				h( 'div', {
					key: column.stage.key,
					className: 'vmsos-column' + ( dragging ? ' is-target' : '' ),
					onDragOver: ( event ) => event.preventDefault(),
					onDrop: ( event ) => {
						event.preventDefault();
						if ( dragging ) {
							move( dragging, column.stage.key );
							setDragging( null );
						}
					},
				},
					h( 'header', null,
						h( 'strong', null, column.stage.label ),
						h( 'span', null, money( column.total, 'INR' ) )
					),
					column.deals.map( ( deal ) =>
						h( 'article', {
							key: deal.id,
							className: 'vmsos-card',
							draggable: true,
							onDragStart: () => setDragging( Number( deal.id ) ),
							onDragEnd: () => setDragging( null ),
						},
							h( 'strong', null, deal.title ),
							h( 'span', null, money( deal.value, deal.currency ) ),
							deal.email ? h( 'em', null, deal.email ) : null
						)
					),
					! column.deals.length ? h( 'p', { className: 'vmsos-column__empty' }, 'Empty' ) : null
				)
			) )
		);
	}

	/* ------------------------------------------------------------ Sequences */

	function Sequences() {
		const [ state, reload ] = useAsync( () => api( 'sequences' ) );
		const [ openId, setOpenId ] = useState( 0 );
		const [ detail, setDetail ] = useState( null );
		const [ name, setName ] = useState( '' );
		const [ step, setStep ] = useState( { subject: '', body: '', delay_minutes: 0, personalise: false } );

		const loadDetail = useCallback( ( id ) => {
			if ( ! id ) {
				setDetail( null );
				return;
			}
			api( 'sequences/' + id ).then( setDetail ).catch( () => setDetail( null ) );
		}, [] );

		useEffect( () => loadDetail( openId ), [ openId, loadDetail ] );

		async function create() {
			if ( ! name.trim() ) {
				return;
			}
			try {
				const created = await api( 'sequences', { method: 'POST', body: { name: name.trim() } } );
				setName( '' );
				reload();
				setOpenId( Number( created.sequence.id ) );
			} catch ( error ) {
				window.alert( error.message );
			}
		}

		async function addStep() {
			try {
				await api( 'sequences/' + openId + '/steps', { method: 'POST', body: step } );
				setStep( { subject: '', body: '', delay_minutes: 0, personalise: false } );
				loadDetail( openId );
			} catch ( error ) {
				window.alert( error.message );
			}
		}

		async function patch( body ) {
			try {
				await api( 'sequences/' + openId, { method: 'POST', body } );
				loadDetail( openId );
				reload();
			} catch ( error ) {
				window.alert( error.message );
			}
		}

		async function test() {
			try {
				const result = await api( 'sequences/' + openId + '/test', { method: 'POST', body: {} } );
				window.alert( 'Test sent to ' + result.sent_to );
			} catch ( error ) {
				window.alert( error.message );
			}
		}

		const rows = ( state.data && state.data.sequences ) || [];
		const triggers = ( state.data && state.data.triggers ) || [];

		return h( Fragment, null,
			h( Panel, {
				title: 'Sequences',
				action: h( 'span', { className: 'vmsos-row' },
					h( 'input', { type: 'text', placeholder: 'New sequence name', value: name, onChange: ( e ) => setName( e.target.value ) } ),
					h( 'button', { className: 'button button-primary', onClick: create }, 'Create' )
				),
			},
				state.loading ? h( Empty, null, 'Loading…' ) : null,
				! state.loading && ! rows.length ? h( Empty, null, 'No sequences yet.' ) : null,
				rows.length ? h( 'table', { className: 'vmsos-table' },
					h( 'thead', null, h( 'tr', null,
						h( 'th', null, 'Name' ),
						h( 'th', null, 'Status' ),
						h( 'th', null, 'Steps' ),
						h( 'th', null, 'Active' ),
						h( 'th', null, 'Sent' ),
						h( 'th', null, 'Opened' ),
						h( 'th', null, '' )
					) ),
					h( 'tbody', null, rows.map( ( row ) =>
						h( 'tr', { key: row.id },
							h( 'td', null, row.name ),
							h( 'td', null, h( 'span', { className: 'vmsos-pill' + ( row.status === 'active' ? ' is-gold' : '' ) }, row.status ) ),
							h( 'td', null, row.steps ),
							h( 'td', null, row.stats.active ),
							h( 'td', null, row.stats.sent ),
							h( 'td', null, row.stats.open ),
							h( 'td', null, h( 'button', { className: 'button', onClick: () => setOpenId( Number( row.id ) ) }, 'Open' ) )
						)
					) )
				) : null
			),

			detail ? h( Panel, {
				title: detail.sequence.name,
				action: h( 'span', { className: 'vmsos-row' },
					h( 'select', {
						value: detail.sequence.trigger_event || '',
						onChange: ( e ) => patch( { trigger_event: e.target.value } ),
					}, triggers.map( ( trigger ) =>
						h( 'option', { key: trigger.key || 'manual', value: trigger.key }, trigger.label )
					) ),
					h( 'button', { className: 'button', onClick: test }, 'Send test' ),
					detail.sequence.status === 'active'
						? h( 'button', { className: 'button', onClick: () => patch( { status: 'paused' } ) }, 'Pause' )
						: h( 'button', { className: 'button button-primary', onClick: () => patch( { status: 'active' } ) }, 'Activate' )
				),
			},
				h( 'ol', { className: 'vmsos-steps' }, ( detail.steps || [] ).map( ( row ) =>
					h( 'li', { key: row.id },
						h( 'strong', null, row.subject || '(no subject)' ),
						h( 'span', { className: 'vmsos-muted' },
							' after ' + row.delay_minutes + ' min' + ( Number( row.personalise ) ? ' · AI personalised' : '' )
						),
						h( 'button', {
							className: 'button-link',
							onClick: async () => {
								await api( 'sequences/steps/' + row.id, { method: 'DELETE' } );
								loadDetail( openId );
							},
						}, 'Remove' )
					)
				) ),

				h( 'div', { className: 'vmsos-step-form' },
					h( 'input', {
						type: 'text',
						placeholder: 'Subject',
						value: step.subject,
						onChange: ( e ) => setStep( { ...step, subject: e.target.value } ),
					} ),
					h( 'textarea', {
						rows: 5,
						placeholder: 'Body — merge tags like {{first_name}} and {{business}} work here',
						value: step.body,
						onChange: ( e ) => setStep( { ...step, body: e.target.value } ),
					} ),
					h( 'div', { className: 'vmsos-row' },
						h( 'label', null, 'Wait (minutes) ',
							h( 'input', {
								type: 'number',
								min: 0,
								value: step.delay_minutes,
								onChange: ( e ) => setStep( { ...step, delay_minutes: Number( e.target.value ) } ),
							} )
						),
						h( 'label', { className: 'vmsos-toggle' },
							h( 'input', {
								type: 'checkbox',
								checked: step.personalise,
								onChange: ( e ) => setStep( { ...step, personalise: e.target.checked } ),
							} ),
							' Rewrite per contact with AI'
						),
						h( 'button', { className: 'button button-primary', onClick: addStep }, 'Add step' )
					)
				),

				h( 'p', { className: 'vmsos-muted' },
					detail.stats.sent + ' sent · ' + detail.stats.open + ' opened · ' +
					detail.stats.click + ' clicked · ' + detail.stats.unsubscribe + ' unsubscribed'
				)
			) : null
		);
	}

	/* -------------------------------------------------------------- Reports */

	function Bars( { series, format } ) {
		const values = series.map( ( point ) => Number( point.total ) );
		const peak = Math.max( 1, ...values );
		const width = 100 / Math.max( 1, series.length );

		return h( 'svg', { className: 'vmsos-chart', viewBox: '0 0 100 40', preserveAspectRatio: 'none', role: 'img' },
			series.map( ( point, index ) => {
				const height = ( Number( point.total ) / peak ) * 36;
				return h( 'rect', {
					key: point.day,
					x: index * width + width * 0.15,
					y: 38 - height,
					width: width * 0.7,
					height: Math.max( 0.4, height ),
					rx: 0.4,
				}, h( 'title', null, point.day + ': ' + ( format ? format( point.total ) : point.total ) ) );
			} )
		);
	}

	function Reports() {
		const [ days, setDays ] = useState( 30 );
		const [ state ] = useAsync( () => api( 'analytics/overview?days=' + days ), [ days ] );

		if ( state.loading ) {
			return h( Empty, null, 'Loading…' );
		}
		if ( state.error ) {
			return h( Empty, null, state.error );
		}

		const data = state.data;
		const totals = data.totals || {};
		const email = data.email || {};
		const openRate = email.sent ? Math.round( ( email.open / email.sent ) * 100 ) : null;

		return h( Fragment, null,
			h( 'div', { className: 'vmsos-filters' }, [ 7, 30, 90 ].map( ( option ) =>
				h( 'button', {
					key: option,
					className: 'vmsos-chip' + ( option === days ? ' is-active' : '' ),
					onClick: () => setDays( option ),
				}, option + ' days' )
			) ),

			h( 'div', { className: 'vmsos-stats' },
				h( Stat, { label: 'Conversations', value: totals.conversations || 0 } ),
				h( Stat, { label: 'Leads', value: totals.leads || 0, tone: 'gold' } ),
				h( Stat, { label: 'Capture rate', value: totals.capture_rate === null ? '—' : totals.capture_rate + '%' } ),
				h( Stat, { label: 'AI spend', value: '$' + Number( totals.spend || 0 ).toFixed( 2 ) } ),
				h( Stat, { label: 'Cost per lead', value: totals.cost_per_lead === null ? '—' : '$' + Number( totals.cost_per_lead ).toFixed( 3 ) } )
			),

			h( Panel, { title: 'Conversations per day' }, h( Bars, { series: data.conversations || [] } ) ),
			h( Panel, { title: 'Leads per day' }, h( Bars, { series: data.leads || [] } ) ),
			h( Panel, { title: 'AI spend per day' }, h( Bars, { series: data.spend || [], format: ( v ) => '$' + Number( v ).toFixed( 3 ) } ) ),

			h( Panel, { title: 'Email' },
				h( 'p', null,
					( email.sent || 0 ) + ' sent · ' + ( email.open || 0 ) + ' opened' +
					( openRate === null ? '' : ' (' + openRate + '%)' ) +
					' · ' + ( email.click || 0 ) + ' clicked · ' + ( email.unsubscribe || 0 ) + ' unsubscribed'
				)
			),

			h( Panel, { title: 'Send budget today' },
				h( 'p', null, [ 'email', 'whatsapp' ].map( ( channel ) => {
					const b = ( data.budget && data.budget[ channel ] ) || {};
					return ( channel === 'email' ? 'Email: ' : ' · WhatsApp: ' ) +
						( b.sent || 0 ) + ' sent' +
						( b.remaining === null || b.remaining === undefined ? ' (uncapped)' : ' · ' + b.remaining + ' left' );
				} ).join( '' ) )
			),

			h( Panel, { title: 'Integrations, last 24h' },
				! ( data.integrations || [] ).length
					? h( Empty, null, 'Nothing sent yet.' )
					: h( 'table', { className: 'vmsos-table' },
						h( 'thead', null, h( 'tr', null,
							h( 'th', null, 'Integration' ),
							h( 'th', null, 'OK' ),
							h( 'th', null, 'Failed' ),
							h( 'th', null, 'Avg' ),
							h( 'th', null, 'Last failure' )
						) ),
						h( 'tbody', null, ( data.integrations || [] ).map( ( row ) =>
							h( 'tr', { key: row.integration },
								h( 'td', null, row.integration ),
								h( 'td', null, row.ok || 0 ),
								h( 'td', null, h( 'span', {
									className: Number( row.failed ) > 0 ? 'vmsos-pill is-gold' : '',
								}, row.failed || 0 ) ),
								h( 'td', null, ( row.avg_ms || 0 ) + ' ms' ),
								h( 'td', null, row.last_failure ? timeAgo( row.last_failure ) : '—' )
							)
						) )
					),

				( data.failures || [] ).length
					? h( 'ul', { className: 'vmsos-failures' }, data.failures.map( ( row, index ) =>
						h( 'li', { key: index },
							h( 'strong', null, row.integration + ' · ' + row.event ),
							' ',
							h( 'span', { className: 'vmsos-muted' }, row.message ),
							h( 'time', null, timeAgo( row.created_at ) )
						)
					) )
					: null
			),

			h( Panel, { title: 'Providers' },
				h( 'table', { className: 'vmsos-table' },
					h( 'thead', null, h( 'tr', null,
						h( 'th', null, 'Provider' ),
						h( 'th', null, 'Calls' ),
						h( 'th', null, 'Failures' ),
						h( 'th', null, 'Avg latency' ),
						h( 'th', null, 'Cost' )
					) ),
					h( 'tbody', null, ( data.providers || [] ).map( ( row ) =>
						h( 'tr', { key: row.provider },
							h( 'td', null, row.provider ),
							h( 'td', null, row.calls ),
							h( 'td', null, row.failures ),
							h( 'td', null, ( row.avg_latency || 0 ) + ' ms' ),
							h( 'td', null, '$' + Number( row.cost || 0 ).toFixed( 4 ) )
						)
					) )
				)
			)
		);
	}

	const TABS = [
		{ key: 'dashboard', label: 'Dashboard', view: Dashboard },
		{ key: 'inbox', label: 'Inbox', view: Inbox },
		{ key: 'leads', label: 'Leads', view: Leads },
		{ key: 'deals', label: 'Deals', view: Deals },
		{ key: 'sequences', label: 'Sequences', view: Sequences },
		{ key: 'reports', label: 'Reports', view: Reports },
		{ key: 'conversations', label: 'History', view: Conversations },
		{ key: 'knowledge', label: 'Knowledge', view: Knowledge },
		{ key: 'agents', label: 'Agents', view: Agents },
	];

	function App() {
		const [ active, setActive ] = useState( 'dashboard' );
		const Current = ( TABS.find( ( tab ) => tab.key === active ) || TABS[ 0 ] ).view;

		return h( 'div', { className: 'vmsos-app' },
			h( 'header', { className: 'vmsos-masthead' },
				h( 'h1', null, 'Sales OS' ),
				h( 'nav', { className: 'vmsos-tabs' }, TABS.map( ( tab ) =>
					h( 'button', {
						key: tab.key,
						className: 'vmsos-tab' + ( tab.key === active ? ' is-active' : '' ),
						onClick: () => setActive( tab.key ),
					}, tab.label )
				) )
			),
			h( 'main', null, h( Current ) )
		);
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const root = document.getElementById( 'vmsos-admin-root' );
		if ( root ) {
			render( h( App ), root );
		}
	} );
} )( window.wp );
