<?php

namespace Admun\GrowthEngine\Social\Platforms;

use Admun\GrowthEngine\Social\Platform;
use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

/** Instagram professional accounts, connected through a Facebook Page (container → publish flow from Bit Social). */
class Instagram extends Platform
{
    public function key(): string { return 'instagram'; }
    public function label(): string { return 'Instagram'; }
    public function char_limit(): int { return 2200; }
    public function needs_image(): bool { return true; }

    public function rules(): string
    {
        return 'Instagram caption: hook in the first 125 characters, short lines, emoji sparingly, 5–12 relevant hashtags at the end. Links are not clickable in captions, so say "link in bio" instead of pasting a URL.';
    }

    public function oauth(): ?array
    {
        return ['via' => 'facebook'];
    }

    public function publish(array &$account, string $text, ?string $link, ?string $image): array
    {
        if (!$image) {
            throw new \RuntimeException('Instagram needs an image. Add a featured image to the post.');
        }
        $g = Facebook::GRAPH;
        $ig = $account['external_id'];
        $token = $account['credentials']['access_token'];

        $container = Http::post($g . "$ig/media", ['image_url' => $image, 'caption' => $text, 'access_token' => $token]);
        $this->wait_ready($container['id'], $token);
        $published = Http::post($g . "$ig/media_publish", ['creation_id' => $container['id'], 'access_token' => $token]);
        $info = Http::get($g . $published['id'] . '?' . http_build_query(['fields' => 'permalink', 'access_token' => $token]));
        return ['id' => $published['id'], 'url' => $info['permalink'] ?? null];
    }

    private function wait_ready(string $id, string $token): void
    {
        for ($i = 0; $i < 20; $i++) {
            $s = Http::get(Facebook::GRAPH . $id . '?' . http_build_query(['fields' => 'status_code', 'access_token' => $token]));
            $code = $s['status_code'] ?? '';
            if ($code === 'FINISHED' || $code === 'PUBLISHED') {
                return;
            }
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                throw new \RuntimeException('Instagram rejected the image (' . $code . ').');
            }
            sleep(3);
        }
        throw new \RuntimeException('Instagram media was not ready after 60 seconds.');
    }
}
