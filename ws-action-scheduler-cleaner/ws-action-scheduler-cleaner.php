<?php
/**
 * Plugin Name: WS Action Scheduler Cleaner
 * Description: Suffering from database bloat? Try cleaning up the Action Scheduler tables through an easy-to-understand GUI.
 * Version: 1.2.5
 * Author: Winning Solutions - Nagel & Mäder GbR
 * Author URI: https://www.winning-solutions.de
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Text Domain: ws-action-scheduler-cleaner
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WSACSC_PLUGIN_FILE', __FILE__);
define('WSACSC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WSACSC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WSACSC_PLUGIN_DIR . 'includes/class-database.php';
require_once WSACSC_PLUGIN_DIR . 'includes/class-activation.php';
require_once WSACSC_PLUGIN_DIR . 'includes/class-admin.php';
require_once WSACSC_PLUGIN_DIR . 'includes/class-ajax.php';
require_once WSACSC_PLUGIN_DIR . 'includes/class-cleanup.php';
require_once WSACSC_PLUGIN_DIR . 'includes/class-scheduler.php';
require_once WSACSC_PLUGIN_DIR . 'includes/filters.php';

register_activation_hook(__FILE__, array('WSACSC_Activation', 'activate'));
register_deactivation_hook(__FILE__, array('WSACSC_Activation', 'deactivate'));

WSACSC_Admin::init();
WSACSC_Ajax::init();
WSACSC_Cleanup::init();
WSACSC_Scheduler::init();
wsacsc_init_filters();

add_action('admin_init', array('WSACSC_Activation', 'migrate_existing_settings'));
