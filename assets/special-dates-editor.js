(function () {
    'use strict';

    const { registerBlockType } = wp.blocks;
    const { createElement: el } = wp.element;
    const { __ } = wp.i18n;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, SelectControl, TextControl, RangeControl, ToggleControl, Disabled, Placeholder } = wp.components;
    const ServerSideRender = wp.serverSideRender;

    const dateFormats = [
        { label: __('January 1, 2026', 'uplink-hours-greetings'), value: 'F j, Y' },
        { label: __('Thursday, January 1, 2026', 'uplink-hours-greetings'), value: 'l, F j, Y' },
        { label: __('Jan 1, 2026', 'uplink-hours-greetings'), value: 'M j, Y' },
        { label: __('01/01/2026', 'uplink-hours-greetings'), value: 'm/d/Y' },
        { label: __('2026-01-01', 'uplink-hours-greetings'), value: 'Y-m-d' }
    ];

    function Edit({ attributes, setAttributes }) {
        const blockProps = useBlockProps();
        return el('div', null,
            el(InspectorControls, null,
                el(PanelBody, { title: __('Special Dates', 'uplink-hours-greetings'), initialOpen: true },
                    el(SelectControl, {
                        label: __('Show', 'uplink-hours-greetings'),
                        value: attributes.mode,
                        options: [
                            { label: __('Upcoming special dates', 'uplink-hours-greetings'), value: 'upcoming' },
                            { label: __('One special date', 'uplink-hours-greetings'), value: 'single' }
                        ],
                        onChange: (mode) => setAttributes({ mode })
                    }),
                    attributes.mode === 'single' ? el(SelectControl, {
                        label: __('Saved date', 'uplink-hours-greetings'),
                        value: attributes.date,
                        options: (window.ulhgrSpecialDates && ulhgrSpecialDates.dates) || [],
                        onChange: (date) => setAttributes({ date }),
                        help: __('Choose a date already configured under Settings → Uplink Hours & Greetings → Special dates.', 'uplink-hours-greetings')
                    }) : el(RangeControl, {
                        label: __('Number of dates', 'uplink-hours-greetings'),
                        value: attributes.limit,
                        min: 1,
                        max: 20,
                        onChange: (limit) => setAttributes({ limit })
                    }),
                    el(SelectControl, {
                        label: __('Date format', 'uplink-hours-greetings'),
                        value: attributes.dateFormat,
                        options: dateFormats,
                        onChange: (dateFormat) => setAttributes({ dateFormat })
                    }),
                    el(ToggleControl, {
                        label: __('Show hours', 'uplink-hours-greetings'),
                        checked: attributes.showHours,
                        onChange: (showHours) => setAttributes({ showHours })
                    }),
                    el(ToggleControl, {
                        label: __('Show message', 'uplink-hours-greetings'),
                        checked: attributes.showMessage,
                        onChange: (showMessage) => setAttributes({ showMessage })
                    })
                )
            ),
            el('div', blockProps,
                el(Disabled, null,
                    el(ServerSideRender, {
                        block: 'uplink-hours-greetings/special-dates',
                        attributes,
                        EmptyResponsePlaceholder: () => el(Placeholder, { icon: 'calendar-alt', label: __('Uplink Special Dates', 'uplink-hours-greetings') }, __('Add a special date in the plugin settings or select another date.', 'uplink-hours-greetings')),
                        ErrorResponsePlaceholder: () => el(Placeholder, { icon: 'warning', label: __('Special Dates Error', 'uplink-hours-greetings') }, __('The preview could not be loaded.', 'uplink-hours-greetings'))
                    })
                )
            )
        );
    }

    registerBlockType('uplink-hours-greetings/special-dates', {
        apiVersion: 3,
        title: __('Uplink Special Dates', 'uplink-hours-greetings'),
        category: 'widgets',
        icon: 'calendar-alt',
        attributes: {
            mode: { type: 'string', default: 'upcoming' },
            date: { type: 'string', default: '' },
            limit: { type: 'integer', default: 5 },
            dateFormat: { type: 'string', default: 'F j, Y' },
            showMessage: { type: 'boolean', default: true },
            showHours: { type: 'boolean', default: true }
        },
        edit: Edit,
        save: () => null
    });
})();
