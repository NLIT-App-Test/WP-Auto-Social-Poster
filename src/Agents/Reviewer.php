<?php

namespace Admun\GrowthEngine\Agents;

use Admun\GrowthEngine\AI\Judges;
use Admun\GrowthEngine\AI\Policy;
use Admun\GrowthEngine\SEO\Checks;
use Admun\GrowthEngine\Social\Registry;
use Admun\GrowthEngine\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Reviewer agent: checks a finished piece against what the user asked for. Rules (SEO basics, character
 * limits, image present) are checked in code; judgment (does it serve the goal, is it accurate, is it good
 * for search and each network) comes from Jev. Returns pass/fail plus the issues the writer must fix.
 */
class Reviewer
{
    const RISK_LIMIT = 0.4;
    const SOCIAL_BAR = 0.6;

    public static function run(array $campaign, array $item, array $draft, array $image, array $platforms): array
    {
        $brand = Settings::get('brand');
        $bar = (float) Settings::get('review_bar', 0.75);
        $issues = [];

        // SEO standard (rules → code).
        $checks = Checks::run([
            'title' => $draft['title'],
            'meta_title' => $draft['meta_title'],
            'meta_description' => $draft['meta_description'],
            'slug' => $draft['slug'],
            'focus_keyword' => $item['keyword'],
            'content_html' => $draft['content_html'],
            'faq' => $draft['faq'],
            'target_words' => (int) Settings::get('words', 1200),
        ]);
        $issues = array_merge($issues, Checks::failed_fixes($checks));

        // Goal match, quality and accuracy (judgment → Jev).
        $article = Judges::article($brand, $campaign, $item, $draft);
        $jev = Policy::composite($article);
        foreach ($article['scores'] as $k => $s) {
            if ($s['value'] < 0.6 && isset(Judges::FIXES[$k])) {
                $issues[] = Judges::FIXES[$k];
            }
        }
        foreach ($article['risks'] as $k => $p) {
            if ($p >= self::RISK_LIMIT) {
                $issues[] = Judges::RISK_FIXES[$k];
            }
        }
        $goal = $article['scores']['goalFit']['value'] ?? 0;
        if ($goal < 0.6) {
            $issues[] = Judges::FIXES['goalFit'];
        }

        // Each social post.
        $social = [];
        foreach ($platforms as $key) {
            $p = Registry::get($key);
            $text = $draft['social'][$key] ?? '';
            $length = mb_strlen($key === 'x' ? preg_replace('~(https?://\S+|\{link\})~', str_repeat('x', 23), $text) : $text);
            $j = Judges::social($brand, $campaign, $p->label(), $p->rules(), $text);
            $score = Policy::composite($j);
            [$rk, $rp] = Policy::max_risk($j);
            $problems = [];
            if ($length > $p->char_limit()) {
                $problems[] = "is $length characters; the limit is {$p->char_limit()}";
            }
            if ($score < self::SOCIAL_BAR) {
                $problems[] = 'is weak (' . Policy::pct($score) . '): sharpen the hook and the push toward the goal';
            }
            if ($rp >= self::RISK_LIMIT) {
                $problems[] = 'has a risk: ' . Policy::RISK_LABELS[$rk] . ' (' . Policy::pct($rp) . ')';
            }
            if ($p->needs_image() && empty($image['id'])) {
                $problems[] = 'needs an image and none was created';
            }
            foreach ($problems as $pr) {
                $issues[] = 'The ' . $p->label() . " post $pr.";
            }
            $social[$key] = ['score' => $score, 'judgment' => $j, 'length' => $length, 'limit' => $p->char_limit(), 'ok' => !$problems];
        }

        $quality = round(0.8 * $jev + 0.2 * $checks['score'], 4);
        [$risk_key, $risk] = Policy::max_risk($article);
        $issues = array_values(array_unique(array_filter($issues)));
        $pass = $quality >= $bar && $goal >= 0.6 && $risk < self::RISK_LIMIT && !array_filter($social, static function ($s) {
            return !$s['ok'];
        }) && $checks['score'] >= 0.75;

        return [
            'pass' => $pass,
            'quality' => $quality,
            'goal_match' => $goal,
            'seo' => $checks['score'],
            'risk' => ['key' => $risk_key, 'value' => $risk],
            'article' => $article,
            'checks' => $checks,
            'social' => $social,
            'image' => $image['note'] ?? '',
            'issues' => $issues,
        ];
    }
}
