<?php

namespace Admun\GrowthEngine\Social\Platforms;

use Admun\GrowthEngine\Social\Platform;
use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

class X extends Platform
{
    public function key(): string { return 'x'; }
    public function label(): string { return 'X (Twitter)'; }
    public function char_limit(): int { return 280; }
    public function link_in_text(): bool { return true; }

    public function rules(): string
    {
        return 'X (Twitter): at most 280 characters total including the link (a link counts as 23 characters) and hashtags. One idea, strong first line, 0–2 hashtags.';
    }

    public function oauth(): ?array
    {
        return [
            'authorize_url' => 'https://x.com/i/oauth2/authorize',
            'token_url' => 'https://api.x.com/2/oauth2/token',
            'scopes' => ['tweet.read', 'tweet.write', 'users.read', 'offline.access', 'media.write'],
            'scope_sep' => ' ',
            'pkce' => true,
            'basic_auth' => true,
            'docs' => 'https://developer.x.com/en/portal/dashboard',
        ];
    }

    public function discover(array $token): array
    {
        $me = Http::get('https://api.x.com/2/users/me', $this->bearer($token['access_token']));
        return [[
            'label' => '@' . $me['data']['username'],
            'external_id' => $me['data']['id'],
            'credentials' => $token,
            'meta' => ['username' => $me['data']['username']],
        ]];
    }

    public function maybe_refresh(array &$account): bool
    {
        return $this->refresh_oauth2($account, [], true);
    }

    public function publish(array &$account, string $text, ?string $link, ?string $image): array
    {
        $token = $account['credentials']['access_token'];
        $body = ['text' => $text];
        if ($image) {
            $img = $this->image_bytes($image);
            $ct = '';
            $multipart = $this->multipart(['media_category' => 'tweet_image'], ['media' => $img], $ct);
            $up = Http::post('https://api.x.com/2/media/upload', $multipart, $this->bearer($token) + ['Content-Type' => $ct], ['timeout' => 90]);
            if (!empty($up['data']['id'])) {
                $body['media'] = ['media_ids' => [$up['data']['id']]];
            }
        }
        $res = Http::post('https://api.x.com/2/tweets', $body, $this->bearer($token));
        $id = $res['data']['id'];
        return ['id' => $id, 'url' => 'https://x.com/' . ($account['meta']['username'] ?? 'i') . '/status/' . $id];
    }
}
