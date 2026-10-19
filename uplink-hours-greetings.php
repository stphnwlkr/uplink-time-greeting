<?php
/**
 * Plugin Name: Uplink Hours & Greetings
 * Plugin URI: https://plugins.uplink.press/articles/meet-uplink-hours-and-greetings/
 * Description: Weekly business schedules, live countdowns, greetings, and dates for blocks, Bricks, Etch, and shortcodes.
 * Version: 1.1.0
 * Author: Steve Walker
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: uplink-hours-greetings
 * Requires at least: 7.0
 * Requires PHP: 8.3
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('UPLINK_HOURS_GREETINGS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('UPLINK_HOURS_GREETINGS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('UPLINK_HOURS_GREETINGS_PLUGIN_VERSION', '1.1.0');
define('UPLINK_HOURS_GREETINGS_OPTION_NAME', 'ulhgr_settings');
define('UPLINK_HOURS_GREETINGS_PERMISSIONS_OPTION', 'ulhgr_permissions');

/**
 * Main plugin class
 */
class Uplink_Hours_Greetings_Plugin {
    private static $instance = null;
    private $admin_feedback = array();

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_filter('map_meta_cap', array($this, 'map_settings_capability'), 10, 4);
        add_filter('option_page_capability_ulhgr_settings_group', array($this, 'settings_capability'));
        add_filter('etch/dynamic_data/option', array($this, 'etch_data'));
        add_filter('bricks/dynamic_tags_list', array($this, 'bricks_tags'));
        add_filter('bricks/setup/control_options', array($this, 'bricks_query_options'));
        add_filter('bricks/query/run', array($this, 'bricks_schedule_query'), 10, 2);
        add_filter('bricks/dynamic_data/render_tag', array($this, 'bricks_render_tag'), 20, 3);
        add_filter('bricks/dynamic_data/render_content', array($this, 'bricks_render_content'), 20, 3);
        add_filter('bricks/frontend/render_data', array($this, 'bricks_render_content'), 20, 2);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_assets'));
        add_action('wp_head', array($this, 'render_structured_data'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_head-settings_page_uplink-hours-greetings', array($this, 'capture_admin_feedback'), 0);

        // Register shortcode
        add_shortcode('uplink_hours_greetings', array($this, 'shortcode_handler'));

        // Plugin activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('Uplink_Hours_Greetings_Plugin', 'uninstall'));

    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Pre-release builds saved a timezone without recording whether it was
        // chosen or copied on activation. Move those installs to the live site default.
        $stored_settings = get_option(UPLINK_HOURS_GREETINGS_OPTION_NAME, false);
        if (is_array($stored_settings) && empty($stored_settings['timezone_wp_default_migrated'])) {
            $stored_settings['default_timezone'] = '';
            unset($stored_settings['timezone_default_migrated']);
            $stored_settings['timezone_wp_default_migrated'] = true;
            update_option(UPLINK_HOURS_GREETINGS_OPTION_NAME, $stored_settings);
        }

        wp_register_script('ulhgr-editor', UPLINK_HOURS_GREETINGS_PLUGIN_URL . 'assets/block-editor.js', array(
            'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render'
        ), UPLINK_HOURS_GREETINGS_PLUGIN_VERSION, true);
        wp_set_script_translations('ulhgr-editor', 'uplink-hours-greetings');
        wp_register_script('ulhgr-special-dates-editor', UPLINK_HOURS_GREETINGS_PLUGIN_URL . 'assets/special-dates-editor.js', array(
            'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render'
        ), UPLINK_HOURS_GREETINGS_PLUGIN_VERSION, true);
        wp_set_script_translations('ulhgr-special-dates-editor', 'uplink-hours-greetings');
        $special_date_options = array(array('label' => __('Select a special date', 'uplink-hours-greetings'), 'value' => ''));
        foreach ($this->get_settings()['special_dates'] as $special) {
            $special_date_options[] = array(
                'label' => ($special['label'] ? $special['label'] . ' — ' : '') . $special['date'],
                'value' => $special['date'],
            );
        }
        wp_localize_script('ulhgr-special-dates-editor', 'ulhgrSpecialDates', array('dates' => $special_date_options));
        // Register the block using block.json
        if (function_exists('register_block_type')) {
            register_block_type(__DIR__, array(
                'render_callback' => array($this, 'render_block'),
            ));
            register_block_type(__DIR__ . '/blocks/special-dates', array(
                'render_callback' => array($this, 'render_special_dates_block'),
            ));
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $default_options = array(
            'profiles' => $this->default_profiles(),
            'week' => $this->default_week(),
            'date_intro' => __('Today is', 'uplink-hours-greetings'),
            'date_suffix' => '',
            'date_only_intro' => true,
            'default_timezone' => '',
            'default_tz_abbr' => '',
            'special_dates' => array(),
            'open_label' => __('Open now', 'uplink-hours-greetings'),
            'closed_label' => __('Closed now', 'uplink-hours-greetings'),
            'schema_enabled' => false,
            'schema_type' => 'LocalBusiness',
            'schema_name' => get_bloginfo('name'),
            'schema_phone' => '',
            'schema_price_range' => '',
            'schema_street' => '',
            'schema_locality' => '',
            'schema_region' => '',
            'schema_postal_code' => '',
            'schema_country' => '',
            'timezone_wp_default_migrated' => true,
            'plugin_version' => UPLINK_HOURS_GREETINGS_PLUGIN_VERSION,
            'activation_date' => current_time('mysql')
        );

        // Only add options if they do not exist, so reactivation keeps saved settings.
        if (false === get_option(UPLINK_HOURS_GREETINGS_OPTION_NAME, false)) {
            add_option(UPLINK_HOURS_GREETINGS_OPTION_NAME, $default_options);
        }

        // Update version if different
        $current_options = get_option(UPLINK_HOURS_GREETINGS_OPTION_NAME, array());
        if (empty($current_options['profiles'])) {
            $current_options['profiles'] = $this->default_profiles();
            $current_options['profiles'][0]['intervals'] = !empty($current_options['intervals']) && is_array($current_options['intervals'])
                ? $this->normalize_intervals($current_options['intervals'])
                : $this->legacy_intervals($current_options);
            $current_options['week'] = $this->default_week();
            update_option(UPLINK_HOURS_GREETINGS_OPTION_NAME, $current_options);
        }
        if (empty($current_options['plugin_version']) || $current_options['plugin_version'] !== UPLINK_HOURS_GREETINGS_PLUGIN_VERSION) {
            $current_options['plugin_version'] = UPLINK_HOURS_GREETINGS_PLUGIN_VERSION;
            $current_options['last_updated'] = current_time('mysql');
            update_option(UPLINK_HOURS_GREETINGS_OPTION_NAME, $current_options);
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation - Clean up all data
     */
    public function deactivate() {
        // Keep settings so a temporary deactivation does not discard configuration.
    }

    /**
     * Plugin uninstall - Also clean up (in case deactivate didn't run)
     */
    public static function uninstall() {
        delete_option(UPLINK_HOURS_GREETINGS_OPTION_NAME);
        delete_option(UPLINK_HOURS_GREETINGS_PERMISSIONS_OPTION);
    }

    /**
     * Get plugin settings with defaults
     */
    private function get_settings() {
        $defaults = array(
            'profiles' => $this->default_profiles(),
            'week' => $this->default_week(),
            'date_intro' => __('Today is', 'uplink-hours-greetings'),
            'date_suffix' => '',
            'date_only_intro' => true,
            'default_timezone' => '',
            'default_tz_abbr' => '',
            'special_dates' => array(),
            'open_label' => __('Open now', 'uplink-hours-greetings'),
            'closed_label' => __('Closed now', 'uplink-hours-greetings'),
            'schema_enabled' => false,
            'schema_type' => 'LocalBusiness',
            'schema_name' => get_bloginfo('name'),
            'schema_phone' => '',
            'schema_price_range' => '',
            'schema_street' => '',
            'schema_locality' => '',
            'schema_region' => '',
            'schema_postal_code' => '',
            'schema_country' => '',
        );

        $settings = get_option(UPLINK_HOURS_GREETINGS_OPTION_NAME, $defaults);
        $settings = wp_parse_args($settings, $defaults);
        if (empty($settings['profiles']) || !is_array($settings['profiles'])) {
            $settings['profiles'] = $this->default_profiles();
            $settings['profiles'][0]['intervals'] = !empty($settings['intervals']) && is_array($settings['intervals'])
                ? $this->normalize_intervals($settings['intervals'])
                : $this->legacy_intervals($settings);
        }
        $settings['profiles'] = $this->normalize_profiles($settings['profiles']);
        $settings['week'] = $this->normalize_week($settings['week'], $settings['profiles']);
        $settings['special_dates'] = $this->normalize_special_dates($settings['special_dates']);
        return $settings;
    }

    /** Normalize one-off closures and reduced-hour dates. */
    private function normalize_special_dates($dates) {
        $normalized = array();
        foreach (array_slice(is_array($dates) ? $dates : array(), 0, 100) as $item) {
            if (!is_array($item) || empty($item['date']) || !$this->valid_iso_date($item['date'])) {
                continue;
            }
            $date = (string) $item['date'];
            $windows = array();
            foreach (array_slice(isset($item['windows']) && is_array($item['windows']) ? $item['windows'] : array(), 0, 6) as $window) {
                if (!is_array($window) || empty($window['open']) || empty($window['close'])) {
                    continue;
                }
                $open = $this->normalize_start_time($window['open']);
                $close = $this->normalize_start_time($window['close']);
                if ($this->start_minutes($close) <= $this->start_minutes($open)) {
                    continue;
                }
                $windows[] = array('open' => $open, 'close' => $close);
            }
            $normalized[$date] = array(
                'id' => !empty($item['id']) ? sanitize_title($item['id']) : 'special-' . str_replace('-', '', $date),
                'date' => $date,
                'label' => isset($item['label']) ? (string) $item['label'] : '',
                'message' => isset($item['message']) ? (string) $item['message'] : '',
                'link_url' => isset($item['link_url']) ? esc_url_raw($item['link_url']) : '',
                'link_label' => isset($item['link_label']) ? (string) $item['link_label'] : '',
                'closed' => !empty($item['closed']),
                'windows' => $windows,
            );
        }
        ksort($normalized);
        return array_values($normalized);
    }

    private function valid_iso_date($date) {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private function default_profiles() {
        return array(array(
            'id' => 'every-day',
            'name' => __('Every day', 'uplink-hours-greetings'),
            'closed' => false,
            'intervals' => $this->default_intervals(),
        ));
    }

    private function default_week() {
        return array_fill(0, 7, 'every-day');
    }

    private function default_intervals() {
        return array(
            array('timing' => 'timed', 'start' => '05:00', 'label' => __('Morning', 'uplink-hours-greetings'), 'message' => __('Good morning!', 'uplink-hours-greetings'), 'event' => ''),
            array('timing' => 'timed', 'start' => '12:00', 'label' => __('Afternoon', 'uplink-hours-greetings'), 'message' => __('Good afternoon!', 'uplink-hours-greetings'), 'event' => ''),
            array('timing' => 'timed', 'start' => '17:00', 'label' => __('Evening', 'uplink-hours-greetings'), 'message' => __('Good evening!', 'uplink-hours-greetings'), 'event' => ''),
            array('timing' => 'timed', 'start' => '22:00', 'label' => __('Night', 'uplink-hours-greetings'), 'message' => __("It's {time} {tz} and we're asleep.", 'uplink-hours-greetings'), 'event' => ''),
        );
    }

    private function legacy_intervals($settings) {
        $intervals = $this->default_intervals();
        foreach (array('morning', 'afternoon', 'evening', 'night') as $index => $period) {
            if (isset($settings[$period . '_start'])) {
                $intervals[$index]['start'] = $this->normalize_start_time($settings[$period . '_start']);
            }
            if (isset($settings[$period . '_message'])) {
                $intervals[$index]['message'] = (string) $settings[$period . '_message'];
            }
        }
        return $intervals;
    }

    private function normalize_intervals($intervals) {
        $normalized = array();
        foreach (array_slice($intervals, 0, 24) as $order => $interval) {
            if (!is_array($interval)) {
                continue;
            }
            $timing = isset($interval['timing']) && 'untimed' === $interval['timing'] ? 'untimed' : 'timed';
            if ('timed' === $timing && empty($interval['start'])) {
                continue;
            }
            $start = 'timed' === $timing ? $this->normalize_start_time($interval['start']) : '';
            $normalized[] = array(
                'timing' => $timing,
                'start' => $start,
                'label' => isset($interval['label']) ? (string) $interval['label'] : '',
                'message' => isset($interval['message']) ? (string) $interval['message'] : '',
                'event' => 'untimed' === $timing ? 'closing' : (isset($interval['event']) && in_array($interval['event'], array('opening', 'closing'), true) ? $interval['event'] : ''),
                '_order' => $order,
            );
        }
        if (!$normalized) {
            return $this->default_intervals();
        }
        usort($normalized, function ($a, $b) {
            if ($a['timing'] !== $b['timing']) {
                return 'timed' === $a['timing'] ? -1 : 1;
            }
            return 'timed' === $a['timing'] ? strcmp($a['start'], $b['start']) : $a['_order'] <=> $b['_order'];
        });
        return array_map(function ($interval) { unset($interval['_order']); return $interval; }, $normalized);
    }

    private function normalize_profiles($profiles) {
        $normalized = array();
        foreach (array_slice($profiles, 0, 14) as $profile) {
            if (!is_array($profile) || empty($profile['id']) || !preg_match('/^[a-z0-9-]+$/', $profile['id'])) {
                continue;
            }
            $normalized[$profile['id']] = array(
                'id' => $profile['id'],
                'name' => isset($profile['name']) ? (string) $profile['name'] : '',
                'closed' => !empty($profile['closed']),
                'intervals' => $this->normalize_intervals(isset($profile['intervals']) && is_array($profile['intervals']) ? $profile['intervals'] : array()),
            );
        }
        return $normalized ? array_values($normalized) : $this->default_profiles();
    }

    private function normalize_week($week, $profiles) {
        $available = array_column($profiles, 'id');
        $fallback = $available[0];
        $normalized = array();
        for ($day = 0; $day < 7; $day++) {
            $candidate = is_array($week) && isset($week[$day]) ? $week[$day] : $fallback;
            $normalized[$day] = in_array($candidate, $available, true) ? $candidate : $fallback;
        }
        return $normalized;
    }

    /**
     * Render block callback for server-side rendering
     */
    public function render_block($attributes, $content, $block) {
        // Sanitize and set defaults
        $attributes = wp_parse_args($attributes, array(
            'display' => 'greeting',
            'dateFormat' => 'F j, Y',
            'timezone' => '',
            'tzAbbr' => '',
            'align' => ''
        ));

        // Generate the greeting content
        $greeting_content = $this->generate_greeting($attributes);

        if (empty($greeting_content)) {
            return '';
        }

        // Prepare wrapper attributes
        $wrapper_attributes = get_block_wrapper_attributes(array(
            'class' => $attributes['align'] ? 'has-text-align-' . esc_attr($attributes['align']) : ''
        ));

        return sprintf(
            '<div %s>%s</div>',
            $wrapper_attributes,
            $greeting_content
        );
    }

    /** Render the dedicated Special Dates block. */
    public function render_special_dates_block($attributes, $content, $block) {
        $attributes = wp_parse_args($attributes, array(
            'mode' => 'upcoming',
            'date' => '',
            'limit' => 5,
            'dateFormat' => 'F j, Y',
            'showMessage' => true,
            'showHours' => true,
            'showLink' => true,
        ));
        $html = $this->special_dates_html($attributes);
        if ('' === $html) {
            return '';
        }
        return sprintf('<div %s>%s</div>', get_block_wrapper_attributes(), $html);
    }

    /**
     * Shortcode handler
     */
    public function shortcode_handler($atts) {
        $attributes = shortcode_atts(array(
            'display' => 'greeting',
            'date_format' => 'F j, Y',
            'timezone' => '',
            'tz_abbr' => '',
            'special_date_mode' => 'upcoming',
            'special_date' => '',
            'special_date_limit' => 5,
            'show_message' => '1',
            'show_hours' => '1',
            'show_link' => '1',
        ), $atts, 'uplink_hours_greetings');

        // Convert to camelCase for consistency with block attributes
        $normalized_attrs = array(
            'display' => sanitize_text_field($attributes['display']),
            'dateFormat' => sanitize_text_field($attributes['date_format']),
            'timezone' => sanitize_text_field($attributes['timezone']),
            'tzAbbr' => sanitize_text_field($attributes['tz_abbr']),
            'specialDateMode' => sanitize_key($attributes['special_date_mode']),
            'specialDate' => sanitize_text_field($attributes['special_date']),
            'specialDateLimit' => absint($attributes['special_date_limit']),
            'showMessage' => !in_array(strtolower((string) $attributes['show_message']), array('0', 'false', 'no'), true),
            'showHours' => !in_array(strtolower((string) $attributes['show_hours']), array('0', 'false', 'no'), true),
            'showLink' => !in_array(strtolower((string) $attributes['show_link']), array('0', 'false', 'no'), true),
        );

        return $this->generate_greeting($normalized_attrs);
    }

    /**
     * Echo function for page builders like Bricks
     */
    public function echo_greeting($atts = array()) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode_handler() escapes every rendered value.
        echo $this->shortcode_handler($atts);
    }

    /**
     * Generate greeting content with proper accessibility
     */
    private function generate_greeting($attributes) {
        $settings = $this->get_settings();

        // Set defaults from settings
        $display = $attributes['display'] ?? 'greeting';
        $date_format = $attributes['dateFormat'] ?? 'F j, Y';
        $timezone = !empty($attributes['timezone']) ? $attributes['timezone'] : ($settings['default_timezone'] ?: wp_timezone_string());
        $tz_abbr_override = !empty($attributes['tzAbbr']) ? $attributes['tzAbbr'] : '';
        $tz_abbr = $tz_abbr_override ?: $settings['default_tz_abbr'];

        // Validate display option
        if (!in_array($display, array('greeting', 'date', 'both', 'status', 'schedule', 'special_dates'), true)) {
            $display = 'greeting';
        }
        if ('schedule' === $display) {
            return $this->schedule_html($attributes['previewAt'] ?? '');
        }
        if ('special_dates' === $display) {
            return $this->special_dates_html(array(
                'mode' => $attributes['specialDateMode'] ?? 'upcoming',
                'date' => $attributes['specialDate'] ?? '',
                'limit' => $attributes['specialDateLimit'] ?? 5,
                'dateFormat' => $date_format,
                'showMessage' => $attributes['showMessage'] ?? true,
                'showHours' => $attributes['showHours'] ?? true,
                'showLink' => $attributes['showLink'] ?? true,
                'previewAt' => $attributes['previewAt'] ?? '',
            ));
        }

        // Validate and sanitize date format
        $date_format = $this->sanitize_date_format($date_format);

        // Validate timezone
        // DateTimeZone also accepts WordPress UTC offset timezones.

        // Create DateTime object with specified timezone
        try {
            $datetime = new DateTime('now', new DateTimeZone($timezone));
        } catch (Exception $e) {
            // Fallback to default timezone if there's an error
            try {
                $timezone = $settings['default_timezone'] ?: wp_timezone_string();
                $datetime = new DateTime('now', new DateTimeZone($timezone));
            } catch (Exception $e2) {
                // Final fallback to the WordPress site timezone.
                $datetime = new DateTime('now', wp_timezone());
                $timezone = wp_timezone_string();
            }
        }
        if (!empty($attributes['previewAt'])) {
            $preview = DateTime::createFromFormat('!Y-m-d H:i', $attributes['previewAt'], $datetime->getTimezone());
            if ($preview && $preview->format('Y-m-d H:i') === $attributes['previewAt']) {
                $datetime = $preview;
            }
        }

        if ('' === $tz_abbr) {
            $tz_abbr = $datetime->format('T');
        }

        if ('status' === $display) {
            $status = $this->status_snapshot($datetime, $settings);
            $next_change = !empty($status['next_change']['timestamp']) ? $status['next_change']['timestamp'] : $datetime->modify('tomorrow')->setTime(0, 0)->getTimestamp();
            return sprintf(
                '<span class="ulhgr-output" data-ulhgr-display="status" data-ulhgr-date-format="%1$s" data-ulhgr-timezone="%2$s" data-ulhgr-tz-abbr="%3$s" data-ulhgr-transition="%4$d">%5$s</span>',
                esc_attr($date_format), esc_attr($timezone), esc_attr($tz_abbr_override), (int) $next_change * 1000, $this->status_html($datetime, $settings)
            );
        }

        $output = '';

        // Generate greeting if needed
        if ($display === 'greeting' || $display === 'both') {
            $greeting = $this->get_time_greeting($datetime, $tz_abbr, $settings);
            $current_time = $datetime->format('c'); // ISO 8601 format for datetime attribute

            // Wrap in semantic HTML with accessibility
            $output .= sprintf(
                '<span class="time-greeting" data-timezone="%s"><time datetime="%s">%s</time></span>',
                esc_attr($timezone),
                esc_attr($current_time),
                $greeting
            );
        }

        // Add separator if showing both
        if ($display === 'both') {
            $output .= ' ';
        }

        // Generate date if needed
        if ($display === 'date' || $display === 'both') {
            try {
                $current_date = wp_date($date_format, $datetime->getTimestamp(), $datetime->getTimezone());
                $iso_date = $datetime->format('Y-m-d'); // ISO format for datetime attribute

                $show_intro = 'both' === $display || !empty($settings['date_only_intro']);
                $date_intro = $show_intro ? trim($settings['date_intro']) : '';
                $date_output = sprintf(
                    '<span class="time-greeting-date">%s<time datetime="%s">%s</time>%s</span>',
                    '' === $date_intro ? '' : esc_html($date_intro) . ' ',
                    esc_attr($iso_date),
                    esc_html($current_date),
                    esc_html($settings['date_suffix'])
                );
                if ('date' === $display) {
                    $output = $date_output;
                } else {
                    $output .= $date_output;
                }
            } catch (Exception $e) {
                // If date formatting fails, skip the date part
                if ($display === 'date') {
                    $output = '<span class="time-greeting-date">' . esc_html__('Date unavailable', 'uplink-hours-greetings') . '</span>';
                }
            }
        }

        $next_interval = 'date' === $display ? null : $this->next_interval($datetime, $settings);
        $next_change = 'date' === $display || !$next_interval
            ? DateTimeImmutable::createFromMutable($datetime)->modify('tomorrow')->setTime(0, 0)->getTimestamp()
            : $next_interval['timestamp'];
        return sprintf(
            '<span class="ulhgr-output" data-ulhgr-display="%s" data-ulhgr-date-format="%s" data-ulhgr-timezone="%s" data-ulhgr-tz-abbr="%s" data-ulhgr-transition="%d">%s</span>',
            esc_attr($display),
            esc_attr($date_format),
            esc_attr($timezone),
            esc_attr($tz_abbr_override),
            (int) $next_change * 1000,
            $output
        );
    }

    /**
     * Sanitize date format to prevent code execution
     */
    private function sanitize_date_format($format) {
        // Allow only safe date format characters
        $safe_format = preg_replace('/[^a-zA-Z0-9\s\-\/\\\:,.\s]/', '', $format);

        // Ensure we have a valid format
        if (empty($safe_format)) {
            return 'F j, Y'; // Default format
        }

        return $safe_format;
    }

    /** Render the active interval's message and its optional live countdown. */
    private function get_time_greeting($datetime, $tz_abbr, $settings) {
        $current = $this->active_interval($datetime, $settings);

        $time_format = get_option('time_format', 'g:i a');
        $current_time = wp_date($time_format, $datetime->getTimestamp(), $datetime->getTimezone());
        $next = $this->next_interval($datetime, $settings);
        $opening = $this->next_interval($datetime, $settings, 'opening');
        $special = $this->special_date_for($datetime->format('Y-m-d'), $settings);
        $message = esc_html($special && '' !== trim($special['message']) ? $special['message'] : $current['message']);
        $replacements = array(
            '{time}' => esc_html($current_time),
            '{tz}' => esc_html($tz_abbr),
            '{next_label}' => $next ? esc_html($next['interval']['label']) : esc_html__('Not scheduled', 'uplink-hours-greetings'),
            '{next_time}' => $next ? esc_html(wp_date($time_format, $next['timestamp'], $datetime->getTimezone())) : esc_html__('Not scheduled', 'uplink-hours-greetings'),
            '{countdown}' => $next ? $this->countdown_markup($next['timestamp'], $datetime->getTimestamp()) : esc_html__('Not scheduled', 'uplink-hours-greetings'),
            '{opening_countdown}' => $opening ? $this->countdown_markup($opening['timestamp'], $datetime->getTimestamp()) : esc_html__('No opening scheduled', 'uplink-hours-greetings'),
            '{opening_time}' => $opening ? esc_html(wp_date($time_format, $opening['timestamp'], $datetime->getTimezone())) : esc_html__('Not scheduled', 'uplink-hours-greetings'),
        );
        return strtr($message, $replacements);
    }

    private function profile_for_day($settings, $day) {
        $id = $settings['week'][$day];
        foreach ($settings['profiles'] as $profile) {
            if ($profile['id'] === $id) {
                return $profile;
            }
        }
        return $settings['profiles'][0];
    }

    private function special_date_for($date, $settings) {
        foreach ($settings['special_dates'] as $special) {
            if ($special['date'] === $date) {
                return $special;
            }
        }
        return null;
    }

    /** Resolve a calendar date, applying a special-date override before its weekday profile. */
    private function profile_for_date($settings, $date) {
        $special = $this->special_date_for($date->format('Y-m-d'), $settings);
        if (!$special) {
            return $this->profile_for_day($settings, (int) $date->format('w'));
        }
        $intervals = array();
        foreach ($special['windows'] as $index => $window) {
            $intervals[] = array('timing' => 'timed', 'start' => $window['open'], 'label' => __('Opening', 'uplink-hours-greetings'), 'message' => $special['message'], 'event' => 'opening');
            $intervals[] = array('timing' => 'timed', 'start' => $window['close'], 'label' => __('Closing', 'uplink-hours-greetings'), 'message' => $special['message'], 'event' => 'closing');
        }
        usort($intervals, function ($a, $b) { return strcmp($a['start'], $b['start']); });
        return array(
            'id' => $special['id'],
            'name' => $special['label'],
            'closed' => !empty($special['closed']),
            'intervals' => $intervals,
            'special' => true,
            'special_date' => $special['date'],
            'special_label' => $special['label'],
            'special_message' => $special['message'],
            'special_link_url' => $special['link_url'],
            'special_link_label' => $special['link_label'],
        );
    }

    private function active_interval($datetime, $settings) {
        $now = DateTimeImmutable::createFromMutable($datetime);
        $midnight = $now->setTime(0, 0);
        $latest = null;
        for ($offset = -7; $offset <= 0; $offset++) {
            $date = $midnight->modify($offset . ' days');
            $profile = $this->profile_for_date($settings, $date);
            if (!empty($profile['closed'])) {
                continue;
            }
            foreach ($profile['intervals'] as $interval) {
                if ('timed' !== ($interval['timing'] ?? 'timed') || empty($interval['start'])) {
                    continue;
                }
                list($hour, $minute) = array_map('intval', explode(':', $interval['start']));
                $timestamp = $date->setTime($hour, $minute)->getTimestamp();
                if ($timestamp <= $now->getTimestamp() && (null === $latest || $timestamp > $latest['timestamp'])) {
                    $latest = array('interval' => $interval, 'timestamp' => $timestamp);
                }
            }
        }
        if ($latest) {
            return $latest['interval'];
        }
        foreach ($settings['profiles'] as $profile) {
            foreach ($profile['intervals'] as $interval) {
                if ('timed' === ($interval['timing'] ?? 'timed') && !empty($interval['start'])) {
                    return $interval;
                }
            }
        }
        return $this->default_intervals()[0];
    }

    private function next_interval($datetime, $settings, $event = '') {
        $now = DateTimeImmutable::createFromMutable($datetime);
        $midnight = $now->setTime(0, 0);
        $next = null;
        for ($offset = 0; $offset <= 21; $offset++) {
            $date = $midnight->modify('+' . $offset . ' days');
            $profile = $this->profile_for_date($settings, $date);
            if (!empty($profile['closed'])) {
                continue;
            }
            foreach ($profile['intervals'] as $interval) {
                if ('timed' !== ($interval['timing'] ?? 'timed') || empty($interval['start'])) {
                    continue;
                }
                if ('' !== $event && ($event !== $interval['event'] || !empty($profile['closed']))) {
                    continue;
                }
                list($hour, $minute) = array_map('intval', explode(':', $interval['start']));
                $timestamp = $date->setTime($hour, $minute)->getTimestamp();
                if ($timestamp <= $now->getTimestamp()) {
                    continue;
                }
                if (null === $next || $timestamp < $next['timestamp']) {
                    $next = array('interval' => $interval, 'timestamp' => $timestamp);
                }
            }
        }
        return $next;
    }

    /** Compute the current open state and the next opening or closing transition. */
    private function status_snapshot($datetime, $settings) {
        $now = DateTimeImmutable::createFromMutable($datetime);
        $midnight = $now->setTime(0, 0);
        $transitions = array();
        for ($offset = -8; $offset <= 21; $offset++) {
            $date = $midnight->modify(($offset < 0 ? '' : '+') . $offset . ' days');
            $profile = $this->profile_for_date($settings, $date);
            if (!empty($profile['closed']) || !empty($profile['special'])) {
                $transitions[] = array('type' => 'closing', 'timestamp' => $date->getTimestamp(), 'reset' => true, 'label' => $profile['special_label'] ?? '', 'date' => $date->format('Y-m-d'));
            }
            if (!empty($profile['closed'])) {
                continue;
            }
            foreach ($profile['intervals'] as $interval) {
                if ('timed' !== ($interval['timing'] ?? 'timed') || empty($interval['start']) || !in_array($interval['event'], array('opening', 'closing'), true)) {
                    continue;
                }
                list($hour, $minute) = array_map('intval', explode(':', $interval['start']));
                $event_date = $date;
                if ('closing' === $interval['event']) {
                    $opening_before = array_filter($profile['intervals'], function ($candidate) use ($interval) {
                        return 'opening' === ($candidate['event'] ?? '') && $this->start_minutes($candidate['start']) > $this->start_minutes($interval['start']);
                    });
                    if ($opening_before) {
                        $event_date = $date->modify('+1 day');
                    }
                }
                $transitions[] = array(
                    'type' => $interval['event'],
                    'timestamp' => $event_date->setTime($hour, $minute)->getTimestamp(),
                    'reset' => false,
                    'label' => $interval['label'],
                    'date' => $date->format('Y-m-d'),
                    'special' => !empty($profile['special']),
                    'special_label' => $profile['special_label'] ?? '',
                );
            }
        }
        usort($transitions, function ($a, $b) {
            if ($a['timestamp'] === $b['timestamp']) {
                return (!empty($a['reset']) ? -1 : 1);
            }
            return $a['timestamp'] <=> $b['timestamp'];
        });
        $latest = null;
        $future = array();
        foreach ($transitions as $transition) {
            if ($transition['timestamp'] <= $now->getTimestamp()) {
                $latest = $transition;
            } else {
                $future[] = $transition;
            }
        }
        $is_open = $latest && 'opening' === $latest['type'];
        $next = null;
        foreach ($future as $transition) {
            if (($is_open && 'closing' === $transition['type']) || (!$is_open && 'opening' === $transition['type'])) {
                $next = $transition;
                break;
            }
        }
        $special = $this->special_date_for($now->format('Y-m-d'), $settings);
        $time_format = get_option('time_format', 'g:i a');
        $date_format = get_option('date_format', 'F j, Y');
        $next_value = array();
        if ($next) {
            $next_value = array_merge($next, array(
                'time' => wp_date($time_format, $next['timestamp'], $now->getTimezone()),
                'date_label' => wp_date($date_format, $next['timestamp'], $now->getTimezone()),
                'countdown' => $this->format_duration($next['timestamp'] - $now->getTimestamp()),
            ));
        }
        $current_window = array();
        if ($is_open && $latest) {
            $current_window = array(
                'start' => wp_date('H:i', $latest['timestamp'], $now->getTimezone()),
                'start_label' => wp_date($time_format, $latest['timestamp'], $now->getTimezone()),
                'start_timestamp' => $latest['timestamp'],
                'end' => $next ? wp_date('H:i', $next['timestamp'], $now->getTimezone()) : '',
                'end_label' => $next ? wp_date($time_format, $next['timestamp'], $now->getTimezone()) : '',
                'end_timestamp' => $next['timestamp'] ?? 0,
            );
        }
        return array(
            'is_open' => (bool) $is_open,
            'state' => $is_open ? 'open' : 'closed',
            'label' => $is_open ? $settings['open_label'] : $settings['closed_label'],
            'current_window' => $current_window,
            'next_change' => $next_value,
            'is_special_date' => (bool) $special,
            'special_date' => $special ? $special['date'] : '',
            'special_label' => $special ? $special['label'] : '',
            'special_link_url' => $special ? $special['link_url'] : '',
            'special_link_label' => $special ? $special['link_label'] : '',
        );
    }

    private function status_html($datetime, $settings) {
        $status = $this->status_snapshot($datetime, $settings);
        $next = $status['next_change'];
        $detail = '';
        if ($next) {
            if ('opening' === $next['type']) {
                /* translators: 1: weekday name, 2: opening time. */
                $detail = sprintf(__('Opens %1$s at %2$s', 'uplink-hours-greetings'), wp_date('l', $next['timestamp'], $datetime->getTimezone()), $next['time']);
            } else {
                /* translators: %s: closing time. */
                $detail = sprintf(__('Closes at %s', 'uplink-hours-greetings'), $next['time']);
            }
        }
        return sprintf(
            '<span class="ulhgr-status ulhgr-status--%1$s" data-state="%1$s"><strong class="ulhgr-status__label">%2$s</strong>%3$s</span>',
            esc_attr($status['state']),
            esc_html($status['label']),
            $detail ? '<span class="ulhgr-status__detail"> ' . esc_html($detail) . '</span>' : ''
        );
    }

    private function countdown_markup($target, $now) {
        return sprintf(
            '<span class="ulhgr-countdown" data-ulhgr-target="%1$d">%2$s</span>',
            (int) $target * 1000,
            esc_html($this->format_duration($target - $now))
        );
    }

    private function format_duration($seconds) {
        $minutes = max(1, (int) ceil($seconds / 60));
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $remainder = $minutes % 60;
        $parts = array();
        if ($days) {
            /* translators: %d: number of days until the next scheduled event. */
            $parts[] = sprintf(_n('%d day', '%d days', $days, 'uplink-hours-greetings'), $days);
        }
        if ($hours) {
            /* translators: %d: number of hours until the next scheduled event. */
            $parts[] = sprintf(_n('%d hour', '%d hours', $hours, 'uplink-hours-greetings'), $hours);
        }
        if ($remainder && !$days) {
            /* translators: %d: number of minutes until the next scheduled event. */
            $parts[] = sprintf(_n('%d minute', '%d minutes', $remainder, 'uplink-hours-greetings'), $remainder);
        }
        return implode(' ', $parts);
    }

    /** Accept legacy integer hours and the current HH:MM setting. */
    private function normalize_start_time($value) {
        if (is_numeric($value) && (int) $value >= 0 && (int) $value <= 23) {
            return sprintf('%02d:00', (int) $value);
        }
        if (is_string($value) && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
            return $value;
        }
        return '00:00';
    }

    private function start_minutes($value) {
        $parts = explode(':', $this->normalize_start_time($value));
        return ((int) $parts[0] * 60) + (int) $parts[1];
    }

    /** A localized, week-start-aware view of the shared business hours. */
    private function schedule_rows($preview_at = '') {
        $settings = $this->get_settings();
        $start = (int) get_option('start_of_week', 0);
        if ($start < 0 || $start > 6) {
            $start = 0;
        }
        $rows = array();
        $time_format = get_option('time_format', 'g:i a');
        try {
            $timezone = new DateTimeZone($settings['default_timezone'] ?: wp_timezone_string());
        } catch (Exception $error) {
            $timezone = wp_timezone();
        }
        $reference = new DateTimeImmutable('now', $timezone);
        if (is_string($preview_at) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $preview_at)) {
            $candidate = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $preview_at, $timezone);
            if ($candidate) {
                $reference = $candidate;
            }
        }
        $days_back = ((int) $reference->format('w') - $start + 7) % 7;
        $week_start = $reference->setTime(0, 0)->modify('-' . $days_back . ' days');
        for ($offset = 0; $offset < 7; $offset++) {
            $day = ($start + $offset) % 7;
            $calendar_date = $week_start->modify('+' . $offset . ' days');
            $profile = $this->profile_for_date($settings, $calendar_date);
            $special = $this->special_date_for($calendar_date->format('Y-m-d'), $settings);
            $hours = array();
            $windows = array();
            if (empty($profile['closed'])) {
                foreach ($profile['intervals'] as $index => $interval) {
                    if ('opening' !== $interval['event']) {
                        continue;
                    }
                    $closing = null;
                    $closing_day = $day;
                    foreach (array_slice($profile['intervals'], $index + 1) as $later) {
                        if ('opening' === $later['event']) {
                            break;
                        }
                        if ('closing' === $later['event']) {
                            $closing = $later;
                            break;
                        }
                    }
                    if (null === $closing) {
                        $closing_day = ($day + 1) % 7;
                        $next_profile = $this->profile_for_date($settings, $calendar_date->modify('+1 day'));
                        foreach ($next_profile['intervals'] as $later) {
                            if ('opening' === $later['event']) {
                                break;
                            }
                            if ('closing' === $later['event'] && 'timed' === ($later['timing'] ?? 'timed')) {
                                $closing = $later;
                                break;
                            }
                        }
                    }
                    $from = $calendar_date->setTime((int) substr($interval['start'], 0, 2), (int) substr($interval['start'], 3, 2));
                    $from_label = wp_date($time_format, $from->getTimestamp(), $timezone);
                    $window = array('start' => $interval['start'], 'start_label' => $from_label, 'end' => '', 'end_label' => '', 'end_type' => 'none', 'overnight' => false);
                    if (null === $closing) {
                        /* translators: %s: opening time without a matching closing time. */
                        $hours[] = sprintf(__('From %s', 'uplink-hours-greetings'), $from_label);
                    } elseif ('untimed' === ($closing['timing'] ?? 'timed')) {
                        $window['end_label'] = '' !== trim($closing['label']) ? $closing['label'] : __('Closing time not set', 'uplink-hours-greetings');
                        $window['end_type'] = 'text';
                        $hours[] = $from_label . '–' . $window['end_label'];
                    } else {
                        $to = ($closing_day === $day ? $calendar_date : $calendar_date->modify('+1 day'))->setTime((int) substr($closing['start'], 0, 2), (int) substr($closing['start'], 3, 2));
                        $window['end'] = $closing['start'];
                        $window['end_label'] = wp_date($time_format, $to->getTimestamp(), $timezone);
                        $window['end_type'] = 'time';
                        $window['overnight'] = $closing_day !== $day;
                        $hours[] = $from_label . '–' . $window['end_label'];
                    }
                    $windows[] = $window;
                }
            }
            $rows[] = array(
                'key' => strtolower($calendar_date->format('l')),
                'number' => $day,
                'day' => wp_date('l', $calendar_date->getTimestamp(), $timezone),
                'date' => $calendar_date->format('Y-m-d'),
                'hours' => !empty($profile['closed']) ? __('Closed', 'uplink-hours-greetings') : ($hours ? implode(', ', $hours) : __('Hours not set', 'uplink-hours-greetings')),
                'state' => !empty($profile['closed']) ? 'closed' : ($hours ? 'open' : 'unset'),
                'is_today' => $calendar_date->format('Y-m-d') === $reference->format('Y-m-d'),
                'is_special' => (bool) $special,
                'special_label' => $special ? $special['label'] : '',
                'special_link_url' => $special ? $special['link_url'] : '',
                'special_link_label' => $special ? $special['link_label'] : '',
                'windows' => $windows,
            );
        }
        return $rows;
    }

    private function schedule_html($preview_at = '') {
        $today = (int) wp_date('w', time(), wp_timezone());
        if (is_string($preview_at) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $preview_at)) {
            $preview = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $preview_at, wp_timezone());
            if ($preview) {
                $today = (int) $preview->format('w');
            }
        }
        $html = '<dl class="ulhgr-schedule" aria-label="' . esc_attr__('Weekly business hours', 'uplink-hours-greetings') . '">';
        foreach ($this->schedule_rows($preview_at) as $row) {
            $html .= '<div class="ulhgr-schedule__day ulhgr-schedule__day--' . esc_attr($row['state']) . ($row['is_today'] ? ' ulhgr-schedule__day--today' : '') . ($row['is_special'] ? ' ulhgr-schedule__day--special' : '') . '" data-day="' . esc_attr($row['key']) . '" data-date="' . esc_attr($row['date']) . '" data-state="' . esc_attr($row['state']) . '" data-special="' . ($row['is_special'] ? 'true' : 'false') . '">';
            $html .= '<dt class="ulhgr-schedule__name">' . esc_html($row['day']);
            if ($row['special_label']) {
                $special_label = esc_html($row['special_label']);
                if ($row['special_link_url']) {
                    $special_label = '<a class="ulhgr-schedule__special-link" href="' . esc_url($row['special_link_url']) . '">' . $special_label . '</a>';
                }
                $html .= '<span class="ulhgr-schedule__special">' . $special_label . '</span>';
            }
            $html .= '</dt><dd class="ulhgr-schedule__hours">';
            if (!$row['windows']) {
                $html .= '<span class="ulhgr-schedule__status">' . esc_html($row['hours']) . '</span>';
            } else {
                foreach ($row['windows'] as $index => $window) {
                    if ($index) {
                        $html .= '<span class="ulhgr-schedule__between">, </span>';
                    }
                    $html .= '<span class="ulhgr-schedule__interval"' . ($window['overnight'] ? ' data-overnight="true"' : '') . '>';
                    if ('none' === $window['end_type']) {
                        $html .= '<span class="ulhgr-schedule__from">' . esc_html__('From', 'uplink-hours-greetings') . ' </span>';
                    }
                    $html .= '<time class="ulhgr-schedule__opens" datetime="' . esc_attr($window['start']) . '">' . esc_html($window['start_label']) . '</time>';
                    if ('none' !== $window['end_type']) {
                        $html .= '<span class="ulhgr-schedule__separator" aria-hidden="true">–</span><span class="ulhgr-visually-hidden">' . esc_html__(' to ', 'uplink-hours-greetings') . '</span>';
                        if ('time' === $window['end_type']) {
                            $html .= '<time class="ulhgr-schedule__closes" datetime="' . esc_attr($window['end']) . '">' . esc_html($window['end_label']) . '</time>';
                        } else {
                            $html .= '<span class="ulhgr-schedule__closes ulhgr-schedule__closes--text">' . esc_html($window['end_label']) . '</span>';
                        }
                        if ($window['overnight']) {
                            $html .= '<span class="ulhgr-visually-hidden">' . esc_html__(' next day', 'uplink-hours-greetings') . '</span>';
                        }
                    }
                    $html .= '</span>';
                }
            }
            $html .= '</dd></div>';
        }
        return $html . '</dl>';
    }

    /** Semantic output for one special date or a list of upcoming dates. */
    private function special_dates_html($attributes = array()) {
        $settings = $this->get_settings();
        $mode = isset($attributes['mode']) && 'single' === $attributes['mode'] ? 'single' : 'upcoming';
        $limit = min(20, max(1, absint($attributes['limit'] ?? 5)));
        $date_format = $this->sanitize_date_format($attributes['dateFormat'] ?? get_option('date_format', 'F j, Y'));
        $show_message = !isset($attributes['showMessage']) || (bool) $attributes['showMessage'];
        $show_hours = !isset($attributes['showHours']) || (bool) $attributes['showHours'];
        $show_link = !isset($attributes['showLink']) || (bool) $attributes['showLink'];
        try {
            $timezone = new DateTimeZone($settings['default_timezone'] ?: wp_timezone_string());
        } catch (Exception $error) {
            $timezone = wp_timezone();
        }
        $reference = new DateTimeImmutable('now', $timezone);
        if (!empty($attributes['previewAt']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $attributes['previewAt'])) {
            $preview = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $attributes['previewAt'], $timezone);
            if ($preview) $reference = $preview;
        }
        $dates = array();
        if ('single' === $mode) {
            $selected = isset($attributes['date']) ? (string) $attributes['date'] : '';
            foreach ($settings['special_dates'] as $special) {
                if ($special['date'] === $selected) {
                    $dates[] = $special;
                    break;
                }
            }
        } else {
            foreach ($settings['special_dates'] as $special) {
                if ($special['date'] >= $reference->format('Y-m-d')) {
                    $dates[] = $special;
                }
                if (count($dates) >= $limit) break;
            }
        }
        if (!$dates) {
            return '<p class="ulhgr-special-dates__empty">' . esc_html('single' === $mode ? __('Select a saved special date.', 'uplink-hours-greetings') : __('No upcoming special dates.', 'uplink-hours-greetings')) . '</p>';
        }
        $items = '';
        foreach ($dates as $special) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $special['date'], $timezone);
            $date_label = $date ? wp_date($date_format, $date->getTimestamp(), $timezone) : $special['date'];
            $state = !empty($special['closed']) ? 'closed' : 'custom-hours';
            $body = '<article class="ulhgr-special-date-card ulhgr-special-date-card--' . esc_attr($state) . '" data-date="' . esc_attr($special['date']) . '" data-state="' . esc_attr($state) . '">';
            $body .= '<header class="ulhgr-special-date-card__header">';
            if ('' !== trim($special['label'])) {
                $body .= '<h3 class="ulhgr-special-date-card__title">' . esc_html($special['label']) . '</h3>';
            }
            $body .= '<time class="ulhgr-special-date-card__date" datetime="' . esc_attr($special['date']) . '">' . esc_html($date_label) . '</time>';
            $body .= '</header>';
            if ($show_hours) {
                $body .= '<div class="ulhgr-special-date-card__hours">' . $this->special_date_hours_html($special, $timezone) . '</div>';
            }
            if ($show_message && '' !== trim($special['message'])) {
                $body .= '<p class="ulhgr-special-date-card__message">' . nl2br(esc_html($special['message'])) . '</p>';
            }
            if ($show_link && !empty($special['link_url'])) {
                $link_label = '' !== trim($special['link_label']) ? $special['link_label'] : __('Learn more', 'uplink-hours-greetings');
                $body .= '<p class="ulhgr-special-date-card__link"><a href="' . esc_url($special['link_url']) . '">' . esc_html($link_label) . '</a></p>';
            }
            $body .= '</article>';
            $items .= 'single' === $mode ? $body : '<li class="ulhgr-special-dates__item">' . $body . '</li>';
        }
        if ('single' === $mode) {
            return '<div class="ulhgr-special-dates ulhgr-special-dates--single">' . $items . '</div>';
        }
        return '<section class="ulhgr-special-dates ulhgr-special-dates--upcoming" aria-label="' . esc_attr__('Upcoming special dates', 'uplink-hours-greetings') . '"><ol class="ulhgr-special-dates__list">' . $items . '</ol></section>';
    }

    private function special_date_hours_html($special, $timezone) {
        if (!empty($special['closed'])) {
            return '<span class="ulhgr-special-date-card__status">' . esc_html__('Closed', 'uplink-hours-greetings') . '</span>';
        }
        $time_format = get_option('time_format', 'g:i a');
        $parts = array();
        foreach ($special['windows'] as $window) {
            $base = new DateTimeImmutable($special['date'], $timezone);
            list($open_hour, $open_minute) = array_map('intval', explode(':', $window['open']));
            list($close_hour, $close_minute) = array_map('intval', explode(':', $window['close']));
            $open = $base->setTime($open_hour, $open_minute);
            $close = $base->setTime($close_hour, $close_minute);
            $parts[] = '<span class="ulhgr-special-date-card__window"><time class="ulhgr-special-date-card__opens" datetime="' . esc_attr($window['open']) . '">' . esc_html(wp_date($time_format, $open->getTimestamp(), $timezone)) . '</time><span class="ulhgr-special-date-card__separator" aria-hidden="true">–</span><span class="ulhgr-visually-hidden">' . esc_html__(' to ', 'uplink-hours-greetings') . '</span><time class="ulhgr-special-date-card__closes" datetime="' . esc_attr($window['close']) . '">' . esc_html(wp_date($time_format, $close->getTimestamp(), $timezone)) . '</time></span>';
        }
        return $parts ? implode('<span class="ulhgr-special-date-card__between">, </span>', $parts) : '<span class="ulhgr-special-date-card__status">' . esc_html__('Hours not set', 'uplink-hours-greetings') . '</span>';
    }

    /** Plain text values for builders that own their element markup. */
    public function plain_value($display = 'greeting') {
        if (!in_array($display, array('greeting', 'date', 'both', 'status', 'schedule', 'special_dates'), true)) {
            return '';
        }
        if ('schedule' === $display) {
            return implode(' • ', array_map(function ($row) { return $row['day'] . ': ' . $row['hours']; }, $this->schedule_rows()));
        }
        $html = $this->generate_greeting(array('display' => $display));
        return html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, get_bloginfo('charset'));
    }

    /** Etch's server-side options data integration. */
    public function etch_data($data) {
        if (!is_array($data)) {
            return $data;
        }
        $settings = $this->get_settings();
        try {
            $timezone = new DateTimeZone($settings['default_timezone'] ?: wp_timezone_string());
        } catch (Exception $error) {
            $timezone = wp_timezone();
        }
        $now = new DateTime('now', $timezone);
        $current = $this->active_interval($now, $settings);
        $next = $this->next_interval($now, $settings);
        $opening = $this->next_interval($now, $settings, 'opening');
        $status = $this->status_snapshot($now, $settings);
        $time_format = get_option('time_format', 'g:i a');
        $timezone_label = $settings['default_tz_abbr'] ?: $now->format('T');
        $week = $this->schedule_rows();
        foreach ($week as &$row) {
            $row['is_today'] = $row['number'] === (int) $now->format('w');
        }
        unset($row);
        $data['uplink_hours_greetings'] = array(
            'greeting' => $this->plain_value('greeting'),
            'date' => $this->plain_value('date'),
            'both' => $this->plain_value('both'),
            'schedule' => $this->plain_value('schedule'),
            'status_output' => $this->plain_value('status'),
            'is_open' => $status['is_open'],
            'status' => $status['state'],
            'status_label' => $status['label'],
            'current_window' => $status['current_window'],
            'next_change' => $status['next_change'],
            'is_special_date' => $status['is_special_date'],
            'special_date' => $status['special_date'],
            'special_label' => $status['special_label'],
            'special_link_url' => $status['special_link_url'],
            'special_link_label' => $status['special_link_label'],
            'timezone' => $timezone->getName(),
            'timezone_abbr' => $timezone_label,
            'now' => array(
                'time' => wp_date($time_format, $now->getTimestamp(), $timezone),
                'date' => wp_date(get_option('date_format', 'F j, Y'), $now->getTimestamp(), $timezone),
                'timestamp' => $now->getTimestamp(),
            ),
            'current' => array(
                'label' => $current['label'],
                'timing' => $current['timing'] ?? 'timed',
                'start' => $current['start'],
                'event' => $current['event'],
                'message' => $current['message'],
                'output' => $this->plain_value('greeting'),
            ),
            'next' => $next ? array(
                'label' => $next['interval']['label'],
                'timing' => $next['interval']['timing'] ?? 'timed',
                'time' => wp_date($time_format, $next['timestamp'], $timezone),
                'countdown' => $this->format_duration($next['timestamp'] - $now->getTimestamp()),
                'event' => $next['interval']['event'],
                'message' => $next['interval']['message'],
                'start' => $next['interval']['start'],
                'timestamp' => $next['timestamp'],
            ) : array(),
            'opening' => $opening ? array(
                'label' => $opening['interval']['label'],
                'timing' => $opening['interval']['timing'] ?? 'timed',
                'time' => wp_date($time_format, $opening['timestamp'], $timezone),
                'countdown' => $this->format_duration($opening['timestamp'] - $now->getTimestamp()),
                'event' => $opening['interval']['event'],
                'message' => $opening['interval']['message'],
                'start' => $opening['interval']['start'],
                'timestamp' => $opening['timestamp'],
            ) : array(),
            'days' => array_reduce($week, function ($days, $row) { $days[$row['key']] = $row['hours']; return $days; }, array()),
            'week' => $week,
            'special_dates' => $settings['special_dates'],
        );
        return $data;
    }

    /** Register native Bricks dynamic data tags. */
    public function bricks_tags($tags) {
        foreach (array(
            'greeting' => __('Greeting', 'uplink-hours-greetings'),
            'date' => __('Date', 'uplink-hours-greetings'),
            'both' => __('Greeting and date', 'uplink-hours-greetings'),
            'schedule' => __('Weekly schedule', 'uplink-hours-greetings'),
            'status' => __('Open or closed status', 'uplink-hours-greetings'),
            'status_label' => __('Status label', 'uplink-hours-greetings'),
            'is_open' => __('Is open (1 or 0)', 'uplink-hours-greetings'),
            'next_change' => __('Next status change', 'uplink-hours-greetings'),
            'special_label' => __('Current special-date label', 'uplink-hours-greetings'),
            'special_link_url' => __('Current special-date link URL', 'uplink-hours-greetings'),
            'special_link_label' => __('Current special-date link label', 'uplink-hours-greetings'),
        ) as $key => $label) {
            $tags[] = array(
                'name' => '{ulhgr_' . $key . '}',
                'label' => $label,
                'group' => __('Uplink Hours & Greetings', 'uplink-hours-greetings'),
            );
        }
        foreach (array(
            'day' => __('Schedule day', 'uplink-hours-greetings'),
            'hours' => __('Schedule hours', 'uplink-hours-greetings'),
            'state' => __('Schedule state', 'uplink-hours-greetings'),
            'key' => __('Schedule day key', 'uplink-hours-greetings'),
            'today' => __('Schedule is today (1 or 0)', 'uplink-hours-greetings'),
            'row_date' => __('Schedule date', 'uplink-hours-greetings'),
            'row_is_special' => __('Schedule is special date (1 or 0)', 'uplink-hours-greetings'),
            'row_special_label' => __('Schedule special-date label', 'uplink-hours-greetings'),
            'row_link_url' => __('Schedule special-date link URL', 'uplink-hours-greetings'),
            'row_link_label' => __('Schedule special-date link label', 'uplink-hours-greetings'),
        ) as $key => $label) {
            $tags[] = array('name' => '{ulhgr_' . $key . '}', 'label' => $label, 'group' => __('Uplink Hours & Greetings', 'uplink-hours-greetings'));
        }
        foreach (array(
            'event_date' => __('Special date: ISO date', 'uplink-hours-greetings'),
            'event_date_label' => __('Special date: formatted date', 'uplink-hours-greetings'),
            'event_title' => __('Special date: title', 'uplink-hours-greetings'),
            'event_message' => __('Special date: message', 'uplink-hours-greetings'),
            'event_hours' => __('Special date: hours', 'uplink-hours-greetings'),
            'event_closed' => __('Special date: closed (1 or 0)', 'uplink-hours-greetings'),
            'event_link_url' => __('Special date: link URL', 'uplink-hours-greetings'),
            'event_link_label' => __('Special date: link label', 'uplink-hours-greetings'),
        ) as $key => $label) {
            $tags[] = array('name' => '{ulhgr_' . $key . '}', 'label' => $label, 'group' => __('Uplink Hours & Greetings', 'uplink-hours-greetings'));
        }
        return $tags;
    }

    /** Expose the ordered seven-day schedule as a native Bricks Query Loop source. */
    public function bricks_query_options($options) {
        $options['queryTypes']['ulhgr_schedule'] = __('Uplink Weekly Schedule', 'uplink-hours-greetings');
        $options['queryTypes']['ulhgr_special_dates'] = __('Uplink Special Dates', 'uplink-hours-greetings');
        return $options;
    }

    public function bricks_schedule_query($results, $query) {
        if (!is_object($query) || !isset($query->object_type)) {
            return $results;
        }
        if ('ulhgr_special_dates' === $query->object_type) {
            return $this->bricks_special_date_rows();
        }
        if ('ulhgr_schedule' !== $query->object_type) {
            return $results;
        }
        $rows = $this->schedule_rows();
        $today = (int) wp_date('w', time(), wp_timezone());
        foreach ($rows as &$row) {
            $row['is_today'] = $row['number'] === $today;
        }
        unset($row);
        return $rows;
    }

    /** Upcoming special dates formatted as rows for Bricks Query Loop. */
    private function bricks_special_date_rows() {
        $settings = $this->get_settings();
        try {
            $timezone = new DateTimeZone($settings['default_timezone'] ?: wp_timezone_string());
        } catch (Exception $error) {
            $timezone = wp_timezone();
        }
        $today = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
        $date_format = get_option('date_format', 'F j, Y');
        $rows = array();
        foreach ($settings['special_dates'] as $special) {
            if ($special['date'] < $today) {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $special['date'], $timezone);
            $rows[] = array(
                'event_date' => $special['date'],
                'event_date_label' => $date ? wp_date($date_format, $date->getTimestamp(), $timezone) : $special['date'],
                'event_title' => $special['label'],
                'event_message' => $special['message'],
                'event_hours' => wp_strip_all_tags($this->special_date_hours_html($special, $timezone)),
                'event_closed' => !empty($special['closed']),
                'event_link_url' => $special['link_url'],
                'event_link_label' => $special['link_label'] ?: __('Learn more', 'uplink-hours-greetings'),
                'windows' => $special['windows'],
            );
        }
        return $rows;
    }

    private function bricks_schedule_field($key) {
        if (!class_exists('\\Bricks\\Query') || 'ulhgr_schedule' !== \Bricks\Query::get_query_object_type()) {
            return null;
        }
        $row = \Bricks\Query::get_loop_object();
        if (!is_array($row) || !array_key_exists('day', $row)) {
            return null;
        }
        if ('today' === $key) {
            return !empty($row['is_today']) ? '1' : '0';
        }
        if ('row_is_special' === $key) {
            return !empty($row['is_special']) ? '1' : '0';
        }
        if ('row_date' === $key) {
            return (string) ($row['date'] ?? '');
        }
        if ('row_special_label' === $key) {
            return (string) ($row['special_label'] ?? '');
        }
        if ('row_link_url' === $key) {
            return (string) ($row['special_link_url'] ?? '');
        }
        if ('row_link_label' === $key) {
            return (string) ($row['special_link_label'] ?? '');
        }
        return isset($row[$key]) && is_scalar($row[$key]) ? (string) $row[$key] : '';
    }

    private function bricks_special_date_field($key) {
        if (!class_exists('\\Bricks\\Query') || 'ulhgr_special_dates' !== \Bricks\Query::get_query_object_type()) {
            return null;
        }
        $row = \Bricks\Query::get_loop_object();
        if (!is_array($row) || !array_key_exists('event_date', $row)) {
            return null;
        }
        if ('event_closed' === $key) {
            return !empty($row[$key]) ? '1' : '0';
        }
        return isset($row[$key]) && is_scalar($row[$key]) ? (string) $row[$key] : '';
    }

    public function bricks_render_tag($tag, $post = null, $context = 'text') {
        if (!is_string($tag)) {
            return $tag;
        }
        $raw = trim($tag);
        if ('{' === substr($raw, 0, 1) && '}' === substr($raw, -1)) {
            $raw = substr($raw, 1, -1);
        }
        $parts = explode(':', $raw);
        $key = array_shift($parts);
        $legacy = array(
            'tgb_greeting' => 'ulhgr_greeting',
            'tgb_date' => 'ulhgr_date',
            'tgb_both' => 'ulhgr_both',
            'tgb_schedule' => 'ulhgr_schedule',
        );
        if (isset($legacy[$key])) {
            $key = $legacy[$key];
        }
        if (in_array($key, array('ulhgr_day', 'ulhgr_hours', 'ulhgr_state', 'ulhgr_key', 'ulhgr_today', 'ulhgr_row_date', 'ulhgr_row_is_special', 'ulhgr_row_special_label', 'ulhgr_row_link_url', 'ulhgr_row_link_label'), true) && class_exists('\\Bricks\\Query') && 'ulhgr_schedule' === \Bricks\Query::get_query_object_type()) {
            $value = $this->bricks_schedule_field(substr($key, 6));
            return null === $value ? $tag : esc_html($value);
        }
        if (in_array($key, array('ulhgr_event_date', 'ulhgr_event_date_label', 'ulhgr_event_title', 'ulhgr_event_message', 'ulhgr_event_hours', 'ulhgr_event_closed', 'ulhgr_event_link_url', 'ulhgr_event_link_label'), true) && class_exists('\\Bricks\\Query') && 'ulhgr_special_dates' === \Bricks\Query::get_query_object_type()) {
            $value = $this->bricks_special_date_field(substr($key, 6));
            if (null === $value) {
                return $tag;
            }
            return 'ulhgr_event_link_url' === $key ? esc_url($value) : esc_html($value);
        }
        if (!in_array($key, array('ulhgr_greeting', 'ulhgr_date', 'ulhgr_both', 'ulhgr_schedule', 'ulhgr_status', 'ulhgr_status_label', 'ulhgr_is_open', 'ulhgr_next_change', 'ulhgr_special_label', 'ulhgr_special_link_url', 'ulhgr_special_link_label'), true)) {
            return $tag;
        }
        $status = $this->current_status_for_builders();
        if ('ulhgr_status_label' === $key) return esc_html($status['label']);
        if ('ulhgr_is_open' === $key) return $status['is_open'] ? '1' : '0';
        if ('ulhgr_next_change' === $key) return esc_html($status['next_change']['time'] ?? '');
        if ('ulhgr_special_label' === $key) return esc_html($status['special_label']);
        if ('ulhgr_special_link_url' === $key) return esc_url($status['special_link_url']);
        if ('ulhgr_special_link_label' === $key) return esc_html($status['special_link_label']);
        return esc_html($this->plain_value(substr($key, 6)));
    }

    public function bricks_render_content($content, $post = null, $context = 'text') {
        if (!is_string($content) || (false === strpos($content, '{ulhgr_') && false === strpos($content, '{tgb_'))) {
            return $content;
        }
        return preg_replace_callback('/\{(?:ulhgr|tgb)_[^{}]+\}/', function ($match) use ($post, $context) {
            return $this->bricks_render_tag($match[0], $post, $context);
        }, $content);
    }

    private function current_status_for_builders() {
        $settings = $this->get_settings();
        try {
            $timezone = new DateTimeZone($settings['default_timezone'] ?: wp_timezone_string());
        } catch (Exception $error) {
            $timezone = wp_timezone();
        }
        return $this->status_snapshot(new DateTime('now', $timezone), $settings);
    }

    /** Print optional LocalBusiness JSON-LD using the same schedule data as every output. */
    public function render_structured_data() {
        $settings = $this->get_settings();
        if (empty($settings['schema_enabled']) || '' === trim($settings['schema_name'])) {
            return;
        }
        $day_urls = array(
            0 => 'https://schema.org/Sunday', 1 => 'https://schema.org/Monday', 2 => 'https://schema.org/Tuesday',
            3 => 'https://schema.org/Wednesday', 4 => 'https://schema.org/Thursday', 5 => 'https://schema.org/Friday', 6 => 'https://schema.org/Saturday',
        );
        $weekly = array();
        for ($day = 0; $day < 7; $day++) {
            $profile = $this->profile_for_day($settings, $day);
            if (!empty($profile['closed'])) continue;
            foreach ($this->profile_windows($profile) as $window) {
                if ('time' !== $window['end_type']) continue;
                $weekly[] = array('@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $day_urls[$day], 'opens' => $window['start'], 'closes' => $window['end']);
            }
        }
        if (!$weekly) return;
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => $settings['schema_type'],
            'name' => $settings['schema_name'],
            'url' => home_url('/'),
            'openingHoursSpecification' => $weekly,
        );
        if ($settings['schema_phone']) $schema['telephone'] = $settings['schema_phone'];
        if ($settings['schema_price_range']) $schema['priceRange'] = $settings['schema_price_range'];
        $logo = get_site_icon_url();
        if ($logo) $schema['image'] = $logo;
        if (array_filter(array($settings['schema_street'], $settings['schema_locality'], $settings['schema_region'], $settings['schema_postal_code'], $settings['schema_country']))) {
            $schema['address'] = array_filter(array(
                '@type' => 'PostalAddress',
                'streetAddress' => $settings['schema_street'],
                'addressLocality' => $settings['schema_locality'],
                'addressRegion' => $settings['schema_region'],
                'postalCode' => $settings['schema_postal_code'],
                'addressCountry' => $settings['schema_country'],
            ));
        }
        $special_specs = array();
        foreach ($settings['special_dates'] as $special) {
            if ($special['date'] < wp_date('Y-m-d', time(), wp_timezone())) continue;
            if ($special['closed']) {
                $special_specs[] = array('@type' => 'OpeningHoursSpecification', 'validFrom' => $special['date'], 'validThrough' => $special['date'], 'opens' => '00:00', 'closes' => '00:00');
            } else {
                foreach ($special['windows'] as $window) {
                    $special_specs[] = array('@type' => 'OpeningHoursSpecification', 'validFrom' => $special['date'], 'validThrough' => $special['date'], 'opens' => $window['open'], 'closes' => $window['close']);
                }
            }
        }
        if ($special_specs) $schema['specialOpeningHoursSpecification'] = $special_specs;
        $schema = apply_filters('ulhgr_schema_data', $schema, $settings);
        if (!apply_filters('ulhgr_output_schema', true, $schema, $settings)) return;
        echo "\n" . '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }

    /** Convert a profile's opening and closing events to reusable windows. */
    private function profile_windows($profile) {
        $windows = array();
        if (!empty($profile['closed'])) return $windows;
        foreach ($profile['intervals'] as $index => $interval) {
            if ('opening' !== ($interval['event'] ?? '') || 'timed' !== ($interval['timing'] ?? 'timed')) continue;
            $closing = null;
            foreach (array_slice($profile['intervals'], $index + 1) as $later) {
                if ('opening' === ($later['event'] ?? '')) break;
                if ('closing' === ($later['event'] ?? '')) { $closing = $later; break; }
            }
            $windows[] = array(
                'start' => $interval['start'],
                'end' => $closing['start'] ?? '',
                'end_type' => !$closing ? 'none' : (('untimed' === ($closing['timing'] ?? 'timed')) ? 'text' : 'time'),
                'end_label' => $closing['label'] ?? '',
            );
        }
        return $windows;
    }

    public function admin_assets($hook) {
        if ('settings_page_uplink-hours-greetings' === $hook) {
            wp_enqueue_style('ulhgr-admin', UPLINK_HOURS_GREETINGS_PLUGIN_URL . 'assets/admin.css', array(), UPLINK_HOURS_GREETINGS_PLUGIN_VERSION);
            wp_enqueue_script('ulhgr-admin', UPLINK_HOURS_GREETINGS_PLUGIN_URL . 'assets/admin.js', array(), UPLINK_HOURS_GREETINGS_PLUGIN_VERSION, true);
            wp_localize_script('ulhgr-admin', 'ulhgrAdmin', array(
                'newSchedule' => __('New schedule', 'uplink-hours-greetings'),
                /* translators: %s: weekday name for a copied schedule. */
                'daySchedule' => __('%s schedule', 'uplink-hours-greetings'),
                'restUrl' => esc_url_raw(rest_url('uplink-hours-greetings/v1/render')),
                'nonce' => wp_create_nonce('wp_rest'),
                'previewError' => __('Preview unavailable. Try again.', 'uplink-hours-greetings'),
                'optional' => __('Optional', 'uplink-hours-greetings'),
                'untimedLabelExample' => __('Last Call', 'uplink-hours-greetings'),
                'specialDate' => __('Special date', 'uplink-hours-greetings'),
                'customHours' => __('Custom hours', 'uplink-hours-greetings'),
                'closed' => __('Closed', 'uplink-hours-greetings'),
                'date' => __('Date', 'uplink-hours-greetings'),
                'label' => __('Label', 'uplink-hours-greetings'),
                'closedAllDay' => __('Closed all day', 'uplink-hours-greetings'),
                'opens' => __('Opens', 'uplink-hours-greetings'),
                'closes' => __('Closes', 'uplink-hours-greetings'),
                'addHoursWindow' => __('Add hours window', 'uplink-hours-greetings'),
                'messageOptional' => __('Message for this date (optional)', 'uplink-hours-greetings'),
                'destinationUrl' => __('Destination URL (optional)', 'uplink-hours-greetings'),
                'linkLabel' => __('Link label (optional)', 'uplink-hours-greetings'),
                'removeSpecialDate' => __('Remove special date', 'uplink-hours-greetings'),
            ));
            $this->enqueue_live_assets();
        }
    }

    public function frontend_assets() {
        $this->enqueue_live_assets();
    }

    private function enqueue_live_assets() {
        wp_enqueue_style('ulhgr-output', UPLINK_HOURS_GREETINGS_PLUGIN_URL . 'assets/block-style.css', array(), UPLINK_HOURS_GREETINGS_PLUGIN_VERSION);
        wp_enqueue_script('ulhgr-live', UPLINK_HOURS_GREETINGS_PLUGIN_URL . 'assets/live.js', array('wp-i18n'), UPLINK_HOURS_GREETINGS_PLUGIN_VERSION, true);
        wp_set_script_translations('ulhgr-live', 'uplink-hours-greetings');
        wp_localize_script('ulhgr-live', 'ulhgrLive', array(
            'restUrl' => esc_url_raw(rest_url('uplink-hours-greetings/v1/render')),
        ));
    }

    public function register_rest_routes() {
        register_rest_route('uplink-hours-greetings/v1', '/render', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_render'),
            'permission_callback' => '__return_true',
        ));
    }

    public function rest_render($request) {
        $attributes = array(
            'display' => sanitize_key($request->get_param('display') ?: 'greeting'),
            'dateFormat' => sanitize_text_field($request->get_param('date_format') ?: 'F j, Y'),
            'timezone' => sanitize_text_field($request->get_param('timezone') ?: ''),
            'tzAbbr' => sanitize_text_field($request->get_param('tz_abbr') ?: ''),
        );
        if (current_user_can('ulhgr_manage_settings') && $request->get_param('at')) {
            $attributes['previewAt'] = sanitize_text_field($request->get_param('at'));
        }
        return rest_ensure_response(array('html' => $this->generate_greeting($attributes)));
    }

    /** Replace WordPress's inline settings notices with one page-specific toast. */
    public function capture_admin_feedback() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The query flag only controls feedback after WordPress has processed the settings form.
        if (empty($_GET['settings-updated'])) {
            return;
        }

        $remaining = array();
        foreach (get_settings_errors() as $feedback) {
            if (UPLINK_HOURS_GREETINGS_OPTION_NAME === $feedback['setting'] || UPLINK_HOURS_GREETINGS_PERMISSIONS_OPTION === $feedback['setting'] ||
                ('general' === $feedback['setting'] && 'settings_updated' === $feedback['code'])) {
                $this->admin_feedback[] = $feedback;
            } else {
                $remaining[] = $feedback;
            }
        }
        $GLOBALS['wp_settings_errors'] = $remaining;
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('Uplink Hours & Greetings Settings', 'uplink-hours-greetings'),
            __('Uplink Hours & Greetings', 'uplink-hours-greetings'),
            'ulhgr_manage_settings',
            'uplink-hours-greetings',
            array($this, 'admin_page')
        );
    }

    /**
     * Initialize admin settings
     */
    public function admin_init() {
        register_setting(
            'ulhgr_settings_group',
            UPLINK_HOURS_GREETINGS_OPTION_NAME,
            array($this, 'sanitize_settings')
        );
        register_setting(
            'ulhgr_permissions_group',
            UPLINK_HOURS_GREETINGS_PERMISSIONS_OPTION,
            array($this, 'sanitize_permissions')
        );
    }

    public function settings_capability() {
        return 'ulhgr_manage_settings';
    }

    /** Administrators and the selected roles or users may edit plugin settings. */
    public function map_settings_capability($caps, $cap, $user_id, $args) {
        if ('ulhgr_manage_settings' !== $cap) {
            return $caps;
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return array('do_not_allow');
        }
        if (user_can($user, 'manage_options')) {
            return array('exist');
        }
        $permissions = get_option(UPLINK_HOURS_GREETINGS_PERMISSIONS_OPTION, array());
        $roles = is_array($permissions) ? ($permissions['roles'] ?? array()) : array();
        $users = is_array($permissions) ? ($permissions['users'] ?? array()) : array();
        if (in_array((int) $user_id, array_map('intval', (array) $users), true) || array_intersect((array) $user->roles, (array) $roles)) {
            return array('exist');
        }
        return array('do_not_allow');
    }

    public function sanitize_permissions($input) {
        if (!current_user_can('manage_options')) {
            return get_option(UPLINK_HOURS_GREETINGS_PERMISSIONS_OPTION, array());
        }
        $input = is_array($input) ? $input : array();
        $available_roles = array_keys(wp_roles()->roles);
        $roles = isset($input['roles']) && is_array($input['roles']) ? $input['roles'] : array();
        $users = isset($input['users']) && is_array($input['users']) ? $input['users'] : array();
        return array(
            'roles' => array_values(array_intersect($available_roles, array_map('sanitize_key', $roles))),
            'users' => array_values(array_unique(array_filter(array_map('absint', $users), 'get_userdata'))),
        );
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        if (!is_array($input)) {
            return $this->get_settings();
        }
        $sanitized = array();
        $current_settings = get_option(UPLINK_HOURS_GREETINGS_OPTION_NAME, array());
        $defaults = $this->get_settings();

        // Preserve existing data that shouldn't be overwritten
        $sanitized['plugin_version'] = UPLINK_HOURS_GREETINGS_PLUGIN_VERSION;
        $sanitized['activation_date'] = $current_settings['activation_date'] ?? current_time('mysql');
        $sanitized['last_updated'] = current_time('mysql');
        $sanitized['timezone_wp_default_migrated'] = true;

        $raw_profiles = isset($input['profiles']) && is_array($input['profiles']) ? $input['profiles'] : array();
        $profiles = array();
        $invalid_schedule = !$raw_profiles || count($raw_profiles) > 14;
        foreach (array_slice($raw_profiles, 0, 14) as $raw_profile) {
            if (!is_array($raw_profile)) {
                $invalid_schedule = true;
                continue;
            }
            $id = isset($raw_profile['id']) && is_scalar($raw_profile['id']) ? sanitize_title(wp_unslash($raw_profile['id'])) : '';
            $name = isset($raw_profile['name']) && is_scalar($raw_profile['name']) ? sanitize_text_field(wp_unslash($raw_profile['name'])) : '';
            $rows = isset($raw_profile['intervals']) && is_array($raw_profile['intervals']) ? $raw_profile['intervals'] : array();
            if ('' === $id || isset($profiles[$id]) || '' === $name || !$rows || count($rows) > 24) {
                $invalid_schedule = true;
                continue;
            }
            $intervals = array();
            $timed_starts = array();
            foreach (array_slice($rows, 0, 24) as $row_order => $row) {
                if (!is_array($row)) {
                    $invalid_schedule = true;
                    continue;
                }
                $timing = isset($row['timing']) && is_scalar($row['timing']) && 'untimed' === sanitize_key(wp_unslash($row['timing'])) ? 'untimed' : 'timed';
                $start = isset($row['start']) && is_scalar($row['start']) ? sanitize_text_field(wp_unslash($row['start'])) : '';
                if ('timed' === $timing && (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $start) || isset($timed_starts[$start]))) {
                    $invalid_schedule = true;
                    continue;
                }
                $label = isset($row['label']) && is_scalar($row['label']) ? sanitize_text_field(wp_unslash($row['label'])) : '';
                $message = isset($row['message']) && is_scalar($row['message']) ? sanitize_textarea_field(wp_unslash($row['message'])) : '';
                $event = isset($row['event']) && is_scalar($row['event']) ? sanitize_key(wp_unslash($row['event'])) : '';
                if ('untimed' === $timing && '' === $label) {
                    $invalid_schedule = true;
                    continue;
                }
                if ('timed' === $timing) {
                    $timed_starts[$start] = true;
                }
                $intervals[] = array(
                    'timing' => $timing,
                    'start' => 'timed' === $timing ? $start : '',
                    'label' => substr($label, 0, 80),
                    'message' => 'timed' === $timing ? $message : '',
                    'event' => 'untimed' === $timing ? 'closing' : (in_array($event, array('opening', 'closing'), true) ? $event : ''),
                    '_order' => $row_order,
                );
            }
            usort($intervals, function ($a, $b) {
                if ($a['timing'] !== $b['timing']) {
                    return 'timed' === $a['timing'] ? -1 : 1;
                }
                return 'timed' === $a['timing'] ? strcmp($a['start'], $b['start']) : $a['_order'] <=> $b['_order'];
            });
            $intervals = array_map(function ($interval) { unset($interval['_order']); return $interval; }, $intervals);
            $profiles[$id] = array('id' => $id, 'name' => substr($name, 0, 80), 'closed' => !empty($raw_profile['closed']), 'intervals' => $intervals);
        }
        $raw_week = isset($input['week']) && is_array($input['week']) ? $input['week'] : array();
        $week = array();
        for ($day = 0; $day < 7; $day++) {
            $id = isset($raw_week[$day]) && is_scalar($raw_week[$day]) ? sanitize_title(wp_unslash($raw_week[$day])) : '';
            if (!isset($profiles[$id])) {
                $invalid_schedule = true;
            }
            $week[$day] = $id;
        }
        if ($invalid_schedule) {
            add_settings_error(UPLINK_HOURS_GREETINGS_OPTION_NAME, 'profiles', __('Use a named schedule for every day. Each schedule needs 1–24 valid entries; timed entries need unique start times and untimed entries need a label. The previous week was kept.', 'uplink-hours-greetings'));
            $sanitized['profiles'] = $defaults['profiles'];
            $sanitized['week'] = $defaults['week'];
        } else {
            $sanitized['profiles'] = array_values($profiles);
            $sanitized['week'] = $week;
        }
        $sanitized['default_timezone'] = sanitize_text_field(wp_unslash($input['default_timezone'] ?? ''));
        $sanitized['default_tz_abbr'] = substr(sanitize_text_field(wp_unslash($input['default_tz_abbr'] ?? '')), 0, 20);
        $sanitized['date_intro'] = sanitize_text_field(wp_unslash($input['date_intro'] ?? $defaults['date_intro']));
        $sanitized['date_suffix'] = substr(sanitize_text_field(wp_unslash($input['date_suffix'] ?? $defaults['date_suffix'])), 0, 12);
        $sanitized['date_only_intro'] = !empty($input['date_only_intro']);
        $sanitized['open_label'] = substr(sanitize_text_field(wp_unslash($input['open_label'] ?? $defaults['open_label'])), 0, 80);
        $sanitized['closed_label'] = substr(sanitize_text_field(wp_unslash($input['closed_label'] ?? $defaults['closed_label'])), 0, 80);

        $raw_special_dates = isset($input['special_dates']) && is_array($input['special_dates']) ? $input['special_dates'] : array();
        $special_dates = array();
        $seen_dates = array();
        foreach (array_slice($raw_special_dates, 0, 100) as $index => $item) {
            if (!is_array($item)) continue;
            $date = isset($item['date']) ? sanitize_text_field(wp_unslash($item['date'])) : '';
            if (!$this->valid_iso_date($date) || isset($seen_dates[$date])) {
                add_settings_error(UPLINK_HOURS_GREETINGS_OPTION_NAME, 'special_date_' . $index, __('Each special date must be a valid, unique calendar date.', 'uplink-hours-greetings'));
                continue;
            }
            $closed = !empty($item['closed']);
            $windows = array();
            if (!$closed) {
                foreach (array_slice(isset($item['windows']) && is_array($item['windows']) ? $item['windows'] : array(), 0, 6) as $window) {
                    if (!is_array($window)) continue;
                    $open = isset($window['open']) ? sanitize_text_field(wp_unslash($window['open'])) : '';
                    $close = isset($window['close']) ? sanitize_text_field(wp_unslash($window['close'])) : '';
                    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $open) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $close) || $this->start_minutes($close) <= $this->start_minutes($open)) {
                        continue;
                    }
                    $windows[] = array('open' => $open, 'close' => $close);
                }
                if (!$windows) {
                    add_settings_error(UPLINK_HOURS_GREETINGS_OPTION_NAME, 'special_hours_' . $index, __('A reduced-hours date needs at least one valid window with a closing time later than its opening time.', 'uplink-hours-greetings'));
                    continue;
                }
            }
            $seen_dates[$date] = true;
            $special_dates[] = array(
                'id' => 'special-' . str_replace('-', '', $date),
                'date' => $date,
                'label' => substr(sanitize_text_field(wp_unslash($item['label'] ?? '')), 0, 100),
                'message' => sanitize_textarea_field(wp_unslash($item['message'] ?? '')),
                'link_url' => esc_url_raw(wp_unslash($item['link_url'] ?? '')),
                'link_label' => substr(sanitize_text_field(wp_unslash($item['link_label'] ?? '')), 0, 100),
                'closed' => $closed,
                'windows' => $windows,
            );
        }
        usort($special_dates, function ($a, $b) { return strcmp($a['date'], $b['date']); });
        $sanitized['special_dates'] = $special_dates;

        $schema_types = array('LocalBusiness', 'Store', 'ProfessionalService', 'Restaurant', 'MedicalBusiness', 'LodgingBusiness', 'AutomotiveBusiness');
        $schema_type = sanitize_text_field(wp_unslash($input['schema_type'] ?? 'LocalBusiness'));
        $sanitized['schema_enabled'] = !empty($input['schema_enabled']);
        $sanitized['schema_type'] = in_array($schema_type, $schema_types, true) ? $schema_type : 'LocalBusiness';
        foreach (array('schema_name' => 160, 'schema_phone' => 60, 'schema_price_range' => 20, 'schema_street' => 160, 'schema_locality' => 100, 'schema_region' => 100, 'schema_postal_code' => 30, 'schema_country' => 80) as $field => $limit) {
            $sanitized[$field] = substr(sanitize_text_field(wp_unslash($input[$field] ?? '')), 0, $limit);
        }

        // An empty value follows the WordPress timezone setting.
        if ('' !== $sanitized['default_timezone']) {
            try {
                new DateTimeZone($sanitized['default_timezone']);
            } catch (Exception $error) {
                add_settings_error(UPLINK_HOURS_GREETINGS_OPTION_NAME, 'default_timezone',
                    __('Invalid timezone identifier.', 'uplink-hours-greetings'));
                $sanitized['default_timezone'] = $current_settings['default_timezone'] ?? '';
            }
        }

        return $sanitized;
    }

    public function text_field_callback($args) {
        $settings = $this->get_settings();
        $value = $settings[$args['field']];

        printf(
            '<input type="text" id="%1$s" name="%3$s[%1$s]" value="%2$s" class="regular-text"%4$s />',
            esc_attr($args['field']),
            esc_attr($value),
            esc_attr(UPLINK_HOURS_GREETINGS_OPTION_NAME),
            isset($args['maxlength']) ? ' maxlength="' . esc_attr((string) $args['maxlength']) . '"' : ''
        );
    }

    public function timezone_field_callback($args) {
        $settings = $this->get_settings();
        $current_value = $settings[$args['field']];
        $timezones = timezone_identifiers_list();

        printf('<select id="%1$s" name="%2$s[%1$s]">', esc_attr($args['field']), esc_attr(UPLINK_HOURS_GREETINGS_OPTION_NAME));

        printf(
            '<option value=""%1$s>%2$s</option>',
            selected($current_value, '', false),
            /* translators: %s: timezone selected in WordPress Settings > General. */
            esc_html(sprintf(__('WordPress site timezone (%s)', 'uplink-hours-greetings'), wp_timezone_string()))
        );

        if ('' !== $current_value && !in_array($current_value, $timezones, true)) {
            printf('<option value="%1$s" selected>%1$s</option>', esc_attr($current_value));
        }

        foreach ($timezones as $timezone) {
            printf(
                '<option value="%1$s"%2$s>%1$s</option>',
                esc_attr($timezone),
                selected($current_value, $timezone, false)
            );
        }

        echo '</select>';
    }

    /**
     * Admin page with tabbed interface
     */
    public function admin_page() {
        if (!current_user_can('ulhgr_manage_settings')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'uplink-hours-greetings'));
        }

        // Get current tab
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This value only selects a read-only settings tab.
        $current_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview';
        if ('settings' === $current_tab) {
            $current_tab = 'overview';
        }
        if (in_array($current_tab, array('usage', 'styling'), true)) {
            $current_tab = 'how-to';
        }
        if (!in_array($current_tab, array('overview', 'schedule', 'special-dates', 'permissions', 'how-to'), true)) {
            $current_tab = 'overview';
        }
        if ('permissions' === $current_tab && !current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'uplink-hours-greetings'));
        }

        ?>
        <div class="wrap ulhgr-admin" data-view="<?php echo esc_attr($current_tab); ?>">
            <div class="ulhgr-admin-header">
                <div class="ulhgr-header-icon" aria-hidden="true"><img src="<?php echo esc_url(UPLINK_HOURS_GREETINGS_PLUGIN_URL . 'assets/uplink-mark.svg'); ?>" alt=""></div>
                <div><p class="ulhgr-eyebrow"><?php esc_html_e('UPLINK · SETTINGS', 'uplink-hours-greetings'); ?></p><h1><?php esc_html_e('Hours & Greetings', 'uplink-hours-greetings'); ?></h1><p><?php esc_html_e('Set what visitors see throughout your week.', 'uplink-hours-greetings'); ?></p></div>
                <span class="ulhgr-version"><?php echo esc_html('v' . UPLINK_HOURS_GREETINGS_PLUGIN_VERSION); ?></span>
            </div>

            <?php if (!empty($this->admin_feedback)) : ?>
                <?php
                $has_error = false;
                foreach ($this->admin_feedback as $feedback) {
                    if ('error' === $feedback['type']) {
                        $has_error = true;
                        break;
                    }
                }
                ?>
                <div class="ulhgr-toast <?php echo $has_error ? 'ulhgr-toast-error' : 'ulhgr-toast-success'; ?>" role="<?php echo $has_error ? 'alert' : 'status'; ?>" aria-live="<?php echo $has_error ? 'assertive' : 'polite'; ?>">
                    <span class="dashicons <?php echo $has_error ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span>
                    <div class="ulhgr-toast-messages">
                        <?php foreach ($this->admin_feedback as $feedback) : ?>
                            <p><?php echo esc_html(wp_strip_all_tags($feedback['message'])); ?></p>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="ulhgr-toast-close" aria-label="<?php esc_attr_e('Dismiss notification', 'uplink-hours-greetings'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <nav class="ulhgr-tabs" role="tablist" aria-orientation="horizontal" aria-label="<?php esc_attr_e('Uplink Hours & Greetings sections', 'uplink-hours-greetings'); ?>">
                <?php
                $tabs = array(
                    'overview' => __('Overview', 'uplink-hours-greetings'),
                    'schedule' => __('Weekly schedule', 'uplink-hours-greetings'),
                    'special-dates' => __('Special dates', 'uplink-hours-greetings'),
                );
                if (current_user_can('manage_options')) {
                    $tabs['permissions'] = __('Permissions', 'uplink-hours-greetings');
                }
                $tabs['how-to'] = __('How to Use', 'uplink-hours-greetings');
                foreach ($tabs as $tab_key => $tab_label) {
                    $tab_url = add_query_arg(array('tab' => $tab_key), admin_url('options-general.php?page=uplink-hours-greetings'));
                    printf(
                        '<a id="ulhgr-tab-%1$s" href="%2$s" class="%3$s" role="tab" aria-controls="ulhgr-panel-%1$s" aria-selected="%4$s" tabindex="%5$s" data-ulhgr-tab="%1$s">%6$s</a>',
                        esc_attr($tab_key),
                        esc_url($tab_url),
                        esc_attr($current_tab === $tab_key ? 'is-active' : ''),
                        $current_tab === $tab_key ? 'true' : 'false',
                        $current_tab === $tab_key ? '0' : '-1',
                        esc_html($tab_label)
                    );
                }
                ?>
            </nav>

            <div class="ulhgr-tab-content">
                <?php $this->render_settings_tabs($current_tab); ?>
                <?php if (current_user_can('manage_options')) : ?>
                    <section id="ulhgr-panel-permissions" class="ulhgr-tab-panel" role="tabpanel" aria-labelledby="ulhgr-tab-permissions" data-ulhgr-panel="permissions"<?php echo 'permissions' === $current_tab ? '' : ' hidden'; ?>><?php $this->render_permissions_tab(); ?></section>
                <?php endif; ?>
                <section id="ulhgr-panel-how-to" class="ulhgr-tab-panel" role="tabpanel" aria-labelledby="ulhgr-tab-how-to" data-ulhgr-panel="how-to"<?php echo 'how-to' === $current_tab ? '' : ' hidden'; ?>><?php $this->render_how_to_tab(); ?></section>
            </div>
        </div>

        <?php
    }

    /** Schedule and message controls. */
    private function render_settings_tabs($current_tab) {
        $settings = $this->get_settings();
        try {
            $preview_now = new DateTime('now', new DateTimeZone($settings['default_timezone'] ?: wp_timezone_string()));
        } catch (Exception $error) {
            $preview_now = new DateTime('now', wp_timezone());
        }
        $days = array(
            0 => __('Sunday', 'uplink-hours-greetings'),
            1 => __('Monday', 'uplink-hours-greetings'),
            2 => __('Tuesday', 'uplink-hours-greetings'),
            3 => __('Wednesday', 'uplink-hours-greetings'),
            4 => __('Thursday', 'uplink-hours-greetings'),
            5 => __('Friday', 'uplink-hours-greetings'),
            6 => __('Saturday', 'uplink-hours-greetings'),
        );
        ?>
        <form method="post" action="options.php" class="ulhgr-settings-form">
            <?php settings_fields('ulhgr_settings_group'); ?>
            <section id="ulhgr-panel-schedule" class="ulhgr-tab-panel" role="tabpanel" aria-labelledby="ulhgr-tab-schedule" data-ulhgr-panel="schedule"<?php echo 'schedule' === $current_tab ? '' : ' hidden'; ?>>
            <section class="ulhgr-panel ulhgr-schedule-panel">
                <div class="ulhgr-panel-heading">
                    <div><p class="ulhgr-overline"><?php esc_html_e('SEVEN-DAY SCHEDULE', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Assign a schedule to each day', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Reuse one schedule across several days. Copy it when one day needs different hours or messages.', 'uplink-hours-greetings'); ?></p></div>
                </div>
                <div class="ulhgr-week-grid">
                    <?php $week_start = (int) get_option('start_of_week', 0); ?>
                    <?php for ($offset = 0; $offset < 7; $offset++) : $day = ($week_start + $offset) % 7; ?>
                    <details class="ulhgr-day-row" data-day="<?php echo esc_attr($day); ?>">
                        <summary><strong><?php echo esc_html($days[$day]); ?></strong><span class="ulhgr-day-summary"><?php echo esc_html($settings['profiles'][array_search($settings['week'][$day], array_column($settings['profiles'], 'id'), true)]['name'] ?? ''); ?></span></summary>
                        <div class="ulhgr-day-controls"><label for="ulhgr-day-<?php echo esc_attr($day); ?>"><?php esc_html_e('Schedule', 'uplink-hours-greetings'); ?></label>
                        <select id="ulhgr-day-<?php echo esc_attr($day); ?>" name="<?php echo esc_attr(UPLINK_HOURS_GREETINGS_OPTION_NAME . '[week][' . $day . ']'); ?>" class="ulhgr-day-profile">
                            <?php foreach ($settings['profiles'] as $profile) : ?>
                            <option value="<?php echo esc_attr($profile['id']); ?>" <?php selected($settings['week'][$day], $profile['id']); ?>><?php echo esc_html($profile['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button ulhgr-customize-day" data-day-name="<?php echo esc_attr($days[$day]); ?>"><?php esc_html_e('Customize this day', 'uplink-hours-greetings'); ?></button>
                        </div>
                    </details>
                    <?php endfor; ?>
                </div>
            </section>
            <section class="ulhgr-panel ulhgr-schedule-panel">
                <div class="ulhgr-panel-heading"><div><p class="ulhgr-overline"><?php esc_html_e('REUSABLE DAILY SCHEDULES', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Hours and messages', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Timed entries change the active message. Use Closing text for a schedule value without a clock time, such as “Last Call”; it appears in schedule output without affecting greetings or countdowns.', 'uplink-hours-greetings'); ?></p></div></div>
                <div class="ulhgr-token-guide" role="note" aria-labelledby="ulhgr-token-guide-title">
                    <div class="ulhgr-token-guide-heading"><span class="dashicons dashicons-editor-code" aria-hidden="true"></span><div><h3 id="ulhgr-token-guide-title"><?php esc_html_e('Write dynamic messages', 'uplink-hours-greetings'); ?></h3><p><?php esc_html_e('Place these tokens in a message. They show the configured local time and upcoming schedule events.', 'uplink-hours-greetings'); ?></p></div></div>
                    <ul class="ulhgr-token-list">
                        <li><code>{time}</code><span><?php esc_html_e('Current time', 'uplink-hours-greetings'); ?></span></li>
                        <li><code>{tz}</code><span><?php esc_html_e('Timezone', 'uplink-hours-greetings'); ?></span></li>
                        <li><code>{countdown}</code><span><?php esc_html_e('Until next entry', 'uplink-hours-greetings'); ?></span></li>
                        <li><code>{next_label}</code><span><?php esc_html_e('Next entry label', 'uplink-hours-greetings'); ?></span></li>
                        <li><code>{next_time}</code><span><?php esc_html_e('Next entry time', 'uplink-hours-greetings'); ?></span></li>
                        <li><code>{opening_countdown}</code><span><?php esc_html_e('Until next opening', 'uplink-hours-greetings'); ?></span></li>
                        <li><code>{opening_time}</code><span><?php esc_html_e('Next opening time', 'uplink-hours-greetings'); ?></span></li>
                    </ul>
                    <p class="ulhgr-token-example"><strong><?php esc_html_e('Example', 'uplink-hours-greetings'); ?></strong> <span><?php esc_html_e('It’s {time}. We open in {opening_countdown}.', 'uplink-hours-greetings'); ?></span></p>
                </div>
                <div class="ulhgr-profiles">
                    <?php foreach ($settings['profiles'] as $profile_index => $profile) : ?>
                    <div class="ulhgr-profile" data-profile-id="<?php echo esc_attr($profile['id']); ?>">
                        <div class="ulhgr-profile-heading">
                            <div class="ulhgr-profile-name"><label><?php esc_html_e('Schedule name', 'uplink-hours-greetings'); ?><input type="text" class="ulhgr-profile-name-input" name="<?php echo esc_attr(UPLINK_HOURS_GREETINGS_OPTION_NAME . '[profiles][' . $profile_index . '][name]'); ?>" value="<?php echo esc_attr($profile['name']); ?>" maxlength="80" required></label><input type="hidden" class="ulhgr-profile-id" name="<?php echo esc_attr(UPLINK_HOURS_GREETINGS_OPTION_NAME . '[profiles][' . $profile_index . '][id]'); ?>" value="<?php echo esc_attr($profile['id']); ?>"></div>
                            <button type="button" class="button ulhgr-remove-profile"><?php esc_html_e('Remove schedule', 'uplink-hours-greetings'); ?></button>
                        </div>
                        <label class="ulhgr-checkbox-field ulhgr-closed-field"><input type="hidden" name="<?php echo esc_attr(UPLINK_HOURS_GREETINGS_OPTION_NAME . '[profiles][' . $profile_index . '][closed]'); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr(UPLINK_HOURS_GREETINGS_OPTION_NAME . '[profiles][' . $profile_index . '][closed]'); ?>" value="1" <?php checked(!empty($profile['closed'])); ?>><?php esc_html_e('Closed all day', 'uplink-hours-greetings'); ?></label>
                        <div class="ulhgr-intervals">
                            <?php foreach ($profile['intervals'] as $row_index => $interval) : ?>
                                <?php $this->render_interval_row($profile_index, $row_index, $interval); ?>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button ulhgr-add-interval"><?php esc_html_e('Add schedule entry', 'uplink-hours-greetings'); ?></button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button ulhgr-add-profile"><?php esc_html_e('Add reusable schedule', 'uplink-hours-greetings'); ?></button>
            </section>
            <div class="ulhgr-save-row"><?php submit_button(__('Save schedule', 'uplink-hours-greetings'), 'primary', 'submit', false); ?></div>
            </section>
            <section id="ulhgr-panel-overview" class="ulhgr-tab-panel" role="tabpanel" aria-labelledby="ulhgr-tab-overview" data-ulhgr-panel="overview"<?php echo 'overview' === $current_tab ? '' : ' hidden'; ?>>
            <section class="ulhgr-panel ulhgr-overview-panel">
                <div class="ulhgr-panel-heading"><div><p class="ulhgr-overline"><?php esc_html_e('LOCAL TIME', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Timezone', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Uses the WordPress site timezone by default. Choose another timezone only when needed.', 'uplink-hours-greetings'); ?></p></div></div>
                <div class="ulhgr-default-fields">
                    <div class="ulhgr-field"><label for="default_timezone"><?php esc_html_e('Timezone', 'uplink-hours-greetings'); ?></label><?php $this->timezone_field_callback(array('field' => 'default_timezone')); ?></div>
                    <div class="ulhgr-field"><label for="default_tz_abbr"><?php esc_html_e('Timezone label (optional)', 'uplink-hours-greetings'); ?></label><?php $this->text_field_callback(array('field' => 'default_tz_abbr')); ?><p><?php esc_html_e('Leave blank to use the timezone’s current abbreviation.', 'uplink-hours-greetings'); ?></p></div>
                </div>
            </section>
            <section class="ulhgr-panel ulhgr-overview-panel">
                <div class="ulhgr-panel-heading"><div><p class="ulhgr-overline"><?php esc_html_e('DATE WORDING', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Date introduction', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Set the words that appear before the date in the combined output and, optionally, Date only.', 'uplink-hours-greetings'); ?></p></div></div>
                <div class="ulhgr-date-wording-fields">
                    <div class="ulhgr-field ulhgr-date-intro-field"><label for="date_intro"><?php esc_html_e('Introduction', 'uplink-hours-greetings'); ?></label><?php $this->text_field_callback(array('field' => 'date_intro')); ?><p><?php esc_html_e('Translate or rewrite “Today is” for your audience. Leave blank to show only the date.', 'uplink-hours-greetings'); ?></p></div>
                    <div class="ulhgr-field ulhgr-date-suffix-field"><label for="date_suffix"><?php esc_html_e('Ending punctuation', 'uplink-hours-greetings'); ?></label><?php $this->text_field_callback(array('field' => 'date_suffix', 'maxlength' => 12)); ?><p><?php esc_html_e('Leave blank for no ending. You can also use a period, exclamation mark, or localized punctuation.', 'uplink-hours-greetings'); ?></p></div>
                </div>
                <label class="ulhgr-checkbox-field" for="date_only_intro"><input type="hidden" name="<?php echo esc_attr(UPLINK_HOURS_GREETINGS_OPTION_NAME . '[date_only_intro]'); ?>" value="0"><input type="checkbox" id="date_only_intro" name="<?php echo esc_attr(UPLINK_HOURS_GREETINGS_OPTION_NAME . '[date_only_intro]'); ?>" value="1" <?php checked(!empty($settings['date_only_intro'])); ?>><?php esc_html_e('Show the introduction with Date only', 'uplink-hours-greetings'); ?></label>
            </section>
            <section class="ulhgr-panel ulhgr-overview-panel">
                <div class="ulhgr-panel-heading"><div><p class="ulhgr-overline"><?php esc_html_e('LIVE STATUS', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Open and closed wording', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('These labels power the status block, shortcode, Bricks tags, and Etch data.', 'uplink-hours-greetings'); ?></p></div></div>
                <div class="ulhgr-default-fields">
                    <div class="ulhgr-field"><label for="open_label"><?php esc_html_e('Open label', 'uplink-hours-greetings'); ?></label><?php $this->text_field_callback(array('field' => 'open_label', 'maxlength' => 80)); ?></div>
                    <div class="ulhgr-field"><label for="closed_label"><?php esc_html_e('Closed label', 'uplink-hours-greetings'); ?></label><?php $this->text_field_callback(array('field' => 'closed_label', 'maxlength' => 80)); ?></div>
                </div>
            </section>
            <?php $this->render_schema_settings($settings); ?>
            <div class="ulhgr-save-row"><?php submit_button(__('Save settings', 'uplink-hours-greetings'), 'primary', 'submit', false); ?></div>
            <section class="ulhgr-panel ulhgr-preview ulhgr-overview-panel">
            <div class="ulhgr-panel-heading"><div><p class="ulhgr-overline"><?php esc_html_e('OUTPUT EXAMPLES', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('What visitors see now', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('These examples use the same saved schedule and show each display option.', 'uplink-hours-greetings'); ?></p></div></div>
            <div class="ulhgr-preview-controls">
                <label><?php esc_html_e('Preview date', 'uplink-hours-greetings'); ?><input type="date" class="ulhgr-preview-date" value="<?php echo esc_attr($preview_now->format('Y-m-d')); ?>"></label>
                <label><?php esc_html_e('Time', 'uplink-hours-greetings'); ?><input type="time" class="ulhgr-preview-time" value="<?php echo esc_attr($preview_now->format('H:i')); ?>"></label>
                <button type="button" class="button ulhgr-preview-button"><?php esc_html_e('Update preview', 'uplink-hours-greetings'); ?></button>
                <span class="ulhgr-preview-status" role="status" aria-live="polite"></span>
            </div>
            <div class="ulhgr-example-grid">
                <?php foreach (array('greeting' => __('Greeting', 'uplink-hours-greetings'), 'date' => __('Date', 'uplink-hours-greetings'), 'both' => __('Greeting and date', 'uplink-hours-greetings'), 'status' => __('Open or closed status', 'uplink-hours-greetings'), 'schedule' => __('Weekly schedule', 'uplink-hours-greetings')) as $display => $label) : ?>
                    <div class="ulhgr-example" data-display="<?php echo esc_attr($display); ?>"><h3><?php echo esc_html($label); ?></h3><div class="ulhgr-preview-value"><?php echo wp_kses_post($this->generate_greeting(array('display' => $display))); ?></div></div>
                <?php endforeach; ?>
            </div>
            <p><?php esc_html_e('Preview uses saved settings. Countdowns in blocks and shortcodes refresh on the page.', 'uplink-hours-greetings'); ?></p>
            </section>
            </section>
            <section id="ulhgr-panel-special-dates" class="ulhgr-tab-panel" role="tabpanel" aria-labelledby="ulhgr-tab-special-dates" data-ulhgr-panel="special-dates"<?php echo 'special-dates' === $current_tab ? '' : ' hidden'; ?>>
                <?php $this->render_special_dates_tab($settings); ?>
            </section>
        </form>
        <?php
    }

    private function render_interval_row($profile_index, $row_index, $interval) {
        $name = UPLINK_HOURS_GREETINGS_OPTION_NAME . '[profiles][' . $profile_index . '][intervals][' . $row_index . ']';
        $timing = isset($interval['timing']) && 'untimed' === $interval['timing'] ? 'untimed' : 'timed';
        ?>
        <div class="ulhgr-interval-row<?php echo 'untimed' === $timing ? ' is-untimed' : ''; ?>">
            <label><?php esc_html_e('Entry type', 'uplink-hours-greetings'); ?><select class="ulhgr-entry-type" name="<?php echo esc_attr($name . '[timing]'); ?>"><option value="timed" <?php selected($timing, 'timed'); ?>><?php esc_html_e('Timed entry', 'uplink-hours-greetings'); ?></option><option value="untimed" <?php selected($timing, 'untimed'); ?>><?php esc_html_e('Closing text', 'uplink-hours-greetings'); ?></option></select></label>
            <label class="ulhgr-time-field"><?php esc_html_e('Starts at', 'uplink-hours-greetings'); ?><input type="time" step="60" name="<?php echo esc_attr($name . '[start]'); ?>" value="<?php echo esc_attr($interval['start']); ?>" <?php echo 'timed' === $timing ? 'required' : 'disabled'; ?>></label>
            <label><?php esc_html_e('Label', 'uplink-hours-greetings'); ?><input type="text" name="<?php echo esc_attr($name . '[label]'); ?>" value="<?php echo esc_attr($interval['label']); ?>" maxlength="80" placeholder="<?php esc_attr_e('Optional', 'uplink-hours-greetings'); ?>"></label>
            <label class="ulhgr-event-field"><?php esc_html_e('Event', 'uplink-hours-greetings'); ?><select name="<?php echo esc_attr($name . '[event]'); ?>" <?php echo 'untimed' === $timing ? 'disabled' : ''; ?>><option value="" <?php selected($interval['event'], ''); ?>><?php esc_html_e('None', 'uplink-hours-greetings'); ?></option><option value="opening" <?php selected($interval['event'], 'opening'); ?>><?php esc_html_e('Opening', 'uplink-hours-greetings'); ?></option><option value="closing" <?php selected($interval['event'], 'closing'); ?>><?php esc_html_e('Closing', 'uplink-hours-greetings'); ?></option></select></label>
            <label class="ulhgr-message-field"><?php esc_html_e('Message', 'uplink-hours-greetings'); ?><textarea name="<?php echo esc_attr($name . '[message]'); ?>" rows="2" <?php echo 'untimed' === $timing ? 'disabled' : ''; ?>><?php echo esc_textarea($interval['message']); ?></textarea></label>
            <button type="button" class="button ulhgr-remove-interval" aria-label="<?php esc_attr_e('Remove schedule entry', 'uplink-hours-greetings'); ?>" title="<?php esc_attr_e('Remove schedule entry', 'uplink-hours-greetings'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v6m4-6v6"/></svg></button>
        </div>
        <?php
    }

    private function render_special_dates_tab($settings) {
        ?>
        <section class="ulhgr-panel">
            <div class="ulhgr-panel-heading"><div><p class="ulhgr-overline"><?php esc_html_e('HOLIDAYS & EXCEPTIONS', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Override specific dates', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Close for a holiday or replace the regular weekday hours with one or more reduced-hour windows. The override is used everywhere automatically.', 'uplink-hours-greetings'); ?></p></div></div>
            <div class="ulhgr-special-dates" data-next-index="<?php echo esc_attr((string) count($settings['special_dates'])); ?>">
                <?php foreach ($settings['special_dates'] as $index => $special) $this->render_special_date_row($index, $special); ?>
            </div>
            <button type="button" class="button ulhgr-add-special-date"><?php esc_html_e('Add special date', 'uplink-hours-greetings'); ?></button>
        </section>
        <div class="ulhgr-save-row"><?php submit_button(__('Save special dates', 'uplink-hours-greetings'), 'primary', 'submit', false); ?></div>
        <?php
    }

    private function render_special_date_row($index, $special) {
        $name = UPLINK_HOURS_GREETINGS_OPTION_NAME . '[special_dates][' . $index . ']';
        $windows = !empty($special['windows']) ? $special['windows'] : array(array('open' => '09:00', 'close' => '17:00'));
        ?>
        <details class="ulhgr-special-date" open>
            <summary><span class="ulhgr-special-date-summary"><strong><?php echo esc_html($special['label'] ?: __('Special date', 'uplink-hours-greetings')); ?></strong><span><?php echo esc_html($special['date']); ?></span></span><span class="ulhgr-special-date-state"><?php echo !empty($special['closed']) ? esc_html__('Closed', 'uplink-hours-greetings') : esc_html__('Custom hours', 'uplink-hours-greetings'); ?></span></summary>
            <div class="ulhgr-special-date-body">
                <div class="ulhgr-special-date-fields">
                    <label><?php esc_html_e('Date', 'uplink-hours-greetings'); ?><input type="date" class="ulhgr-special-date-input" name="<?php echo esc_attr($name . '[date]'); ?>" value="<?php echo esc_attr($special['date']); ?>" required></label>
                    <label><?php esc_html_e('Label', 'uplink-hours-greetings'); ?><input type="text" class="ulhgr-special-label-input" name="<?php echo esc_attr($name . '[label]'); ?>" value="<?php echo esc_attr($special['label']); ?>" maxlength="100" placeholder="<?php esc_attr_e('Holiday, private event…', 'uplink-hours-greetings'); ?>"></label>
                </div>
                <label class="ulhgr-checkbox-field"><input type="hidden" name="<?php echo esc_attr($name . '[closed]'); ?>" value="0"><input type="checkbox" class="ulhgr-special-closed" name="<?php echo esc_attr($name . '[closed]'); ?>" value="1" <?php checked(!empty($special['closed'])); ?>><?php esc_html_e('Closed all day', 'uplink-hours-greetings'); ?></label>
                <div class="ulhgr-special-windows">
                    <?php foreach ($windows as $window_index => $window) : ?>
                    <div class="ulhgr-special-window">
                        <label><?php esc_html_e('Opens', 'uplink-hours-greetings'); ?><input type="time" name="<?php echo esc_attr($name . '[windows][' . $window_index . '][open]'); ?>" value="<?php echo esc_attr($window['open']); ?>"></label>
                        <label><?php esc_html_e('Closes', 'uplink-hours-greetings'); ?><input type="time" name="<?php echo esc_attr($name . '[windows][' . $window_index . '][close]'); ?>" value="<?php echo esc_attr($window['close']); ?>"></label>
                        <button type="button" class="button ulhgr-remove-special-window" aria-label="<?php esc_attr_e('Remove hours window', 'uplink-hours-greetings'); ?>">&times;</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button ulhgr-add-special-window"><?php esc_html_e('Add hours window', 'uplink-hours-greetings'); ?></button>
                <label class="ulhgr-special-message"><?php esc_html_e('Message for this date (optional)', 'uplink-hours-greetings'); ?><textarea name="<?php echo esc_attr($name . '[message]'); ?>" rows="2" placeholder="<?php esc_attr_e('Closed today for the holiday.', 'uplink-hours-greetings'); ?>"><?php echo esc_textarea($special['message']); ?></textarea></label>
                <div class="ulhgr-special-link-fields">
                    <label><?php esc_html_e('Destination URL (optional)', 'uplink-hours-greetings'); ?><input type="url" name="<?php echo esc_attr($name . '[link_url]'); ?>" value="<?php echo esc_attr($special['link_url']); ?>" placeholder="https://example.com/special-event"></label>
                    <label><?php esc_html_e('Link label (optional)', 'uplink-hours-greetings'); ?><input type="text" name="<?php echo esc_attr($name . '[link_label]'); ?>" value="<?php echo esc_attr($special['link_label']); ?>" maxlength="100" placeholder="<?php esc_attr_e('Learn more', 'uplink-hours-greetings'); ?>"></label>
                </div>
                <button type="button" class="button ulhgr-remove-special-date"><?php esc_html_e('Remove special date', 'uplink-hours-greetings'); ?></button>
            </div>
        </details>
        <?php
    }

    private function render_schema_settings($settings) {
        $name = UPLINK_HOURS_GREETINGS_OPTION_NAME;
        ?>
        <section class="ulhgr-panel ulhgr-overview-panel">
            <div class="ulhgr-panel-heading"><div><p class="ulhgr-overline"><?php esc_html_e('SEARCH ENGINES', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('LocalBusiness structured data', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Optionally publish your regular and special hours as JSON-LD. Fill in the business identity fields before enabling it.', 'uplink-hours-greetings'); ?></p></div></div>
            <label class="ulhgr-checkbox-field"><input type="hidden" name="<?php echo esc_attr($name . '[schema_enabled]'); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr($name . '[schema_enabled]'); ?>" value="1" <?php checked(!empty($settings['schema_enabled'])); ?>><?php esc_html_e('Add LocalBusiness structured data to the site', 'uplink-hours-greetings'); ?></label>
            <div class="ulhgr-schema-grid">
                <label><?php esc_html_e('Business type', 'uplink-hours-greetings'); ?><select name="<?php echo esc_attr($name . '[schema_type]'); ?>"><?php foreach (array('LocalBusiness','Store','ProfessionalService','Restaurant','MedicalBusiness','LodgingBusiness','AutomotiveBusiness') as $type) : ?><option value="<?php echo esc_attr($type); ?>" <?php selected($settings['schema_type'], $type); ?>><?php echo esc_html($type); ?></option><?php endforeach; ?></select></label>
                <?php foreach (array('schema_name' => __('Business name', 'uplink-hours-greetings'), 'schema_phone' => __('Phone', 'uplink-hours-greetings'), 'schema_price_range' => __('Price range', 'uplink-hours-greetings'), 'schema_street' => __('Street address', 'uplink-hours-greetings'), 'schema_locality' => __('City or locality', 'uplink-hours-greetings'), 'schema_region' => __('State or region', 'uplink-hours-greetings'), 'schema_postal_code' => __('Postal code', 'uplink-hours-greetings'), 'schema_country' => __('Country', 'uplink-hours-greetings')) as $field => $label) : ?>
                    <label><?php echo esc_html($label); ?><input type="text" name="<?php echo esc_attr($name . '[' . $field . ']'); ?>" value="<?php echo esc_attr($settings[$field]); ?>"></label>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /** Administrator-only access controls for changing plugin settings. */
    private function render_permissions_tab() {
        $permissions = get_option(UPLINK_HOURS_GREETINGS_PERMISSIONS_OPTION, array());
        $selected_roles = is_array($permissions) ? ($permissions['roles'] ?? array()) : array();
        $selected_users = is_array($permissions) ? ($permissions['users'] ?? array()) : array();
        $users = get_users(array('orderby' => 'display_name', 'order' => 'ASC'));
        ?>
        <form method="post" action="options.php" class="ulhgr-permissions-form">
            <?php settings_fields('ulhgr_permissions_group'); ?>
            <input type="hidden" name="<?php echo esc_attr(UPLINK_HOURS_GREETINGS_PERMISSIONS_OPTION); ?>[_submitted]" value="1">
            <section class="ulhgr-panel">
                <div class="ulhgr-panel-heading"><div><p class="ulhgr-overline"><?php esc_html_e('ACCESS CONTROL', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Who can update settings', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Administrators always have access. You can also allow entire roles or specific users to edit the schedule and other plugin settings.', 'uplink-hours-greetings'); ?></p></div></div>
                <div class="ulhgr-permission-grid">
                    <fieldset class="ulhgr-permission-group">
                        <legend><?php esc_html_e('Roles', 'uplink-hours-greetings'); ?></legend>
                        <p><?php esc_html_e('Everyone with a selected role can update plugin settings.', 'uplink-hours-greetings'); ?></p>
                        <div class="ulhgr-permission-list">
                            <div class="ulhgr-permission-choice ulhgr-permission-fixed"><span><?php esc_html_e('Administrator', 'uplink-hours-greetings'); ?></span><span><?php esc_html_e('Always allowed', 'uplink-hours-greetings'); ?></span></div>
                            <?php foreach (wp_roles()->roles as $role_key => $role) : if ('administrator' === $role_key) { continue; } ?>
                                <label class="ulhgr-permission-choice"><input type="checkbox" name="<?php echo esc_attr(UPLINK_HOURS_GREETINGS_PERMISSIONS_OPTION); ?>[roles][]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, (array) $selected_roles, true)); ?>><span><?php echo esc_html(translate_user_role($role['name'])); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <fieldset class="ulhgr-permission-group">
                        <legend><?php esc_html_e('Individual users', 'uplink-hours-greetings'); ?></legend>
                        <p><?php esc_html_e('Grant access to a user without changing their role.', 'uplink-hours-greetings'); ?></p>
                        <label class="ulhgr-user-search-label" for="ulhgr-user-search"><?php esc_html_e('Find a user', 'uplink-hours-greetings'); ?></label>
                        <input type="search" id="ulhgr-user-search" class="ulhgr-user-search" placeholder="<?php esc_attr_e('Search by name or username', 'uplink-hours-greetings'); ?>">
                        <div class="ulhgr-permission-list ulhgr-user-list">
                            <?php foreach ($users as $user) : if (user_can($user, 'manage_options')) { continue; } ?>
                                <label class="ulhgr-permission-choice ulhgr-user-choice"><input type="checkbox" name="<?php echo esc_attr(UPLINK_HOURS_GREETINGS_PERMISSIONS_OPTION); ?>[users][]" value="<?php echo esc_attr($user->ID); ?>" <?php checked(in_array((int) $user->ID, array_map('intval', (array) $selected_users), true)); ?>><span><?php echo esc_html($user->display_name); ?><small><?php echo esc_html($user->user_login); ?></small></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                </div>
            </section>
            <div class="ulhgr-save-row"><?php submit_button(__('Save permissions', 'uplink-hours-greetings'), 'primary', 'submit', false); ?></div>
        </form>
        <?php
    }

    /** Usage and appearance guidance for every supported editor. */
    private function render_how_to_tab() {
        ?>
        <div class="ulhgr-guide-tabs" role="tablist" aria-label="<?php esc_attr_e('How to use Uplink Hours & Greetings', 'uplink-hours-greetings'); ?>">
            <button type="button" id="ulhgr-guide-tab-blocks" class="ulhgr-guide-tab is-active" role="tab" aria-selected="true" aria-controls="ulhgr-guide-panel-blocks" data-ulhgr-guide-tab="blocks"><?php esc_html_e('Blocks and shortcodes', 'uplink-hours-greetings'); ?></button>
            <button type="button" id="ulhgr-guide-tab-etch" class="ulhgr-guide-tab" role="tab" aria-selected="false" aria-controls="ulhgr-guide-panel-etch" tabindex="-1" data-ulhgr-guide-tab="etch"><?php esc_html_e('Etch', 'uplink-hours-greetings'); ?></button>
            <button type="button" id="ulhgr-guide-tab-bricks" class="ulhgr-guide-tab" role="tab" aria-selected="false" aria-controls="ulhgr-guide-panel-bricks" tabindex="-1" data-ulhgr-guide-tab="bricks"><?php esc_html_e('Bricks Builder', 'uplink-hours-greetings'); ?></button>
        </div>

        <div id="ulhgr-guide-panel-blocks" class="ulhgr-guide-panel" role="tabpanel" aria-labelledby="ulhgr-guide-tab-blocks" data-ulhgr-guide-panel="blocks">
            <div class="ulhgr-usage-grid">
                <section class="ulhgr-panel"><p class="ulhgr-overline"><?php esc_html_e('WORDPRESS', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Block editor', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Insert Uplink Hours & Greetings, then choose greeting, date, greeting and date, live status, or weekly schedule in the block sidebar. Insert Uplink Special Dates to show one saved event or a list of upcoming events.', 'uplink-hours-greetings'); ?></p><p><?php esc_html_e('Business-hour output uses entries marked Opening and Closing. Greeting-only entries can remain set to None.', 'uplink-hours-greetings'); ?></p></section>
                <section class="ulhgr-panel"><p class="ulhgr-overline"><?php esc_html_e('SHORTCODE', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Shortcode and PHP', 'uplink-hours-greetings'); ?></h2><p><code>[uplink_hours_greetings]</code> <code>[uplink_hours_greetings display="status"]</code> <code>[uplink_hours_greetings display="schedule"]</code></p><p><code>uplink_hours_greetings_echo( array( 'display' => 'schedule' ) );</code></p></section>
                <section class="ulhgr-panel ulhgr-appearance-guide"><p class="ulhgr-overline"><?php esc_html_e('APPEARANCE', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Style the output', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Blocks inherit the theme colors and typography. Block sidebar controls can set color, spacing, and type. The Special Dates block adds layout spacing only, so event titles, links, and other text follow the block and theme styles.', 'uplink-hours-greetings'); ?></p><p><?php esc_html_e('The ready-made schedule uses these classes:', 'uplink-hours-greetings'); ?></p><p><code>.ulhgr-schedule</code> <code>.ulhgr-schedule__day</code> <code>.ulhgr-schedule__name</code> <code>.ulhgr-schedule__hours</code> <code>.ulhgr-schedule__interval</code> <code>.ulhgr-schedule__status</code></p></section>
            </div>
        </div>

        <div id="ulhgr-guide-panel-etch" class="ulhgr-guide-panel" role="tabpanel" aria-labelledby="ulhgr-guide-tab-etch" data-ulhgr-guide-panel="etch" hidden>
            <section class="ulhgr-panel ulhgr-etch-guide"><p class="ulhgr-overline"><?php esc_html_e('ETCH', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Options data', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Use a complete output directly, or bind individual values to elements you build and style in Etch.', 'uplink-hours-greetings'); ?></p>
                <h3><?php esc_html_e('Ready-made outputs', 'uplink-hours-greetings'); ?></h3><p><code>{options.uplink_hours_greetings.greeting}</code> <code>{options.uplink_hours_greetings.date}</code> <code>{options.uplink_hours_greetings.both}</code> <code>{options.uplink_hours_greetings.status_output}</code> <code>{options.uplink_hours_greetings.schedule}</code></p>
                <h3><?php esc_html_e('Live status', 'uplink-hours-greetings'); ?></h3><p><code>{options.uplink_hours_greetings.is_open}</code> <code>{options.uplink_hours_greetings.status}</code> <code>{options.uplink_hours_greetings.status_label}</code> <code>{options.uplink_hours_greetings.current_window.start_label}</code> <code>{options.uplink_hours_greetings.current_window.end_label}</code> <code>{options.uplink_hours_greetings.next_change.type}</code> <code>{options.uplink_hours_greetings.next_change.time}</code> <code>{options.uplink_hours_greetings.next_change.countdown}</code></p>
                <h3><?php esc_html_e('Weekly schedule loop', 'uplink-hours-greetings'); ?></h3><p><?php esc_html_e('Add an Etch Loop block. Set Target to options.uplink_hours_greetings.week and Item ID to day. Build the row with native elements:', 'uplink-hours-greetings'); ?></p><p><code>{day.day}</code> <code>{day.date}</code> <code>{day.hours}</code> <code>{day.state}</code> <code>{day.key}</code> <code>{day.number}</code> <code>{day.is_today}</code> <code>{day.is_special}</code> <code>{day.special_label}</code> <code>{day.special_link_url}</code> <code>{day.special_link_label}</code></p>
                <h3><?php esc_html_e('Special dates loop', 'uplink-hours-greetings'); ?></h3><p><?php esc_html_e('Loop over options.uplink_hours_greetings.special_dates and choose an item ID such as special. Each item includes date, label, message, closed, windows, link_url, and link_label.', 'uplink-hours-greetings'); ?></p><p><code>{special.date}</code> <code>{special.label}</code> <code>{special.message}</code> <code>{special.closed}</code> <code>{special.link_url}</code> <code>{special.link_label}</code></p>
            </section>
        </div>

        <div id="ulhgr-guide-panel-bricks" class="ulhgr-guide-panel" role="tabpanel" aria-labelledby="ulhgr-guide-tab-bricks" data-ulhgr-guide-panel="bricks" hidden>
            <section class="ulhgr-panel"><p class="ulhgr-overline"><?php esc_html_e('BRICKS BUILDER', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Query Loop and dynamic tags', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Add a non-looping wrapper for the layout. Inside it, add a Div or Block, enable Query Loop on that inner element, and choose Uplink Weekly Schedule. Add text elements inside the repeating row and bind these tags:', 'uplink-hours-greetings'); ?></p><p><code>{ulhgr_day}</code> <code>{ulhgr_hours}</code> <code>{ulhgr_state}</code> <code>{ulhgr_key}</code> <code>{ulhgr_today}</code> <code>{ulhgr_row_date}</code> <code>{ulhgr_row_is_special}</code> <code>{ulhgr_row_special_label}</code> <code>{ulhgr_row_link_url}</code> <code>{ulhgr_row_link_label}</code></p><p><?php esc_html_e('Row tags only resolve inside the Uplink Weekly Schedule loop. Business-hour values require Opening and Closing events in the assigned schedule.', 'uplink-hours-greetings'); ?></p>
                <h3><?php esc_html_e('Load special events', 'uplink-hours-greetings'); ?></h3><p><?php esc_html_e('Add another inner Div or Block, enable Query Loop, and choose Uplink Special Dates. It returns upcoming saved events in date order. Build each event with these tags:', 'uplink-hours-greetings'); ?></p><p><code>{ulhgr_event_date}</code> <code>{ulhgr_event_date_label}</code> <code>{ulhgr_event_title}</code> <code>{ulhgr_event_message}</code> <code>{ulhgr_event_hours}</code> <code>{ulhgr_event_closed}</code> <code>{ulhgr_event_link_url}</code> <code>{ulhgr_event_link_label}</code></p>
                <h3><?php esc_html_e('Direct tags', 'uplink-hours-greetings'); ?></h3><p><code>{ulhgr_greeting}</code> <code>{ulhgr_date}</code> <code>{ulhgr_both}</code> <code>{ulhgr_status}</code> <code>{ulhgr_status_label}</code> <code>{ulhgr_is_open}</code> <code>{ulhgr_next_change}</code> <code>{ulhgr_special_label}</code> <code>{ulhgr_special_link_url}</code> <code>{ulhgr_special_link_label}</code> <code>{ulhgr_schedule}</code></p><p><?php esc_html_e('For ready-made semantic markup, use a Bricks Shortcode element with [uplink_hours_greetings display="schedule"].', 'uplink-hours-greetings'); ?></p>
            </section>
        </div>
        <?php
    }
}

// Initialize the plugin
Uplink_Hours_Greetings_Plugin::get_instance();

/**
 * Echo function for external use (Bricks Builder, etc.)
 */
function uplink_hours_greetings_echo($atts = array()) {
    Uplink_Hours_Greetings_Plugin::get_instance()->echo_greeting($atts);
}
