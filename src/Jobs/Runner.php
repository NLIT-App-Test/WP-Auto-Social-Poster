<?php

namespace Admun\GrowthEngine\Jobs;

use Admun\GrowthEngine\Agents\Orchestrator;
use Admun\GrowthEngine\Install;
use Admun\GrowthEngine\Support\Log;

defined('ABSPATH') || exit;

/**
 * Background worker. AI calls can take minutes, so they never run inside an admin page request:
 * WP-Cron ticks every minute, and enqueueing a job also fires a non-blocking loopback "kick".
 */
class Runner
{
    const LOCK = 'age_runner_lock';
    const BUDGET_SECONDS = 240;

    public static function register(): void
    {
        add_action('wp_ajax_nopriv_age_kick', [self::class, 'handle_kick']);
        add_action('wp_ajax_age_kick', [self::class, 'handle_kick']);
    }

    /** Fire-and-forget request that runs a tick in its own PHP process. */
    public static function kick(): void
    {
        $token = wp_generate_password(20, false);
        set_transient('age_kick_' . $token, 1, MINUTE_IN_SECONDS);
        wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body' => ['action' => 'age_kick', 'token' => $token],
        ]);
    }

    public static function handle_kick(): void
    {
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        if ($token === '' || !get_transient('age_kick_' . $token)) {
            wp_die('', '', ['response' => 403]);
        }
        delete_transient('age_kick_' . $token);
        ignore_user_abort(true);
        self::tick();
        wp_die();
    }

    public static function tick(): void
    {
        if (get_transient(self::LOCK)) {
            return;
        }
        set_transient(self::LOCK, 1, self::BUDGET_SECONDS + 120);
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::BUDGET_SECONDS + 300);
        }
        $started = time();
        update_option('age_last_tick', $started, false);
        try {
            Orchestrator::tick();
            self::recover_stuck();
            while (time() - $started < self::BUDGET_SECONDS && ($job = self::claim())) {
                self::run($job);
                Orchestrator::tick();
            }
        } finally {
            delete_transient(self::LOCK);
        }
    }

    private static function claim(): ?array
    {
        global $wpdb;
        $t = Install::table('jobs');
        $id = $wpdb->get_var("SELECT id FROM $t WHERE status = 'queued' AND run_after <= UTC_TIMESTAMP() ORDER BY run_after, id LIMIT 1");
        if (!$id) {
            return null;
        }
        $ok = $wpdb->update($t, ['status' => 'running', 'updated_at' => current_time('mysql', true)], ['id' => $id, 'status' => 'queued']);
        return $ok ? Jobs::get((int) $id) : null;
    }

    /** Jobs left "running" by a crashed or timed-out process go back to the queue (twice at most). */
    private static function recover_stuck(): void
    {
        global $wpdb;
        $t = Install::table('jobs');
        $wpdb->query("UPDATE $t SET status = 'queued', attempts = attempts + 1 WHERE status = 'running' AND attempts < 2 AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)");
        $wpdb->query("UPDATE $t SET status = 'failed', error = 'Timed out. Your host may stop long PHP requests; raise max_execution_time or lower the article length.' WHERE status = 'running' AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)");
    }

    public static function run(array $job): void
    {
        try {
            switch ($job['type']) {
                case 'plan':
                    $out = Orchestrator::job_plan($job['input']);
                    break;
                case 'produce':
                    $out = Orchestrator::job_produce($job['input']);
                    break;
                default:
                    throw new \RuntimeException('Unknown job type ' . $job['type']);
            }
            Jobs::update($job['id'], ['status' => 'done', 'output' => $out, 'error' => null]);
        } catch (\Throwable $e) {
            Jobs::update($job['id'], ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            Log::error($job['type'], "Job #{$job['id']} failed: " . $e->getMessage());
        }
    }
}
