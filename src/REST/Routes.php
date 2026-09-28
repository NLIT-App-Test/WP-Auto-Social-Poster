<?php

namespace Admun\GrowthEngine\REST;

use Admun\GrowthEngine\Agents\Orchestrator;
use Admun\GrowthEngine\AI\Claude;
use Admun\GrowthEngine\AI\Jev;
use Admun\GrowthEngine\Campaigns\Store;
use Admun\GrowthEngine\Jobs\Runner;
use Admun\GrowthEngine\Social\Accounts;
use Admun\GrowthEngine\Social\OAuth;
use Admun\GrowthEngine\Social\Registry;
use Admun\GrowthEngine\Support\Log;
use Admun\GrowthEngine\Support\Settings;
use WP_REST_Request as Req;

defined('ABSPATH') || exit;

class Routes
{
    const NS = 'age/v1';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        $admin = static function () {
            return current_user_can('manage_options');
        };
        $r = static function (string $path, string $methods, callable $cb) use ($admin) {
            register_rest_route(self::NS, $path, [
                'methods' => $methods,
                'permission_callback' => $admin,
                'callback' => static function (Req $req) use ($cb) {
                    try {
                        return rest_ensure_response($cb($req));
                    } catch (\Throwable $e) {
                        return new \WP_Error('age_error', $e->getMessage(), ['status' => 400]);
                    }
                },
            ]);
        };

        /* ---------------- campaigns */
        $r('/campaigns', 'GET', static function () {
            return Store::campaigns();
        });
        $r('/campaigns', 'POST', static function (Req $q) {
            if (!Claude::configured() || !Jev::configured()) {
                throw new \RuntimeException('Add your Anthropic and TypeSafe keys in Settings first.');
            }
            $topic = trim(sanitize_textarea_field((string) $q['topic']));
            $goal = trim(sanitize_textarea_field((string) $q['goal']));
            $start = sanitize_text_field((string) $q['start_date']);
            $end = sanitize_text_field((string) $q['end_date']);
            $accounts = array_values(array_filter(array_map('intval', (array) $q['account_ids'])));
            if ($topic === '' || $goal === '') {
                throw new \RuntimeException('Tell us the topic and the goal.');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) {
                throw new \RuntimeException('Choose a valid start and end date.');
            }
            if (!$accounts) {
                throw new \RuntimeException('Choose at least one social account.');
            }
            $time = preg_match('/^\d{2}:\d{2}$/', (string) $q['post_time']) ? $q['post_time'] : '09:00';
            $tz = wp_timezone();
            return ['id' => Orchestrator::start([
                'name' => sanitize_text_field((string) $q['name']) ?: wp_trim_words($topic, 8, ''),
                'topic' => $topic,
                'goal' => $goal,
                'audience' => sanitize_textarea_field((string) $q['audience']),
                'notes' => sanitize_textarea_field((string) $q['notes']),
                'start_date' => $start,
                'end_date' => $end,
                'start_at' => (new \DateTimeImmutable("$start 00:00", $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'end_at' => (new \DateTimeImmutable("$end 23:59", $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'per_week' => max(1, min(14, (int) $q['per_week'])),
                'post_time' => $time,
                'account_ids' => $accounts,
            ])];
        });
        $r('/campaigns/(?P<id>\d+)', 'GET', static function (Req $q) {
            $c = Store::campaign((int) $q['id']);
            if (!$c) {
                throw new \RuntimeException('Campaign not found.');
            }
            $c['items'] = array_map([self::class, 'item_view'], Store::items($c['id']));
            $c['accounts'] = array_values(array_filter(Accounts::list(), static function ($a) use ($c) {
                return in_array($a['id'], $c['account_ids'], true);
            }));
            return $c;
        });
        $r('/campaigns/(?P<id>\d+)', 'DELETE', static function (Req $q) {
            Store::delete_campaign((int) $q['id']);
            return ['ok' => true];
        });
        $r('/campaigns/(?P<id>\d+)/approve', 'POST', static function (Req $q) {
            Orchestrator::approve_plan((int) $q['id']);
            return ['ok' => true];
        });
        $r('/campaigns/(?P<id>\d+)/replan', 'POST', static function (Req $q) {
            Orchestrator::replan((int) $q['id'], sanitize_textarea_field((string) $q['feedback']));
            return ['ok' => true];
        });
        $r('/campaigns/(?P<id>\d+)/pause', 'POST', static function (Req $q) {
            Orchestrator::set_paused((int) $q['id'], (bool) $q['paused']);
            return ['ok' => true];
        });

        /* ---------------- checklist items */
        $r('/items/(?P<id>\d+)/approve', 'POST', static function (Req $q) {
            Orchestrator::approve_item((int) $q['id'], (array) ($q['social'] ?? []));
            return ['ok' => true];
        });
        $r('/items/(?P<id>\d+)/changes', 'POST', static function (Req $q) {
            $feedback = trim(sanitize_textarea_field((string) $q['feedback']));
            if ($feedback === '') {
                throw new \RuntimeException('Say what should change.');
            }
            Orchestrator::request_changes((int) $q['id'], $feedback, (bool) $q['redo_image']);
            return ['ok' => true];
        });
        $r('/items/(?P<id>\d+)/skip', 'POST', static function (Req $q) {
            Orchestrator::skip_item((int) $q['id']);
            return ['ok' => true];
        });
        $r('/items/(?P<id>\d+)/produce', 'POST', static function (Req $q) {
            Orchestrator::produce_now((int) $q['id']);
            return ['ok' => true];
        });
        $r('/items/(?P<id>\d+)/retry', 'POST', static function (Req $q) {
            Orchestrator::retry_publish((int) $q['id']);
            return ['ok' => true];
        });

        /* ---------------- settings + connections */
        $r('/settings', 'GET', static function () {
            return [
                'settings' => Settings::public(),
                'claude' => Claude::configured(),
                'jev' => Jev::configured(),
                'last_tick' => (int) get_option('age_last_tick', 0),
                'now' => time(),
            ];
        });
        $r('/settings', 'POST', static function (Req $q) {
            Settings::update((array) $q->get_json_params());
            return Settings::public();
        });
        $r('/test', 'POST', [self::class, 'test_keys']);
        $r('/platforms', 'GET', static function () {
            return ['platforms' => Registry::describe(), 'redirect_uri' => OAuth::redirect_uri()];
        });
        $r('/apps/(?P<platform>[a-z]+)', 'POST', static function (Req $q) {
            OAuth::save_app($q['platform'], (string) $q['client_id'], (string) $q['client_secret']);
            return ['ok' => true];
        });
        $r('/oauth/(?P<platform>[a-z]+)', 'POST', static function (Req $q) {
            return ['url' => OAuth::authorize_url($q['platform'])];
        });
        $r('/accounts', 'GET', static function () {
            return Accounts::list();
        });
        $r('/accounts/manual', 'POST', static function (Req $q) {
            $platform = sanitize_key((string) $q['platform']);
            $fields = array_map(static function ($v) {
                return trim(sanitize_text_field((string) $v));
            }, (array) $q['fields']);
            foreach (Registry::get($platform)->connect_manual($fields) as $a) {
                Accounts::save($a['platform'] ?? $platform, $a);
            }
            Log::info('social', "Connected a $platform account.");
            return Accounts::list();
        });
        $r('/accounts/(?P<id>\d+)', 'DELETE', static function (Req $q) {
            Accounts::delete((int) $q['id']);
            return Accounts::list();
        });
        $r('/run', 'POST', static function () {
            Runner::kick();
            return ['ok' => true];
        });
        $r('/log', 'GET', static function () {
            return Log::recent(100);
        });
    }

    /** What the UI needs for one checklist item. */
    public static function item_view(array $i): array
    {
        $review = $i['review'];
        return [
            'id' => $i['id'],
            'seq' => $i['seq'],
            'publish_at' => $i['publish_at'],
            'title' => $i['post_id'] ? get_the_title($i['post_id']) : $i['title'],
            'planned_title' => $i['title'],
            'keyword' => $i['keyword'],
            'objective' => $i['objective'],
            'angle' => $i['angle'],
            'status' => $i['status'],
            'error' => $i['error'],
            'rounds' => $i['rounds'],
            'steps' => $i['steps'] ?: [],
            'post' => $i['post_id'] ? [
                'id' => $i['post_id'],
                'preview' => get_preview_post_link($i['post_id']) ?: get_permalink($i['post_id']),
                'url' => get_post_status($i['post_id']) === 'publish' ? get_permalink($i['post_id']) : null,
                'edit' => admin_url('post.php?post=' . $i['post_id'] . '&action=edit'),
                'excerpt' => get_the_excerpt($i['post_id']),
                'words' => str_word_count(wp_strip_all_tags((string) get_post_field('post_content', $i['post_id']))),
            ] : null,
            'image' => $i['image_id'] ? wp_get_attachment_image_url($i['image_id'], 'medium_large') : null,
            'social' => $i['social'],
            'review' => $review ? [
                'pass' => $review['pass'],
                'quality' => $review['quality'],
                'goal_match' => $review['goal_match'],
                'seo' => $review['seo'],
                'risk' => $review['risk'],
                'issues' => $review['issues'],
                'image' => $review['image'],
                'scores' => $review['article']['scores'],
                'social' => array_map(static function ($s) {
                    return ['score' => $s['score'], 'ok' => $s['ok'], 'length' => $s['length'], 'limit' => $s['limit']];
                }, $review['social']),
            ] : null,
        ];
    }

    public static function test_keys(): array
    {
        $out = [];
        try {
            Claude::json('Reply with the requested JSON.', 'Say ok.', [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['ok'], 'properties' => ['ok' => ['type' => 'boolean']],
            ], ['max_tokens' => 1024, 'effort' => 'low', 'timeout' => 60]);
            $out['claude'] = 'ok';
        } catch (\Throwable $e) {
            $out['claude'] = $e->getMessage();
        }
        try {
            $r = Jev::ask('The sky is blue.', ['t' => Jev::noul('Is this statement about the sky?')]);
            $out['jev'] = isset($r['answers']['t']['noul']) ? 'ok' : 'unexpected response';
        } catch (\Throwable $e) {
            $out['jev'] = $e->getMessage();
        }
        return $out;
    }
}
