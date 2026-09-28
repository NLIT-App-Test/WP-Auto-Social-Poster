<?php

namespace Admun\GrowthEngine\Social;

use Admun\GrowthEngine\Support\Crypto;
use Admun\GrowthEngine\Support\Http;
use Admun\GrowthEngine\Support\Log;

defined('ABSPATH') || exit;

/**
 * OAuth2 "connect account" flow using the site owner's own developer apps.
 * Redirect URI to register with every provider: redirect_uri() below (shown in the admin UI).
 */
class OAuth
{
    const APPS_OPTION = 'age_oauth_apps';

    public static function register(): void
    {
        add_action('admin_post_age_oauth', [self::class, 'callback']);
    }

    public static function redirect_uri(): string
    {
        return admin_url('admin-post.php?action=age_oauth');
    }

    public static function app(string $platform): array
    {
        $apps = Crypto::decrypt_array((string) get_option(self::APPS_OPTION, ''));
        return $apps[$platform] ?? ['client_id' => '', 'client_secret' => ''];
    }

    public static function app_configured(string $platform): bool
    {
        $a = self::app($platform);
        return $a['client_id'] !== '' && $a['client_secret'] !== '';
    }

    public static function save_app(string $platform, string $client_id, string $client_secret): void
    {
        $apps = Crypto::decrypt_array((string) get_option(self::APPS_OPTION, ''));
        $current = $apps[$platform] ?? ['client_id' => '', 'client_secret' => ''];
        $apps[$platform] = [
            'client_id' => trim($client_id),
            // Keep the stored secret when the field was left blank.
            'client_secret' => trim($client_secret) !== '' ? trim($client_secret) : $current['client_secret'],
        ];
        update_option(self::APPS_OPTION, Crypto::encrypt_array($apps), false);
    }

    public static function authorize_url(string $platform): string
    {
        $p = Registry::get($platform);
        $cfg = $p->oauth();
        if (!$cfg || !empty($cfg['via'])) {
            throw new \RuntimeException($p->label() . ' does not connect with OAuth.');
        }
        $app = self::app($platform);
        if ($app['client_id'] === '') {
            throw new \RuntimeException('Save your ' . $p->label() . ' app client ID and secret first.');
        }

        $state = wp_generate_password(32, false);
        $store = ['platform' => $platform, 'user' => get_current_user_id()];
        $params = [
            'response_type' => 'code',
            'client_id' => $app['client_id'],
            'redirect_uri' => self::redirect_uri(),
            'scope' => implode($cfg['scope_sep'] ?? ' ', $cfg['scopes']),
            'state' => $state,
        ] + ($cfg['extra'] ?? []);
        if (!empty($cfg['pkce'])) {
            $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $store['verifier'] = $verifier;
            $params['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $params['code_challenge_method'] = 'S256';
        }
        set_transient('age_oauth_' . $state, $store, 15 * MINUTE_IN_SECONDS);
        return $cfg['authorize_url'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public static function callback(): void
    {
        $back = admin_url('admin.php?page=growth-engine#/settings');
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $store = get_transient('age_oauth_' . $state);
        delete_transient('age_oauth_' . $state);

        try {
            if (!$store || (int) $store['user'] !== get_current_user_id()) {
                throw new \RuntimeException('The connection link expired. Please try again.');
            }
            if (!empty($_GET['error'])) {
                throw new \RuntimeException(sanitize_text_field(wp_unslash($_GET['error_description'] ?? $_GET['error'])));
            }
            $platform = $store['platform'];
            $p = Registry::get($platform);
            $token = self::exchange($p, sanitize_text_field(wp_unslash($_GET['code'] ?? '')), $store['verifier'] ?? null);

            $saved = 0;
            foreach ($p->discover($token) as $acc) {
                Accounts::save($acc['platform'] ?? $platform, $acc);
                $saved++;
            }
            Log::info('social', "Connected $saved " . $p->label() . ' account(s).');
            wp_safe_redirect(add_query_arg('age_notice', rawurlencode("Connected $saved account(s)."), $back));
        } catch (\Throwable $e) {
            Log::error('social', 'OAuth failed: ' . $e->getMessage());
            wp_safe_redirect(add_query_arg('age_error', rawurlencode($e->getMessage()), $back));
        }
        exit;
    }

    private static function exchange(Platform $p, string $code, ?string $verifier): array
    {
        $cfg = $p->oauth();
        $app = self::app($p->key());
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirect_uri(),
            'client_id' => $app['client_id'],
        ];
        if ($verifier) {
            $body['code_verifier'] = $verifier;
        }
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        if (!empty($cfg['basic_auth'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($app['client_id'] . ':' . $app['client_secret']);
        } else {
            $body['client_secret'] = $app['client_secret'];
        }

        if (($cfg['token_method'] ?? 'POST') === 'GET') {
            $t = Http::get($cfg['token_url'] . '?' . http_build_query($body));
        } else {
            $t = Http::post($cfg['token_url'], http_build_query($body), $headers);
        }
        if (empty($t['access_token'])) {
            throw new \RuntimeException('The provider did not return an access token.');
        }
        return array_filter([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? null,
            'expires_at' => !empty($t['expires_in']) ? time() + (int) $t['expires_in'] : null,
        ]);
    }
}
