/**
 * BDN Live Blog — Gutenberg sidebar panel
 *
 * Registers a "Live Blog" panel in the block editor Document sidebar.
 * All it does is:
 *   1. Toggle live blogging on/off for this post
 *   2. Set the status (Live / Scheduled / Ended)
 *
 * Everything else — writing entries, editing, deleting, ending —
 * happens on the public story URL.
 */
( function () {
	const { registerPlugin }    = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editPost;
	const { useSelect, useDispatch }     = wp.data;
	const { ToggleControl, SelectControl, Notice, Spinner } = wp.components;
	const { useState, useEffect } = wp.element;

	const REST_BASE = window.BDN_LB_Editor?.rest_url || '/wp-json/bdn-liveblog/v1/';
	const NONCE     = window.BDN_LB_Editor?.nonce    || '';

	function LiveBlogPanel() {
		const postId   = useSelect( s => s( 'core/editor' ).getCurrentPostId() );
		const postMeta = useSelect( s => s( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {} );
		const { editPost } = useDispatch( 'core/editor' );

		const enabled = !! postMeta._bdn_liveblog_enabled;
		const status  = postMeta._bdn_liveblog_status || 'ended';

		const [ saving, setSaving ] = useState( false );
		const [ notice, setNotice ] = useState( '' );

		// Persist status change to REST immediately (meta also saved with post).
		async function persistStatus( newStatus ) {
			setSaving( true );
			try {
				await fetch( REST_BASE + 'status', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
					body: JSON.stringify( { post_id: postId, status: newStatus } ),
				} );
				setNotice( 'Saved.' );
				setTimeout( () => setNotice( '' ), 2500 );
			} catch ( e ) {
				setNotice( 'Save failed — try again.' );
			} finally {
				setSaving( false );
			}
		}

		function setEnabled( val ) {
			editPost( { meta: { ...postMeta, _bdn_liveblog_enabled: val } } );
			if ( val && status === 'ended' ) {
				const next = 'live';
				editPost( { meta: { ...postMeta, _bdn_liveblog_enabled: val, _bdn_liveblog_status: next } } );
				persistStatus( next );
			}
		}

		function setStatus( val ) {
			editPost( { meta: { ...postMeta, _bdn_liveblog_status: val } } );
			persistStatus( val );
		}

		const storyUrl = window.BDN_LB_Editor?.post_url || '';

		return wp.element.createElement(
			PluginDocumentSettingPanel,
			{ name: 'bdn-liveblog-panel', title: 'Live Blog', className: 'bdn-lb-editor-panel' },

			wp.element.createElement( ToggleControl, {
				label:    'Enable live blog',
				checked:  enabled,
				onChange: setEnabled,
			} ),

			enabled && wp.element.createElement( SelectControl, {
				label:    'Status',
				value:    status,
				options:  [
					{ label: '🔴 Live',      value: 'live'      },
					{ label: '🕐 Scheduled', value: 'scheduled' },
					{ label: '⬛ Ended',     value: 'ended'     },
				],
				onChange: setStatus,
			} ),

			saving && wp.element.createElement( Spinner ),

			notice && wp.element.createElement(
				Notice,
				{ status: notice.includes( 'failed' ) ? 'error' : 'success', isDismissible: false },
				notice
			),

			enabled && storyUrl && wp.element.createElement(
				'p',
				{ style: { marginTop: '12px', fontSize: '12px', lineHeight: '1.5' } },
				'Entries are written directly on the story page. ',
				wp.element.createElement(
					'a',
					{ href: storyUrl, target: '_blank', rel: 'noopener noreferrer' },
					'Open story →'
				)
			)
		);
	}

	registerPlugin( 'bdn-liveblog', { render: LiveBlogPanel } );
} )();
