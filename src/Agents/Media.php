<?php

namespace Admun\GrowthEngine\Agents;

use Admun\GrowthEngine\Support\Http;
use Admun\GrowthEngine\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Media agent: creates the image for a checklist item. It generates a custom AI image first and
 * falls back to a matching Pexels stock photo. The image becomes the article's featured image and is
 * attached to every social post.
 */
class Media
{
    /** @return array{id: int, source: string, note: string} */
    public static function run(int $post_id, string $prompt, string $query, string $alt): array
    {
        $notes = [];
        if (Settings::get('openai_key') !== '') {
            try {
                return ['id' => self::generate($post_id, $prompt, $alt), 'source' => 'ai', 'note' => 'AI-generated image'];
            } catch (\Throwable $e) {
                $notes[] = 'AI image failed: ' . $e->getMessage();
            }
        } else {
            $notes[] = 'No OpenAI key, so no AI image';
        }
        if (Settings::get('pexels_key') !== '') {
            try {
                $id = self::stock($post_id, $query, $alt);
                if ($id) {
                    return ['id' => $id, 'source' => 'stock', 'note' => implode('; ', array_merge($notes, ['used a Pexels stock photo']))];
                }
                $notes[] = "No stock photo found for \"$query\"";
            } catch (\Throwable $e) {
                $notes[] = 'Stock photo failed: ' . $e->getMessage();
            }
        } else {
            $notes[] = 'no Pexels key for a stock fallback';
        }
        return ['id' => 0, 'source' => 'none', 'note' => implode('; ', $notes)];
    }

    private static function generate(int $post_id, string $prompt, string $alt): int
    {
        $res = Http::post('https://api.openai.com/v1/images/generations', [
            'model' => apply_filters('age_image_model', 'gpt-image-1'),
            'prompt' => $prompt . ' Photographic, high quality. No text, letters, watermarks or logos.',
            'size' => '1536x1024',
            'n' => 1,
        ], ['Authorization' => 'Bearer ' . Settings::get('openai_key')], ['timeout' => 180]);
        $b64 = $res['data'][0]['b64_json'] ?? '';
        if ($b64 === '') {
            throw new \RuntimeException('no image returned');
        }
        self::load();
        $upload = wp_upload_bits(sanitize_title(wp_trim_words($alt, 8, '')) . '-' . $post_id . '.png', null, base64_decode($b64));
        if (!empty($upload['error'])) {
            throw new \RuntimeException($upload['error']);
        }
        $id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => $alt, 'post_status' => 'inherit'], $upload['file'], $post_id);
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
        self::finish($id, $post_id, $alt, 'AI-generated image');
        return (int) $id;
    }

    private static function stock(int $post_id, string $query, string $alt): int
    {
        $res = Http::get('https://api.pexels.com/v1/search?' . http_build_query([
            'query' => $query, 'per_page' => 5, 'orientation' => 'landscape',
        ]), ['Authorization' => Settings::get('pexels_key')]);
        $photo = $res['photos'][0] ?? null;
        if (!$photo) {
            return 0;
        }
        self::load();
        $id = media_sideload_image($photo['src']['large2x'] ?? $photo['src']['original'], $post_id, $alt, 'id');
        if (is_wp_error($id)) {
            throw new \RuntimeException($id->get_error_message());
        }
        self::finish((int) $id, $post_id, $alt, sprintf('Photo by %s on Pexels', $photo['photographer'] ?? 'Pexels'));
        return (int) $id;
    }

    private static function finish(int $id, int $post_id, string $alt, string $caption): void
    {
        update_post_meta($id, '_wp_attachment_image_alt', $alt);
        wp_update_post(['ID' => $id, 'post_excerpt' => $caption]);
        set_post_thumbnail($post_id, $id);
    }

    private static function load(): void
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
}
