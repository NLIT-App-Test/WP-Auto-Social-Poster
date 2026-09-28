<?php

namespace Admun\GrowthEngine\Jobs;

use Admun\GrowthEngine\Install;

defined('ABSPATH') || exit;

class Jobs
{
    public static function enqueue(string $type, array $input, int $run_after = 0): int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert(Install::table('jobs'), [
            'type' => $type,
            'status' => 'queued',
            'input' => wp_json_encode($input),
            'run_after' => gmdate('Y-m-d H:i:s', $run_after ?: time()),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $wpdb->insert_id;
        if (!$run_after || $run_after <= time()) {
            Runner::kick();
        }
        return $id;
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . Install::table('jobs') . " WHERE id = %d", $id), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    public static function list(?string $type = null, int $limit = 100): array
    {
        global $wpdb;
        $t = Install::table('jobs');
        $rows = $type
            ? $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE type = %s ORDER BY id DESC LIMIT %d", $type, $limit), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare("SELECT * FROM $t ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
        return array_map([self::class, 'hydrate'], $rows ?: []);
    }

    public static function update(int $id, array $fields): void
    {
        global $wpdb;
        foreach (['input', 'output'] as $k) {
            if (isset($fields[$k]) && is_array($fields[$k])) {
                $fields[$k] = wp_json_encode($fields[$k]);
            }
        }
        $fields['updated_at'] = current_time('mysql', true);
        $wpdb->update(Install::table('jobs'), $fields, ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(Install::table('jobs'), ['id' => $id]);
    }

    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['input'] = json_decode((string) $row['input'], true) ?: [];
        $row['output'] = $row['output'] ? json_decode($row['output'], true) : null;
        return $row;
    }
}
