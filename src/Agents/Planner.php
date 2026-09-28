<?php

namespace Admun\GrowthEngine\Agents;

use Admun\GrowthEngine\AI\Claude;
use Admun\GrowthEngine\Campaigns\Store;
use Admun\GrowthEngine\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Planner agent: understands the topic and goal, then splits the posting period into a strategy and a
 * checklist, with one item per posting date. Each item says what to publish and what it must achieve.
 */
class Planner
{
    const MAX_ITEMS = 120;

    public static function run(array $campaign, string $start_date, string $end_date): array
    {
        $dates = array_slice(Store::schedule($start_date, $end_date, $campaign['per_week'], $campaign['post_time']), 0, self::MAX_ITEMS);
        if (!$dates) {
            throw new \RuntimeException('The period has no posting dates. Check the start and end dates.');
        }
        $n = count($dates);
        $brand = Settings::get('brand');
        $str = ['type' => 'string'];

        $plan = Claude::json(
            implode("\n", [
                "You are the campaign planner for {$brand['name']} ({$brand['product']}).",
                'You turn a goal into a publishing plan: a clear strategy and a checklist of pieces that, published in order, build toward the goal.',
                'Every piece is an SEO blog article with social posts promoting it, so each needs a real search keyword people type.',
                'Sequence matters: start broad to attract the audience and build trust, then go deeper, then drive the goal\'s action. No two pieces may cover the same thing.',
                "Write in {$brand['language']}. Never plan content about: {$brand['forbidden']}.",
            ]),
            "Campaign\nTopic: {$campaign['topic']}\nGoal: {$campaign['goal']}\n"
            . ($campaign['audience'] ? "Audience: {$campaign['audience']}\n" : '')
            . ($campaign['notes'] ? "Notes from the user: {$campaign['notes']}\n" : '')
            . "Period: $start_date to $end_date, {$campaign['per_week']} pieces per week.\n\n"
            . "Plan exactly $n checklist items, in publishing order.",
            [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['understanding', 'strategy', 'success_signals', 'keywords', 'checklist'],
                'properties' => [
                    'understanding' => ['type' => 'string', 'description' => 'One or two sentences restating what the user wants to achieve and for whom'],
                    'strategy' => ['type' => 'string', 'description' => '3–5 sentences: the approach and how the sequence builds toward the goal'],
                    'success_signals' => ['type' => 'array', 'items' => $str, 'description' => '2–4 measurable signs the campaign is working'],
                    'keywords' => ['type' => 'array', 'items' => $str, 'description' => 'The core keyword cluster for the topic'],
                    'checklist' => [
                        'type' => 'array',
                        'description' => "Exactly $n items",
                        'items' => [
                            'type' => 'object', 'additionalProperties' => false,
                            'required' => ['title', 'keyword', 'objective', 'angle'],
                            'properties' => [
                                'title' => ['type' => 'string', 'description' => 'Working title of the article'],
                                'keyword' => ['type' => 'string', 'description' => 'Primary search keyword for this piece'],
                                'objective' => ['type' => 'string', 'description' => 'What this piece must achieve for the goal, in one sentence'],
                                'angle' => ['type' => 'string', 'description' => 'The specific angle, format and key points'],
                            ],
                        ],
                    ],
                ],
            ],
            ['max_tokens' => 32000, 'timeout' => 600]
        );

        $checklist = array_slice((array) $plan['checklist'], 0, $n);
        if (count($checklist) < $n) {
            // Fewer ideas than dates: drop the trailing dates rather than inventing filler.
            $dates = array_slice($dates, 0, count($checklist));
        }
        $items = [];
        foreach ($checklist as $i => $c) {
            $items[] = [
                'publish_at' => $dates[$i],
                'title' => sanitize_text_field($c['title']),
                'keyword' => sanitize_text_field($c['keyword']),
                'objective' => sanitize_textarea_field($c['objective']),
                'angle' => sanitize_textarea_field($c['angle']),
            ];
        }
        unset($plan['checklist']);
        return ['plan' => $plan, 'items' => $items];
    }
}
