<?php

namespace Admun\GrowthEngine\Support;

use Admun\GrowthEngine\Install;

defined('ABSPATH') || exit;

class Log
{
    public static function info(string $channel, string $message): void
    {
        self::write('info', $channel, $message);
    }

    public static function warn(string $channel, string $message): void
    {
        self::write('warn', $channel, $message);
    }

    public static function error(string $channel, string $message): void
    {
        self::write('error', $channel, $message);
    }

    private static function write(string $level, string $channel, string $message): void
    {
        global $wpdb;
        $wpdb->insert(Install::table('log'), [
            'level' => $level,
            'channel' => $channel,
            'message' => mb_substr($message, 0, 2000),
            'created_at' => current_time('mysql', true),
        ]);
        // Keep the log bounded.
        if (wp_rand(1, 50) === 1) {
            $wpdb->query("DELETE FROM " . Install::table('log') . " WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)");
        }
    }

    public static function recent(int $limit = 200): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT level, channel, message, created_at FROM " . Install::table('log') . " ORDER BY id DESC LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
    }
}
