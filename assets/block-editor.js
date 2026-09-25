(function() {
    'use strict';
    
    const { registerBlockType } = wp.blocks;
    const { __ } = wp.i18n;
    const { 
        PanelBody, 
        SelectControl, 
        TextControl,
        Placeholder,
        Disabled
    } = wp.components;
    const {
        InspectorControls,
        useBlockProps,
        BlockControls,
        AlignmentToolbar
    } = wp.blockEditor;
    const { createElement: el, useState } = wp.element;
    const ServerSideRender = wp.serverSideRender;

    // Common timezone options
    const timezoneOptions = [
        { label: __('Use WordPress site timezone', 'uplink-time-greeting'), value: '' },
        { label: __('America/New_York (ET)', 'uplink-time-greeting'), value: 'America/New_York' },
        { label: __('America/Chicago (CT)', 'uplink-time-greeting'), value: 'America/Chicago' },
        { label: __('America/Denver (MT)', 'uplink-time-greeting'), value: 'America/Denver' },
        { label: __('America/Los_Angeles (PT)', 'uplink-time-greeting'), value: 'America/Los_Angeles' },
        { label: __('Europe/London (GMT)', 'uplink-time-greeting'), value: 'Europe/London' },
        { label: __('Europe/Paris (CET)', 'uplink-time-greeting'), value: 'Europe/Paris' },
        { label: __('Asia/Tokyo (JST)', 'uplink-time-greeting'), value: 'Asia/Tokyo' },
        { label: __('Australia/Sydney (AEST)', 'uplink-time-greeting'), value: 'Australia/Sydney' },
        { label: __('Custom', 'uplink-time-greeting'), value: 'custom' }
    ];

    // Common date format options
    const dateFormatOptions = [
        { label: __('January 1, 2024 (F j, Y)', 'uplink-time-greeting'), value: 'F j, Y' },
        { label: __('Jan 1, 2024 (M j, Y)', 'uplink-time-greeting'), value: 'M j, Y' },
        { label: __('1/1/2024 (n/j/Y)', 'uplink-time-greeting'), value: 'n/j/Y' },
        { label: __('01/01/2024 (m/d/Y)', 'uplink-time-greeting'), value: 'm/d/Y' },
        { label: __('2024-01-01 (Y-m-d)', 'uplink-time-greeting'), value: 'Y-m-d' },
        { label: __('Monday, January 1, 2024 (l, F j, Y)', 'uplink-time-greeting'), value: 'l, F j, Y' },
        { label: __('Custom', 'uplink-time-greeting'), value: 'custom' }
    ];

    // Auto-set timezone abbreviations
    const timezoneAbbreviations = {
        'America/New_York': 'ET',
        'America/Chicago': 'CT',
        'America/Denver': 'MT',
        'America/Los_Angeles': 'PT',
        'Europe/London': 'GMT',
        'Europe/Paris': 'CET',
        'Asia/Tokyo': 'JST',
        'Australia/Sydney': 'AEST'
    };

    // Edit component
    function TimeGreetingEdit({ attributes, setAttributes }) {
        const { display, dateFormat, timezone, tzAbbr, align } = attributes;
        const [customTimezone, setCustomTimezone] = useState(false);
        const [customDateFormat, setCustomDateFormat] = useState(false);
        
        const blockProps = useBlockProps({
            className: align ? `has-text-align-${align}` : undefined,
        });

        // Handle timezone change
        function handleTimezoneChange(value) {
            setCustomTimezone(value === 'custom');
            if (value && value !== 'custom' && value !== '') {
                setAttributes({ timezone: value });
                // Auto-set abbreviation if available
                if (timezoneAbbreviations[value]) {
                    setAttributes({ tzAbbr: timezoneAbbreviations[value] });
                }
            } else if (value === '') {
                setAttributes({ timezone: '', tzAbbr: '' });
            }
        }

        // Handle date format change
        function handleDateFormatChange(value) {
            setCustomDateFormat(value === 'custom');
            if (value !== 'custom') {
                setAttributes({ dateFormat: value });
            }
        }

        // Check if we need custom fields
        const needsCustomTimezone = timezone && !timezoneOptions.find(tz => tz.value === timezone);
        const needsCustomDateFormat = !dateFormatOptions.find(option => option.value === dateFormat);

        return el('div', null,
            // Block Controls (Toolbar)
            el(BlockControls, null,
                el(AlignmentToolbar, {
                    value: align,
                    onChange: (newAlign) => setAttributes({ align: newAlign })
                })
            ),

            // Inspector Controls (Sidebar)
            el(InspectorControls, null,
                // Display Settings Panel
                el(PanelBody, {
                    title: __('Display Settings', 'uplink-time-greeting'),
                    initialOpen: true
                },
                    el(SelectControl, {
                        label: __('Display Type', 'uplink-time-greeting'),
                        value: display,
                        options: [
                            { label: __('Greeting Only', 'uplink-time-greeting'), value: 'greeting' },
                            { label: __('Date Only', 'uplink-time-greeting'), value: 'date' },
                            { label: __('Both Greeting and Date', 'uplink-time-greeting'), value: 'both' },
                            { label: __('Weekly Schedule', 'uplink-time-greeting'), value: 'schedule' }
                        ],
                        onChange: (value) => setAttributes({ display: value }),
                        help: __('Choose what to display in your time greeting block.', 'uplink-time-greeting')
                    }),

                    // Date format controls (only show if date is being displayed)
                    (display === 'date' || display === 'both') && [
                        el('hr', { key: 'divider1', style: { margin: '16px 0' } }),
                        el(SelectControl, {
                            key: 'dateFormatSelect',
                            label: __('Date Format', 'uplink-time-greeting'),
                            value: (customDateFormat || needsCustomDateFormat) ? 'custom' : dateFormat,
                            options: dateFormatOptions,
                            onChange: handleDateFormatChange,
                            help: __('Choose how the date should be formatted.', 'uplink-time-greeting')
                        }),

                        // Custom date format field
                        (customDateFormat || needsCustomDateFormat) && el(TextControl, {
                            key: 'customDateFormat',
                            label: __('Custom Date Format', 'uplink-time-greeting'),
                            value: dateFormat,
                            onChange: (value) => setAttributes({ dateFormat: value }),
                            help: __('Use PHP date format characters. Example: F j, Y', 'uplink-time-greeting')
                        })
                    ]
                ),

                // Timezone Settings Panel
                el(PanelBody, {
                    title: __('Timezone Settings', 'uplink-time-greeting'),
                    initialOpen: false
                },
                    el(SelectControl, {
                        label: __('Timezone', 'uplink-time-greeting'),
                        value: (customTimezone || needsCustomTimezone) ? 'custom' : timezone,
                        options: timezoneOptions,
                        onChange: handleTimezoneChange,
                        help: __('Follows the timezone in WordPress Settings → General unless an override is selected.', 'uplink-time-greeting')
                    }),

                    // Custom timezone field
                    (customTimezone || needsCustomTimezone) && el(TextControl, {
                        label: __('Custom Timezone', 'uplink-time-greeting'),
                        value: timezone,
                        onChange: (value) => setAttributes({ timezone: value }),
                        help: __('Enter a valid PHP timezone identifier (e.g., America/New_York)', 'uplink-time-greeting')
                    }),

                    el(TextControl, {
                        label: __('Timezone Abbreviation', 'uplink-time-greeting'),
                        value: tzAbbr,
                        onChange: (value) => setAttributes({ tzAbbr: value }),
                        help: __('Short abbreviation shown with time (e.g., ET, PT, GMT)', 'uplink-time-greeting')
                    })
                )
            ),

            // Block Content (Preview)
            el('div', blockProps,
                el(Disabled, null,
                    el(ServerSideRender, {
                        block: 'time-greeting-block/time-greeting',
                        attributes: attributes,
                        EmptyResponsePlaceholder: () => el(Placeholder, {
                            icon: 'clock',
                            label: __('Uplink Hours & Greetings', 'uplink-time-greeting')
                        }, __('Loading preview...', 'uplink-time-greeting')),
                        ErrorResponsePlaceholder: ({ response }) => el(Placeholder, {
                            icon: 'warning',
                            label: __('Time Greeting Error', 'uplink-time-greeting')
                        }, __('Error loading preview. Please check your settings.', 'uplink-time-greeting'))
                    })
                )
            )
        );
    }

    // Register the block
    registerBlockType('time-greeting-block/time-greeting', {
        apiVersion: 3,
        title: __('Uplink Hours & Greetings', 'uplink-time-greeting'),
        category: 'widgets',
        icon: 'clock',
        attributes: {
            display: { type: 'string', default: 'greeting' },
            dateFormat: { type: 'string', default: 'F j, Y' },
            timezone: { type: 'string', default: '' },
            tzAbbr: { type: 'string', default: '' },
            align: { type: 'string' }
        },
        supports: {
            html: false,
            customClassName: true,
            align: ['left', 'center', 'right', 'wide', 'full'],
            spacing: { margin: true, padding: true },
            typography: { fontSize: true, lineHeight: true },
            color: { text: true, background: true, gradients: true }
        },
        edit: TimeGreetingEdit,
        save: () => null,
    });
})();
