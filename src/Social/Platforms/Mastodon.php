<?php

namespace Admun\GrowthEngine\Social\Platforms;

use Admun\GrowthEngine\Social\Platform;
use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

class Mastodon extends Platform
{
    public function key(): string { return 'mastodon'; }
    public function label(): string { return 'Mastodon'; }
    public function char_limit(): int { return 500; }
    public function link_in_text(): bool { return true; }

    public function rules(): string
    {
        return 'Mastodon: at most 500 characters including the link, thoughtful and non-salesy, 1–3 CamelCase hashtags, link on its own line.';
    }

    public function manual_fields(): array
    {
        return [
            ['name' => 'instance', 'label' => 'Instance URL (e.g. https://mastodon.social)', 'type' => 'text'],
            ['name' => 'access_token', 'label' => 'Access token', 'type' => 'password',
                'help' => 'Preferences → Development → New application with write:statuses and write:media scopes.'],
        ];
    }

    public function connect_manual(array $f): array
    {
        $instance = untrailingslashit(esc_url_raw($f['instance']));
        $me = Http::get("$instance/api/v1/accounts/verify_credentials", $this->bearer($f['access_token']));
        return [[
            'label' => '@' . $me['acct'] . '@' . wp_parse_url($instance, PHP_URL_HOST),
            'external_id' => $me['id'],
            'credentials' => ['access_token' => $f['access_token']],
            'meta' => ['instance' => $instance],
        ]];
    }

    public function publish(array &$account, string $text, ?string $link, ?string $image): array
    {
        $instance = $account['meta']['instance'];
        $auth = $this->bearer($account['credentials']['access_token']);
        $body = ['status' => $text];
        if ($image) {
            $img = $this->image_bytes($image);
            $ct = '';
            $mp = $this->multipart([], ['file' => $img], $ct);
            $media = Http::post("$instance/api/v2/media", $mp, $auth + ['Content-Type' => $ct], ['timeout' => 90]);
            $body['media_ids'] = [$media['id']];
        }
        $res = Http::post("$instance/api/v1/statuses", $body, $auth + ['Idempotency-Key' => md5($text . $link)]);
        return ['id' => $res['id'], 'url' => $res['url'] ?? null];
    }
}
