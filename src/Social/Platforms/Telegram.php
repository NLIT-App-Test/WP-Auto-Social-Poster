<?php

namespace Admun\GrowthEngine\Social\Platforms;

use Admun\GrowthEngine\Social\Platform;
use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

class Telegram extends Platform
{
    public function key(): string { return 'telegram'; }
    public function label(): string { return 'Telegram'; }
    public function char_limit(): int { return 1024; }
    public function link_in_text(): bool { return true; }

    public function rules(): string
    {
        return 'Telegram channel post: 200–900 characters, clear and scannable, may use a few emoji as bullets, include the link on its own line at the end.';
    }

    public function manual_fields(): array
    {
        return [
            ['name' => 'bot_token', 'label' => 'Bot token (from @BotFather)', 'type' => 'password'],
            ['name' => 'chat_id', 'label' => 'Channel or group (@channelname or numeric chat id)', 'type' => 'text',
                'help' => 'Add the bot to the channel as an administrator with permission to post.'],
        ];
    }

    public function connect_manual(array $f): array
    {
        $api = 'https://api.telegram.org/bot' . $f['bot_token'] . '/';
        $chat = Http::get($api . 'getChat?' . http_build_query(['chat_id' => $f['chat_id']]));
        return [[
            'label' => $chat['result']['title'] ?? $f['chat_id'],
            'external_id' => (string) $chat['result']['id'],
            'credentials' => ['bot_token' => $f['bot_token']],
            'meta' => ['username' => $chat['result']['username'] ?? ''],
        ]];
    }

    public function publish(array &$account, string $text, ?string $link, ?string $image): array
    {
        $api = 'https://api.telegram.org/bot' . $account['credentials']['bot_token'] . '/';
        $chat = $account['external_id'];
        $html = esc_html($text);
        if ($image) {
            $res = Http::post($api . 'sendPhoto', ['chat_id' => $chat, 'photo' => $image, 'caption' => $html, 'parse_mode' => 'HTML']);
        } else {
            $res = Http::post($api . 'sendMessage', ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML']);
        }
        $id = (string) $res['result']['message_id'];
        $user = $account['meta']['username'] ?? '';
        return ['id' => $id, 'url' => $user ? "https://t.me/$user/$id" : null];
    }
}
