<?php

namespace Admun\GrowthEngine;

use Admun\GrowthEngine\Admin\Admin;
use Admun\GrowthEngine\Jobs\Runner;
use Admun\GrowthEngine\REST\Routes;
use Admun\GrowthEngine\SEO\Frontend;
use Admun\GrowthEngine\Social\OAuth;

defined('ABSPATH') || exit;

class Plugin
{
    const CRON_HOOK = 'age_tick';

    public static function boot(): void
    {
        Install::maybe_migrate();

        add_filter('cron_schedules', static function ($s) {
            $s['age_minute'] = ['interval' => 60, 'display' => 'Every minute (Growth Engine)'];
            return $s;
        });
        add_action(self::CRON_HOOK, [Runner::class, 'tick']);
        add_action('init', [self::class, 'schedule_cron']);

        Runner::register();
        Frontend::register();
        Routes::register();
        OAuth::register();

        if (is_admin()) {
            Admin::register();
        }
    }

    public static function schedule_cron(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 30, 'age_minute', self::CRON_HOOK);
        }
    }
}
