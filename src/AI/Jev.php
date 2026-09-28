<?php

namespace Admun\GrowthEngine\AI;

use Admun\GrowthEngine\Support\Http;
use Admun\GrowthEngine\Support\HttpError;
use Admun\GrowthEngine\Support\Settings;

defined('ABSPATH') || exit;

/**
 * TypeSafe System One API (Jev). Jev does not generate text; it returns calibrated typed judgments
 * (score / choice / noul) about the state we send, which our code turns into decisions.
 */
class Jev
{
    const ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

    public static function configured(): bool
    {
        return Settings::get('typesafe_key') !== '';
    }

    public static function score(string $instructions, array $levels): array
    {
        return ['type' => 'score', 'instructions' => $instructions, 'criteria' => array_values($levels)];
    }

    public static function noul(string $instructions, ?string $yes = null, ?string $no = null): array
    {
        $q = ['type' => 'noul', 'instructions' => $instructions];
        if ($yes !== null || $no !== null) {
            $q['criteria'] = array_filter(['true' => $yes, 'false' => $no], static function ($v) {
                return $v !== null;
            });
        }
        return $q;
    }

    public static function choice(string $instructions, array $options): array
    {
        return ['type' => 'choice', 'instructions' => $instructions, 'criteria' => $options];
    }

    /**
     * Ask every question against the same state in one parallel request.
     *
     * @return array{answers: array, model: string, input_tokens: int}
     */
    public static function ask($state, array $questions): array
    {
        if (!self::configured()) {
            throw new \RuntimeException('Add your TypeSafe (Jev) API key in Growth Engine → Settings.');
        }
        $attempt = 0;
        while (true) {
            try {
                $res = Http::post(self::ENDPOINT, [
                    'model' => 'jev-latest',
                    'state' => $state,
                    'questions' => $questions,
                ], ['Authorization' => 'Bearer ' . Settings::get('typesafe_key')], ['timeout' => 60]);
                break;
            } catch (HttpError $e) {
                $retryable = in_array($e->status, [429, 500, 502, 503], true) || $e->status === 0;
                if (!$retryable || ++$attempt >= 3) {
                    throw new \RuntimeException('Jev: ' . $e->getMessage(), 0, $e);
                }
                sleep(2 * $attempt);
            }
        }
        return [
            'answers' => $res['answers'] ?? [],
            'model' => $res['model'] ?? 'jev',
            'input_tokens' => (int) ($res['usage']['input_tokens'] ?? 0),
        ];
    }

    /** Normalize a Score answer to 0–1 using the number of rubric levels. */
    public static function norm(array $answer, int $levels): float
    {
        return $levels > 1 ? max(0.0, min(1.0, ((float) $answer['score']) / ($levels - 1))) : 0.0;
    }
}
