<?php
/**
 * Plugin Name: Uplink Time Greeting
 * Description: Time-aware greetings and dates for the block editor, Bricks, Etch, and shortcodes.
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
            'morning_start' => '05:00',
            'afternoon_start' => '12:00',
            'evening_start' => '17:00',
            'night_start' => '22:00',
            'morning_message' => __('Good morning!', 'uplink-time-greeting'),
            'afternoon_message' => __('Good afternoon!', 'uplink-time-greeting'),
            'evening_message' => __('Good evening!', 'uplink-time-greeting'),
            'night_message' => __("It's {time} {tz} and we're asleep.", 'uplink-time-greeting'),
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
            $initial_options['plugin_version'] = TGB_PLUGIN_VERSION;
            add_option(TGB_OPTION_NAME, $initial_options);
        }

        // Update version if different
        $current_options = get_option(TGB_OPTION_NAME, array());
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
            'morning_start' => '05:00',
            'afternoon_start' => '12:00',
            'evening_start' => '17:00',
            'night_start' => '22:00',
            'morning_message' => __('Good morning!', 'uplink-time-greeting'),
            'afternoon_message' => __('Good afternoon!', 'uplink-time-greeting'),
            'evening_message' => __('Good evening!', 'uplink-time-greeting'),
            'night_message' => __("It's {time} {tz} and we're asleep.", 'uplink-time-greeting'),
            'date_intro' => __('Today is', 'uplink-time-greeting'),
            'date_only_intro' => true,
            'default_timezone' => wp_timezone_string(),
            'default_tz_abbr' => ''
        );

        $settings = get_option(TGB_OPTION_NAME, $defaults);
        $settings = wp_parse_args($settings, $defaults);
        foreach (array('morning', 'afternoon', 'evening', 'night') as $period) {
            $settings[$period . '_start'] = $this->normalize_start_time($settings[$period . '_start']);
        }
        return $settings;
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
        $tz_abbr = !empty($attributes['tzAbbr']) ? $attributes['tzAbbr'] : $settings['default_tz_abbr'];

        // Validate display option
        if (!in_array($display, array('greeting', 'date', 'both'))) {
            $display = 'greeting';
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
                esc_html($greeting)
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

        return $output;
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

    /**
     * Get time-based greeting using DateTime object
     */
    private function get_time_greeting($datetime, $tz_abbr, $settings) {
        $now = ((int) $datetime->format('H') * 60) + (int) $datetime->format('i');
        $period = 'night';
        foreach (array('morning', 'afternoon', 'evening', 'night') as $candidate) {
            if ($now >= $this->start_minutes($settings[$candidate . '_start'])) {
                $period = $candidate;
            }
        }

        $time_format = get_option('time_format', 'g:i a');
        $current_time = wp_date($time_format, $datetime->getTimestamp(), $datetime->getTimezone());
        return str_replace(
            array('{time}', '{tz}'),
            array($current_time, $tz_abbr),
            $settings[$period . '_message']
        );
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

    /** Plain text values for builders that own their element markup. */
    public function plain_value($display = 'greeting') {
        if (!in_array($display, array('greeting', 'date', 'both'), true)) {
            return '';
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
        );
        return $data;
    }

    /** Register native Bricks dynamic data tags. */
    public function bricks_tags($tags) {
        foreach (array(
            'greeting' => __('Greeting', 'uplink-time-greeting'),
            'date' => __('Date', 'uplink-time-greeting'),
            'both' => __('Greeting and date', 'uplink-time-greeting'),
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
        if (!in_array($key, array('tgb_greeting', 'tgb_date', 'tgb_both'), true)) {
            return $tag;
        }
        return esc_html($this->plain_value(substr($key, 4)));
    }

    public function bricks_render_content($content, $post = null, $context = 'text') {
        if (!is_string($content) || false === strpos($content, '{tgb_')) {
            return $content;
        }
        foreach (array('greeting', 'date', 'both') as $key) {
            $content = str_replace('{tgb_' . $key . '}', esc_html($this->plain_value($key)), $content);
        }
        return $content;
    }

    public function admin_assets($hook) {
        if ('settings_page_time-greeting-settings' === $hook) {
            wp_enqueue_style('tgb-admin', TGB_PLUGIN_URL . 'assets/admin.css', array(), TGB_PLUGIN_VERSION);
            wp_enqueue_script('tgb-admin', TGB_PLUGIN_URL . 'assets/admin.js', array(), TGB_PLUGIN_VERSION, true);
        }
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

        foreach (array('morning', 'afternoon', 'evening', 'night') as $period) {
            $start_key = $period . '_start';
            $message_key = $period . '_message';
            $raw_start = sanitize_text_field(wp_unslash($input[$start_key] ?? $defaults[$start_key]));
            if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $raw_start)) {
                add_settings_error(TGB_OPTION_NAME, $start_key, __('Enter each start time as HH:MM in 24-hour format.', 'uplink-time-greeting'));
                $raw_start = $defaults[$start_key];
            }
            $sanitized[$start_key] = $raw_start;
            $sanitized[$message_key] = sanitize_textarea_field(wp_unslash($input[$message_key] ?? $defaults[$message_key]));
        }
        $sanitized['default_timezone'] = sanitize_text_field(wp_unslash($input['default_timezone'] ?? ''));
        $sanitized['default_tz_abbr'] = substr(sanitize_text_field(wp_unslash($input['default_tz_abbr'] ?? '')), 0, 20);
        $sanitized['date_intro'] = sanitize_text_field(wp_unslash($input['date_intro'] ?? $defaults['date_intro']));
        $sanitized['date_only_intro'] = !empty($input['date_only_intro']);

        if (!($this->start_minutes($sanitized['morning_start']) < $this->start_minutes($sanitized['afternoon_start']) &&
            $this->start_minutes($sanitized['afternoon_start']) < $this->start_minutes($sanitized['evening_start']) &&
            $this->start_minutes($sanitized['evening_start']) < $this->start_minutes($sanitized['night_start']))) {
            add_settings_error(TGB_OPTION_NAME, 'period_order', __('Start times must increase from morning through night. Previous times were kept.', 'uplink-time-greeting'));
            foreach (array('morning_start', 'afternoon_start', 'evening_start', 'night_start') as $field) {
                $sanitized[$field] = $defaults[$field];
            }
        }

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
        $current_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        if (!in_array($current_tab, array('settings', 'usage', 'styling'), true)) {
            $current_tab = 'settings';
        }

        ?>
        <div class="wrap tgb-admin">
            <div class="tgb-admin-header">
                <div class="tgb-header-icon" aria-hidden="true"><img src="<?php echo esc_url(TGB_PLUGIN_URL . 'assets/uplink-mark.svg'); ?>" alt=""></div>
                <div><p class="tgb-eyebrow"><?php esc_html_e('UPLINK TIME GREETING · SETTINGS', 'uplink-time-greeting'); ?></p><h1><?php esc_html_e('Uplink Time Greeting', 'uplink-time-greeting'); ?></h1><p><?php esc_html_e('Set what visitors see throughout your business day.', 'uplink-time-greeting'); ?></p></div>
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
                <a href="<?php echo esc_url(add_query_arg('tab', 'settings', admin_url('options-general.php?page=time-greeting-settings'))); ?>"
                   class="<?php echo $current_tab === 'settings' ? 'is-active' : ''; ?>" <?php echo $current_tab === 'settings' ? 'aria-current="page"' : ''; ?>>
                    <?php esc_html_e('Schedule & messages', 'uplink-time-greeting'); ?>
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
                <?php if ($current_tab === 'settings'): ?>
                    <?php $this->render_settings_tab(); ?>
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
    private function render_settings_tab() {
        $settings = $this->get_settings();
        $periods = array(
            'morning' => array(__('Morning', 'uplink-time-greeting'), __('Opening hours', 'uplink-time-greeting')),
            'afternoon' => array(__('Afternoon', 'uplink-time-greeting'), __('Daytime', 'uplink-time-greeting')),
            'evening' => array(__('Evening', 'uplink-time-greeting'), __('After hours', 'uplink-time-greeting')),
            'night' => array(__('Night', 'uplink-time-greeting'), __('Late hours', 'uplink-time-greeting')),
        );
        ?>
        <form method="post" action="options.php" class="tgb-settings-form">
            <?php settings_fields('tgb_settings_group'); ?>
            <section class="tgb-panel">
                <div class="tgb-panel-heading">
                    <div><p class="tgb-overline"><?php esc_html_e('DAILY SCHEDULE', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Messages by time of day', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Each start time begins a new period. Night continues until morning starts again.', 'uplink-time-greeting'); ?></p></div>
                </div>
                <div class="tgb-period-grid">
                    <?php foreach ($periods as $period => $labels) : ?>
                    <div class="tgb-period-card">
                        <div class="tgb-period-heading"><span class="tgb-period-dot tgb-period-<?php echo esc_attr($period); ?>" aria-hidden="true"></span><div><h3><?php echo esc_html($labels[0]); ?></h3><p><?php echo esc_html($labels[1]); ?></p></div></div>
                        <div class="tgb-field"><label for="<?php echo esc_attr($period); ?>_start"><?php esc_html_e('Starts at', 'uplink-time-greeting'); ?></label><input type="time" step="60" id="<?php echo esc_attr($period); ?>_start" name="<?php echo esc_attr(TGB_OPTION_NAME . '[' . $period . '_start]'); ?>" value="<?php echo esc_attr($settings[$period . '_start']); ?>" required></div>
                        <div class="tgb-field"><label for="<?php echo esc_attr($period); ?>_message"><?php esc_html_e('Message', 'uplink-time-greeting'); ?></label><textarea id="<?php echo esc_attr($period); ?>_message" name="<?php echo esc_attr(TGB_OPTION_NAME . '[' . $period . '_message]'); ?>" rows="3"><?php echo esc_textarea($settings[$period . '_message']); ?></textarea></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="tgb-note"><?php esc_html_e('Use {time} for the current local time and {tz} for the timezone abbreviation in any message. Time follows your WordPress time format.', 'uplink-time-greeting'); ?></p>
            </section>
            <section class="tgb-panel">
                <div class="tgb-panel-heading"><div><p class="tgb-overline"><?php esc_html_e('LOCAL TIME', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Timezone', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('The schedule uses this timezone unless a block or shortcode provides its own.', 'uplink-time-greeting'); ?></p></div></div>
                <div class="tgb-default-fields">
                    <div class="tgb-field"><label for="default_timezone"><?php esc_html_e('Timezone', 'uplink-time-greeting'); ?></label><?php $this->timezone_field_callback(array('field' => 'default_timezone')); ?></div>
                    <div class="tgb-field"><label for="default_tz_abbr"><?php esc_html_e('Timezone label (optional)', 'uplink-time-greeting'); ?></label><?php $this->text_field_callback(array('field' => 'default_tz_abbr')); ?><p><?php esc_html_e('Leave blank to use the timezone’s current abbreviation.', 'uplink-time-greeting'); ?></p></div>
                </div>
            </section>
            <section class="tgb-panel">
                <div class="tgb-panel-heading"><div><p class="tgb-overline"><?php esc_html_e('DATE WORDING', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Date introduction', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Set the words that appear before the date in the combined output and, optionally, Date only.', 'uplink-time-greeting'); ?></p></div></div>
                <div class="tgb-field tgb-date-intro-field"><label for="date_intro"><?php esc_html_e('Introduction', 'uplink-time-greeting'); ?></label><?php $this->text_field_callback(array('field' => 'date_intro')); ?><p><?php esc_html_e('Translate or rewrite “Today is” for your audience. Leave blank to show only the date.', 'uplink-time-greeting'); ?></p></div>
                <label class="tgb-checkbox-field" for="date_only_intro"><input type="hidden" name="<?php echo esc_attr(TGB_OPTION_NAME . '[date_only_intro]'); ?>" value="0"><input type="checkbox" id="date_only_intro" name="<?php echo esc_attr(TGB_OPTION_NAME . '[date_only_intro]'); ?>" value="1" <?php checked(!empty($settings['date_only_intro'])); ?>><?php esc_html_e('Show the introduction with Date only', 'uplink-time-greeting'); ?></label>
            </section>
            <div class="tgb-save-row"><?php submit_button(__('Save schedule', 'uplink-time-greeting'), 'primary', 'submit', false); ?></div>
        </form>
        <section class="tgb-panel tgb-preview">
            <div class="tgb-panel-heading"><div><p class="tgb-overline"><?php esc_html_e('OUTPUT EXAMPLES', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('What visitors see now', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('These examples use the same saved schedule and show each display option.', 'uplink-time-greeting'); ?></p></div></div>
            <div class="tgb-example-grid">
                <?php foreach (array('greeting' => __('Greeting', 'uplink-time-greeting'), 'date' => __('Date', 'uplink-time-greeting'), 'both' => __('Greeting and date', 'uplink-time-greeting')) as $display => $label) : ?>
                    <div class="tgb-example"><h3><?php echo esc_html($label); ?></h3><div class="tgb-preview-value"><?php echo wp_kses_post($this->generate_greeting(array('display' => $display))); ?></div></div>
                <?php endforeach; ?>
            </div>
            <p><?php esc_html_e('Examples update after you save. Cached pages may update later.', 'uplink-time-greeting'); ?></p>
        </section>
        <?php
    }

    /** Usage examples for each supported editor. */
    private function render_usage_tab() {
        ?>
        <div class="tgb-usage-grid">
            <section class="tgb-panel"><p class="tgb-overline"><?php esc_html_e('WORDPRESS', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Block editor', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Insert the Uplink Time Greeting block, then choose greeting, date, or both in the block sidebar.', 'uplink-time-greeting'); ?></p></section>
            <section class="tgb-panel"><p class="tgb-overline"><?php esc_html_e('BRICKS', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Dynamic tags', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Add one of these tags to a text element:', 'uplink-time-greeting'); ?></p><p><code>{tgb_greeting}</code> <code>{tgb_date}</code> <code>{tgb_both}</code></p></section>
            <section class="tgb-panel"><p class="tgb-overline"><?php esc_html_e('ETCH', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Options data', 'uplink-time-greeting'); ?></h2><p><?php esc_html_e('Add an options data key to a text element:', 'uplink-time-greeting'); ?></p><p><code>{options.time_greeting.greeting}</code><br><code>{options.time_greeting.date}</code><br><code>{options.time_greeting.both}</code></p></section>
            <section class="tgb-panel"><p class="tgb-overline"><?php esc_html_e('SHORTCODE', 'uplink-time-greeting'); ?></p><h2><?php esc_html_e('Shortcode and PHP', 'uplink-time-greeting'); ?></h2><p><code>[time_greeting]</code> <code>[time_greeting display="both"]</code></p><p><code>time_greeting_echo( array( 'display' => 'both' ) );</code></p><p><?php esc_html_e('Use timezone, tz_abbr, date_format, and display parameters where supported.', 'uplink-time-greeting'); ?></p></section>
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
