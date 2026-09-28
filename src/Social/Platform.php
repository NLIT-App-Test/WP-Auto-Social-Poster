<?php

namespace Admun\GrowthEngine\Social;

use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

/**
 * One social network. Publishing logic in the platform classes is adapted from Bit Social Pro
 * (Bit Apps, GPL-2.0+), reworked to use the site owner's own developer apps instead of a hosted broker.
 */
abstract class Platform
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** Hard character limit enforced in code before anything is published. */
    abstract public function char_limit(): int;

    /** Writing rules given to Claude and used by Jev's platform-fit judgment. */
    abstract public function rules(): string;

    /**
     * Publish and return ['id' => string, 'url' => ?string].
     *
     * @param array $account account row with decoded 'credentials' and 'meta'
     */
    abstract public function publish(array &$account, string $text, ?string $link, ?string $image): array;

    public function needs_image(): bool
    {
        return false;
    }

    /** Whether the link should be embedded in the text by the writer (true) or attached separately. */
    public function link_in_text(): bool
    {
        return false;
    }

    /** OAuth2 config, or null for platforms connected by pasting credentials. */
    public function oauth(): ?array
    {
        return null;
    }

    /** Fields shown for manual connection (token paste, bot token, webhook URL...). */
    public function manual_fields(): array
    {
        return [];
    }

    /**
     * Turn manual fields into one or more accounts: [['label','external_id','credentials','meta'], ...].
     */
    public function connect_manual(array $fields): array
    {
        throw new \RuntimeException($this->label() . ' does not support manual connection.');
    }

    /** After OAuth: list the pages/boards/profiles the token can post to. */
    public function discover(array $token): array
    {
        return [];
    }

    /** Refresh credentials in place if they are expiring. Return true if they changed. */
    public function maybe_refresh(array &$account): bool
    {
        return false;
    }

    /* ------------------------------------------------------------ helpers */

    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /** Load image bytes from the media library path when possible, else download (ported from Bit Social). */
    protected function image_bytes(string $url): array
    {
        $id = attachment_url_to_postid($url);
        $path = $id ? get_attached_file($id) : '';
        $bytes = ($path && is_readable($path)) ? file_get_contents($path) : false;
        if ($bytes === false || $bytes === '') {
            $res = wp_remote_get($url, ['timeout' => 30]);
            $bytes = is_wp_error($res) ? '' : wp_remote_retrieve_body($res);
        }
        if ($bytes === '') {
            throw new \RuntimeException('Could not load image: ' . $url);
        }
        $info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($bytes) : false;
        $mime = $info['mime'] ?? 'image/jpeg';
        $name = basename((string) wp_parse_url($url, PHP_URL_PATH)) ?: 'image.jpg';
        return ['bytes' => $bytes, 'mime' => $mime, 'name' => $name];
    }

    protected function multipart(array $fields, array $files, string &$contentType): string
    {
        $b = wp_generate_password(24, false);
        $eol = "\r\n";
        $body = '';
        foreach ($fields as $k => $v) {
            $body .= "--$b$eol" . "Content-Disposition: form-data; name=\"$k\"$eol$eol$v$eol";
        }
        foreach ($files as $k => $f) {
            $body .= "--$b$eol" . "Content-Disposition: form-data; name=\"$k\"; filename=\"{$f['name']}\"$eol"
                . "Content-Type: {$f['mime']}$eol$eol{$f['bytes']}$eol";
        }
        $contentType = "multipart/form-data; boundary=$b";
        return $body . "--$b--$eol";
    }

    /** Standard OAuth2 refresh-token grant. */
    protected function refresh_oauth2(array &$account, array $extra_headers = [], bool $basic = false): bool
    {
        $c = &$account['credentials'];
        if (empty($c['refresh_token']) || empty($c['expires_at']) || $c['expires_at'] > time() + 300) {
            return false;
        }
        $app = OAuth::app($this->key());
        $body = ['grant_type' => 'refresh_token', 'refresh_token' => $c['refresh_token']];
        $headers = $extra_headers + ['Content-Type' => 'application/x-www-form-urlencoded'];
        if ($basic) {
            $headers['Authorization'] = 'Basic ' . base64_encode($app['client_id'] . ':' . $app['client_secret']);
            $body['client_id'] = $app['client_id'];
        } else {
            $body['client_id'] = $app['client_id'];
            $body['client_secret'] = $app['client_secret'];
        }
        $t = Http::post($this->oauth()['token_url'], http_build_query($body), $headers);
        $c['access_token'] = $t['access_token'];
        if (!empty($t['refresh_token'])) {
            $c['refresh_token'] = $t['refresh_token'];
        }
        $c['expires_at'] = time() + (int) ($t['expires_in'] ?? 3600);
        return true;
    }
}
