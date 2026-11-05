( () => {
	'use strict';

	const settings = window.BloggingLiveSettings || {};
	const strings = settings.strings || {};

	// Pages that are not live yet check for a status change at this slower pace.
	const SCHEDULED_INTERVAL = 60000;

	const request = async ( url ) => {
		const headers = { Accept: 'application/json' };
		if ( settings.nonce ) {
			headers[ 'X-WP-Nonce' ] = settings.nonce;
		}

		const response = await fetch( url, {
			credentials: 'same-origin',
			headers,
		} );

		if ( ! response.ok ) {
			throw new Error(
				`Blogging Live request failed with status ${ response.status }`
			);
		}

		return response.json();
	};

	const addQuery = ( endpoint, params ) => {
		const url = new URL( endpoint, window.location.href );
		Object.entries( params ).forEach( ( [ key, value ] ) => {
			if ( value ) {
				url.searchParams.set( key, value );
			}
		} );
		return url.toString();
	};

	/**
	 * Parse server-rendered entry markup into elements. Scripts inserted through
	 * HTML parsing never run, so they are recreated to let embeds initialize.
	 *
	 * Security: this markup is the same server-rendered the_content output the
	 * page itself prints, so re-running its scripts grants nothing new. Script
	 * tags only survive saving for users with unfiltered_html; everyone else's
	 * entry content is filtered by kses before it reaches this feed.
	 *
	 * @param {string} html Entry markup.
	 * @return {Element[]} Parsed elements.
	 */
	const toElements = ( html ) => {
		const template = document.createElement( 'template' );
		template.innerHTML = html;
		template.content.querySelectorAll( 'script' ).forEach( ( original ) => {
			const script = document.createElement( 'script' );
			Array.from( original.attributes ).forEach( ( attribute ) =>
				script.setAttribute( attribute.name, attribute.value )
			);
			script.textContent = original.textContent;
			original.replaceWith( script );
		} );
		return Array.from( template.content.children );
	};

	// Common embed libraries skip content added after they first ran.
	const processEmbeds = ( elements ) => {
		elements.forEach( ( element ) => {
			window.twttr?.widgets?.load?.( element );
		} );
		window.instgrm?.Embeds?.process?.();
	};

	const initialize = ( container ) => {
		const endpoint = container.dataset.endpoint;
		const entriesNode = container.querySelector(
			'.blogging-live__entries'
		);
		const pinnedNode = container.querySelector( '.blogging-live__pinned' );
		const newButton = container.querySelector(
			'.blogging-live__new-updates'
		);
		const olderButton = container.querySelector(
			'.blogging-live__load-older'
		);
		const message = container.querySelector( '.blogging-live__message' );
		const statusNode = container.querySelector( '.blogging-live__status' );
		const displayOrder = container.dataset.order || 'desc';
		const pinnedLimit = Number( container.dataset.pinnedLimit || 0 );
		const liveInterval =
			Math.max( 5, Number( container.dataset.refresh || 15 ) ) * 1000;
		// Only entries in the feed count as seen: the pinned section can hold
		// older entries that still need to load into the feed later.
		const seen = new Set(
			Array.from(
				entriesNode.querySelectorAll( '[data-entry-id]' )
			).map( ( entry ) => Number( entry.dataset.entryId ) )
		);
		let pending = [];
		let busy = false;
		let timer = null;

		const feedEntry = ( id ) =>
			entriesNode.querySelector( `[data-entry-id="${ id }"]` );
		const pinnedEntry = ( id ) =>
			pinnedNode?.querySelector( `[data-entry-id="${ id }"]` );

		const removeEmptyMessage = () => {
			container.querySelector( '.blogging-live__empty' )?.remove();
		};

		const announcePending = () => {
			if ( ! pending.length ) {
				newButton.hidden = true;
				return;
			}
			newButton.textContent =
				pending.length === 1
					? strings.oneNewUpdate || '1 new update'
					: ( strings.manyNewUpdates || '%d new updates' ).replace(
							'%d',
							pending.length
					  );
			newButton.hidden = false;
		};

		const dispatch = ( name, detail ) =>
			container.dispatchEvent( new CustomEvent( name, { detail } ) );

		const syncPinned = ( entry ) => {
			if ( ! pinnedNode || ! pinnedLimit ) {
				return;
			}

			const current = pinnedEntry( entry.id );
			if ( ! entry.is_pinned || ! entry.pinned_html ) {
				current?.remove();
			} else {
				const elements = toElements( entry.pinned_html );
				if ( current ) {
					current.replaceWith( ...elements );
				} else {
					pinnedNode.prepend( ...elements );
				}
				processEmbeds( elements );
				Array.from( pinnedNode.children )
					.slice( pinnedLimit )
					.forEach( ( extra ) => extra.remove() );
			}
			pinnedNode.hidden = ! pinnedNode.children.length;
		};

		const applyUpdates = ( updated = [] ) => {
			const changedIds = [];
			updated.forEach( ( entry ) => {
				const queued = pending.findIndex(
					( item ) => item.id === entry.id
				);
				if ( queued !== -1 ) {
					pending[ queued ] = entry;
					return;
				}

				const current = feedEntry( entry.id );
				if ( current ) {
					const elements = toElements( entry.html );
					current.replaceWith( ...elements );
					processEmbeds( elements );
					changedIds.push( entry.id );
				}
				syncPinned( entry );
			} );

			if ( changedIds.length ) {
				dispatch( 'blogging-live:entries-updated', {
					ids: changedIds,
				} );
			}
		};

		const applyRemovals = ( removed = [] ) => {
			const removedIds = removed.filter(
				( id ) =>
					feedEntry( id ) ||
					pinnedEntry( id ) ||
					pending.some( ( item ) => item.id === id )
			);
			removedIds.forEach( ( id ) => {
				feedEntry( id )?.remove();
				pinnedEntry( id )?.remove();
				seen.delete( id );
			} );
			pending = pending.filter( ( item ) => ! removed.includes( item.id ) );
			if ( pinnedNode ) {
				pinnedNode.hidden = ! pinnedNode.children.length;
			}

			if ( removedIds.length ) {
				dispatch( 'blogging-live:entries-removed', { ids: removedIds } );
			}
		};

		const applyStatus = ( data ) => {
			if ( ! data.status || data.status === container.dataset.status ) {
				return;
			}

			container.classList.remove(
				`blogging-live--${ container.dataset.status }`
			);
			container.classList.add( `blogging-live--${ data.status }` );
			container.dataset.status = data.status;
			if ( statusNode && data.status_label ) {
				statusNode.textContent = data.status_label;
			}
			dispatch( 'blogging-live:status-changed', { status: data.status } );
		};

		const schedule = () => {
			window.clearTimeout( timer );
			timer = null;

			const status = container.dataset.status;
			if ( status === 'ended' || document.hidden ) {
				return;
			}

			timer = window.setTimeout(
				poll,
				status === 'live' ? liveInterval : SCHEDULED_INTERVAL
			);
		};

		const poll = async () => {
			if ( busy ) {
				schedule();
				return;
			}
			busy = true;
			try {
				const data = await request(
					addQuery( endpoint, {
						after: container.dataset.latestCursor,
						since: container.dataset.serverTime,
						per_page: 50,
					} )
				);
				const received =
					data.order === 'desc'
						? [ ...data.entries ].reverse()
						: data.entries;
				const fresh = received.filter(
					( entry ) => ! seen.has( entry.id )
				);
				fresh.forEach( ( entry ) => {
					seen.add( entry.id );
					syncPinned( entry );
				} );
				pending = pending.concat( fresh );

				applyUpdates( data.updated );
				applyRemovals( data.removed );
				applyStatus( data );

				if ( data.server_time ) {
					container.dataset.serverTime = data.server_time;
				}
				if ( data.newest_cursor ) {
					container.dataset.latestCursor = data.newest_cursor;
				}
				if ( ! container.dataset.oldestCursor && data.oldest_cursor ) {
					container.dataset.oldestCursor = data.oldest_cursor;
				}
				if ( ! entriesNode.querySelector( '[data-entry-id]' ) ) {
					olderButton.hidden = ! data.has_more;
				}
				announcePending();
			} catch ( error ) {
				dispatch( 'blogging-live:error', error );
			} finally {
				busy = false;
				schedule();
			}
		};

		newButton.addEventListener( 'click', () => {
			removeEmptyMessage();
			const added = [];
			pending.forEach( ( entry ) => {
				const elements = toElements( entry.html );
				if ( displayOrder === 'desc' ) {
					entriesNode.prepend( ...elements );
				} else {
					entriesNode.append( ...elements );
				}
				elements.forEach( ( element ) => {
					element.classList.add( 'blogging-live-entry--new' );
					added.push( element );
				} );
			} );
			processEmbeds( added );

			const addedIds = pending.map( ( entry ) => entry.id );
			pending = [];
			announcePending();
			dispatch( 'blogging-live:entries-added', { ids: addedIds } );
		} );

		olderButton.addEventListener( 'click', async () => {
			if ( busy || ! container.dataset.oldestCursor ) {
				return;
			}
			busy = true;
			olderButton.disabled = true;
			message.textContent = strings.loading || 'Loading updates…';

			try {
				const data = await request(
					addQuery( endpoint, {
						before: container.dataset.oldestCursor,
					} )
				);
				const older = data.entries.filter(
					( entry ) => ! seen.has( entry.id )
				);
				older.forEach( ( entry ) => seen.add( entry.id ) );
				removeEmptyMessage();

				const elements = older.flatMap( ( entry ) =>
					toElements( entry.html )
				);
				if ( displayOrder === 'asc' ) {
					entriesNode.prepend( ...elements.reverse() );
				} else {
					entriesNode.append( ...elements );
				}
				processEmbeds( elements );

				if ( data.oldest_cursor ) {
					container.dataset.oldestCursor = data.oldest_cursor;
				}
				olderButton.hidden = ! data.has_more;
				message.textContent = older.length
					? ''
					: strings.none || 'No additional updates.';
				dispatch( 'blogging-live:entries-added', {
					ids: older.map( ( entry ) => entry.id ),
				} );
			} catch ( error ) {
				message.textContent =
					strings.error ||
					'Updates could not be loaded. Please try again.';
				dispatch( 'blogging-live:error', error );
			} finally {
				olderButton.disabled = false;
				busy = false;
			}
		} );

		// Pause in background tabs and catch up as soon as the reader returns.
		document.addEventListener( 'visibilitychange', () => {
			if ( document.hidden ) {
				window.clearTimeout( timer );
				timer = null;
			} else if ( container.dataset.status !== 'ended' ) {
				poll();
			}
		} );

		schedule();
	};

	document
		.querySelectorAll( '.blogging-live[data-endpoint]' )
		.forEach( initialize );
} )();
