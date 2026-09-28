<?php

namespace Admun\GrowthEngine\Social;

use Admun\GrowthEngine\Social\Platforms;

defined('ABSPATH') || exit;

class Registry
{
    /** @var Platform[]|null */
    private static $platforms;

    /** @return Platform[] keyed by platform key */
    public static function all(): array
    {
        if (self::$platforms === null) {
            self::$platforms = [];
            $list = [
                new Platforms\X(), new Platforms\LinkedIn(), new Platforms\Facebook(), new Platforms\Instagram(),
                new Platforms\Threads(), new Platforms\Pinterest(), new Platforms\Bluesky(), new Platforms\GoogleBusiness(),
                new Platforms\Telegram(), new Platforms\Discord(), new Platforms\Tumblr(), new Platforms\Mastodon(),
                new Platforms\Webhook(),
            ];
            foreach (apply_filters('age_platforms', $list) as $p) {
                self::$platforms[$p->key()] = $p;
            }
        }
        return self::$platforms;
    }

    public static function get(string $key): Platform
    {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new \RuntimeException("Unknown platform: $key");
        }
        return $all[$key];
    }

    /** Descriptions for the admin UI. */
    public static function describe(): array
    {
        $out = [];
        foreach (self::all() as $key => $p) {
            $oauth = $p->oauth();
            $out[] = [
                'key' => $key,
                'label' => $p->label(),
                'oauth' => $oauth && empty($oauth['via']),
                'via' => $oauth['via'] ?? null,
                'docs' => $oauth['docs'] ?? null,
                'scopes' => $oauth['scopes'] ?? [],
                'app_configured' => $oauth && empty($oauth['via']) ? OAuth::app_configured($key) : false,
                'manual_fields' => $p->manual_fields(),
                'needs_image' => $p->needs_image(),
                'char_limit' => $p->char_limit(),
            ];
        }
        return $out;
    }
}
