const { registerBlockType } = wp.blocks;
const { createElement: el } = wp.element;
const { useBlockProps } = wp.blockEditor;
const { useSelect } = wp.data;

registerBlockType('neagent/city-categories', {
    edit: function () {
        const blockProps = useBlockProps();

        const slug = useSelect(
            (select) => select('core/editor').getCurrentPost()?.slug,
            []
        );

        return el(
            'div',
            {
                ...blockProps,
                style: {
                    padding: '12px',
                    border: '2px solid #2271b1',
                    background: '#f0f6fc'
                }
            },
            slug
                ? `City Categories Preview for: ${slug}`
                : 'City Categories Preview: Unknown city'
        );
    },

    save: function () {
        return null;
    }
});