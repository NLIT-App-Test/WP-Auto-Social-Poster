<?php

namespace Admun\GrowthEngine\AI;

use Admun\GrowthEngine\Support\Http;
use Admun\GrowthEngine\Support\HttpError;
use Admun\GrowthEngine\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Claude Messages API over wp_remote_request. WordPress plugins avoid bundling Composer HTTP stacks
 * (Guzzle version clashes between plugins are common), so this speaks the documented REST shape directly.
 */
class Claude
{
    const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public static function configured(): bool
    {
        return Settings::get('anthropic_key') !== '';
    }

    /**
     * Ask for a JSON object that matches $schema (structured outputs).
     * Schemas must set additionalProperties:false on every object.
     */
    public static function json(string $system, string $prompt, array $schema, array $opts = []): array
    {
        $res = self::call([
            'max_tokens' => $opts['max_tokens'] ?? 16000,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'output_config' => [
                'effort' => $opts['effort'] ?? Settings::get('claude_effort', 'medium'),
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        ], $opts['timeout'] ?? 300);

        $text = self::text($res);
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Claude returned invalid JSON (stop_reason: ' . ($res['stop_reason'] ?? '?') . ').');
        }
        return $data;
    }

    /**
     * Free-text research with live web search. Returns ['text' => ..., 'sources' => [[title,url],...]].
     */
    public static function research(string $system, string $prompt, int $max_searches = 8): array
    {
        $messages = [['role' => 'user', 'content' => $prompt]];
        $body = [
            'max_tokens' => 16000,
            'system' => $system,
            'tools' => [['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => $max_searches]],
            'output_config' => ['effort' => Settings::get('claude_effort', 'medium')],
        ];

        $sources = [];
        $text = '';
        // Server-side tool loops can pause; resend the paused turn to let the server continue.
        for ($i = 0; $i < 5; $i++) {
            $res = self::call($body + ['messages' => $messages], 600);
            foreach ($res['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'];
                }
                if (($block['type'] ?? '') === 'web_search_tool_result' && isset($block['content'][0])) {
                    foreach ($block['content'] as $r) {
                        if (!empty($r['url'])) {
                            $sources[$r['url']] = ['title' => $r['title'] ?? $r['url'], 'url' => $r['url']];
                        }
                    }
                }
            }
            if (($res['stop_reason'] ?? '') !== 'pause_turn') {
                break;
            }
            $messages = [['role' => 'user', 'content' => $prompt], ['role' => 'assistant', 'content' => $res['content']]];
        }
        return ['text' => trim($text), 'sources' => array_values($sources)];
    }

    private static function call(array $body, int $timeout): array
    {
        if (!self::configured()) {
            throw new \RuntimeException('Add your Anthropic API key in Growth Engine → Settings.');
        }
        $body = [
            'model' => Settings::get('claude_model', 'claude-opus-5'),
            'thinking' => ['type' => 'adaptive'],
            // If a safety classifier declines, the API reruns on Anthropic's recommended fallback model.
            'fallbacks' => 'default',
        ] + $body;

        $headers = [
            'x-api-key' => Settings::get('anthropic_key'),
            'anthropic-version' => '2023-06-01',
            'anthropic-beta' => 'server-side-fallback-2026-07-01',
            'content-type' => 'application/json',
        ];

        $attempt = 0;
        while (true) {
            try {
                $res = Http::post(self::ENDPOINT, $body, $headers, ['timeout' => $timeout]);
                break;
            } catch (HttpError $e) {
                $retryable = in_array($e->status, [429, 500, 502, 503, 529], true) || $e->status === 0;
                if (!$retryable || ++$attempt >= 3) {
                    throw new \RuntimeException('Claude: ' . $e->getMessage(), 0, $e);
                }
                sleep(5 * $attempt);
            }
        }

        if (($res['stop_reason'] ?? '') === 'refusal') {
            throw new \RuntimeException('Claude declined this request.');
        }
        if (($res['stop_reason'] ?? '') === 'max_tokens') {
            throw new \RuntimeException('Claude hit the output limit before finishing; try a shorter article length.');
        }
        return $res;
    }

    private static function text(array $res): string
    {
        $out = '';
        foreach ($res['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $out .= $block['text'];
            }
        }
        return $out;
    }
}
