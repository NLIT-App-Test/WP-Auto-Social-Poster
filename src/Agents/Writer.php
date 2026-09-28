<?php

namespace Admun\GrowthEngine\Agents;

use Admun\GrowthEngine\AI\Claude;
use Admun\GrowthEngine\Social\Registry;
use Admun\GrowthEngine\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Content agent: writes one checklist item as an SEO article plus a post for each social network,
 * following the plan. On a revision it receives the reviewer's (or the user's) issues and the last draft.
 */
class Writer
{
    public static function run(array $campaign, array $item, array $platforms, ?array $previous = null, array $issues = []): array
    {
        $brand = Settings::get('brand');
        $words = (int) Settings::get('words', 1200);
        $links = self::internal_links($item['keyword']);

        $rules = [];
        foreach ($platforms as $key) {
            $p = Registry::get($key);
            $rules[] = '- ' . $p->label() . " ($key): " . $p->rules() . ($p->link_in_text() ? ' Put the literal token {link} where the article URL should appear.' : '');
        }

        $system = implode("\n", [
            "You are the content writer for {$brand['name']}. You write articles that rank in search, get quoted by AI answer engines, and move readers toward the campaign goal; and the social posts that promote them.",
            '',
            'Standards:',
            '- SEO: the focus keyword appears in the title, SEO title, meta description, first paragraph and at least one H2, naturally. Descriptive H2/H3 headings, short paragraphs, lists or tables where they help.',
            '- Answer-first: open with the direct answer; start each section with a one-sentence answer, then expand.',
            '- Accuracy: never invent statistics, studies, quotes, customer names or results. Qualify claims. When citing a fact, link an authoritative source you are confident exists (official docs, standards bodies, major publications).',
            '- Include 2–4 internal links using ONLY URLs from the internal link list. End with one clear call to action that serves the campaign goal, linking to the CTA URL.',
            '- HTML only: <h2>, <h3>, <p>, <ul>, <ol>, <li>, <table>, <thead>, <tbody>, <tr>, <th>, <td>, <strong>, <em>, <a>, <blockquote>. No <h1>, no inline styles, no markdown.',
            '- Social posts promote this article and the campaign goal, follow each network\'s rules, and must not repeat the article intro word for word.',
            "- Write in {$brand['language']}.",
            '',
            "Brand: {$brand['name']}",
            "Product: {$brand['product']}",
            "Voice: {$brand['voice']}",
            "Never mention: {$brand['forbidden']}",
            "Call-to-action URL: {$brand['cta_url']}",
        ]);

        $brief = "Campaign topic: {$campaign['topic']}\nCampaign goal: {$campaign['goal']}\n"
            . ($campaign['audience'] ? "Audience: {$campaign['audience']}\n" : '')
            . ($campaign['notes'] ? "User notes: {$campaign['notes']}\n" : '')
            . "\nThis checklist item (#{$item['seq']}):\nWorking title: {$item['title']}\nFocus keyword: {$item['keyword']}\n"
            . "Objective: {$item['objective']}\nAngle: {$item['angle']}\n"
            . "Target length: about $words words.\n\n"
            . "Social networks:\n" . implode("\n", $rules) . "\n\n"
            . "Internal link list (title — URL):\n" . ($links ? implode("\n", $links) : '(none yet; skip internal links)');

        $prompt = $previous
            ? "Revise this draft so it fixes every issue below, keeping what already works. Return the complete revised piece.\n\nIssues:\n- "
                . implode("\n- ", $issues) . "\n\n$brief\n\nCurrent draft (JSON):\n" . wp_json_encode($previous)
            : "Write this checklist item.\n\n$brief";

        $d = Claude::json($system, $prompt, self::schema($platforms), ['max_tokens' => 20000, 'timeout' => 600]);
        return self::clean($d, $platforms);
    }

    private static function schema(array $platforms): array
    {
        $str = ['type' => 'string'];
        $social = [];
        foreach ($platforms as $key) {
            $social[$key] = ['type' => 'string', 'description' => 'Ready-to-publish post for ' . Registry::get($key)->label() . ', including hashtags'];
        }
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['title', 'slug', 'meta_title', 'meta_description', 'excerpt', 'quick_answer', 'content_html', 'faq', 'howto',
                'tags', 'social', 'image_prompt', 'image_query', 'image_alt'],
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Headline containing the focus keyword'],
                'slug' => ['type' => 'string', 'description' => 'lowercase-hyphenated slug, max 6 words, contains the keyword'],
                'meta_title' => ['type' => 'string', 'description' => 'SEO title, 30–60 characters, keyword near the start'],
                'meta_description' => ['type' => 'string', 'description' => '120–158 characters, benefit-led, includes the keyword'],
                'excerpt' => ['type' => 'string', 'description' => '1–2 sentence summary'],
                'quick_answer' => ['type' => 'string', 'description' => '40–60 word direct answer, quotable on its own'],
                'content_html' => ['type' => 'string', 'description' => 'Article body HTML, without H1, quick answer or FAQ'],
                'faq' => ['type' => 'array', 'description' => '3–5 real follow-up questions with self-contained 2–4 sentence answers', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['question', 'answer'],
                    'properties' => ['question' => $str, 'answer' => $str],
                ]],
                'howto' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['applicable', 'name', 'steps'],
                    'description' => 'applicable=true only if the article teaches a step-by-step procedure',
                    'properties' => [
                        'applicable' => ['type' => 'boolean'], 'name' => $str,
                        'steps' => ['type' => 'array', 'items' => [
                            'type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'text'],
                            'properties' => ['name' => $str, 'text' => $str],
                        ]],
                    ],
                ],
                'tags' => ['type' => 'array', 'items' => $str, 'description' => '3–6 WordPress tags'],
                'social' => ['type' => 'object', 'additionalProperties' => false, 'required' => array_values($platforms), 'properties' => $social ?: new \stdClass()],
                'image_prompt' => ['type' => 'string', 'description' => 'Detailed prompt for one striking photographic image for this piece; no text, letters or logos in the image'],
                'image_query' => ['type' => 'string', 'description' => '2–4 word stock photo search query as a fallback'],
                'image_alt' => ['type' => 'string', 'description' => 'Descriptive alt text including the focus keyword naturally'],
            ],
        ];
    }

    private static function clean(array $d, array $platforms): array
    {
        foreach (['title', 'slug', 'meta_title', 'meta_description', 'excerpt', 'quick_answer', 'image_prompt', 'image_query', 'image_alt'] as $k) {
            $d[$k] = trim(wp_strip_all_tags((string) ($d[$k] ?? '')));
        }
        $d['content_html'] = preg_replace('~</?h1[^>]*>~i', '', (string) ($d['content_html'] ?? ''));
        $d['faq'] = array_values(array_filter((array) ($d['faq'] ?? []), static function ($f) {
            return !empty($f['question']) && !empty($f['answer']);
        }));
        $d['tags'] = array_values(array_filter(array_map('sanitize_text_field', (array) ($d['tags'] ?? []))));
        $d['howto'] = is_array($d['howto'] ?? null) ? $d['howto'] : ['applicable' => false, 'name' => '', 'steps' => []];
        $social = [];
        foreach ($platforms as $key) {
            $social[$key] = trim((string) ($d['social'][$key] ?? ''));
        }
        $d['social'] = $social;
        return $d;
    }

    private static function internal_links(string $keyword): array
    {
        $ids = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 's' => $keyword, 'posts_per_page' => 12, 'fields' => 'ids']);
        $recent = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 25, 'fields' => 'ids']);
        $out = [];
        foreach (array_unique(array_merge($ids, $recent)) as $id) {
            $out[] = get_the_title($id) . ' — ' . get_permalink($id);
        }
        return array_slice($out, 0, 30);
    }
}
