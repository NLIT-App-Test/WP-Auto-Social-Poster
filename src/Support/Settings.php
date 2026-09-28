<?php

namespace Admun\GrowthEngine\Support;

defined('ABSPATH') || exit;

class Settings
{
    const OPTION = 'age_settings';

    /** Keys stored encrypted and never sent back to the browser in full. */
    const SECRETS = ['anthropic_key', 'typesafe_key', 'openai_key', 'pexels_key'];

    public static function defaults(): array
    {
        return [
            'anthropic_key' => '',
            'typesafe_key' => '',
            'openai_key' => '',
            'pexels_key' => '',
            'claude_model' => 'claude-opus-5',
            'brand' => [
                'name' => get_bloginfo('name'),
                'product' => get_bloginfo('description'),
                'voice' => 'Clear, confident and helpful. Short sentences. No hype words.',
                'forbidden' => 'Competitor names, politics, guaranteed results, medical or legal advice',
                'cta_url' => home_url('/'),
                'language' => 'English',
            ],
            'words' => 1200,
            // Reviewer bar: how well the content must match the goal and SEO standard to pass.
            'review_bar' => 0.75,
            // How far ahead content is produced, so it waits for your approval before its date.
            'lookahead_days' => 7,
        ];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        $s = self::merge(self::defaults(), is_array($saved) ? $saved : []);
        foreach (self::SECRETS as $k) {
            $s[$k] = Crypto::decrypt((string) $s[$k]);
        }
        return $s;
    }

    public static function get(string $path, $default = null)
    {
        $value = self::all();
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** Merge a partial update. Empty secret fields keep the stored value; "-" clears it. */
    public static function update(array $patch): array
    {
        $current = get_option(self::OPTION, []);
        $current = is_array($current) ? $current : [];
        foreach (self::SECRETS as $k) {
            if (!array_key_exists($k, $patch)) {
                continue;
            }
            $v = trim((string) $patch[$k]);
            if ($v === '' || strpos($v, '••') !== false) {
                unset($patch[$k]);
            } elseif ($v === '-') {
                $patch[$k] = '';
            } else {
                $patch[$k] = Crypto::encrypt($v);
            }
        }
        update_option(self::OPTION, self::merge($current, $patch), false);
        return self::all();
    }

    /** Settings safe for the browser: secrets are masked. */
    public static function public(): array
    {
        $s = self::all();
        foreach (self::SECRETS as $k) {
            $s[$k] = $s[$k] === '' ? '' : '••••' . substr($s[$k], -4);
        }
        return $s;
    }

    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }
}
