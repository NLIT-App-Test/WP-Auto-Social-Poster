<?php

namespace Admun\GrowthEngine\Social;

use Admun\GrowthEngine\Install;
use Admun\GrowthEngine\Support\Crypto;

defined('ABSPATH') || exit;

class Accounts
{
    /** Upsert by platform + external id so reconnecting refreshes tokens instead of duplicating. */
    public static function save(string $platform, array $a): int
    {
        global $wpdb;
        $t = Install::table('accounts');
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE platform = %s AND external_id = %s",
            $platform,
            (string) $a['external_id']
        ));
        $row = [
            'platform' => $platform,
            'label' => mb_substr((string) $a['label'], 0, 190),
            'external_id' => (string) $a['external_id'],
            'credentials' => Crypto::encrypt_array($a['credentials'] ?? []),
            'meta' => wp_json_encode($a['meta'] ?? []),
            'status' => 'active',
            'status_note' => null,
        ];
        if ($existing) {
            $wpdb->update($t, $row, ['id' => $existing]);
            return (int) $existing;
        }
        $row['created_at'] = current_time('mysql', true);
        $wpdb->insert($t, $row);
        return (int) $wpdb->insert_id;
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . Install::table('accounts') . " WHERE id = %d", $id), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    /** @return array[] accounts without credentials, for listing */
    public static function list(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id, platform, label, external_id, meta, status, status_note, created_at FROM "
            . Install::table('accounts') . " ORDER BY platform, label", ARRAY_A) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['meta'] = json_decode((string) $r['meta'], true) ?: [];
        }
        return $rows;
    }

    public static function update_credentials(array $account): void
    {
        global $wpdb;
        $wpdb->update(Install::table('accounts'), ['credentials' => Crypto::encrypt_array($account['credentials'])], ['id' => $account['id']]);
    }

    public static function set(int $id, array $fields): void
    {
        global $wpdb;
        $wpdb->update(Install::table('accounts'), $fields, ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(Install::table('accounts'), ['id' => $id]);
    }

    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['credentials'] = Crypto::decrypt_array((string) $row['credentials']);
        $row['meta'] = json_decode((string) $row['meta'], true) ?: [];
        return $row;
    }
}
