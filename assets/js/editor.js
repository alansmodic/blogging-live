( () => {
	'use strict';

	const config = window.BloggingLiveEditor || {};
	const { registerPlugin } = wp.plugins;
	const { createElement: el, Fragment } = wp.element;
	const { useSelect, useDispatch } = wp.data;
	const { Button, Notice, SelectControl, ToggleControl } = wp.components;
	const { __ } = wp.i18n;
	const { addQueryArgs } = wp.url;
	// PluginDocumentSettingPanel moved from wp.editPost to wp.editor in WordPress 6.6.
	const PluginDocumentSettingPanel =
		wp.editor?.PluginDocumentSettingPanel ||
		wp.editPost.PluginDocumentSettingPanel;

	const editUrl = ( id ) =>
		addQueryArgs( `${ config.adminUrl }post.php`, {
			post: id,
			action: 'edit',
		} );

	const newEntryUrl = ( id ) =>
		addQueryArgs( `${ config.adminUrl }post-new.php`, {
			post_type: config.entryPostType,
			blogging_live_id: id,
		} );

	const usePostField = ( name ) => {
		const value = useSelect(
			( select ) => select( 'core/editor' ).getEditedPostAttribute( name ),
			[ name ]
		);
		const { editPost } = useDispatch( 'core/editor' );
		return [ value, ( next ) => editPost( { [ name ]: next } ) ];
	};

	const useMeta = ( key ) => {
		const [ meta, setMeta ] = usePostField( 'meta' );
		return [
			meta?.[ key ],
			( value ) => setMeta( { ...meta, [ key ]: value } ),
		];
	};

	const HostPanel = () => {
		const [ enabled, setEnabled ] = useMeta( '_blogging_live_enabled' );
		const [ bloggingLiveId ] = usePostField( 'blogging_live_id' );

		let details = null;
		if ( bloggingLiveId ) {
			details = el(
				'div',
				{ style: { display: 'flex', gap: '8px', flexWrap: 'wrap' } },
				el(
					Button,
					{ variant: 'secondary', href: editUrl( bloggingLiveId ) },
					__( 'Manage Blogging Live', 'blogging-live' )
				),
				el(
					Button,
					{ variant: 'secondary', href: newEntryUrl( bloggingLiveId ) },
					__( 'Add update', 'blogging-live' )
				)
			);
		} else if ( enabled && ! config.canCreate ) {
			details = el(
				Notice,
				{ status: 'warning', isDismissible: false },
				__(
					'You do not have permission to create a Blogging Live.',
					'blogging-live'
				)
			);
		} else if ( enabled ) {
			details = el(
				'p',
				null,
				__(
					'A Blogging Live container will be created when this post is saved.',
					'blogging-live'
				)
			);
		}

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'blogging-live-host',
				title: __( 'Blogging Live', 'blogging-live' ),
			},
			el( ToggleControl, {
				label: __( 'Enable Blogging Live for this content', 'blogging-live' ),
				checked: !! enabled,
				onChange: setEnabled,
				__nextHasNoMarginBottom: true,
			} ),
			details
		);
	};

	const EntryPanel = () => {
		const [ parentId, setParentId ] = usePostField( 'blogging_live_parent' );
		const [ pinned, setPinned ] = useMeta( '_blogging_live_is_pinned' );
		const [ keyEvent, setKeyEvent ] = useMeta(
			'_blogging_live_is_key_event'
		);
		const containers = useSelect(
			( select ) =>
				select( 'core' ).getEntityRecords(
					'postType',
					config.containerType,
					{
						per_page: config.containerLimit || 100,
						status: [ 'publish', 'draft', 'pending', 'private', 'future' ],
						orderby: 'modified',
						order: 'desc',
						context: 'edit',
						_fields: [ 'id', 'title' ],
					}
				),
			[]
		);

		const options = [
			{ value: '0', label: __( 'Select a Blogging Live', 'blogging-live' ) },
		].concat(
			( containers || [] ).map( ( container ) => ( {
				value: String( container.id ),
				label:
					container.title?.raw ||
					container.title?.rendered ||
					`#${ container.id }`,
			} ) )
		);

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'blogging-live-entry',
				title: __( 'Update details', 'blogging-live' ),
			},
			el( SelectControl, {
				label: __( 'Blogging Live', 'blogging-live' ),
				value: String( parentId || 0 ),
				options,
				onChange: ( value ) => setParentId( Number( value ) ),
				__nextHasNoMarginBottom: true,
			} ),
			parentId
				? null
				: el(
						Notice,
						{ status: 'warning', isDismissible: false },
						__(
							'Choose a Blogging Live before publishing. Updates without one are saved as drafts.',
							'blogging-live'
						)
				  ),
			el( ToggleControl, {
				label: __( 'Pin this update', 'blogging-live' ),
				checked: !! pinned,
				onChange: setPinned,
				__nextHasNoMarginBottom: true,
			} ),
			el( ToggleControl, {
				label: __( 'Mark as a key event', 'blogging-live' ),
				checked: !! keyEvent,
				onChange: setKeyEvent,
				__nextHasNoMarginBottom: true,
			} )
		);
	};

	registerPlugin( 'blogging-live', {
		render: () =>
			el(
				Fragment,
				null,
				config.context === 'entry' ? el( EntryPanel ) : el( HostPanel )
			),
	} );
} )();
