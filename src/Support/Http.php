<?php

namespace Admun\GrowthEngine\Support;

defined('ABSPATH') || exit;

class Http
{
    /**
     * JSON request helper around wp_remote_request. Returns the decoded body or throws HttpError.
     *
     * @param array|string|null $body Arrays are JSON-encoded unless $opts['form'] is true.
     */
    public static function request(string $method, string $url, $body = null, array $headers = [], array $opts = [])
    {
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $opts['timeout'] ?? 60,
        ];
        if ($body !== null) {
            if (is_array($body) && !empty($opts['form'])) {
                $args['body'] = $body;
            } elseif (is_array($body)) {
                $args['body'] = wp_json_encode($body);
                $args['headers']['Content-Type'] = $args['headers']['Content-Type'] ?? 'application/json';
            } else {
                $args['body'] = $body;
            }
        }

        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            throw new HttpError($res->get_error_message(), 0);
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $raw = wp_remote_retrieve_body($res);
        $json = json_decode($raw, true);
        $data = $json === null && $raw !== 'null' ? $raw : $json;

        if ($code < 200 || $code >= 300) {
            throw new HttpError(self::describe($code, $data), $code, $data);
        }
        if (!empty($opts['with_headers'])) {
            return ['body' => $data, 'headers' => wp_remote_retrieve_headers($res)];
        }
        return $data;
    }

    public static function get(string $url, array $headers = [], array $opts = [])
    {
        return self::request('GET', $url, null, $headers, $opts);
    }

    public static function post(string $url, $body = null, array $headers = [], array $opts = [])
    {
        return self::request('POST', $url, $body, $headers, $opts);
    }

    private static function describe(int $code, $data): string
    {
        if (is_array($data)) {
            $msg = $data['error']['message'] ?? $data['error_description'] ?? $data['message'] ?? $data['detail'] ?? $data['error'] ?? $data['errors'][0]['message'] ?? $data['description'] ?? null;
            if (is_array($msg)) {
                $msg = wp_json_encode($msg);
            }
            if ($msg) {
                return "HTTP $code: $msg";
            }
            return "HTTP $code: " . mb_substr((string) wp_json_encode($data), 0, 300);
        }
        return "HTTP $code: " . mb_substr(wp_strip_all_tags((string) $data), 0, 300);
    }
}
