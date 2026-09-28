<?php

namespace Admun\GrowthEngine\Social\Platforms;

use Admun\GrowthEngine\Social\Platform;
use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

/** Each Pinterest board is saved as its own account. */
class Pinterest extends Platform
{
    const API = 'https://api.pinterest.com/v5/';

    public function key(): string { return 'pinterest'; }
    public function label(): string { return 'Pinterest'; }
    public function char_limit(): int { return 500; }
    public function needs_image(): bool { return true; }

    public function rules(): string
    {
        return 'Pinterest pin description: 150–500 characters, keyword-rich and descriptive (Pinterest is a search engine), say what the reader will learn or get, 2–5 hashtags at the end. The link is attached to the pin.';
    }

    public function oauth(): ?array
    {
        return [
            'authorize_url' => 'https://www.pinterest.com/oauth/',
            'token_url' => self::API . 'oauth/token',
            'scopes' => ['boards:read', 'pins:read', 'pins:write', 'user_accounts:read'],
            'scope_sep' => ',',
            'basic_auth' => true,
            'docs' => 'https://developers.pinterest.com/apps/',
        ];
    }

    public function discover(array $token): array
    {
        $boards = Http::get(self::API . 'boards?page_size=100', $this->bearer($token['access_token']));
        $out = [];
        foreach ($boards['items'] ?? [] as $b) {
            $out[] = [
                'label' => 'Board: ' . $b['name'],
                'external_id' => $b['id'],
                'credentials' => $token,
                'meta' => ['board' => $b['name']],
            ];
        }
        if (!$out) {
            throw new \RuntimeException('No Pinterest boards found. Create a board first.');
        }
        return $out;
    }

    public function maybe_refresh(array &$account): bool
    {
        return $this->refresh_oauth2($account, [], true);
    }

    public function publish(array &$account, string $text, ?string $link, ?string $image): array
    {
        if (!$image) {
            throw new \RuntimeException('Pinterest needs an image. Add a featured image to the post.');
        }
        $pin = Http::post(self::API . 'pins', array_filter([
            'board_id' => $account['external_id'],
            'title' => mb_substr(wp_trim_words($text, 12, ''), 0, 100),
            'description' => $text,
            'link' => $link,
            'alt_text' => mb_substr(wp_trim_words($text, 20, ''), 0, 500),
            'media_source' => ['source_type' => 'image_url', 'url' => $image],
        ]), $this->bearer($account['credentials']['access_token']));
        return ['id' => $pin['id'], 'url' => 'https://www.pinterest.com/pin/' . $pin['id']];
    }
}
