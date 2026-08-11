(() => {
  'use strict';

  const { registerBlockType } = wp.blocks;
  const { createElement: el, Fragment } = wp.element;
  const { InspectorControls, useBlockProps } = wp.blockEditor;
  const { PanelBody, TextControl, ToggleControl } = wp.components;
  const { __ } = wp.i18n;
  const ServerSideRender = wp.serverSideRender;

  registerBlockType('blogging-live/feed', {
    edit: ({ attributes, setAttributes }) => {
      const blockProps = useBlockProps();

      return el(
        Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: __('Blogging Live settings', 'blogging-live') },
            el(TextControl, {
              label: __('Liveblog ID', 'blogging-live'),
              help: __('Leave at 0 to use the liveblog connected to this post.', 'blogging-live'),
              type: 'number',
              min: 0,
              value: attributes.bloggingLiveId || 0,
              onChange: (value) => setAttributes({ bloggingLiveId: Number(value) || 0 }),
            }),
            el(ToggleControl, {
              label: __('Show liveblog header', 'blogging-live'),
              checked: attributes.showHeader,
              onChange: (value) => setAttributes({ showHeader: value }),
            }),
          ),
        ),
        el(
          'div',
          blockProps,
          el(ServerSideRender, { block: 'blogging-live/feed', attributes }),
        ),
      );
    },
    save: () => null,
  });
})();
