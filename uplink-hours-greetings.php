<?php
/**
 * Plugin Name: Uplink Hours & Greetings
 * Plugin URI: https://plugins.uplink.press/articles/meet-uplink-hours-and-greetings/
 * Description: Weekly business schedules, live countdowns, greetings, and dates for blocks, Bricks, Etch, and shortcodes.
 * Version: 1.0.1
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
define('UPLINK_HOURS_GREETINGS_PLUGIN_VERSION', '1.0.1');
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
        // Register the block using block.json
        if (function_exists('register_block_type')) {
            register_block_type(__DIR__, array(
                'render_callback' => array($this, 'render_block'),
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
            'default_tz_abbr' => ''
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
        return $settings;
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

    /**
     * Shortcode handler
     */
    public function shortcode_handler($atts) {
        $attributes = shortcode_atts(array(
            'display' => 'greeting',
            'date_format' => 'F j, Y',
            'timezone' => '',
            'tz_abbr' => ''
        ), $atts, 'uplink_hours_greetings');

        // Convert to camelCase for consistency with block attributes
        $normalized_attrs = array(
            'display' => sanitize_text_field($attributes['display']),
            'dateFormat' => sanitize_text_field($attributes['date_format']),
            'timezone' => sanitize_text_field($attributes['timezone']),
            'tzAbbr' => sanitize_text_field($attributes['tz_abbr'])
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
        if (!in_array($display, array('greeting', 'date', 'both', 'schedule'), true)) {
            $display = 'greeting';
        }
        if ('schedule' === $display) {
            return $this->schedule_html($attributes['previewAt'] ?? '');
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
        $message = esc_html($current['message']);
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

    private function active_interval($datetime, $settings) {
        $now = DateTimeImmutable::createFromMutable($datetime);
        $midnight = $now->setTime(0, 0);
        $latest = null;
        for ($offset = -7; $offset <= 0; $offset++) {
            $date = $midnight->modify($offset . ' days');
            $profile = $this->profile_for_day($settings, (int) $date->format('w'));
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
        for ($offset = 0; $offset <= 7; $offset++) {
            $date = $midnight->modify('+' . $offset . ' days');
            $profile = $this->profile_for_day($settings, (int) $date->format('w'));
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
    private function schedule_rows() {
        $settings = $this->get_settings();
        $start = (int) get_option('start_of_week', 0);
        if ($start < 0 || $start > 6) {
            $start = 0;
        }
        $rows = array();
        $time_format = get_option('time_format', 'g:i a');
        $sunday = new DateTimeImmutable('2023-01-01', wp_timezone());
        for ($offset = 0; $offset < 7; $offset++) {
            $day = ($start + $offset) % 7;
            $profile = $this->profile_for_day($settings, $day);
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
                        $next_profile = $this->profile_for_day($settings, $closing_day);
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
                    $from = $sunday->modify('+' . $day . ' days')->setTime((int) substr($interval['start'], 0, 2), (int) substr($interval['start'], 3, 2));
                    $from_label = wp_date($time_format, $from->getTimestamp(), wp_timezone());
                    $window = array('start' => $interval['start'], 'start_label' => $from_label, 'end' => '', 'end_label' => '', 'end_type' => 'none', 'overnight' => false);
                    if (null === $closing) {
                        /* translators: %s: opening time without a matching closing time. */
                        $hours[] = sprintf(__('From %s', 'uplink-hours-greetings'), $from_label);
                    } elseif ('untimed' === ($closing['timing'] ?? 'timed')) {
                        $window['end_label'] = '' !== trim($closing['label']) ? $closing['label'] : __('Closing time not set', 'uplink-hours-greetings');
                        $window['end_type'] = 'text';
                        $hours[] = $from_label . '–' . $window['end_label'];
                    } else {
                        $to = $sunday->modify('+' . ($closing_day === $day ? $day : $day + 1) . ' days')->setTime((int) substr($closing['start'], 0, 2), (int) substr($closing['start'], 3, 2));
                        $window['end'] = $closing['start'];
                        $window['end_label'] = wp_date($time_format, $to->getTimestamp(), wp_timezone());
                        $window['end_type'] = 'time';
                        $window['overnight'] = $closing_day !== $day;
                        $hours[] = $from_label . '–' . $window['end_label'];
                    }
                    $windows[] = $window;
                }
            }
            $rows[] = array(
                'key' => strtolower($sunday->modify('+' . $day . ' days')->format('l')),
                'number' => $day,
                'day' => wp_date('l', $sunday->modify('+' . $day . ' days')->getTimestamp(), wp_timezone()),
                'hours' => !empty($profile['closed']) ? __('Closed', 'uplink-hours-greetings') : ($hours ? implode(', ', $hours) : __('Hours not set', 'uplink-hours-greetings')),
                'state' => !empty($profile['closed']) ? 'closed' : ($hours ? 'open' : 'unset'),
                'is_today' => false,
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
        foreach ($this->schedule_rows() as $row) {
            $html .= '<div class="ulhgr-schedule__day ulhgr-schedule__day--' . esc_attr($row['state']) . ($row['number'] === $today ? ' ulhgr-schedule__day--today' : '') . '" data-day="' . esc_attr($row['key']) . '" data-state="' . esc_attr($row['state']) . '">';
            $html .= '<dt class="ulhgr-schedule__name">' . esc_html($row['day']) . '</dt><dd class="ulhgr-schedule__hours">';
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

    /** Plain text values for builders that own their element markup. */
    public function plain_value($display = 'greeting') {
        if (!in_array($display, array('greeting', 'date', 'both', 'schedule'), true)) {
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
        ) as $key => $label) {
            $tags[] = array('name' => '{ulhgr_' . $key . '}', 'label' => $label, 'group' => __('Uplink Hours & Greetings', 'uplink-hours-greetings'));
        }
        return $tags;
    }

    /** Expose the ordered seven-day schedule as a native Bricks Query Loop source. */
    public function bricks_query_options($options) {
        $options['queryTypes']['ulhgr_schedule'] = __('Uplink Weekly Schedule', 'uplink-hours-greetings');
        return $options;
    }

    public function bricks_schedule_query($results, $query) {
        if (!is_object($query) || !isset($query->object_type) || 'ulhgr_schedule' !== $query->object_type) {
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
        return isset($row[$key]) && is_scalar($row[$key]) ? (string) $row[$key] : '';
    }

    public function bricks_render_tag($tag, $post = null, $context = 'text') {
        if (!is_string($tag)) {
            return $tag;
        }
        $key = trim($tag, '{}');
        if (in_array($key, array('ulhgr_day', 'ulhgr_hours', 'ulhgr_state', 'ulhgr_key', 'ulhgr_today'), true)) {
            $value = $this->bricks_schedule_field(substr($key, 6));
            return null === $value ? $tag : esc_html($value);
        }
        if (!in_array($key, array('ulhgr_greeting', 'ulhgr_date', 'ulhgr_both', 'ulhgr_schedule'), true)) {
            return $tag;
        }
        return esc_html($this->plain_value(substr($key, 6)));
    }

    public function bricks_render_content($content, $post = null, $context = 'text') {
        if (!is_string($content) || false === strpos($content, '{ulhgr_')) {
            return $content;
        }
        foreach (array('day', 'hours', 'state', 'key', 'today') as $key) {
            $value = $this->bricks_schedule_field($key);
            if (null !== $value) {
                $content = str_replace('{ulhgr_' . $key . '}', esc_html($value), $content);
            }
        }
        foreach (array('greeting', 'date', 'both', 'schedule') as $key) {
            $content = str_replace('{ulhgr_' . $key . '}', esc_html($this->plain_value($key)), $content);
        }
        return $content;
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
        if (!in_array($current_tab, array('overview', 'schedule', 'permissions', 'how-to'), true)) {
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
                <?php foreach (array('greeting' => __('Greeting', 'uplink-hours-greetings'), 'date' => __('Date', 'uplink-hours-greetings'), 'both' => __('Greeting and date', 'uplink-hours-greetings'), 'schedule' => __('Weekly schedule', 'uplink-hours-greetings')) as $display => $label) : ?>
                    <div class="ulhgr-example" data-display="<?php echo esc_attr($display); ?>"><h3><?php echo esc_html($label); ?></h3><div class="ulhgr-preview-value"><?php echo wp_kses_post($this->generate_greeting(array('display' => $display))); ?></div></div>
                <?php endforeach; ?>
            </div>
            <p><?php esc_html_e('Preview uses saved settings. Countdowns in blocks and shortcodes refresh on the page.', 'uplink-hours-greetings'); ?></p>
            </section>
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
        <div class="ulhgr-usage-grid">
            <section class="ulhgr-panel"><p class="ulhgr-overline"><?php esc_html_e('WORDPRESS', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Block editor', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Insert the Uplink Hours & Greetings block, then choose greeting, date, both, or weekly schedule in the block sidebar.', 'uplink-hours-greetings'); ?></p></section>
            <section class="ulhgr-panel"><p class="ulhgr-overline"><?php esc_html_e('BRICKS', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Query Loop and dynamic tags', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Enable Query Loop on a Div or Container and choose Uplink Weekly Schedule. Add child elements for each day, then style them in Bricks:', 'uplink-hours-greetings'); ?></p><p><code>{ulhgr_day}</code> <code>{ulhgr_hours}</code> <code>{ulhgr_state}</code> <code>{ulhgr_key}</code> <code>{ulhgr_today}</code></p><p><?php esc_html_e('For semantic markup, put the repeating Div inside a dl and use dt and dd for the day and hours children. A Shortcode element with [uplink_hours_greetings display="schedule"] gives ready-made markup. Inline text tags:', 'uplink-hours-greetings'); ?></p><p><code>{ulhgr_greeting}</code> <code>{ulhgr_date}</code> <code>{ulhgr_both}</code> <code>{ulhgr_schedule}</code></p><p><?php esc_html_e('Tags resolve when the page renders. Use a Shortcode element for a live countdown.', 'uplink-hours-greetings'); ?></p></section>
            <section class="ulhgr-panel ulhgr-etch-guide"><p class="ulhgr-overline"><?php esc_html_e('ETCH', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Options data', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('Use a complete output directly, or bind individual values to elements you build and style in Etch.', 'uplink-hours-greetings'); ?></p>
                <h3><?php esc_html_e('Ready-made outputs', 'uplink-hours-greetings'); ?></h3><p><code>{options.uplink_hours_greetings.greeting}</code> <code>{options.uplink_hours_greetings.date}</code> <code>{options.uplink_hours_greetings.both}</code> <code>{options.uplink_hours_greetings.schedule}</code></p>
                <h3><?php esc_html_e('Current and upcoming values', 'uplink-hours-greetings'); ?></h3><p><code>{options.uplink_hours_greetings.timezone}</code> <code>{options.uplink_hours_greetings.timezone_abbr}</code> <code>{options.uplink_hours_greetings.now.time}</code> <code>{options.uplink_hours_greetings.now.date}</code> <code>{options.uplink_hours_greetings.now.timestamp}</code></p><p><code>{options.uplink_hours_greetings.current.label}</code> <code>{options.uplink_hours_greetings.current.timing}</code> <code>{options.uplink_hours_greetings.current.start}</code> <code>{options.uplink_hours_greetings.current.event}</code> <code>{options.uplink_hours_greetings.current.message}</code> <code>{options.uplink_hours_greetings.current.output}</code></p><p><code>{options.uplink_hours_greetings.next.label}</code> <code>{options.uplink_hours_greetings.next.timing}</code> <code>{options.uplink_hours_greetings.next.time}</code> <code>{options.uplink_hours_greetings.next.countdown}</code> <code>{options.uplink_hours_greetings.next.event}</code> <code>{options.uplink_hours_greetings.next.message}</code> <code>{options.uplink_hours_greetings.next.start}</code> <code>{options.uplink_hours_greetings.next.timestamp}</code></p><p><code>{options.uplink_hours_greetings.opening.label}</code> <code>{options.uplink_hours_greetings.opening.timing}</code> <code>{options.uplink_hours_greetings.opening.time}</code> <code>{options.uplink_hours_greetings.opening.countdown}</code> <code>{options.uplink_hours_greetings.opening.event}</code> <code>{options.uplink_hours_greetings.opening.message}</code> <code>{options.uplink_hours_greetings.opening.start}</code> <code>{options.uplink_hours_greetings.opening.timestamp}</code></p>
                <h3><?php esc_html_e('Weekly schedule loop', 'uplink-hours-greetings'); ?></h3><p><?php esc_html_e('Add an Etch Loop block. Set Target to options.uplink_hours_greetings.week and Item ID to day. Inside it, build the row with native elements and these values:', 'uplink-hours-greetings'); ?></p><p><code>{day.day}</code> <code>{day.hours}</code> <code>{day.state}</code> <code>{day.key}</code> <code>{day.number}</code> <code>{day.is_today}</code></p><p><?php esc_html_e('Each day also includes windows. Add a nested Loop block with Target day.windows and Item ID window. Use window.start, window.start_label, window.end, window.end_label, window.end_type, and window.overnight. end_type is time, text, or none, so an untimed closing such as “Last Call” can be marked up as text. Individual day strings are available at options.uplink_hours_greetings.days.monday through sunday.', 'uplink-hours-greetings'); ?></p>
            </section>
            <section class="ulhgr-panel"><p class="ulhgr-overline"><?php esc_html_e('SHORTCODE', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Shortcode and PHP', 'uplink-hours-greetings'); ?></h2><p><code>[uplink_hours_greetings]</code> <code>[uplink_hours_greetings display="both"]</code> <code>[uplink_hours_greetings display="schedule"]</code></p><p><code>uplink_hours_greetings_echo( array( 'display' => 'schedule' ) );</code></p><p><?php esc_html_e('Use timezone, tz_abbr, date_format, and display parameters where supported.', 'uplink-hours-greetings'); ?></p></section>
            <section class="ulhgr-panel ulhgr-appearance-guide"><p class="ulhgr-overline"><?php esc_html_e('APPEARANCE', 'uplink-hours-greetings'); ?></p><h2><?php esc_html_e('Style the output', 'uplink-hours-greetings'); ?></h2><p><?php esc_html_e('The WordPress block inherits theme colors and typography. Its sidebar also provides color, spacing, and type controls. Bricks and Etch values are plain text, so style their elements in the builder.', 'uplink-hours-greetings'); ?></p><p><?php esc_html_e('These variables control the WordPress greeting and date defaults:', 'uplink-hours-greetings'); ?></p><pre class="ulhgr-css-example"><code>.wp-block-uplink-hours-greetings-hours-greetings {
    --ulhgr-greeting-font-weight: 600;
    --ulhgr-date-font-style: italic;
}</code></pre><p><?php esc_html_e('The ready-made schedule uses these classes:', 'uplink-hours-greetings'); ?></p><p><code>.ulhgr-schedule</code> <code>.ulhgr-schedule__day</code> <code>.ulhgr-schedule__name</code> <code>.ulhgr-schedule__hours</code> <code>.ulhgr-schedule__interval</code> <code>.ulhgr-schedule__status</code></p></section>
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
