<?php
/**
 * Plugin Name: Uplink Hours & Greetings
 * Description: Weekly business schedules, live countdowns, greetings, and dates for blocks, Bricks, Etch, and shortcodes.
 * Version: 1.0.0
 * Author: Stephen Walker
 * Author URI: https://flyingw.co
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: uplink-time-greeting
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('UPLINK_TIME_GREETING_PLUGIN_URL', plugin_dir_url(__FILE__));
define('UPLINK_TIME_GREETING_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('UPLINK_TIME_GREETING_PLUGIN_VERSION', '1.0.0');
define('UPLINK_TIME_GREETING_OPTION_NAME', 'utg_settings');
define('UPLINK_TIME_GREETING_PERMISSIONS_OPTION', 'utg_permissions');

/**
 * Main plugin class
 */
class Uplink_Time_Greeting_Plugin {
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
        add_filter('option_page_capability_tgb_settings_group', array($this, 'settings_capability'));
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
        add_action('admin_head-settings_page_time-greeting-settings', array($this, 'capture_admin_feedback'), 0);

        // Register shortcode
        add_shortcode('time_greeting', array($this, 'shortcode_handler'));

        // Plugin activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('Uplink_Time_Greeting_Plugin', 'uninstall'));

    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Pre-release builds saved a timezone without recording whether it was
        // chosen or copied on activation. Move those installs to the live site default.
        $stored_settings = get_option(UPLINK_TIME_GREETING_OPTION_NAME, false);
        if (is_array($stored_settings) && empty($stored_settings['timezone_wp_default_migrated'])) {
            $stored_settings['default_timezone'] = '';
            unset($stored_settings['timezone_default_migrated']);
            $stored_settings['timezone_wp_default_migrated'] = true;
            update_option(UPLINK_TIME_GREETING_OPTION_NAME, $stored_settings);
        }

        wp_register_script('tgb-editor', UPLINK_TIME_GREETING_PLUGIN_URL . 'assets/block-editor.js', array(
            'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render'
        ), UPLINK_TIME_GREETING_PLUGIN_VERSION, true);
        wp_set_script_translations('tgb-editor', 'uplink-time-greeting');
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
            'date_intro' => __('Today is', 'uplink-time-greeting'),
            'date_only_intro' => true,
            'default_timezone' => '',
            'default_tz_abbr' => '',
            'timezone_wp_default_migrated' => true,
            'plugin_version' => UPLINK_TIME_GREETING_PLUGIN_VERSION,
            'activation_date' => current_time('mysql')
        );

        // Only add options if they don't exist (prevents overwriting on reactivation)
        if (false === get_option(UPLINK_TIME_GREETING_OPTION_NAME, false)) {
            $legacy_options = get_option('tgb_settings', array());
            $initial_options = is_array($legacy_options) && !empty($legacy_options)
                ? wp_parse_args($legacy_options, $default_options)
                : $default_options;
            if (!empty($legacy_options) && is_array($legacy_options)) {
                $initial_options['profiles'][0]['intervals'] = $this->legacy_intervals($legacy_options);
            }
            $initial_options['plugin_version'] = UPLINK_TIME_GREETING_PLUGIN_VERSION;
            add_option(UPLINK_TIME_GREETING_OPTION_NAME, $initial_options);
        }

        // Update version if different
        $current_options = get_option(UPLINK_TIME_GREETING_OPTION_NAME, array());
        if (empty($current_options['profiles'])) {
            $current_options['profiles'] = $this->default_profiles();
            $current_options['profiles'][0]['intervals'] = !empty($current_options['intervals']) && is_array($current_options['intervals'])
                ? $this->normalize_intervals($current_options['intervals'])
                : $this->legacy_intervals($current_options);
            $current_options['week'] = $this->default_week();
            update_option(UPLINK_TIME_GREETING_OPTION_NAME, $current_options);
        }
        if (empty($current_options['plugin_version']) || $current_options['plugin_version'] !== UPLINK_TIME_GREETING_PLUGIN_VERSION) {
            $current_options['plugin_version'] = UPLINK_TIME_GREETING_PLUGIN_VERSION;
            $current_options['last_updated'] = current_time('mysql');
            update_option(UPLINK_TIME_GREETING_OPTION_NAME, $current_options);
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
        delete_option(UPLINK_TIME_GREETING_OPTION_NAME);
        delete_option(UPLINK_TIME_GREETING_PERMISSIONS_OPTION);
    }

    /**
     * Get plugin settings with defaults
     */
    private function get_settings() {
        $defaults = array(
            'profiles' => $this->default_profiles(),
            'week' => $this->default_week(),
            'date_intro' => __('Today is', 'uplink-time-greeting'),
            'date_only_intro' => true,
            'default_timezone' => '',
            'default_tz_abbr' => ''
        );

        $settings = get_option(UPLINK_TIME_GREETING_OPTION_NAME, $defaults);
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
            'name' => __('Every day', 'uplink-time-greeting'),
            'closed' => false,
            'intervals' => $this->default_intervals(),
        ));
    }

    private function default_week() {
        return array_fill(0, 7, 'every-day');
    }

    private function default_intervals() {
        return array(
            array('start' => '05:00', 'label' => __('Morning', 'uplink-time-greeting'), 'message' => __('Good morning!', 'uplink-time-greeting'), 'event' => ''),
            array('start' => '12:00', 'label' => __('Afternoon', 'uplink-time-greeting'), 'message' => __('Good afternoon!', 'uplink-time-greeting'), 'event' => ''),
            array('start' => '17:00', 'label' => __('Evening', 'uplink-time-greeting'), 'message' => __('Good evening!', 'uplink-time-greeting'), 'event' => ''),
            array('start' => '22:00', 'label' => __('Night', 'uplink-time-greeting'), 'message' => __("It's {time} {tz} and we're asleep.", 'uplink-time-greeting'), 'event' => ''),
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
        foreach (array_slice($intervals, 0, 24) as $interval) {
            if (!is_array($interval) || empty($interval['start'])) {
                continue;
            }
            $start = $this->normalize_start_time($interval['start']);
            $normalized[$start] = array(
                'start' => $start,
                'label' => isset($interval['label']) ? (string) $interval['label'] : '',
                'message' => isset($interval['message']) ? (string) $interval['message'] : '',
                'event' => isset($interval['event']) && in_array($interval['event'], array('opening', 'closing'), true) ? $interval['event'] : '',
            );
        }
        if (!$normalized) {
            return $this->default_intervals();
        }
        ksort($normalized, SORT_STRING);
        return array_values($normalized);
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
        ), $atts, 'time_greeting');

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
                    '' === $date_intro ? '' : '.'
                );
                if ('date' === $display) {
                    $output = $date_output;
                } else {
                    $output .= $date_output;
                }
            } catch (Exception $e) {
                // If date formatting fails, skip the date part
                if ($display === 'date') {
                    $output = '<span class="time-greeting-date">' . esc_html__('Date unavailable', 'uplink-time-greeting') . '</span>';
                }
            }
        }

        $next_change = 'date' === $display
            ? DateTimeImmutable::createFromMutable($datetime)->modify('tomorrow')->setTime(0, 0)->getTimestamp()
            : $this->next_interval($datetime, $settings)['timestamp'];
        return sprintf(
            '<span class="utg-output" data-utg-display="%s" data-utg-date-format="%s" data-utg-timezone="%s" data-utg-tz-abbr="%s" data-utg-transition="%d">%s</span>',
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
            '{next_label}' => esc_html($next['interval']['label']),
            '{next_time}' => esc_html(wp_date($time_format, $next['timestamp'], $datetime->getTimezone())),
            '{countdown}' => $this->countdown_markup($next['timestamp'], $datetime->getTimestamp()),
            '{opening_countdown}' => $opening ? $this->countdown_markup($opening['timestamp'], $datetime->getTimestamp()) : esc_html__('No opening scheduled', 'uplink-time-greeting'),
            '{opening_time}' => $opening ? esc_html(wp_date($time_format, $opening['timestamp'], $datetime->getTimezone())) : esc_html__('Not scheduled', 'uplink-time-greeting'),
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
                list($hour, $minute) = array_map('intval', explode(':', $interval['start']));
                $timestamp = $date->setTime($hour, $minute)->getTimestamp();
                if ($timestamp <= $now->getTimestamp() && (null === $latest || $timestamp > $latest['timestamp'])) {
                    $latest = array('interval' => $interval, 'timestamp' => $timestamp);
                }
            }
        }
        return $latest ? $latest['interval'] : $settings['profiles'][0]['intervals'][0];
    }

    private function next_interval($datetime, $settings, $event = '') {
        $now = DateTimeImmutable::createFromMutable($datetime);
        $midnight = $now->setTime(0, 0);
        $next = null;
        for ($offset = 0; $offset <= 7; $offset++) {
            $date = $midnight->modify('+' . $offset . ' days');
            $profile = $this->profile_for_day($settings, (int) $date->format('w'));
            foreach ($profile['intervals'] as $interval) {
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
            '<span class="utg-countdown" data-utg-target="%1$d">%2$s</span>',
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
            $parts[] = sprintf(_n('%d day', '%d days', $days, 'uplink-time-greeting'), $days);
        }
        if ($hours) {
            /* translators: %d: number of hours until the next scheduled event. */
            $parts[] = sprintf(_n('%d hour', '%d hours', $hours, 'uplink-time-greeting'), $hours);
        }
        if ($remainder && !$days) {
            /* translators: %d: number of minutes until the next scheduled event. */
            $parts[] = sprintf(_n('%d minute', '%d minutes', $remainder, 'uplink-time-greeting'), $remainder);
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
                            $closing = $later['start'];
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
                            if ('closing' === $later['event']) {
                                $closing = $later['start'];
                                break;
                            }
                        }
                    }
                    $from = $sunday->modify('+' . $day . ' days')->setTime((int) substr($interval['start'], 0, 2), (int) substr($interval['start'], 3, 2));
                    $from_label = wp_date($time_format, $from->getTimestamp(), wp_timezone());
                    $window = array('start' => $interval['start'], 'start_label' => $from_label, 'end' => '', 'end_label' => '', 'overnight' => false);
                    if (null === $closing) {
                        /* translators: %s: opening time without a matching closing time. */
                        $hours[] = sprintf(__('From %s', 'uplink-time-greeting'), $from_label);
                    } else {
                        $to = $sunday->modify('+' . ($closing_day === $day ? $day : $day + 1) . ' days')->setTime((int) substr($closing, 0, 2), (int) substr($closing, 3, 2));
                        $window['end'] = $closing;
                        $window['end_label'] = wp_date($time_format, $to->getTimestamp(), wp_timezone());
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
                'hours' => !empty($profile['closed']) ? __('Closed', 'uplink-time-greeting') : ($hours ? implode(', ', $hours) : __('Hours not set', 'uplink-time-greeting')),
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
        $html = '<dl class="utg-schedule" aria-label="' . esc_attr__('Weekly business hours', 'uplink-time-greeting') . '">';
        foreach ($this->schedule_rows() as $row) {
            $html .= '<div class="utg-schedule__day utg-schedule__day--' . esc_attr($row['state']) . ($row['number'] === $today ? ' utg-schedule__day--today' : '') . '" data-day="' . esc_attr($row['key']) . '" data-state="' . esc_attr($row['state']) . '">';
            $html .= '<dt class="utg-schedule__name">' . esc_html($row['day']) . '</dt><dd class="utg-schedule__hours">';
            if (!$row['windows']) {
                $html .= '<span class="utg-schedule__status">' . esc_html($row['hours']) . '</span>';
            } else {
                foreach ($row['windows'] as $index => $window) {
                    if ($index) {
                        $html .= '<span class="utg-schedule__between">, </span>';
                    }
                    $html .= '<span class="utg-schedule__interval"' . ($window['overnight'] ? ' data-overnight="true"' : '') . '>';
                    if ('' === $window['end']) {
                        $html .= '<span class="utg-schedule__from">' . esc_html__('From', 'uplink-time-greeting') . ' </span>';
                    }
                    $html .= '<time class="utg-schedule__opens" datetime="' . esc_attr($window['start']) . '">' . esc_html($window['start_label']) . '</time>';
                    if ('' !== $window['end']) {
                        $html .= '<span class="utg-schedule__separator" aria-hidden="true">–</span><span class="utg-visually-hidden">' . esc_html__(' to ', 'uplink-time-greeting') . '</span>';
                        $html .= '<time class="utg-schedule__closes" datetime="' . esc_attr($window['end']) . '">' . esc_html($window['end_label']) . '</time>';
                        if ($window['overnight']) {
                            $html .= '<span class="utg-visually-hidden">' . esc_html__(' next day', 'uplink-time-greeting') . '</span>';
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
        $data['time_greeting'] = array(
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
                'start' => $current['start'],
                'event' => $current['event'],
                'message' => $current['message'],
                'output' => $this->plain_value('greeting'),
            ),
            'next' => $next ? array(
                'label' => $next['interval']['label'],
                'time' => wp_date($time_format, $next['timestamp'], $timezone),
                'countdown' => $this->format_duration($next['timestamp'] - $now->getTimestamp()),
                'event' => $next['interval']['event'],
                'message' => $next['interval']['message'],
                'start' => $next['interval']['start'],
                'timestamp' => $next['timestamp'],
            ) : array(),
            'opening' => $opening ? array(
                'label' => $opening['interval']['label'],
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
            'greeting' => __('Greeting', 'uplink-time-greeting'),
            'date' => __('Date', 'uplink-time-greeting'),
            'both' => __('Greeting and date', 'uplink-time-greeting'),
            'schedule' => __('Weekly schedule', 'uplink-time-greeting'),
        ) as $key => $label) {
            $tags[] = array(
                'name' => '{tgb_' . $key . '}',
                'label' => $label,
                'group' => __('Uplink Hours & Greetings', 'uplink-time-greeting'),
            );
        }
        foreach (array(
            'day' => __('Schedule day', 'uplink-time-greeting'),
            'hours' => __('Schedule hours', 'uplink-time-greeting'),
            'state' => __('Schedule state', 'uplink-time-greeting'),
            'key' => __('Schedule day key', 'uplink-time-greeting'),
            'today' => __('Schedule is today (1 or 0)', 'uplink-time-greeting'),
        ) as $key => $label) {
            $tags[] = array('name' => '{utg_' . $key . '}', 'label' => $label, 'group' => __('Uplink Hours & Greetings', 'uplink-time-greeting'));
        }
        return $tags;
    }

    /** Expose the ordered seven-day schedule as a native Bricks Query Loop source. */
    public function bricks_query_options($options) {
        $options['queryTypes']['utg_schedule'] = __('Uplink Weekly Schedule', 'uplink-time-greeting');
        return $options;
    }

    public function bricks_schedule_query($results, $query) {
        if (!is_object($query) || !isset($query->object_type) || 'utg_schedule' !== $query->object_type) {
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
        if (!class_exists('\\Bricks\\Query') || 'utg_schedule' !== \Bricks\Query::get_query_object_type()) {
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
        if (in_array($key, array('utg_day', 'utg_hours', 'utg_state', 'utg_key', 'utg_today'), true)) {
            $value = $this->bricks_schedule_field(substr($key, 4));
            return null === $value ? $tag : esc_html($value);
        }
        if (!in_array($key, array('tgb_greeting', 'tgb_date', 'tgb_both', 'tgb_schedule'), true)) {
            return $tag;
        }
        return esc_html($this->plain_value(substr($key, 4)));
    }

    public function bricks_render_content($content, $post = null, $context = 'text') {
        if (!is_string($content) || (false === strpos($content, '{tgb_') && false === strpos($content, '{utg_'))) {
            return $content;
        }
        foreach (array('day', 'hours', 'state', 'key', 'today') as $key) {
            $value = $this->bricks_schedule_field($key);
            if (null !== $value) {
                $content = str_replace('{utg_' . $key . '}', esc_html($value), $content);
            }
        }
        foreach (array('greeting', 'date', 'both', 'schedule') as $key) {
            $content = str_replace('{tgb_' . $key . '}', esc_html($this->plain_value($key)), $content);
        }
        return $content;
    }

    public function admin_assets($hook) {
        if ('settings_page_time-greeting-settings' === $hook) {
            wp_enqueue_style('tgb-admin', UPLINK_TIME_GREETING_PLUGIN_URL . 'assets/admin.css', array(), UPLINK_TIME_GREETING_PLUGIN_VERSION);
            wp_enqueue_script('tgb-admin', UPLINK_TIME_GREETING_PLUGIN_URL . 'assets/admin.js', array(), UPLINK_TIME_GREETING_PLUGIN_VERSION, true);
            wp_localize_script('tgb-admin', 'utgAdmin', array(
                'newSchedule' => __('New schedule', 'uplink-time-greeting'),
                /* translators: %s: weekday name for a copied schedule. */
                'daySchedule' => __('%s schedule', 'uplink-time-greeting'),
                'restUrl' => esc_url_raw(rest_url('uplink-time-greeting/v1/render')),
                'nonce' => wp_create_nonce('wp_rest'),
                'previewError' => __('Preview unavailable. Try again.', 'uplink-time-greeting'),
            ));
            $this->enqueue_live_assets();
        }
    }

    public function frontend_assets() {
        $this->enqueue_live_assets();
    }

    private function enqueue_live_assets() {
        wp_enqueue_style('utg-output', UPLINK_TIME_GREETING_PLUGIN_URL . 'assets/block-style.css', array(), UPLINK_TIME_GREETING_PLUGIN_VERSION);
        wp_enqueue_script('utg-live', UPLINK_TIME_GREETING_PLUGIN_URL . 'assets/live.js', array('wp-i18n'), UPLINK_TIME_GREETING_PLUGIN_VERSION, true);
        wp_set_script_translations('utg-live', 'uplink-time-greeting');
        wp_localize_script('utg-live', 'utgLive', array(
            'restUrl' => esc_url_raw(rest_url('uplink-time-greeting/v1/render')),
        ));
    }

    public function register_rest_routes() {
        register_rest_route('uplink-time-greeting/v1', '/render', array(
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
        if (current_user_can('utg_manage_settings') && $request->get_param('at')) {
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
            if (UPLINK_TIME_GREETING_OPTION_NAME === $feedback['setting'] || UPLINK_TIME_GREETING_PERMISSIONS_OPTION === $feedback['setting'] ||
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
            __('Uplink Hours & Greetings Settings', 'uplink-time-greeting'),
            __('Uplink Hours & Greetings', 'uplink-time-greeting'),
            'utg_manage_settings',
            'time-greeting-settings',
            array($this, 'admin_page')
        );
    }

    /**
     * Initialize admin settings
     */
    public function admin_init() {
        register_setting(
            'tgb_settings_group',
            UPLINK_TIME_GREETING_OPTION_NAME,
            array($this, 'sanitize_settings')
        );
        register_setting(
            'tgb_permissions_group',
            UPLINK_TIME_GREETING_PERMISSIONS_OPTION,
            array($this, 'sanitize_permissions')
        );
    }

    public function settings_capability() {
        return 'utg_manage_settings';
    }

    /** Administrators and the selected roles or users may edit plugin settings. */
    public function map_settings_capability($caps, $cap, $user_id, $args) {
        if ('utg_manage_settings' !== $cap) {
            return $caps;
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return array('do_not_allow');
        }
        if (user_can($user, 'manage_options')) {
            return array('exist');
        }
        $permissions = get_option(UPLINK_TIME_GREETING_PERMISSIONS_OPTION, array());
        $roles = is_array($permissions) ? ($permissions['roles'] ?? array()) : array();
        $users = is_array($permissions) ? ($permissions['users'] ?? array()) : array();
        if (in_array((int) $user_id, array_map('intval', (array) $users), true) || array_intersect((array) $user->roles, (array) $roles)) {
            return array('exist');
        }
        return array('do_not_allow');
    }

    public function sanitize_permissions($input) {
        if (!current_user_can('manage_options')) {
            return get_option(UPLINK_TIME_GREETING_PERMISSIONS_OPTION, array());
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
        $current_settings = get_option(UPLINK_TIME_GREETING_OPTION_NAME, array());
        $defaults = $this->get_settings();

        // Preserve existing data that shouldn't be overwritten
        $sanitized['plugin_version'] = UPLINK_TIME_GREETING_PLUGIN_VERSION;
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
            foreach (array_slice($rows, 0, 24) as $row) {
                if (!is_array($row)) {
                    $invalid_schedule = true;
                    continue;
                }
                $start = isset($row['start']) && is_scalar($row['start']) ? sanitize_text_field(wp_unslash($row['start'])) : '';
                if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $start) || isset($intervals[$start])) {
                    $invalid_schedule = true;
                    continue;
                }
                $label = isset($row['label']) && is_scalar($row['label']) ? sanitize_text_field(wp_unslash($row['label'])) : '';
                $message = isset($row['message']) && is_scalar($row['message']) ? sanitize_textarea_field(wp_unslash($row['message'])) : '';
                $event = isset($row['event']) && is_scalar($row['event']) ? sanitize_key(wp_unslash($row['event'])) : '';
                $intervals[$start] = array(
                    'start' => $start,
                    'label' => substr($label, 0, 80),
                    'message' => $message,
                    'event' => in_array($event, array('opening', 'closing'), true) ? $event : '',
                );
            }
            ksort($intervals, SORT_STRING);
            $profiles[$id] = array('id' => $id, 'name' => substr($name, 0, 80), 'closed' => !empty($raw_profile['closed']), 'intervals' => array_values($intervals));
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
            add_settings_error(UPLINK_TIME_GREETING_OPTION_NAME, 'profiles', __('Use a named schedule for every day. Each schedule needs 1–24 entries with unique start times. The previous week was kept.', 'uplink-time-greeting'));
            $sanitized['profiles'] = $defaults['profiles'];
            $sanitized['week'] = $defaults['week'];
        } else {
            $sanitized['profiles'] = array_values($profiles);
            $sanitized['week'] = $week;
        }
        $sanitized['default_timezone'] = sanitize_text_field(wp_unslash($input['default_timezone'] ?? ''));
        $sanitized['default_tz_abbr'] = substr(sanitize_text_field(wp_unslash($input['default_tz_abbr'] ?? '')), 0, 20);
        $sanitized['date_intro'] = sanitize_text_field(wp_unslash($input['date_intro'] ?? $defaults['date_intro']));
        $sanitized['date_only_intro'] = !empty($input['date_only_intro']);

        // An empty value follows the WordPress timezone setting.
        if ('' !== $sanitized['default_timezone']) {
            try {
                new DateTimeZone($sanitized['default_timezone']);
            } catch (Exception $error) {
                add_settings_error(UPLINK_TIME_GREETING_OPTION_NAME, 'default_timezone',
                    __('Invalid timezone identifier.', 'uplink-time-greeting'));
                $sanitized['default_timezone'] = $current_settings['default_timezone'] ?? '';
            }
        }

        return $sanitized;
    }

    public function text_field_callback($args) {
        $settings = $this->get_settings();
        $value = $settings[$args['field']];

        printf(
            '<input type="text" id="%1$s" name="%3$s[%1$s]" value="%2$s" class="regular-text" />',
            esc_attr($args['field']),
            esc_attr($value),
            esc_attr(UPLINK_TIME_GREETING_OPTION_NAME)
        );
    }

    public function timezone_field_callback($args) {
        $settings = $this->get_settings();
        $current_value = $settings[$args['field']];
        $timezones = timezone_identifiers_list();

        printf('<select id="%1$s" name="%2$s[%1$s]">', esc_attr($args['field']), esc_attr(UPLINK_TIME_GREETING_OPTION_NAME));

        printf(
            '<option value=""%1$s>%2$s</option>',
            selected($current_value, '', false),
            /* translators: %s: timezone selected in WordPress Settings > General. */
            esc_html(sprintf(__('WordPress site timezone (%s)', 'uplink-time-greeting'), wp_timezone_string()))
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
        if (!current_user_can('utg_manage_settings')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'uplink-time-greeting'));
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
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'uplink-time-greeting'));
        }

        ?>
        <div class="wrap tgb-admin" data-view="<?php echo esc_attr($current_tab); ?>">
            <div class="tgb-admin-header">
                <div class="tgb-header-icon" aria-hidden="true"><img src="<?php echo esc_url(UPLINK_TIME_GREETING_PLUGIN_URL . 'assets/uplink-mark.svg'); ?>" alt=""></div>
                <div><p class="tgb-eyebrow"><?php esc_html_e('UPLINK · SETTINGS', 'uplink-time-greeting'); ?></p><h1><?php esc_html_e('Hours & Greetings', 'uplink-time-greeting'); ?></h1><p><?php esc_html_e('Set what visitors see throughout your week.', 'uplink-time-greeting'); ?></p></div>
                <span class="tgb-version"><?php echo esc_html('v' . UPLINK_TIME_GREETING_PLUGIN_VERSION); ?></span>
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
                <div class="tgb-toast <?php echo $has_error ? 'tgb-toast-error' : 'tgb-toast-success'; ?>" role="<?php echo $has_error ? 'alert' : 'status'; ?>" aria-live="<?php echo $has_error ? 'assertive' : 'polite'; ?>">
                    <span class="dashicons <?php echo $has_error ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span>
                    <div class="tgb-toast-messages">
                        <?php foreach ($this->admin_feedback as $feedback) : ?>
                            <p><?php echo esc_html(wp_strip_all_tags($feedback['message'])); ?></p>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="tgb-toast-close" aria-label="<?php esc_attr_e('Dismiss notification', 'uplink-time-greeting'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <nav class="tgb-tabs" role="tablist" aria-orientation="horizontal" aria-label="<?php esc_attr_e('Uplink Hours & Greetings sections', 'uplink-time-greeting'); ?>">
                <?php
                $tabs = array(
                    'overview' => __('Overview', 'uplink-time-greeting'),
                    'schedule' => __('Weekly schedule', 'uplink-time-greeting'),
                );
                if (current_user_can('manage_options')) {
                    $tabs['permissions'] = __('Permissions', 'uplink-time-greeting');
                }
                $tabs['how-to'] = __('How to Use', 'uplink-time-greeting');
                foreach ($tabs as $tab_key => $tab_label) {
                    $tab_url = add_query_arg(array('tab' => $tab_key), admin_url('options-general.php?page=time-greeting-settings'));
                    printf(
                        '<a id="utg-tab-%1$s" href="%2$s" class="%3$s" role="tab" aria-controls="utg-panel-%1$s" aria-selected="%4$s" tabindex="%5$s" data-utg-tab="%1$s">%6$s</a>',
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

            <div class="tgb-tab-content">
                <?php $this->render_settings_tabs($current_tab); ?>
                <?php if (current_user_can('manage_options')) : ?>
                    <section id="utg-panel-permissions" class="tgb-tab-panel" role="tabpanel" aria-labelledby="utg-tab-permissions" data-utg-panel="permissions"<?php echo 'permissions' === $current_tab ? '' : ' hidden'; ?>><?php $this->render_permissions_tab(); ?></section>
                <?php endif; ?>
                <section id="utg-panel-how-to" class="tgb-tab-panel" role="tabpanel" aria-labelledby="utg-tab-how-to" data-utg-panel="how-to"<?php echo 'how-to' === $current_tab ? '' : ' hidden'; ?>><?php $this->render_how_to_tab(); ?></section>
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
            0 => __('Sunday', 'uplink-time-greeting'),
            1 => __('Monday', 'uplink-time-greeting'),
            2 => __('Tuesday', 'uplink-time-greeting'),
            3 => __('Wednesday', 'uplink-time-greeting'),
            4 => __('Thursday', 'uplink-time-greeting'),
            5 => __('Friday', 'uplink-time-greeting'),
            6 => __('Saturday', 'uplink-time-greeting'),
        );
        ?>
        <form method="post" action="options.php" class="tgb-settings-form">
            <?php settings_fields('tgb_settings_group'); ?>
            <section id="utg-panel-schedule" class="tgb-tab-panel" role="tabpanel" aria-labelledby="utg-tab-schedule" data-utg-panel="schedule"<?php echo 'schedule' === $current_tab ? '' : ' hidden'; ?>>
            <section class="tgb-panel utg-schedule-panel">
                <div class="tgb-panel-heading">
                    <div><p class="tgb-overline"><?php esc_html_e('SEVEN-DAY SCHEDULE', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Assign a schedule to each day', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Reuse one schedule across several days. Copy it when one day needs different hours or messages.', 'uplink-time-greeting'); ?></p></div>
                </div>
                <div class="utg-week-grid">
                    <?php $week_start = (int) get_option('start_of_week', 0); ?>
                    <?php for ($offset = 0; $offset < 7; $offset++) : $day = ($week_start + $offset) % 7; ?>
                    <details class="utg-day-row" data-day="<?php echo esc_attr($day); ?>">
                        <summary><strong><?php echo esc_html($days[$day]); ?></strong><span class="utg-day-summary"><?php echo esc_html($settings['profiles'][array_search($settings['week'][$day], array_column($settings['profiles'], 'id'), true)]['name'] ?? ''); ?></span></summary>
                        <div class="utg-day-controls"><label for="utg-day-<?php echo esc_attr($day); ?>"><?php esc_html_e('Schedule', 'uplink-time-greeting'); ?></label>
                        <select id="utg-day-<?php echo esc_attr($day); ?>" name="<?php echo esc_attr(UPLINK_TIME_GREETING_OPTION_NAME . '[week][' . $day . ']'); ?>" class="utg-day-profile">
                            <?php foreach ($settings['profiles'] as $profile) : ?>
                            <option value="<?php echo esc_attr($profile['id']); ?>" <?php selected($settings['week'][$day], $profile['id']); ?>><?php echo esc_html($profile['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button utg-customize-day" data-day-name="<?php echo esc_attr($days[$day]); ?>"><?php esc_html_e('Customize this day', 'uplink-time-greeting'); ?></button>
                        </div>
                    </details>
                    <?php endfor; ?>
                </div>
            </section>
            <section class="tgb-panel utg-schedule-panel">
                <div class="tgb-panel-heading"><div><p class="tgb-overline"><?php esc_html_e('REUSABLE DAILY SCHEDULES', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Hours and messages', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Each entry begins at its start time and continues until the next entry, even across midnight. Mark an opening to count down to it from another day.', 'uplink-time-greeting'); ?></p></div></div>
                <div class="utg-token-guide" role="note" aria-labelledby="utg-token-guide-title">
                    <div class="utg-token-guide-heading"><span class="dashicons dashicons-editor-code" aria-hidden="true"></span><div><h3 id="utg-token-guide-title"><?php esc_html_e('Write dynamic messages', 'uplink-time-greeting'); ?></h3><p><?php esc_html_e('Place these tokens in a message. They show the configured local time and upcoming schedule events.', 'uplink-time-greeting'); ?></p></div></div>
                    <ul class="utg-token-list">
                        <li><code>{time}</code><span><?php esc_html_e('Current time', 'uplink-time-greeting'); ?></span></li>
                        <li><code>{tz}</code><span><?php esc_html_e('Timezone', 'uplink-time-greeting'); ?></span></li>
                        <li><code>{countdown}</code><span><?php esc_html_e('Until next entry', 'uplink-time-greeting'); ?></span></li>
                        <li><code>{next_label}</code><span><?php esc_html_e('Next entry label', 'uplink-time-greeting'); ?></span></li>
                        <li><code>{next_time}</code><span><?php esc_html_e('Next entry time', 'uplink-time-greeting'); ?></span></li>
                        <li><code>{opening_countdown}</code><span><?php esc_html_e('Until next opening', 'uplink-time-greeting'); ?></span></li>
                        <li><code>{opening_time}</code><span><?php esc_html_e('Next opening time', 'uplink-time-greeting'); ?></span></li>
                    </ul>
                    <p class="utg-token-example"><strong><?php esc_html_e('Example', 'uplink-time-greeting'); ?></strong> <span><?php esc_html_e('It’s {time}. We open in {opening_countdown}.', 'uplink-time-greeting'); ?></span></p>
                </div>
                <div class="utg-profiles">
                    <?php foreach ($settings['profiles'] as $profile_index => $profile) : ?>
                    <div class="utg-profile" data-profile-id="<?php echo esc_attr($profile['id']); ?>">
                        <div class="utg-profile-heading">
                            <div class="utg-profile-name"><label><?php esc_html_e('Schedule name', 'uplink-time-greeting'); ?><input type="text" class="utg-profile-name-input" name="<?php echo esc_attr(UPLINK_TIME_GREETING_OPTION_NAME . '[profiles][' . $profile_index . '][name]'); ?>" value="<?php echo esc_attr($profile['name']); ?>" maxlength="80" required></label><input type="hidden" class="utg-profile-id" name="<?php echo esc_attr(UPLINK_TIME_GREETING_OPTION_NAME . '[profiles][' . $profile_index . '][id]'); ?>" value="<?php echo esc_attr($profile['id']); ?>"></div>
                            <button type="button" class="button utg-remove-profile"><?php esc_html_e('Remove schedule', 'uplink-time-greeting'); ?></button>
                        </div>
                        <label class="tgb-checkbox-field utg-closed-field"><input type="hidden" name="<?php echo esc_attr(UPLINK_TIME_GREETING_OPTION_NAME . '[profiles][' . $profile_index . '][closed]'); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr(UPLINK_TIME_GREETING_OPTION_NAME . '[profiles][' . $profile_index . '][closed]'); ?>" value="1" <?php checked(!empty($profile['closed'])); ?>><?php esc_html_e('Closed all day', 'uplink-time-greeting'); ?></label>
                        <div class="utg-intervals">
                            <?php foreach ($profile['intervals'] as $row_index => $interval) : ?>
                                <?php $this->render_interval_row($profile_index, $row_index, $interval); ?>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button utg-add-interval"><?php esc_html_e('Add time entry', 'uplink-time-greeting'); ?></button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button utg-add-profile"><?php esc_html_e('Add reusable schedule', 'uplink-time-greeting'); ?></button>
            </section>
            <div class="tgb-save-row"><?php submit_button(__('Save schedule', 'uplink-time-greeting'), 'primary', 'submit', false); ?></div>
            </section>
            <section id="utg-panel-overview" class="tgb-tab-panel" role="tabpanel" aria-labelledby="utg-tab-overview" data-utg-panel="overview"<?php echo 'overview' === $current_tab ? '' : ' hidden'; ?>>
            <section class="tgb-panel utg-overview-panel">
                <div class="tgb-panel-heading"><div><p class="tgb-overline"><?php esc_html_e('LOCAL TIME', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Timezone', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Uses the WordPress site timezone by default. Choose another timezone only when needed.', 'uplink-time-greeting'); ?></p></div></div>
                <div class="tgb-default-fields">
                    <div class="tgb-field"><label for="default_timezone"><?php esc_html_e('Timezone', 'uplink-time-greeting'); ?></label><?php $this->timezone_field_callback(array('field' => 'default_timezone')); ?></div>
                    <div class="tgb-field"><label for="default_tz_abbr"><?php esc_html_e('Timezone label (optional)', 'uplink-time-greeting'); ?></label><?php $this->text_field_callback(array('field' => 'default_tz_abbr')); ?><p><?php esc_html_e('Leave blank to use the timezone’s current abbreviation.', 'uplink-time-greeting'); ?></p></div>
                </div>
            </section>
            <section class="tgb-panel utg-overview-panel">
                <div class="tgb-panel-heading"><div><p class="tgb-overline"><?php esc_html_e('DATE WORDING', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Date introduction', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Set the words that appear before the date in the combined output and, optionally, Date only.', 'uplink-time-greeting'); ?></p></div></div>
                <div class="tgb-field tgb-date-intro-field"><label for="date_intro"><?php esc_html_e('Introduction', 'uplink-time-greeting'); ?></label><?php $this->text_field_callback(array('field' => 'date_intro')); ?><p><?php esc_html_e('Translate or rewrite “Today is” for your audience. Leave blank to show only the date.', 'uplink-time-greeting'); ?></p></div>
                <label class="tgb-checkbox-field" for="date_only_intro"><input type="hidden" name="<?php echo esc_attr(UPLINK_TIME_GREETING_OPTION_NAME . '[date_only_intro]'); ?>" value="0"><input type="checkbox" id="date_only_intro" name="<?php echo esc_attr(UPLINK_TIME_GREETING_OPTION_NAME . '[date_only_intro]'); ?>" value="1" <?php checked(!empty($settings['date_only_intro'])); ?>><?php esc_html_e('Show the introduction with Date only', 'uplink-time-greeting'); ?></label>
            </section>
            <div class="tgb-save-row"><?php submit_button(__('Save settings', 'uplink-time-greeting'), 'primary', 'submit', false); ?></div>
            <section class="tgb-panel tgb-preview utg-overview-panel">
            <div class="tgb-panel-heading"><div><p class="tgb-overline"><?php esc_html_e('OUTPUT EXAMPLES', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('What visitors see now', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('These examples use the same saved schedule and show each display option.', 'uplink-time-greeting'); ?></p></div></div>
            <div class="utg-preview-controls">
                <label><?php esc_html_e('Preview date', 'uplink-time-greeting'); ?><input type="date" class="utg-preview-date" value="<?php echo esc_attr($preview_now->format('Y-m-d')); ?>"></label>
                <label><?php esc_html_e('Time', 'uplink-time-greeting'); ?><input type="time" class="utg-preview-time" value="<?php echo esc_attr($preview_now->format('H:i')); ?>"></label>
                <button type="button" class="button utg-preview-button"><?php esc_html_e('Update preview', 'uplink-time-greeting'); ?></button>
                <span class="utg-preview-status" role="status" aria-live="polite"></span>
            </div>
            <div class="tgb-example-grid">
                <?php foreach (array('greeting' => __('Greeting', 'uplink-time-greeting'), 'date' => __('Date', 'uplink-time-greeting'), 'both' => __('Greeting and date', 'uplink-time-greeting'), 'schedule' => __('Weekly schedule', 'uplink-time-greeting')) as $display => $label) : ?>
                    <div class="tgb-example" data-display="<?php echo esc_attr($display); ?>"><h3><?php echo esc_html($label); ?></h3><div class="tgb-preview-value"><?php echo wp_kses_post($this->generate_greeting(array('display' => $display))); ?></div></div>
                <?php endforeach; ?>
            </div>
            <p><?php esc_html_e('Preview uses saved settings. Countdowns in blocks and shortcodes refresh on the page.', 'uplink-time-greeting'); ?></p>
            </section>
            </section>
        </form>
        <?php
    }

    private function render_interval_row($profile_index, $row_index, $interval) {
        $name = UPLINK_TIME_GREETING_OPTION_NAME . '[profiles][' . $profile_index . '][intervals][' . $row_index . ']';
        ?>
        <div class="utg-interval-row">
            <label><?php esc_html_e('Starts at', 'uplink-time-greeting'); ?><input type="time" step="60" name="<?php echo esc_attr($name . '[start]'); ?>" value="<?php echo esc_attr($interval['start']); ?>" required></label>
            <label><?php esc_html_e('Label', 'uplink-time-greeting'); ?><input type="text" name="<?php echo esc_attr($name . '[label]'); ?>" value="<?php echo esc_attr($interval['label']); ?>" maxlength="80" placeholder="<?php esc_attr_e('Optional', 'uplink-time-greeting'); ?>"></label>
            <label><?php esc_html_e('Event', 'uplink-time-greeting'); ?><select name="<?php echo esc_attr($name . '[event]'); ?>"><option value="" <?php selected($interval['event'], ''); ?>><?php esc_html_e('None', 'uplink-time-greeting'); ?></option><option value="opening" <?php selected($interval['event'], 'opening'); ?>><?php esc_html_e('Opening', 'uplink-time-greeting'); ?></option><option value="closing" <?php selected($interval['event'], 'closing'); ?>><?php esc_html_e('Closing', 'uplink-time-greeting'); ?></option></select></label>
            <label class="utg-message-field"><?php esc_html_e('Message', 'uplink-time-greeting'); ?><textarea name="<?php echo esc_attr($name . '[message]'); ?>" rows="2"><?php echo esc_textarea($interval['message']); ?></textarea></label>
            <button type="button" class="button utg-remove-interval" aria-label="<?php esc_attr_e('Remove time entry', 'uplink-time-greeting'); ?>" title="<?php esc_attr_e('Remove time entry', 'uplink-time-greeting'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v6m4-6v6"/></svg></button>
        </div>
        <?php
    }

    /** Administrator-only access controls for changing plugin settings. */
    private function render_permissions_tab() {
        $permissions = get_option(UPLINK_TIME_GREETING_PERMISSIONS_OPTION, array());
        $selected_roles = is_array($permissions) ? ($permissions['roles'] ?? array()) : array();
        $selected_users = is_array($permissions) ? ($permissions['users'] ?? array()) : array();
        $users = get_users(array('orderby' => 'display_name', 'order' => 'ASC'));
        ?>
        <form method="post" action="options.php" class="utg-permissions-form">
            <?php settings_fields('tgb_permissions_group'); ?>
            <input type="hidden" name="<?php echo esc_attr(UPLINK_TIME_GREETING_PERMISSIONS_OPTION); ?>[_submitted]" value="1">
            <section class="tgb-panel">
                <div class="tgb-panel-heading"><div><p class="tgb-overline"><?php esc_html_e('ACCESS CONTROL', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Who can update settings', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Administrators always have access. You can also allow entire roles or specific users to edit the schedule and other plugin settings.', 'uplink-time-greeting'); ?></p></div></div>
                <div class="utg-permission-grid">
                    <fieldset class="utg-permission-group">
                        <legend><?php esc_html_e('Roles', 'uplink-time-greeting'); ?></legend>
                        <p><?php esc_html_e('Everyone with a selected role can update plugin settings.', 'uplink-time-greeting'); ?></p>
                        <div class="utg-permission-list">
                            <div class="utg-permission-choice utg-permission-fixed"><span><?php esc_html_e('Administrator', 'uplink-time-greeting'); ?></span><span><?php esc_html_e('Always allowed', 'uplink-time-greeting'); ?></span></div>
                            <?php foreach (wp_roles()->roles as $role_key => $role) : if ('administrator' === $role_key) { continue; } ?>
                                <label class="utg-permission-choice"><input type="checkbox" name="<?php echo esc_attr(UPLINK_TIME_GREETING_PERMISSIONS_OPTION); ?>[roles][]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, (array) $selected_roles, true)); ?>><span><?php echo esc_html(translate_user_role($role['name'])); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <fieldset class="utg-permission-group">
                        <legend><?php esc_html_e('Individual users', 'uplink-time-greeting'); ?></legend>
                        <p><?php esc_html_e('Grant access to a user without changing their role.', 'uplink-time-greeting'); ?></p>
                        <label class="utg-user-search-label" for="utg-user-search"><?php esc_html_e('Find a user', 'uplink-time-greeting'); ?></label>
                        <input type="search" id="utg-user-search" class="utg-user-search" placeholder="<?php esc_attr_e('Search by name or username', 'uplink-time-greeting'); ?>">
                        <div class="utg-permission-list utg-user-list">
                            <?php foreach ($users as $user) : if (user_can($user, 'manage_options')) { continue; } ?>
                                <label class="utg-permission-choice utg-user-choice"><input type="checkbox" name="<?php echo esc_attr(UPLINK_TIME_GREETING_PERMISSIONS_OPTION); ?>[users][]" value="<?php echo esc_attr($user->ID); ?>" <?php checked(in_array((int) $user->ID, array_map('intval', (array) $selected_users), true)); ?>><span><?php echo esc_html($user->display_name); ?><small><?php echo esc_html($user->user_login); ?></small></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                </div>
            </section>
            <div class="tgb-save-row"><?php submit_button(__('Save permissions', 'uplink-time-greeting'), 'primary', 'submit', false); ?></div>
        </form>
        <?php
    }

    /** Usage and appearance guidance for every supported editor. */
    private function render_how_to_tab() {
        ?>
        <div class="tgb-usage-grid">
            <section class="tgb-panel"><p class="tgb-overline"><?php esc_html_e('WORDPRESS', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Block editor', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Insert the Uplink Hours & Greetings block, then choose greeting, date, both, or weekly schedule in the block sidebar.', 'uplink-time-greeting'); ?></p></section>
            <section class="tgb-panel"><p class="tgb-overline"><?php esc_html_e('BRICKS', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Query Loop and dynamic tags', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Enable Query Loop on a Div or Container and choose Uplink Weekly Schedule. Add child elements for each day, then style them in Bricks:', 'uplink-time-greeting'); ?></p><p><code>{utg_day}</code> <code>{utg_hours}</code> <code>{utg_state}</code> <code>{utg_key}</code> <code>{utg_today}</code></p><p><?php esc_html_e('For semantic markup, put the repeating Div inside a dl and use dt and dd for the day and hours children. A Shortcode element with [time_greeting display="schedule"] gives ready-made markup. Inline text tags:', 'uplink-time-greeting'); ?></p><p><code>{tgb_greeting}</code> <code>{tgb_date}</code> <code>{tgb_both}</code> <code>{tgb_schedule}</code></p><p><?php esc_html_e('Tags resolve when the page renders. Use a Shortcode element for a live countdown.', 'uplink-time-greeting'); ?></p></section>
            <section class="tgb-panel tgb-etch-guide"><p class="tgb-overline"><?php esc_html_e('ETCH', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Options data', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Use a complete output directly, or bind individual values to elements you build and style in Etch.', 'uplink-time-greeting'); ?></p>
                <h3><?php esc_html_e('Ready-made outputs', 'uplink-time-greeting'); ?></h3><p><code>{options.time_greeting.greeting}</code> <code>{options.time_greeting.date}</code> <code>{options.time_greeting.both}</code> <code>{options.time_greeting.schedule}</code></p>
                <h3><?php esc_html_e('Current and upcoming values', 'uplink-time-greeting'); ?></h3><p><code>{options.time_greeting.timezone}</code> <code>{options.time_greeting.timezone_abbr}</code> <code>{options.time_greeting.now.time}</code> <code>{options.time_greeting.now.date}</code> <code>{options.time_greeting.now.timestamp}</code></p><p><code>{options.time_greeting.current.label}</code> <code>{options.time_greeting.current.start}</code> <code>{options.time_greeting.current.event}</code> <code>{options.time_greeting.current.message}</code> <code>{options.time_greeting.current.output}</code></p><p><code>{options.time_greeting.next.label}</code> <code>{options.time_greeting.next.time}</code> <code>{options.time_greeting.next.countdown}</code> <code>{options.time_greeting.next.event}</code> <code>{options.time_greeting.next.message}</code> <code>{options.time_greeting.next.start}</code> <code>{options.time_greeting.next.timestamp}</code></p><p><code>{options.time_greeting.opening.label}</code> <code>{options.time_greeting.opening.time}</code> <code>{options.time_greeting.opening.countdown}</code> <code>{options.time_greeting.opening.event}</code> <code>{options.time_greeting.opening.message}</code> <code>{options.time_greeting.opening.start}</code> <code>{options.time_greeting.opening.timestamp}</code></p>
                <h3><?php esc_html_e('Weekly schedule loop', 'uplink-time-greeting'); ?></h3><p><code>{#loop options.time_greeting.week as day}</code><br><code>{day.day}</code> <code>{day.hours}</code> <code>{day.state}</code> <code>{day.key}</code> <code>{day.number}</code> <code>{day.is_today}</code><br><code>{/loop}</code></p><p><?php esc_html_e('Each day also includes windows. Each window contains start, start_label, end, end_label, and overnight. Individual day strings are available at options.time_greeting.days.monday through sunday.', 'uplink-time-greeting'); ?></p>
            </section>
            <section class="tgb-panel"><p class="tgb-overline"><?php esc_html_e('SHORTCODE', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Shortcode and PHP', 'uplink-time-greeting'); ?></h2><p><code>[time_greeting]</code> <code>[time_greeting display="both"]</code> <code>[time_greeting display="schedule"]</code></p><p><code>time_greeting_echo( array( 'display' => 'schedule' ) );</code></p><p><?php esc_html_e('Use timezone, tz_abbr, date_format, and display parameters where supported.', 'uplink-time-greeting'); ?></p></section>
            <section class="tgb-panel tgb-appearance-guide"><p class="tgb-overline"><?php esc_html_e('APPEARANCE', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Style the output', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('The WordPress block inherits theme colors and typography. Its sidebar also provides color, spacing, and type controls. Bricks and Etch values are plain text, so style their elements in the builder.', 'uplink-time-greeting'); ?></p><p><?php esc_html_e('These variables control the WordPress greeting and date defaults:', 'uplink-time-greeting'); ?></p><pre class="tgb-css-example"><code>.wp-block-time-greeting-block-time-greeting {
    --tgb-greeting-font-weight: 600;
    --tgb-date-font-style: italic;
}</code></pre><p><?php esc_html_e('The ready-made schedule uses these classes:', 'uplink-time-greeting'); ?></p><p><code>.utg-schedule</code> <code>.utg-schedule__day</code> <code>.utg-schedule__name</code> <code>.utg-schedule__hours</code> <code>.utg-schedule__interval</code> <code>.utg-schedule__status</code></p></section>
        </div>
        <?php
    }
}

// Initialize the plugin
Uplink_Time_Greeting_Plugin::get_instance();

/**
 * Echo function for external use (Bricks Builder, etc.)
 */
function time_greeting_echo($atts = array()) {
    Uplink_Time_Greeting_Plugin::get_instance()->echo_greeting($atts);
}
