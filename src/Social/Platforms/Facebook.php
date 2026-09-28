<?php

namespace Admun\GrowthEngine\Social\Platforms;

use Admun\GrowthEngine\Social\OAuth;
use Admun\GrowthEngine\Social\Platform;
use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

/**
 * Facebook Pages. One Meta login also discovers the Instagram business accounts linked to those
 * pages, which are saved as Instagram accounts.
 */
class Facebook extends Platform
{
    const GRAPH = 'https://graph.facebook.com/v23.0/';

    public function key(): string { return 'facebook'; }
    public function label(): string { return 'Facebook Page'; }
    public function char_limit(): int { return 63206; }

    public function rules(): string
    {
        return 'Facebook Page: 80–400 characters, conversational, invites comments, 0–3 hashtags. The link is attached as a preview card, so do not paste the URL.';
    }

    public function oauth(): ?array
    {
        return [
            'authorize_url' => 'https://www.facebook.com/v23.0/dialog/oauth',
            'token_url' => self::GRAPH . 'oauth/access_token',
            'token_method' => 'GET',
            'scopes' => ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement', 'business_management', 'instagram_basic', 'instagram_content_publish'],
            'scope_sep' => ',',
            'docs' => 'https://developers.facebook.com/apps',
            'also_creates' => ['instagram'],
        ];
    }

    public function discover(array $token): array
    {
        $app = OAuth::app('facebook');
        // Exchange for a long-lived user token so the page tokens derived from it do not expire.
        $long = Http::get(self::GRAPH . 'oauth/access_token?' . http_build_query([
            'grant_type' => 'fb_exchange_token',
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'fb_exchange_token' => $token['access_token'],
        ]));
        $pages = Http::get(self::GRAPH . 'me/accounts?' . http_build_query([
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'limit' => 100,
            'access_token' => $long['access_token'],
        ]));

        $accounts = [];
        foreach ($pages['data'] ?? [] as $p) {
            $accounts[] = [
                'platform' => 'facebook',
                'label' => $p['name'],
                'external_id' => $p['id'],
                'credentials' => ['access_token' => $p['access_token']],
                'meta' => [],
            ];
            if (!empty($p['instagram_business_account']['id'])) {
                $ig = $p['instagram_business_account'];
                $accounts[] = [
                    'platform' => 'instagram',
                    'label' => '@' . ($ig['username'] ?? $ig['id']),
                    'external_id' => $ig['id'],
                    'credentials' => ['access_token' => $p['access_token']],
                    'meta' => ['page' => $p['name']],
                ];
            }
        }
        if (!$accounts) {
            throw new \RuntimeException('No Facebook Pages found. Make sure you granted access to at least one Page.');
        }
        return $accounts;
    }

    public function publish(array &$account, string $text, ?string $link, ?string $image): array
    {
        $page = $account['external_id'];
        $token = $account['credentials']['access_token'];
        if ($image && !$link) {
            $res = Http::post(self::GRAPH . "$page/photos", ['url' => $image, 'caption' => $text, 'access_token' => $token]);
            $id = $res['post_id'] ?? $res['id'];
        } else {
            $res = Http::post(self::GRAPH . "$page/feed", array_filter(['message' => $text, 'link' => $link, 'access_token' => $token]));
            $id = $res['id'];
        }
        return ['id' => $id, 'url' => 'https://www.facebook.com/' . $id];
    }
}
