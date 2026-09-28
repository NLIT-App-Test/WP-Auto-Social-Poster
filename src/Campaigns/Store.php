<?php

namespace Admun\GrowthEngine\Campaigns;

use Admun\GrowthEngine\Install;

defined('ABSPATH') || exit;

/** Campaigns (the user's goal) and their checklist items. */
class Store
{
    /* ---------------------------------------------------------------- campaigns */

    public static function create_campaign(array $c): int
    {
        global $wpdb;
        $wpdb->insert(Install::table('campaigns'), [
            'name' => mb_substr($c['name'], 0, 190),
            'topic' => $c['topic'],
            'goal' => $c['goal'],
            'audience' => $c['audience'] ?? '',
            'notes' => $c['notes'] ?? '',
            'start_at' => $c['start_at'],
            'end_at' => $c['end_at'],
            'per_week' => $c['per_week'],
            'post_time' => $c['post_time'],
            'account_ids' => wp_json_encode(array_values(array_map('intval', $c['account_ids']))),
            'status' => 'planning',
            'created_at' => current_time('mysql', true),
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function campaign(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Install::table('campaigns') . ' WHERE id = %d', $id), ARRAY_A);
        return $row ? self::hydrate_campaign($row) : null;
    }

    public static function campaigns(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . Install::table('campaigns') . ' ORDER BY id DESC', ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $c = self::hydrate_campaign($r);
            $c['counts'] = self::counts($c['id']);
            $out[] = $c;
        }
        return $out;
    }

    public static function update_campaign(int $id, array $fields): void
    {
        global $wpdb;
        if (isset($fields['plan']) && is_array($fields['plan'])) {
            $fields['plan'] = wp_json_encode($fields['plan']);
        }
        $wpdb->update(Install::table('campaigns'), $fields, ['id' => $id]);
    }

    public static function delete_campaign(int $id): void
    {
        global $wpdb;
        $wpdb->delete(Install::table('items'), ['campaign_id' => $id]);
        $wpdb->delete(Install::table('campaigns'), ['id' => $id]);
    }

    private static function hydrate_campaign(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['per_week'] = (int) $r['per_week'];
        $r['account_ids'] = json_decode((string) $r['account_ids'], true) ?: [];
        $r['plan'] = $r['plan'] ? json_decode($r['plan'], true) : null;
        return $r;
    }

    private static function counts(int $campaign_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT status, COUNT(*) n FROM ' . Install::table('items') . ' WHERE campaign_id = %d GROUP BY status',
            $campaign_id
        ), ARRAY_A) ?: [];
        return array_map('intval', wp_list_pluck($rows, 'n', 'status'));
    }

    /* ---------------------------------------------------------------- items */

    public static function replace_items(int $campaign_id, array $items): void
    {
        global $wpdb;
        $t = Install::table('items');
        $wpdb->delete($t, ['campaign_id' => $campaign_id]);
        foreach ($items as $i => $it) {
            $wpdb->insert($t, [
                'campaign_id' => $campaign_id,
                'seq' => $i + 1,
                'publish_at' => $it['publish_at'],
                'title' => mb_substr($it['title'], 0, 250),
                'keyword' => mb_substr($it['keyword'], 0, 190),
                'objective' => $it['objective'],
                'angle' => $it['angle'],
                'status' => 'planned',
                'updated_at' => current_time('mysql', true),
            ]);
        }
    }

    public static function item(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Install::table('items') . ' WHERE id = %d', $id), ARRAY_A);
        return $row ? self::hydrate_item($row) : null;
    }

    public static function items(int $campaign_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Install::table('items') . ' WHERE campaign_id = %d ORDER BY seq',
            $campaign_id
        ), ARRAY_A) ?: [];
        return array_map([self::class, 'hydrate_item'], $rows);
    }

    /** Items in a given status whose date is before $before (UTC mysql datetime). */
    public static function items_due(string $status, string $before, int $limit = 10): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT i.* FROM ' . Install::table('items') . ' i JOIN ' . Install::table('campaigns') . " c ON c.id = i.campaign_id
             WHERE c.status = 'active' AND i.status = %s AND i.publish_at <= %s ORDER BY i.publish_at LIMIT %d",
            $status,
            $before,
            $limit
        ), ARRAY_A) ?: [];
        return array_map([self::class, 'hydrate_item'], $rows);
    }

    public static function update_item(int $id, array $fields): void
    {
        global $wpdb;
        foreach (['social', 'review', 'steps'] as $k) {
            if (isset($fields[$k]) && is_array($fields[$k])) {
                $fields[$k] = wp_json_encode($fields[$k]);
            }
        }
        $fields['updated_at'] = current_time('mysql', true);
        $wpdb->update(Install::table('items'), $fields, ['id' => $id]);
    }

    /** Atomically move an item from one status to another; false if someone else already moved it. */
    public static function claim_item(int $id, string $from, string $to): bool
    {
        global $wpdb;
        return (bool) $wpdb->update(Install::table('items'), ['status' => $to, 'updated_at' => current_time('mysql', true)], ['id' => $id, 'status' => $from]);
    }

    private static function hydrate_item(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['campaign_id'] = (int) $r['campaign_id'];
        $r['seq'] = (int) $r['seq'];
        $r['post_id'] = $r['post_id'] ? (int) $r['post_id'] : null;
        $r['image_id'] = $r['image_id'] ? (int) $r['image_id'] : null;
        $r['rounds'] = (int) $r['rounds'];
        foreach (['social', 'review', 'steps'] as $k) {
            $r[$k] = $r[$k] ? json_decode($r[$k], true) : null;
        }
        return $r;
    }

    /* ---------------------------------------------------------------- scheduling */

    /**
     * Posting dates for a campaign: $per_week posts spread evenly across each week between start and end,
     * at the chosen local time. Returned as UTC mysql datetimes.
     */
    public static function schedule(string $start_local_date, string $end_local_date, int $per_week, string $time): array
    {
        $tz = wp_timezone();
        $start = new \DateTimeImmutable($start_local_date . ' ' . $time, $tz);
        $end = new \DateTimeImmutable($end_local_date . ' 23:59', $tz);
        $per_week = max(1, min(14, $per_week));
        $dates = [];
        for ($week = $start; $week <= $end; $week = $week->modify('+7 days')) {
            for ($i = 0; $i < $per_week; $i++) {
                // Spread across the week: 1/week → day 0, 3/week → days 0, 2, 4, 7/week → daily.
                $offset_minutes = (int) round($i * 7 * 24 * 60 / $per_week);
                $d = $week->modify("+$offset_minutes minutes");
                $d = $d->setTime((int) substr($time, 0, 2), (int) substr($time, 3, 2));
                // Never schedule into the past (e.g. today's posting time has already gone by).
                if ($d <= $end && $d->getTimestamp() > time() + HOUR_IN_SECONDS) {
                    $dates[] = $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
            }
        }
        return array_values(array_unique($dates));
    }
}
