<?php

namespace Admun\GrowthEngine\AI;

defined('ABSPATH') || exit;

class Policy
{
    const RISK_LABELS = [
        'unverifiableClaim' => 'unsupported claim',
        'offensive' => 'could offend',
        'forbiddenTopic' => 'forbidden topic',
        'misleading' => 'overpromises',
    ];

    /** Mean of the normalized Jev scores. */
    public static function composite(array $judgment): float
    {
        $scores = array_column($judgment['scores'] ?? [], 'value');
        return $scores ? round(array_sum($scores) / count($scores), 4) : 0.0;
    }

    public static function max_risk(array $judgment): array
    {
        $max = 0.0;
        $key = '';
        foreach ($judgment['risks'] ?? [] as $k => $p) {
            if ($p > $max) {
                $max = $p;
                $key = $k;
            }
        }
        return [$key, $max];
    }

    public static function pct(float $x): string
    {
        return round($x * 100) . '%';
    }
}
