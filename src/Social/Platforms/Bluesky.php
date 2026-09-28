<?php

namespace Admun\GrowthEngine\Social\Platforms;

use Admun\GrowthEngine\Social\Platform;
use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

class Bluesky extends Platform
{
    const XRPC = 'https://bsky.social/xrpc/';

    public function key(): string { return 'bluesky'; }
    public function label(): string { return 'Bluesky'; }
    public function char_limit(): int { return 300; }

    public function rules(): string
    {
        return 'Bluesky: at most 300 characters, conversational, 0–2 hashtags. The link is attached as a preview card, so do not paste the URL.';
    }

    public function manual_fields(): array
    {
        return [
            ['name' => 'handle', 'label' => 'Handle (e.g. yourbrand.bsky.social)', 'type' => 'text'],
            ['name' => 'app_password', 'label' => 'App password', 'type' => 'password',
                'help' => 'Create one in Bluesky → Settings → Privacy and security → App passwords.'],
        ];
    }

    public function connect_manual(array $f): array
    {
        $s = $this->session($f['handle'], $f['app_password']);
        return [[
            'label' => '@' . $s['handle'],
            'external_id' => $s['did'],
            'credentials' => ['handle' => $f['handle'], 'app_password' => $f['app_password']],
            'meta' => ['handle' => $s['handle']],
        ]];
    }

    private function session(string $handle, string $password): array
    {
        return Http::post(self::XRPC . 'com.atproto.server.createSession', ['identifier' => $handle, 'password' => $password]);
    }

    public function publish(array &$account, string $text, ?string $link, ?string $image): array
    {
        $s = $this->session($account['credentials']['handle'], $account['credentials']['app_password']);
        $auth = $this->bearer($s['accessJwt']);

        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'langs' => ['en'],
        ];
        $facets = $this->facets($text);
        if ($facets) {
            $record['facets'] = $facets;
        }

        $thumb = null;
        if ($image) {
            try {
                $img = $this->image_bytes($image);
                if (strlen($img['bytes']) <= 1000000) {
                    $blob = Http::post(self::XRPC . 'com.atproto.repo.uploadBlob', $img['bytes'], $auth + ['Content-Type' => $img['mime']]);
                    $thumb = $blob['blob'];
                }
            } catch (\Throwable $e) {
                $thumb = null; // Post without the image rather than failing.
            }
        }
        if ($link) {
            $card = ['uri' => $link, 'title' => '', 'description' => ''];
            try {
                $meta = Http::get('https://cardyb.bsky.app/v1/extract?' . http_build_query(['url' => $link]));
                $card['title'] = (string) ($meta['title'] ?? '');
                $card['description'] = (string) ($meta['description'] ?? '');
            } catch (\Throwable $e) {
                // Card metadata is optional.
            }
            if ($thumb) {
                $card['thumb'] = $thumb;
            }
            $record['embed'] = ['$type' => 'app.bsky.embed.external', 'external' => $card];
        } elseif ($thumb) {
            $record['embed'] = ['$type' => 'app.bsky.embed.images', 'images' => [['alt' => wp_trim_words($text, 15), 'image' => $thumb]]];
        }

        $res = Http::post(self::XRPC . 'com.atproto.repo.createRecord', [
            'repo' => $s['did'],
            'collection' => 'app.bsky.feed.post',
            'record' => $record,
        ], $auth);
        $rkey = substr($res['uri'], strrpos($res['uri'], '/') + 1);
        return ['id' => $res['uri'], 'url' => 'https://bsky.app/profile/' . $s['handle'] . '/post/' . $rkey];
    }

    /** Rich-text facets use UTF-8 byte offsets, which is what PREG_OFFSET_CAPTURE returns. */
    private function facets(string $text): array
    {
        $facets = [];
        preg_match_all('~https?://[^\s<>"]+[^\s<>".,;:!?)]~u', $text, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$url, $start]) {
            $facets[] = [
                'index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($url)],
                'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]],
            ];
        }
        preg_match_all('/(?<![\w#])#([\p{L}\p{N}_]+)/u', $text, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $i => [$tag, $start]) {
            $facets[] = [
                'index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($tag)],
                'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $m[1][$i][0]]],
            ];
        }
        return $facets;
    }
}
