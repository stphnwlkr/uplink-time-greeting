<?php
/**
 * Plugin Name: Uplink Time Greeting
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
define('TGB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TGB_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('TGB_PLUGIN_VERSION', '1.0.0');
define('TGB_OPTION_NAME', 'utg_settings');

/**
 * Main plugin class
 */
class UplinkTimeGreeting {
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
        add_filter('etch/dynamic_data/option', array($this, 'etch_data'));
        add_filter('bricks/dynamic_tags_list', array($this, 'bricks_tags'));
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
        register_uninstall_hook(__FILE__, array('UplinkTimeGreeting', 'uninstall'));

    }

    /**
     * Initialize plugin
     */
    public function init() {
        wp_register_script('tgb-editor', TGB_PLUGIN_URL . 'assets/block-editor.js', array(
            'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render'
        ), TGB_PLUGIN_VERSION, true);
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
            'default_timezone' => wp_timezone_string(),
            'default_tz_abbr' => '',
            'plugin_version' => TGB_PLUGIN_VERSION,
            'activation_date' => current_time('mysql')
        );

        // Only add options if they don't exist (prevents overwriting on reactivation)
        if (false === get_option(TGB_OPTION_NAME, false)) {
            $legacy_options = get_option('tgb_settings', array());
            $initial_options = is_array($legacy_options) && !empty($legacy_options)
                ? wp_parse_args($legacy_options, $default_options)
                : $default_options;
            if (!empty($legacy_options) && is_array($legacy_options)) {
                $initial_options['profiles'][0]['intervals'] = $this->legacy_intervals($legacy_options);
            }
            $initial_options['plugin_version'] = TGB_PLUGIN_VERSION;
            add_option(TGB_OPTION_NAME, $initial_options);
        }

        // Update version if different
        $current_options = get_option(TGB_OPTION_NAME, array());
        if (empty($current_options['profiles'])) {
            $current_options['profiles'] = $this->default_profiles();
            $current_options['profiles'][0]['intervals'] = !empty($current_options['intervals']) && is_array($current_options['intervals'])
                ? $this->normalize_intervals($current_options['intervals'])
                : $this->legacy_intervals($current_options);
            $current_options['week'] = $this->default_week();
            update_option(TGB_OPTION_NAME, $current_options);
        }
        if (empty($current_options['plugin_version']) || $current_options['plugin_version'] !== TGB_PLUGIN_VERSION) {
            $current_options['plugin_version'] = TGB_PLUGIN_VERSION;
            $current_options['last_updated'] = current_time('mysql');
            update_option(TGB_OPTION_NAME, $current_options);
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
        delete_option(TGB_OPTION_NAME);
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
            'default_timezone' => wp_timezone_string(),
            'default_tz_abbr' => ''
        );

        $settings = get_option(TGB_OPTION_NAME, $defaults);
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
        $timezone = !empty($attributes['timezone']) ? $attributes['timezone'] : $settings['default_timezone'];
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
                $datetime = new DateTime('now', new DateTimeZone($settings['default_timezone']));
                $timezone = $settings['default_timezone'];
            } catch (Exception $e2) {
                // Final fallback to server timezone
                $datetime = new DateTime();
                $timezone = date_default_timezone_get();
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
                    if (null === $closing) {
                        /* translators: %s: opening time without a matching closing time. */
                        $hours[] = sprintf(__('From %s', 'uplink-time-greeting'), $from_label);
                    } else {
                        $to = $sunday->modify('+' . ($closing_day === $day ? $day : $day + 1) . ' days')->setTime((int) substr($closing, 0, 2), (int) substr($closing, 3, 2));
                        $hours[] = $from_label . '–' . wp_date($time_format, $to->getTimestamp(), wp_timezone());
                    }
                }
            }
            $rows[] = array(
                'key' => strtolower($sunday->modify('+' . $day . ' days')->format('l')),
                'number' => $day,
                'day' => wp_date('l', $sunday->modify('+' . $day . ' days')->getTimestamp(), wp_timezone()),
                'hours' => !empty($profile['closed']) ? __('Closed', 'uplink-time-greeting') : ($hours ? implode(', ', $hours) : __('Hours not set', 'uplink-time-greeting')),
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
            $html .= '<div class="utg-schedule-day' . ($row['number'] === $today ? ' is-today' : '') . '"><dt>' . esc_html($row['day']) . '</dt><dd>' . esc_html($row['hours']) . '</dd></div>';
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
        $data['time_greeting'] = array(
            'greeting' => $this->plain_value('greeting'),
            'date' => $this->plain_value('date'),
            'both' => $this->plain_value('both'),
            'schedule' => $this->plain_value('schedule'),
            'days' => array_reduce($this->schedule_rows(), function ($days, $row) { $days[$row['key']] = $row['hours']; return $days; }, array()),
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
                'group' => __('Uplink Time Greeting', 'uplink-time-greeting'),
            );
        }
        return $tags;
    }

    public function bricks_render_tag($tag, $post = null, $context = 'text') {
        if (!is_string($tag)) {
            return $tag;
        }
        $key = trim($tag, '{}');
        if (!in_array($key, array('tgb_greeting', 'tgb_date', 'tgb_both', 'tgb_schedule'), true)) {
            return $tag;
        }
        return esc_html($this->plain_value(substr($key, 4)));
    }

    public function bricks_render_content($content, $post = null, $context = 'text') {
        if (!is_string($content) || false === strpos($content, '{tgb_')) {
            return $content;
        }
        foreach (array('greeting', 'date', 'both', 'schedule') as $key) {
            $content = str_replace('{tgb_' . $key . '}', esc_html($this->plain_value($key)), $content);
        }
        return $content;
    }

    public function admin_assets($hook) {
        if ('settings_page_time-greeting-settings' === $hook) {
            wp_enqueue_style('tgb-admin', TGB_PLUGIN_URL . 'assets/admin.css', array(), TGB_PLUGIN_VERSION);
            wp_enqueue_script('tgb-admin', TGB_PLUGIN_URL . 'assets/admin.js', array(), TGB_PLUGIN_VERSION, true);
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
        wp_enqueue_style('utg-output', TGB_PLUGIN_URL . 'assets/block-style.css', array(), TGB_PLUGIN_VERSION);
        wp_enqueue_script('utg-live', TGB_PLUGIN_URL . 'assets/live.js', array('wp-i18n'), TGB_PLUGIN_VERSION, true);
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
        if (current_user_can('manage_options') && $request->get_param('at')) {
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
            if (TGB_OPTION_NAME === $feedback['setting'] ||
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
            __('Uplink Time Greeting Settings', 'uplink-time-greeting'),
            __('Uplink Time Greeting', 'uplink-time-greeting'),
            'manage_options',
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
            TGB_OPTION_NAME,
            array($this, 'sanitize_settings')
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
        $current_settings = get_option(TGB_OPTION_NAME, array());
        $defaults = $this->get_settings();

        // Preserve existing data that shouldn't be overwritten
        $sanitized['plugin_version'] = TGB_PLUGIN_VERSION;
        $sanitized['activation_date'] = $current_settings['activation_date'] ?? current_time('mysql');
        $sanitized['last_updated'] = current_time('mysql');

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
            add_settings_error(TGB_OPTION_NAME, 'profiles', __('Use a named schedule for every day. Each schedule needs 1–24 entries with unique start times. The previous week was kept.', 'uplink-time-greeting'));
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

        // Validate timezone
        try {
            new DateTimeZone($sanitized['default_timezone']);
        } catch (Exception $error) {
            add_settings_error(TGB_OPTION_NAME, 'default_timezone',
                __('Invalid timezone identifier.', 'uplink-time-greeting'));
            $sanitized['default_timezone'] = $current_settings['default_timezone'] ?? wp_timezone_string();
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
            esc_attr(TGB_OPTION_NAME)
        );
    }

    public function timezone_field_callback($args) {
        $settings = $this->get_settings();
        $current_value = $settings[$args['field']];
        $timezones = timezone_identifiers_list();

        printf('<select id="%1$s" name="%2$s[%1$s]">', esc_attr($args['field']), esc_attr(TGB_OPTION_NAME));

        if (!in_array($current_value, $timezones, true)) {
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
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'uplink-time-greeting'));
        }

        // Get current tab
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This value only selects a read-only settings tab.
        $current_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview';
        if ('settings' === $current_tab) {
            $current_tab = 'overview';
        }
        if (!in_array($current_tab, array('overview', 'schedule', 'usage', 'styling'), true)) {
            $current_tab = 'overview';
        }

        ?>
        <div class="wrap tgb-admin" data-view="<?php echo esc_attr($current_tab); ?>">
            <div class="tgb-admin-header">
                <div class="tgb-header-icon" aria-hidden="true"><img src="<?php echo esc_url(TGB_PLUGIN_URL . 'assets/uplink-mark.svg'); ?>" alt=""></div>
                <div><p class="tgb-eyebrow"><?php esc_html_e('UPLINK TIME GREETING · SETTINGS', 'uplink-time-greeting'); ?></p><h1><?php esc_html_e('Uplink Time Greeting', 'uplink-time-greeting'); ?></h1><p><?php esc_html_e('Set what visitors see throughout your business week.', 'uplink-time-greeting'); ?></p></div>
                <span class="tgb-version"><?php echo esc_html('v' . TGB_PLUGIN_VERSION); ?></span>
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
            <nav class="tgb-tabs" aria-label="<?php esc_attr_e('Uplink Time Greeting sections', 'uplink-time-greeting'); ?>">
                <a href="<?php echo esc_url(add_query_arg('tab', 'overview', admin_url('options-general.php?page=time-greeting-settings'))); ?>"
                   class="<?php echo $current_tab === 'overview' ? 'is-active' : ''; ?>" <?php echo $current_tab === 'overview' ? 'aria-current="page"' : ''; ?>>
                    <?php esc_html_e('Overview', 'uplink-time-greeting'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'schedule', admin_url('options-general.php?page=time-greeting-settings'))); ?>"
                   class="<?php echo $current_tab === 'schedule' ? 'is-active' : ''; ?>" <?php echo $current_tab === 'schedule' ? 'aria-current="page"' : ''; ?>>
                    <?php esc_html_e('Weekly schedule', 'uplink-time-greeting'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'usage', admin_url('options-general.php?page=time-greeting-settings'))); ?>"
                   class="<?php echo $current_tab === 'usage' ? 'is-active' : ''; ?>" <?php echo $current_tab === 'usage' ? 'aria-current="page"' : ''; ?>>
                    <?php esc_html_e('Use in editors', 'uplink-time-greeting'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'styling', admin_url('options-general.php?page=time-greeting-settings'))); ?>"
                   class="<?php echo $current_tab === 'styling' ? 'is-active' : ''; ?>" <?php echo $current_tab === 'styling' ? 'aria-current="page"' : ''; ?>>
                    <?php esc_html_e('Appearance', 'uplink-time-greeting'); ?>
                </a>
            </nav>

            <div class="tgb-tab-content">
                <?php if (in_array($current_tab, array('overview', 'schedule'), true)): ?>
                    <?php $this->render_settings_tab($current_tab); ?>
                <?php elseif ($current_tab === 'usage'): ?>
                    <?php $this->render_usage_tab(); ?>
                <?php elseif ($current_tab === 'styling'): ?>
                    <?php $this->render_styling_tab(); ?>
                <?php endif; ?>
            </div>
        </div>

        <?php
    }

    /** Schedule and message controls. */
    private function render_settings_tab($current_tab) {
        $settings = $this->get_settings();
        try {
            $preview_now = new DateTime('now', new DateTimeZone($settings['default_timezone']));
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
                        <select id="utg-day-<?php echo esc_attr($day); ?>" name="<?php echo esc_attr(TGB_OPTION_NAME . '[week][' . $day . ']'); ?>" class="utg-day-profile">
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
                <div class="utg-profiles">
                    <?php foreach ($settings['profiles'] as $profile_index => $profile) : ?>
                    <div class="utg-profile" data-profile-id="<?php echo esc_attr($profile['id']); ?>">
                        <div class="utg-profile-heading">
                            <div class="utg-profile-name"><label><?php esc_html_e('Schedule name', 'uplink-time-greeting'); ?><input type="text" class="utg-profile-name-input" name="<?php echo esc_attr(TGB_OPTION_NAME . '[profiles][' . $profile_index . '][name]'); ?>" value="<?php echo esc_attr($profile['name']); ?>" maxlength="80" required></label><input type="hidden" class="utg-profile-id" name="<?php echo esc_attr(TGB_OPTION_NAME . '[profiles][' . $profile_index . '][id]'); ?>" value="<?php echo esc_attr($profile['id']); ?>"></div>
                            <button type="button" class="button utg-remove-profile"><?php esc_html_e('Remove schedule', 'uplink-time-greeting'); ?></button>
                        </div>
                        <label class="tgb-checkbox-field utg-closed-field"><input type="hidden" name="<?php echo esc_attr(TGB_OPTION_NAME . '[profiles][' . $profile_index . '][closed]'); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr(TGB_OPTION_NAME . '[profiles][' . $profile_index . '][closed]'); ?>" value="1" <?php checked(!empty($profile['closed'])); ?>><?php esc_html_e('Closed all day', 'uplink-time-greeting'); ?></label>
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
                <p class="tgb-note"><?php esc_html_e('Message tokens: {time}, {tz}, {countdown} to the next entry, {next_label}, {next_time}, {opening_countdown}, and {opening_time}. Example: It’s {time}. We open in {countdown}.', 'uplink-time-greeting'); ?></p>
            </section>
            <section class="tgb-panel utg-overview-panel">
                <div class="tgb-panel-heading"><div><p class="tgb-overline"><?php esc_html_e('LOCAL TIME', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Timezone', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('The schedule uses this timezone unless a block or shortcode provides its own.', 'uplink-time-greeting'); ?></p></div></div>
                <div class="tgb-default-fields">
                    <div class="tgb-field"><label for="default_timezone"><?php esc_html_e('Timezone', 'uplink-time-greeting'); ?></label><?php $this->timezone_field_callback(array('field' => 'default_timezone')); ?></div>
                    <div class="tgb-field"><label for="default_tz_abbr"><?php esc_html_e('Timezone label (optional)', 'uplink-time-greeting'); ?></label><?php $this->text_field_callback(array('field' => 'default_tz_abbr')); ?><p><?php esc_html_e('Leave blank to use the timezone’s current abbreviation.', 'uplink-time-greeting'); ?></p></div>
                </div>
            </section>
            <section class="tgb-panel utg-overview-panel">
                <div class="tgb-panel-heading"><div><p class="tgb-overline"><?php esc_html_e('DATE WORDING', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Date introduction', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Set the words that appear before the date in the combined output and, optionally, Date only.', 'uplink-time-greeting'); ?></p></div></div>
                <div class="tgb-field tgb-date-intro-field"><label for="date_intro"><?php esc_html_e('Introduction', 'uplink-time-greeting'); ?></label><?php $this->text_field_callback(array('field' => 'date_intro')); ?><p><?php esc_html_e('Translate or rewrite “Today is” for your audience. Leave blank to show only the date.', 'uplink-time-greeting'); ?></p></div>
                <label class="tgb-checkbox-field" for="date_only_intro"><input type="hidden" name="<?php echo esc_attr(TGB_OPTION_NAME . '[date_only_intro]'); ?>" value="0"><input type="checkbox" id="date_only_intro" name="<?php echo esc_attr(TGB_OPTION_NAME . '[date_only_intro]'); ?>" value="1" <?php checked(!empty($settings['date_only_intro'])); ?>><?php esc_html_e('Show the introduction with Date only', 'uplink-time-greeting'); ?></label>
            </section>
            <div class="tgb-save-row"><?php submit_button($current_tab === 'schedule' ? __('Save schedule', 'uplink-time-greeting') : __('Save settings', 'uplink-time-greeting'), 'primary', 'submit', false); ?></div>
        </form>
        <section class="tgb-panel tgb-preview utg-overview-panel">
            <div class="tgb-panel-heading"><div><p class="tgb-overline"><?php esc_html_e('OUTPUT EXAMPLES', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('What visitors see now', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('These examples use the same saved schedule and show each display option.', 'uplink-time-greeting'); ?></p></div></div>
            <div class="utg-preview-controls">
                <label><?php esc_html_e('Preview date', 'uplink-time-greeting'); ?><input type="date" class="utg-preview-date" value="<?php echo esc_attr($preview_now->format('Y-m-d')); ?>"></label>
                <label><?php esc_html_e('Time', 'uplink-time-greeting'); ?><input type="time" class="utg-preview-time" value="<?php echo esc_attr($preview_now->format('H:i')); ?>"></label>
                <button type="button" class="button utg-preview-button"><?php esc_html_e('Preview saved schedule', 'uplink-time-greeting'); ?></button>
                <span class="utg-preview-status" role="status" aria-live="polite"></span>
            </div>
            <div class="tgb-example-grid">
                <?php foreach (array('greeting' => __('Greeting', 'uplink-time-greeting'), 'date' => __('Date', 'uplink-time-greeting'), 'both' => __('Greeting and date', 'uplink-time-greeting'), 'schedule' => __('Weekly schedule', 'uplink-time-greeting')) as $display => $label) : ?>
                    <div class="tgb-example" data-display="<?php echo esc_attr($display); ?>"><h3><?php echo esc_html($label); ?></h3><div class="tgb-preview-value"><?php echo wp_kses_post($this->generate_greeting(array('display' => $display))); ?></div></div>
                <?php endforeach; ?>
            </div>
            <p><?php esc_html_e('Preview uses saved settings. Countdowns in blocks and shortcodes refresh on the page.', 'uplink-time-greeting'); ?></p>
        </section>
        <?php
    }

    private function render_interval_row($profile_index, $row_index, $interval) {
        $name = TGB_OPTION_NAME . '[profiles][' . $profile_index . '][intervals][' . $row_index . ']';
        ?>
        <div class="utg-interval-row">
            <label><?php esc_html_e('Starts at', 'uplink-time-greeting'); ?><input type="time" step="60" name="<?php echo esc_attr($name . '[start]'); ?>" value="<?php echo esc_attr($interval['start']); ?>" required></label>
            <label><?php esc_html_e('Label', 'uplink-time-greeting'); ?><input type="text" name="<?php echo esc_attr($name . '[label]'); ?>" value="<?php echo esc_attr($interval['label']); ?>" maxlength="80" placeholder="<?php esc_attr_e('Optional', 'uplink-time-greeting'); ?>"></label>
            <label><?php esc_html_e('Event', 'uplink-time-greeting'); ?><select name="<?php echo esc_attr($name . '[event]'); ?>"><option value="" <?php selected($interval['event'], ''); ?>><?php esc_html_e('None', 'uplink-time-greeting'); ?></option><option value="opening" <?php selected($interval['event'], 'opening'); ?>><?php esc_html_e('Opening', 'uplink-time-greeting'); ?></option><option value="closing" <?php selected($interval['event'], 'closing'); ?>><?php esc_html_e('Closing', 'uplink-time-greeting'); ?></option></select></label>
            <label class="utg-message-field"><?php esc_html_e('Message', 'uplink-time-greeting'); ?><textarea name="<?php echo esc_attr($name . '[message]'); ?>" rows="2"><?php echo esc_textarea($interval['message']); ?></textarea></label>
            <button type="button" class="button utg-remove-interval" aria-label="<?php esc_attr_e('Remove time entry', 'uplink-time-greeting'); ?>">&times;</button>
        </div>
        <?php
    }

    /** Usage examples for each supported editor. */
    private function render_usage_tab() {
        ?>
        <div class="tgb-usage-grid">
            <section class="tgb-panel"><p class="tgb-overline"><?php esc_html_e('WORDPRESS', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Block editor', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Insert the Uplink Time Greeting block, then choose greeting, date, both, or weekly schedule in the block sidebar.', 'uplink-time-greeting'); ?></p></section>
            <section class="tgb-panel"><p class="tgb-overline"><?php esc_html_e('BRICKS', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Dynamic tags', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Add one of these tags to a text element:', 'uplink-time-greeting'); ?></p><p><code>{tgb_greeting}</code> <code>{tgb_date}</code> <code>{tgb_both}</code> <code>{tgb_schedule}</code></p><p><?php esc_html_e('Tags resolve when the page renders. Use a Shortcode element for a formatted schedule or live countdown.', 'uplink-time-greeting'); ?></p></section>
            <section class="tgb-panel"><p class="tgb-overline"><?php esc_html_e('ETCH', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Options data', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Add an options data key to a text element:', 'uplink-time-greeting'); ?></p><p><code>{options.time_greeting.greeting}</code><br><code>{options.time_greeting.date}</code><br><code>{options.time_greeting.both}</code><br><code>{options.time_greeting.schedule}</code><br><code>{options.time_greeting.days.monday}</code></p><p><?php esc_html_e('Options data resolves when the page renders. Use a shortcode-capable element for a formatted schedule or live countdown.', 'uplink-time-greeting'); ?></p></section>
            <section class="tgb-panel"><p class="tgb-overline"><?php esc_html_e('SHORTCODE', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Shortcode and PHP', 'uplink-time-greeting'); ?></h2><p><code>[time_greeting]</code> <code>[time_greeting display="both"]</code> <code>[time_greeting display="schedule"]</code></p><p><code>time_greeting_echo( array( 'display' => 'schedule' ) );</code></p><p><?php esc_html_e('Use timezone, tz_abbr, date_format, and display parameters where supported.', 'uplink-time-greeting'); ?></p></section>
        </div>
        <?php
    }

    /**
     * Render styling tab content
     */
    private function render_styling_tab() {
        ?>
        <div class="tgb-section">
            <h2><?php esc_html_e('Styling the block', 'uplink-time-greeting'); ?></h2>
            <p><?php esc_html_e('The WordPress block inherits its theme\'s color and typography settings. You can also set color, spacing, and type in the block sidebar.', 'uplink-time-greeting'); ?></p>
            <p><?php esc_html_e('These two CSS variables control the greeting weight and date style:', 'uplink-time-greeting'); ?></p>
            <pre class="tgb-css-example"><code>.wp-block-time-greeting-block-time-greeting {
    --tgb-greeting-font-weight: 600;
    --tgb-date-font-style: italic;
}</code></pre>
        </div>
        <div class="tgb-section">
            <h2><?php esc_html_e('Bricks and Etch', 'uplink-time-greeting'); ?></h2>
            <p><?php esc_html_e('Their dynamic data values are plain text. Style the text element in the builder.', 'uplink-time-greeting'); ?></p>
        </div>
        <?php
    }
}

// Initialize the plugin
UplinkTimeGreeting::get_instance();

/**
 * Echo function for external use (Bricks Builder, etc.)
 */
function time_greeting_echo($atts = array()) {
    UplinkTimeGreeting::get_instance()->echo_greeting($atts);
}
