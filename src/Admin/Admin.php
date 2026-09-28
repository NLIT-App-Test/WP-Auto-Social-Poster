<?php

namespace Admun\GrowthEngine\Admin;

defined('ABSPATH') || exit;

class Admin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_filter('plugin_action_links_' . plugin_basename(AGE_FILE), static function ($links) {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=growth-engine#/settings')) . '">Settings</a>');
            return $links;
        });
    }

    public static function menu(): void
    {
        $icon = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#a7aaad"><path d="M3 15l4-5 3 3 6-8v3h2V2h-6v2h3l-5 6.5-3-3L1.5 13.8z"/><rect x="2" y="16" width="16" height="2" rx="1"/></svg>');
        add_menu_page('Growth Engine', 'Growth Engine', 'manage_options', 'growth-engine', [self::class, 'page'], $icon, 26);
        add_submenu_page('growth-engine', 'Campaigns', 'Campaigns', 'manage_options', 'growth-engine', [self::class, 'page']);
        add_submenu_page('growth-engine', 'New campaign', 'New campaign', 'manage_options', 'growth-engine#/new', [self::class, 'page']);
        add_submenu_page('growth-engine', 'Settings', 'Settings', 'manage_options', 'growth-engine#/settings', [self::class, 'page']);
    }

    public static function page(): void
    {
        echo '<div class="wrap age-wrap"><div id="age-app"><p>Loading…</p></div></div>';
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_growth-engine') {
            return;
        }
        wp_enqueue_style('age-admin', AGE_URL . 'assets/admin.css', [], AGE_VERSION);
        wp_enqueue_script('age-admin', AGE_URL . 'assets/admin.js', [], AGE_VERSION, true);
        wp_localize_script('age-admin', 'AGE', [
            'root' => esc_url_raw(rest_url('age/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'notice' => isset($_GET['age_notice']) ? sanitize_text_field(wp_unslash($_GET['age_notice'])) : '',
            'error' => isset($_GET['age_error']) ? sanitize_text_field(wp_unslash($_GET['age_error'])) : '',
            'today' => wp_date('Y-m-d'),
            'tz' => wp_timezone_string(),
            'version' => AGE_VERSION,
        ]);
    }
}
