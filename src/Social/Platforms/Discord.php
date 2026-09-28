<?php

namespace Admun\GrowthEngine\Social\Platforms;

use Admun\GrowthEngine\Social\Platform;
use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

class Discord extends Platform
{
    public function key(): string { return 'discord'; }
    public function label(): string { return 'Discord'; }
    public function char_limit(): int { return 2000; }
    public function link_in_text(): bool { return true; }

    public function rules(): string
    {
        return 'Discord announcement: 150–800 characters, friendly community tone, may use **bold** for the headline, include the link on its own line.';
    }

    public function manual_fields(): array
    {
        return [
            ['name' => 'webhook', 'label' => 'Channel webhook URL', 'type' => 'password',
                'help' => 'Discord channel → Edit channel → Integrations → Webhooks → New webhook → Copy URL.'],
        ];
    }

    public function connect_manual(array $f): array
    {
        if (!preg_match('~^https://(canary\.|ptb\.)?discord(app)?\.com/api/webhooks/\d+/[\w-]+$~', trim($f['webhook']))) {
            throw new \RuntimeException('That does not look like a Discord webhook URL.');
        }
        $info = Http::get(trim($f['webhook']));
        return [[
            'label' => '#' . ($info['name'] ?? 'discord'),
            'external_id' => (string) ($info['channel_id'] ?? $info['id']),
            'credentials' => ['webhook' => trim($f['webhook'])],
            'meta' => ['guild_id' => $info['guild_id'] ?? ''],
        ]];
    }

    public function publish(array &$account, string $text, ?string $link, ?string $image): array
    {
        $body = ['content' => $text];
        if ($image) {
            $body['embeds'] = [['image' => ['url' => $image]]];
        }
        $res = Http::post($account['credentials']['webhook'] . '?wait=true', $body);
        $guild = $account['meta']['guild_id'] ?? '';
        return ['id' => $res['id'], 'url' => $guild ? "https://discord.com/channels/$guild/{$res['channel_id']}/{$res['id']}" : null];
    }
}
