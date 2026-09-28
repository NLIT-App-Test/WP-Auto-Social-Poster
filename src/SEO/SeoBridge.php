<?php

namespace Admun\GrowthEngine\SEO;

defined('ABSPATH') || exit;

/**
 * Writes title / description / focus keyword into whichever SEO plugin is active.
 * Storage details (meta keys, AIOSEO's custom table) adapted from AI Puffer (GPL-2.0+).
 * With no SEO plugin, values are kept in our own meta and printed by Frontend.
 */
class SeoBridge
{
    public static function active(): string
    {
        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }
        if (defined('RANK_MATH_VERSION')) {
            return 'rank_math';
        }
        if (defined('AIOSEO_VERSION')) {
            return 'aioseo';
        }
        if (defined('THE_SEO_FRAMEWORK_VERSION')) {
            return 'seo_framework';
        }
        return 'none';
    }

    public static function label(): string
    {
        return [
            'yoast' => 'Yoast SEO',
            'rank_math' => 'Rank Math',
            'aioseo' => 'All in One SEO',
            'seo_framework' => 'The SEO Framework',
            'none' => 'Growth Engine (built-in)',
        ][self::active()];
    }

    public static function set(int $post_id, string $title, string $description, string $keyword): void
    {
        $title = sanitize_text_field($title);
        $description = sanitize_text_field($description);
        $keyword = sanitize_text_field($keyword);

        // Always keep our own copy so audits and social posts can read it regardless of plugin.
        update_post_meta($post_id, '_age_meta_title', $title);
        update_post_meta($post_id, '_age_meta_description', $description);
        update_post_meta($post_id, '_age_focus_keyword', $keyword);

        switch (self::active()) {
            case 'yoast':
                self::put($post_id, '_yoast_wpseo_title', $title);
                self::put($post_id, '_yoast_wpseo_metadesc', $description);
                self::put($post_id, '_yoast_wpseo_focuskw', $keyword);
                break;
            case 'rank_math':
                self::put($post_id, 'rank_math_title', $title);
                self::put($post_id, 'rank_math_description', $description);
                self::put($post_id, 'rank_math_focus_keyword', $keyword);
                break;
            case 'aioseo':
                self::aioseo($post_id, $title, $description, $keyword);
                break;
            case 'seo_framework':
                self::put($post_id, '_genesis_title', $title);
                self::put($post_id, '_genesis_description', $description);
                break;
        }
    }

    public static function focus_keyword(int $post_id): string
    {
        foreach (['_age_focus_keyword', '_yoast_wpseo_focuskw', 'rank_math_focus_keyword'] as $key) {
            $v = (string) get_post_meta($post_id, $key, true);
            if ($v !== '') {
                return explode(',', $v)[0];
            }
        }
        $aio = get_post_meta($post_id, '_aioseo_keywords', true);
        if (is_string($aio)) {
            $aio = json_decode($aio, true);
        }
        return (string) ($aio['focus']['keyphrase'] ?? '');
    }

    public static function meta_description(int $post_id): string
    {
        foreach (['_age_meta_description', '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description', '_genesis_description'] as $key) {
            $v = (string) get_post_meta($post_id, $key, true);
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    public static function meta_title(int $post_id): string
    {
        foreach (['_age_meta_title', '_yoast_wpseo_title', 'rank_math_title', '_aioseo_title', '_genesis_title'] as $key) {
            $v = (string) get_post_meta($post_id, $key, true);
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    private static function put(int $post_id, string $key, string $value): void
    {
        if ($value !== '') {
            update_post_meta($post_id, $key, $value);
        }
    }

    private static function aioseo(int $post_id, string $title, string $description, string $keyword): void
    {
        global $wpdb;
        $keyphrases = ['focus' => ['keyphrase' => $keyword, 'analysis' => new \stdClass()], 'additional' => []];
        self::put($post_id, '_aioseo_title', $title);
        self::put($post_id, '_aioseo_description', $description);
        update_post_meta($post_id, '_aioseo_keywords', $keyphrases);

        $table = $wpdb->prefix . 'aioseo_posts';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        $now = current_time('mysql', true);
        $row = ['title' => $title, 'description' => $description, 'keyphrases' => wp_json_encode($keyphrases), 'updated' => $now];
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE post_id = %d", $post_id));
        if ($existing) {
            $wpdb->update($table, $row, ['post_id' => $post_id]);
        } else {
            $wpdb->insert($table, $row + ['post_id' => $post_id, 'created' => $now]);
        }
    }
}
