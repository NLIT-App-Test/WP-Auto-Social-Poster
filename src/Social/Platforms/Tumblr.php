<?php

namespace Admun\GrowthEngine\Social\Platforms;

use Admun\GrowthEngine\Social\Platform;
use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

/** Tumblr via OAuth2 and the Neue Post Format. Each blog is saved as its own account. */
class Tumblr extends Platform
{
    public function key(): string { return 'tumblr'; }
    public function label(): string { return 'Tumblr'; }
    public function char_limit(): int { return 4096; }

    public function rules(): string
    {
        return 'Tumblr: 100–600 characters, personable, 3–8 tags written as hashtags at the end. The link is attached separately.';
    }

    public function oauth(): ?array
    {
        return [
            'authorize_url' => 'https://www.tumblr.com/oauth2/authorize',
            'token_url' => 'https://api.tumblr.com/v2/oauth2/token',
            'scopes' => ['basic', 'write', 'offline_access'],
            'scope_sep' => ' ',
            'docs' => 'https://www.tumblr.com/oauth/apps',
        ];
    }

    public function discover(array $token): array
    {
        $info = Http::get('https://api.tumblr.com/v2/user/info', $this->bearer($token['access_token']));
        $out = [];
        foreach ($info['response']['user']['blogs'] ?? [] as $b) {
            $out[] = ['label' => $b['name'], 'external_id' => $b['uuid'] ?? $b['name'], 'credentials' => $token, 'meta' => ['blog' => $b['name']]];
        }
        return $out;
    }

    public function maybe_refresh(array &$account): bool
    {
        return $this->refresh_oauth2($account);
    }

    public function publish(array &$account, string $text, ?string $link, ?string $image): array
    {
        // Pull trailing hashtags out as Tumblr tags.
        preg_match_all('/#([\p{L}\p{N}_]+)/u', $text, $m);
        $body = trim(preg_replace('/(\s*#[\p{L}\p{N}_]+)+\s*$/u', '', $text));
        $content = [['type' => 'text', 'text' => $body]];
        if ($image) {
            $content[] = ['type' => 'image', 'media' => [['url' => $image]]];
        }
        if ($link) {
            $content[] = ['type' => 'link', 'url' => $link];
        }
        $blog = $account['meta']['blog'];
        $res = Http::post("https://api.tumblr.com/v2/blog/$blog.tumblr.com/posts",
            ['content' => $content, 'tags' => implode(',', $m[1])], $this->bearer($account['credentials']['access_token']));
        $id = (string) ($res['response']['id'] ?? '');
        return ['id' => $id, 'url' => $id ? "https://www.tumblr.com/$blog/$id" : null];
    }
}
