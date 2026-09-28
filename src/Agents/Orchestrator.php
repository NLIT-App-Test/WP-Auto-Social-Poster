<?php

namespace Admun\GrowthEngine\Agents;

use Admun\GrowthEngine\AI\Policy;
use Admun\GrowthEngine\Campaigns\Store;
use Admun\GrowthEngine\Jobs\Jobs;
use Admun\GrowthEngine\Jobs\Runner;
use Admun\GrowthEngine\SEO\SeoBridge;
use Admun\GrowthEngine\Social\Accounts;
use Admun\GrowthEngine\Social\Registry;
use Admun\GrowthEngine\Support\Log;
use Admun\GrowthEngine\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Main agent. Runs the campaign flow and is the only part that talks to the user:
 *
 *   goal → Planner (plan + checklist) → user approves plan
 *        → for each item, ahead of its date: Writer → Media → Reviewer (→ Writer again if it fails)
 *        → user approves the finished piece → published on its date to the site and every network.
 *
 * Item statuses: planned → queued → producing → awaiting_approval → scheduled → publishing → live
 * Side exits: changes requested → queued again; skipped; failed (production error, can be re-run);
 * partial / publish_failed (some or all networks failed, can be retried).
 */
class Orchestrator
{
    const MAX_REVISIONS = 2;

    /* ================================================================ user actions */

    public static function start(array $input): int
    {
        $id = Store::create_campaign($input);
        Jobs::enqueue('plan', ['campaign_id' => $id, 'start' => $input['start_date'], 'end' => $input['end_date']]);
        Log::info('campaign', "Campaign \"{$input['name']}\" created; planner is working.");
        return $id;
    }

    public static function approve_plan(int $campaign_id): void
    {
        $c = self::campaign($campaign_id);
        if ($c['status'] !== 'plan_review') {
            throw new \RuntimeException('This plan is not waiting for approval.');
        }
        Store::update_campaign($campaign_id, ['status' => 'active']);
        Log::info('campaign', "Plan approved for \"{$c['name']}\". Content is produced ahead of each date.");
        Runner::kick();
    }

    public static function replan(int $campaign_id, string $feedback): void
    {
        $c = self::campaign($campaign_id);
        $notes = trim($c['notes'] . "\nPlan feedback: " . $feedback);
        Store::update_campaign($campaign_id, ['status' => 'planning', 'notes' => $notes, 'error' => null]);
        Jobs::enqueue('plan', [
            'campaign_id' => $campaign_id,
            'start' => get_date_from_gmt($c['start_at'], 'Y-m-d'),
            'end' => get_date_from_gmt($c['end_at'], 'Y-m-d'),
        ]);
    }

    public static function set_paused(int $campaign_id, bool $paused): void
    {
        self::campaign($campaign_id);
        Store::update_campaign($campaign_id, ['status' => $paused ? 'paused' : 'active']);
    }

    /** Approve a finished piece. Optional edits to the social texts are saved first. */
    public static function approve_item(int $item_id, array $social_edits = []): void
    {
        $item = self::item($item_id);
        if ($item['status'] !== 'awaiting_approval') {
            throw new \RuntimeException('This item is not waiting for approval.');
        }
        if ($social_edits) {
            $social = $item['social'];
            foreach ($social_edits as $platform => $text) {
                if (isset($social['texts'][$platform])) {
                    $social['texts'][$platform] = sanitize_textarea_field($text);
                }
            }
            Store::update_item($item_id, ['social' => $social]);
        }
        Store::update_item($item_id, ['status' => 'scheduled']);
        self::step($item_id, 'You', 'approved');
        Runner::kick();
    }

    public static function request_changes(int $item_id, string $feedback, bool $redo_image): void
    {
        $item = self::item($item_id);
        if (!in_array($item['status'], ['awaiting_approval', 'failed'], true)) {
            throw new \RuntimeException('Changes can only be requested before the piece is approved.');
        }
        Store::update_item($item_id, ['status' => 'queued', 'feedback' => wp_json_encode(['text' => $feedback, 'redo_image' => $redo_image])]);
        self::step($item_id, 'You', 'requested changes: ' . $feedback);
        Jobs::enqueue('produce', ['item_id' => $item_id]);
    }

    public static function skip_item(int $item_id): void
    {
        $item = self::item($item_id);
        if (in_array($item['status'], ['live', 'publishing'], true)) {
            throw new \RuntimeException('This item is already published.');
        }
        Store::update_item($item_id, ['status' => 'skipped']);
        self::step($item_id, 'You', 'skipped');
    }

    /** Produce an item now instead of waiting for the look-ahead window. */
    public static function produce_now(int $item_id): void
    {
        $item = self::item($item_id);
        if (!Store::claim_item($item_id, $item['status'] === 'failed' ? 'failed' : 'planned', 'queued')) {
            throw new \RuntimeException('This item is already in progress.');
        }
        Jobs::enqueue('produce', ['item_id' => $item_id]);
    }

    public static function retry_publish(int $item_id): void
    {
        $item = self::item($item_id);
        if (!in_array($item['status'], ['partial', 'publish_failed'], true) || !Store::claim_item($item_id, $item['status'], 'publishing')) {
            throw new \RuntimeException('Only failed publishes can be retried.');
        }
        self::publish(self::item($item_id));
    }

    /* ================================================================ background work */

    /** Called every worker tick: start production ahead of dates, publish what is due, close finished campaigns. */
    public static function tick(): void
    {
        // A production that died mid-way (host killed PHP) is surfaced instead of hanging forever.
        global $wpdb;
        $wpdb->query("UPDATE " . \Admun\GrowthEngine\Install::table('items') . " SET status = 'failed',
            error = 'Timed out while producing. Press Produce now to try again; if it repeats, raise PHP max_execution_time.'
            WHERE status = 'producing' AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE)");
        $ahead = gmdate('Y-m-d H:i:s', time() + max(1, (int) Settings::get('lookahead_days', 7)) * DAY_IN_SECONDS);
        foreach (Store::items_due('planned', $ahead, 5) as $item) {
            if (Store::claim_item($item['id'], 'planned', 'queued')) {
                Jobs::enqueue('produce', ['item_id' => $item['id']]);
            }
        }
        foreach (Store::items_due('scheduled', gmdate('Y-m-d H:i:s'), 5) as $item) {
            if (Store::claim_item($item['id'], 'scheduled', 'publishing')) {
                self::publish($item);
            }
        }
        foreach (Store::campaigns() as $c) {
            if ($c['status'] === 'active' && $c['counts'] && !array_diff(array_keys($c['counts']), ['live', 'skipped'])) {
                Store::update_campaign($c['id'], ['status' => 'completed']);
                Log::info('campaign', "Campaign \"{$c['name']}\" completed.");
            }
        }
    }

    public static function job_plan(array $input): array
    {
        $c = self::campaign((int) $input['campaign_id']);
        try {
            $r = Planner::run($c, $input['start'], $input['end']);
        } catch (\Throwable $e) {
            Store::update_campaign($c['id'], ['status' => 'plan_failed', 'error' => $e->getMessage()]);
            throw $e;
        }
        Store::replace_items($c['id'], $r['items']);
        Store::update_campaign($c['id'], ['plan' => $r['plan'], 'status' => 'plan_review', 'error' => null]);
        Log::info('campaign', sprintf('Planner finished "%s": %d checklist items. Waiting for your approval.', $c['name'], count($r['items'])));
        return ['items' => count($r['items'])];
    }

    /** Writer → Media → Reviewer, with up to MAX_REVISIONS rewrites when the reviewer finds issues. */
    public static function job_produce(array $input): array
    {
        $item = self::item((int) $input['item_id']);
        if (!Store::claim_item($item['id'], 'queued', 'producing')) {
            return ['skipped' => 'not queued'];
        }
        $campaign = self::campaign($item['campaign_id']);
        $platforms = self::platforms($campaign);

        try {
            $feedback = $item['feedback'] ? json_decode($item['feedback'], true) : null;
            $previous = $item['post_id'] ? get_post_meta($item['post_id'], '_age_draft', true) : null;
            $issues = $feedback ? ['The user asked for these changes (highest priority): ' . $feedback['text']] : [];

            self::step($item['id'], 'Content agent', $previous ? 'revising with your feedback' : 'writing article and social posts');
            $draft = Writer::run($campaign, $item, $platforms, $previous ?: null, $issues);
            $post_id = self::save_post($item, $draft);

            $image = ['id' => $item['image_id'] ?: 0, 'note' => 'kept existing image'];
            if (!$item['image_id'] || !empty($feedback['redo_image'])) {
                self::step($item['id'], 'Media agent', 'creating image');
                $image = Media::run($post_id, $draft['image_prompt'], $draft['image_query'], $draft['image_alt']);
                self::step($item['id'], 'Media agent', $image['note']);
            } elseif ($item['image_id']) {
                set_post_thumbnail($post_id, $item['image_id']);
            }

            $round = 0;
            while (true) {
                self::step($item['id'], 'Reviewer', 'checking goal match, SEO and accuracy');
                $review = Reviewer::run($campaign, $item, $draft, $image, $platforms);
                self::step($item['id'], 'Reviewer', ($review['pass'] ? 'passed' : 'found ' . count($review['issues']) . ' issue(s)')
                    . sprintf(' — goal match %s, quality %s, SEO %s', Policy::pct($review['goal_match']), Policy::pct($review['quality']), Policy::pct($review['seo'])));
                if ($review['pass'] || $round >= self::MAX_REVISIONS) {
                    break;
                }
                $round++;
                self::step($item['id'], 'Content agent', "revision $round: fixing reviewer issues");
                $draft = Writer::run($campaign, $item, $platforms, $draft, $review['issues']);
                self::save_post(array_merge($item, ['post_id' => $post_id]), $draft);
            }

            Store::update_item($item['id'], [
                'status' => 'awaiting_approval',
                'post_id' => $post_id,
                'image_id' => $image['id'] ?: null,
                'social' => ['texts' => $draft['social'], 'results' => []],
                'review' => $review,
                'rounds' => $item['rounds'] + $round + 1,
                'feedback' => null,
                'error' => null,
            ]);
            self::step($item['id'], 'Main agent', $review['pass']
                ? 'ready for your approval'
                : 'ready for your approval — reviewer still has concerns after ' . self::MAX_REVISIONS . ' revisions');
            return ['post_id' => $post_id, 'pass' => $review['pass']];
        } catch (\Throwable $e) {
            Store::update_item($item['id'], ['status' => 'failed', 'error' => $e->getMessage()]);
            self::step($item['id'], 'Main agent', 'failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /* ================================================================ publishing */

    private static function publish(array $item): void
    {
        $campaign = self::campaign($item['campaign_id']);
        $post_id = (int) $item['post_id'];

        if (get_post_status($post_id) !== 'publish') {
            $now = current_time('mysql');
            wp_update_post(['ID' => $post_id, 'post_status' => 'publish', 'post_date' => $now, 'post_date_gmt' => get_gmt_from_date($now)]);
        }
        $link = get_permalink($post_id);
        $image = get_the_post_thumbnail_url($post_id, 'full') ?: null;
        $social = $item['social'];
        $results = $social['results'] ?? [];

        foreach ($campaign['account_ids'] as $account_id) {
            if (!empty($results[$account_id]['ok']) || !empty($results[$account_id]['unconfirmed'])) {
                continue; // Already posted, or may have posted (never risk a duplicate).
            }
            $account = Accounts::get((int) $account_id);
            if (!$account || $account['status'] !== 'active') {
                $results[$account_id] = ['ok' => false, 'error' => 'account disconnected or paused', 'label' => $account['label'] ?? "#$account_id"];
                continue;
            }
            $p = Registry::get($account['platform']);
            $text = (string) ($social['texts'][$account['platform']] ?? '');
            $text = trim(str_replace('{link}', $link, $text));
            try {
                if ($p->maybe_refresh($account)) {
                    Accounts::update_credentials($account);
                }
                $r = $p->publish($account, $text, $p->link_in_text() ? null : $link, $image);
                $results[$account_id] = ['ok' => true, 'url' => $r['url'] ?? null, 'label' => $account['label'], 'platform' => $account['platform']];
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                // A read timeout means the network may have received the post; don't retry it automatically.
                $unconfirmed = stripos($msg, 'timed out') !== false && stripos($msg, 'connect') === false;
                $results[$account_id] = [
                    'ok' => false,
                    'unconfirmed' => $unconfirmed,
                    'error' => $unconfirmed ? 'No reply in time; it may have posted. Check the account before posting manually.' : $msg,
                    'label' => $account['label'],
                    'platform' => $account['platform'],
                ];
                Log::error('publish', "{$account['label']}: " . $e->getMessage());
            }
        }

        $failed = array_filter($results, static function ($r) {
            return empty($r['ok']);
        });
        $social['results'] = $results;
        $status = !$failed ? 'live' : (count($failed) < count($results) ? 'partial' : 'publish_failed');
        Store::update_item($item['id'], ['status' => $status, 'social' => $social, 'error' => $failed ? count($failed) . ' network(s) failed' : null]);
        self::step($item['id'], 'Main agent', $status === 'live' ? 'published to the site and ' . count($results) . ' network(s)' : "published with errors ($status)");
        Log::info('publish', sprintf('#%d "%s" → %s', $item['seq'], get_the_title($post_id), $status));
    }

    /* ================================================================ helpers */

    /** Create or update the WordPress draft for an item (published only on its date, after approval). */
    private static function save_post(array $item, array $d): int
    {
        $content = '<div class="age-quick-answer"><p><strong>' . esc_html__('Quick answer:', 'admun-growth-engine') . '</strong> ' . esc_html($d['quick_answer']) . "</p></div>\n\n" . $d['content_html'];
        if ($d['faq']) {
            $content .= "\n\n<h2>" . esc_html__('Frequently asked questions', 'admun-growth-engine') . "</h2>\n";
            foreach ($d['faq'] as $f) {
                $content .= '<h3>' . esc_html($f['question']) . '</h3><p>' . esc_html($f['answer']) . "</p>\n";
            }
        }
        $arr = [
            'post_title' => $d['title'],
            'post_name' => sanitize_title($d['slug']),
            'post_content' => wp_kses_post($content),
            'post_excerpt' => $d['excerpt'],
            'post_status' => 'draft',
            'post_type' => 'post',
            'tags_input' => $d['tags'],
        ];
        if (!empty($item['post_id']) && get_post($item['post_id'])) {
            $arr['ID'] = $item['post_id'];
            $id = wp_update_post($arr, true);
        } else {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            $arr['post_author'] = (int) ($admins[0] ?? 1);
            $id = wp_insert_post($arr, true);
        }
        if (is_wp_error($id)) {
            throw new \RuntimeException($id->get_error_message());
        }
        SeoBridge::set($id, $d['meta_title'], $d['meta_description'], $item['keyword']);
        update_post_meta($id, '_age_faq', $d['faq']);
        update_post_meta($id, '_age_howto', $d['howto']);
        update_post_meta($id, '_age_item', $item['id']);
        update_post_meta($id, '_age_draft', $d);
        Store::update_item($item['id'], ['post_id' => $id]);
        return (int) $id;
    }

    private static function platforms(array $campaign): array
    {
        $keys = [];
        foreach ($campaign['account_ids'] as $id) {
            $a = Accounts::get((int) $id);
            if ($a) {
                $keys[$a['platform']] = true;
            }
        }
        return array_keys($keys);
    }

    private static function step(int $item_id, string $agent, string $message): void
    {
        $item = Store::item($item_id);
        $steps = $item['steps'] ?: [];
        $steps[] = ['at' => time(), 'agent' => $agent, 'message' => $message];
        Store::update_item($item_id, ['steps' => array_slice($steps, -40)]);
    }

    private static function campaign(int $id): array
    {
        $c = Store::campaign($id);
        if (!$c) {
            throw new \RuntimeException('Campaign not found.');
        }
        return $c;
    }

    private static function item(int $id): array
    {
        $i = Store::item($id);
        if (!$i) {
            throw new \RuntimeException('Checklist item not found.');
        }
        return $i;
    }
}
