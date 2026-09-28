<?php

namespace Admun\GrowthEngine\Social\Platforms;

use Admun\GrowthEngine\Social\Platform;
use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

class LinkedIn extends Platform
{
    const VERSION = '202509';

    public function key(): string { return 'linkedin'; }
    public function label(): string { return 'LinkedIn'; }
    public function char_limit(): int { return 3000; }

    public function rules(): string
    {
        return 'LinkedIn: 600–1300 characters. Scroll-stopping first line, short paragraphs separated by blank lines, a concrete takeaway, 3–5 hashtags at the end. The link is attached as a preview card, so do not paste the URL.';
    }

    public function oauth(): ?array
    {
        return [
            'authorize_url' => 'https://www.linkedin.com/oauth/v2/authorization',
            'token_url' => 'https://www.linkedin.com/oauth/v2/accessToken',
            'scopes' => ['openid', 'profile', 'w_member_social'],
            'scope_sep' => ' ',
            'docs' => 'https://www.linkedin.com/developers/apps',
        ];
    }

    public function manual_fields(): array
    {
        return [
            ['name' => 'access_token', 'label' => 'Access token', 'type' => 'password'],
            ['name' => 'author', 'label' => 'Author URN (urn:li:person:… or urn:li:organization:…)', 'type' => 'text',
                'help' => 'Company pages need a token with w_organization_social (LinkedIn Community Management API).'],
        ];
    }

    public function connect_manual(array $f): array
    {
        return [[
            'label' => $f['author'],
            'external_id' => $f['author'],
            'credentials' => ['access_token' => $f['access_token']],
            'meta' => ['author' => $f['author']],
        ]];
    }

    public function discover(array $token): array
    {
        $me = Http::get('https://api.linkedin.com/v2/userinfo', $this->bearer($token['access_token']));
        $urn = 'urn:li:person:' . $me['sub'];
        return [[
            'label' => $me['name'] ?? $urn,
            'external_id' => $urn,
            'credentials' => $token,
            'meta' => ['author' => $urn],
        ]];
    }

    public function maybe_refresh(array &$account): bool
    {
        return $this->refresh_oauth2($account);
    }

    private function headers(string $token): array
    {
        return $this->bearer($token) + [
            'LinkedIn-Version' => self::VERSION,
            'X-Restli-Protocol-Version' => '2.0.0',
        ];
    }

    public function publish(array &$account, string $text, ?string $link, ?string $image): array
    {
        $token = $account['credentials']['access_token'];
        $author = $account['meta']['author'];
        $post = [
            'author' => $author,
            // LinkedIn's "little text" format treats these characters as markup.
            'commentary' => preg_replace('/([\\\\|{}@\[\]()<>#*_~])/', '\\\\$1', $text),
            'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];
        // Hashtags are allowed unescaped.
        $post['commentary'] = str_replace('\\#', '#', $post['commentary']);

        $imageUrn = null;
        if ($image) {
            $init = Http::post('https://api.linkedin.com/rest/images?action=initializeUpload',
                ['initializeUploadRequest' => ['owner' => $author]], $this->headers($token));
            $img = $this->image_bytes($image);
            Http::request('PUT', $init['value']['uploadUrl'], $img['bytes'], $this->bearer($token) + ['Content-Type' => $img['mime']], ['timeout' => 90]);
            $imageUrn = $init['value']['image'];
        }
        if ($link) {
            $post['content'] = ['article' => array_filter(['source' => $link, 'title' => wp_trim_words(wp_strip_all_tags($text), 12), 'thumbnail' => $imageUrn])];
        } elseif ($imageUrn) {
            $post['content'] = ['media' => ['id' => $imageUrn]];
        }

        $res = Http::post('https://api.linkedin.com/rest/posts', $post, $this->headers($token), ['with_headers' => true]);
        $id = $res['headers']['x-restli-id'] ?? '';
        return ['id' => $id, 'url' => $id ? 'https://www.linkedin.com/feed/update/' . $id : null];
    }
}
