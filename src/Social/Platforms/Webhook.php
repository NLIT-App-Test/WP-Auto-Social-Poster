<?php

namespace Admun\GrowthEngine\Social\Platforms;

use Admun\GrowthEngine\Social\Platform;
use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

/** Send posts to Zapier, Make, n8n, Buffer, etc. — covers any network without a native adapter. */
class Webhook extends Platform
{
    public function key(): string { return 'webhook'; }
    public function label(): string { return 'Webhook (Zapier / Make / n8n)'; }
    public function char_limit(): int { return 5000; }

    public function rules(): string
    {
        return 'Generic social post: 100–600 characters, clear hook, one takeaway, 1–3 hashtags. The link is sent separately.';
    }

    public function manual_fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name (e.g. "Zapier → TikTok")', 'type' => 'text'],
            ['name' => 'url', 'label' => 'Webhook URL', 'type' => 'password'],
            ['name' => 'secret', 'label' => 'Shared secret (optional, sent as X-Growth-Engine-Signature)', 'type' => 'password'],
        ];
    }

    public function connect_manual(array $f): array
    {
        if (!wp_http_validate_url($f['url'])) {
            throw new \RuntimeException('Enter a valid https webhook URL.');
        }
        return [[
            'label' => $f['name'] ?: wp_parse_url($f['url'], PHP_URL_HOST),
            'external_id' => md5($f['url']),
            'credentials' => ['url' => $f['url'], 'secret' => $f['secret'] ?? ''],
            'meta' => [],
        ]];
    }

    public function publish(array &$account, string $text, ?string $link, ?string $image): array
    {
        // Stable id lets the receiver drop duplicates if a delivery is retried.
        $payload = ['id' => md5($account['id'] . '|' . $text), 'text' => $text, 'link' => $link, 'image' => $image, 'site' => home_url('/'), 'sent_at' => gmdate('c')];
        $json = wp_json_encode($payload);
        $headers = ['Content-Type' => 'application/json'];
        if (!empty($account['credentials']['secret'])) {
            $headers['X-Growth-Engine-Signature'] = hash_hmac('sha256', $json, $account['credentials']['secret']);
        }
        Http::post($account['credentials']['url'], $json, $headers);
        return ['id' => 'webhook-' . time(), 'url' => null];
    }
}
