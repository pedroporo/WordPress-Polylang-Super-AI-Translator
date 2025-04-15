<?php
/**
 * Plugin Name: Super Traductor Polilag
 * Description: Automatically translate WordPress content using OpenAI API and Polylang integration
 * Version: 1.0
 * Author: Miwewoderecho
 * Author URI: https://pedroporo.github.io
 * Text Domain: super-ai-traductor
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('SPT_VERSION', '1.0');
define('SPT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SPT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load required files
require_once SPT_PLUGIN_DIR . 'includes/class-spt-core.php';
require_once SPT_PLUGIN_DIR . 'includes/class-spt-admin.php';
require_once SPT_PLUGIN_DIR . 'includes/class-spt-openai.php';
require_once SPT_PLUGIN_DIR . 'includes/class-spt-polylang.php';

// Initialize the plugin
function spt_init() {
    // Check if Polylang is active
    if (!function_exists('pll_languages_list')) {
        add_action('admin_notices', 'spt_polylang_missing_notice');
        return;
    }

    // Initialize plugin classes
    new SPT_Core();
    if (is_admin()) {
        new SPT_Admin();
    }
}
add_action('plugins_loaded', 'spt_init');

// Admin notice for missing Polylang
function spt_polylang_missing_notice() {
    $class = 'notice notice-error';
    $message = __('Este supertraductor requiere que este Polilang.', 'super-ai-polylang-translator');
    printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
}