<?php
/**
 * Plugin Name:       Admun Growth Engine
 * Description:       Goal-driven content campaigns: planner, writer, media and reviewer agents prepare SEO articles and social posts; you approve; it publishes on schedule.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Admun
 * Author URI:        https://admun.eu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       admun-growth-engine
 *
 * Portions adapted from Bit Social Pro (Bit Apps, GPL-2.0+) and AI Puffer / AI Power (Senol Sahin, GPL-2.0+).
 * See CREDITS.md.
 */

defined('ABSPATH') || exit;

define('AGE_VERSION', '2.0.0');
define('AGE_FILE', __FILE__);
define('AGE_DIR', plugin_dir_path(__FILE__));
define('AGE_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function ($class) {
    $prefix = 'Admun\\GrowthEngine\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = AGE_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, ['Admun\\GrowthEngine\\Install', 'activate']);
register_deactivation_hook(__FILE__, ['Admun\\GrowthEngine\\Install', 'deactivate']);

add_action('plugins_loaded', ['Admun\\GrowthEngine\\Plugin', 'boot']);
