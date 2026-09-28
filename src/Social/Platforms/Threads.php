<?php

namespace Admun\GrowthEngine\Social\Platforms;

use Admun\GrowthEngine\Social\OAuth;
use Admun\GrowthEngine\Social\Platform;
use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

class Threads extends Platform
{
    const API = 'https://graph.threads.net/v1.0/';

    public function key(): string { return 'threads'; }
    public function label(): string { return 'Threads'; }
    public function char_limit(): int { return 500; }

    public function rules(): string
    {
        return 'Threads: at most 500 characters, conversational and opinionated, 0–1 hashtag. The link is attached separately, so do not paste the URL.';
    }

    public function oauth(): ?array
    {
        return [
            'authorize_url' => 'https://threads.net/oauth/authorize',
            'token_url' => 'https://graph.threads.net/oauth/access_token',
            'scopes' => ['threads_basic', 'threads_content_publish'],
            'scope_sep' => ',',
            'docs' => 'https://developers.facebook.com/apps',
        ];
    }

    public function discover(array $token): array
    {
        $app = OAuth::app('threads');
        $long = Http::get('https://graph.threads.net/access_token?' . http_build_query([
            'grant_type' => 'th_exchange_token',
            'client_secret' => $app['client_secret'],
            'access_token' => $token['access_token'],
        ]));
        $me = Http::get(self::API . 'me?' . http_build_query(['fields' => 'id,username', 'access_token' => $long['access_token']]));
        return [[
            'label' => '@' . $me['username'],
            'external_id' => $me['id'],
            'credentials' => ['access_token' => $long['access_token'], 'expires_at' => time() + (int) ($long['expires_in'] ?? 5184000)],
            'meta' => ['username' => $me['username']],
        ]];
    }

    public function maybe_refresh(array &$account): bool
    {
        $c = &$account['credentials'];
        // Long-lived tokens last 60 days; renew when fewer than 7 remain.
        if (empty($c['expires_at']) || $c['expires_at'] > time() + 7 * DAY_IN_SECONDS) {
            return false;
        }
        $t = Http::get('https://graph.threads.net/refresh_access_token?' . http_build_query([
            'grant_type' => 'th_refresh_token',
            'access_token' => $c['access_token'],
        ]));
        $c['access_token'] = $t['access_token'];
        $c['expires_at'] = time() + (int) ($t['expires_in'] ?? 5184000);
        return true;
    }

    public function publish(array &$account, string $text, ?string $link, ?string $image): array
    {
        $uid = $account['external_id'];
        $token = $account['credentials']['access_token'];
        $params = ['text' => $text, 'access_token' => $token];
        if ($image) {
            $params += ['media_type' => 'IMAGE', 'image_url' => $image];
        } else {
            $params['media_type'] = 'TEXT';
            if ($link) {
                $params['link_attachment'] = $link;
            }
        }
        $container = Http::post(self::API . "$uid/threads?" . http_build_query($params));
        for ($i = 0; $i < 20; $i++) {
            $s = Http::get(self::API . $container['id'] . '?' . http_build_query(['fields' => 'status,error_message', 'access_token' => $token]));
            if (in_array($s['status'] ?? '', ['FINISHED', 'PUBLISHED'], true)) {
                break;
            }
            if (in_array($s['status'] ?? '', ['ERROR', 'EXPIRED'], true)) {
                throw new \RuntimeException('Threads: ' . ($s['error_message'] ?? 'container failed'));
            }
            sleep(3);
        }
        $post = Http::post(self::API . "$uid/threads_publish?" . http_build_query(['creation_id' => $container['id'], 'access_token' => $token]));
        $info = Http::get(self::API . $post['id'] . '?' . http_build_query(['fields' => 'permalink', 'access_token' => $token]));
        return ['id' => $post['id'], 'url' => $info['permalink'] ?? null];
    }
}
