<?php

namespace Admun\GrowthEngine\SEO;

defined('ABSPATH') || exit;

/**
 * Deterministic on-page SEO checks. These are rules, so code verifies them instead of a model.
 * Check selection follows the Yoast / Rank Math / AIOSEO analyses that AI Puffer optimizes for.
 */
class Checks
{
    /**
     * @param array $a {title, meta_title?, meta_description, slug?, focus_keyword, content_html, faq?, target_words?}
     * @return array{score: float, checks: array<int, array{id: string, label: string, pass: bool, fix: string}>}
     */
    public static function run(array $a): array
    {
        $kw = self::norm($a['focus_keyword'] ?? '');
        $html = (string) ($a['content_html'] ?? '');
        $text = self::text($html);
        $words = str_word_count(wp_strip_all_tags($text));
        $title = (string) ($a['meta_title'] ?? '') ?: (string) ($a['title'] ?? '');
        $desc = (string) ($a['meta_description'] ?? '');
        $slug = (string) ($a['slug'] ?? '');
        preg_match_all('~<h2[^>]*>(.*?)</h2>~is', $html, $h2);
        preg_match_all('~<p[^>]*>(.*?)</p>~is', $html, $ps);
        $first_p = self::norm(wp_strip_all_tags($ps[1][0] ?? mb_substr($text, 0, 400)));
        $internal = preg_match_all('~<a\s[^>]*href=["\'](' . preg_quote(home_url(), '~') . '|/)[^"\']*~i', $html);
        $external = preg_match_all('~<a\s[^>]*href=["\']https?://(?!' . preg_quote((string) wp_parse_url(home_url(), PHP_URL_HOST), '~') . ')~i', $html);
        $target = (int) ($a['target_words'] ?? 0);
        $kw_count = $kw !== '' ? substr_count(self::norm($text), $kw) : 0;
        $density = $words ? ($kw_count * max(1, str_word_count($kw))) / $words * 100 : 0;
        $long_paras = 0;
        foreach ($ps[1] as $p) {
            if (str_word_count(wp_strip_all_tags($p)) > 120) {
                $long_paras++;
            }
        }

        $checks = [
            ['kw_title', 'Focus keyword in SEO title', $kw !== '' && strpos(self::norm($title), $kw) !== false,
                'Include the exact focus keyword in the SEO title, ideally near the start.'],
            ['title_len', 'SEO title 30–60 characters', mb_strlen($title) >= 30 && mb_strlen($title) <= 60,
                'Make the SEO title 30–60 characters (currently ' . mb_strlen($title) . ').'],
            ['desc_len', 'Meta description 120–158 characters', mb_strlen($desc) >= 120 && mb_strlen($desc) <= 158,
                'Make the meta description 120–158 characters (currently ' . mb_strlen($desc) . ').'],
            ['kw_desc', 'Focus keyword in meta description', $kw !== '' && strpos(self::norm($desc), $kw) !== false,
                'Use the focus keyword naturally in the meta description.'],
            ['kw_intro', 'Focus keyword in the first paragraph', $kw !== '' && strpos($first_p, $kw) !== false,
                'Use the focus keyword in the first paragraph.'],
            ['kw_h2', 'Focus keyword in a subheading', $kw !== '' && (bool) array_filter($h2[1], static function ($h) use ($kw) {
                return strpos(self::norm(wp_strip_all_tags($h)), $kw) !== false;
            }), 'Use the focus keyword (or a close variant) in at least one H2.'],
            ['density', 'Keyword density 0.5–2.5%', $density >= 0.5 && $density <= 2.5,
                sprintf('Adjust focus keyword usage to 0.5–2.5%% density (currently %.1f%%).', $density)],
            ['structure', 'At least 3 H2 sections', count($h2[1]) >= 3, 'Break the article into at least 3 H2 sections.'],
            ['no_h1', 'No H1 inside the body', !preg_match('~<h1~i', $html), 'Remove H1 tags from the body; WordPress prints the title as H1.'],
            ['paragraphs', 'Paragraphs under 120 words', $long_paras === 0, "Split the $long_paras paragraph(s) longer than 120 words."],
            ['internal', 'At least 2 internal links', $internal >= 2, 'Add at least 2 relevant internal links from the provided list.'],
            ['external', 'At least 1 authoritative outbound link', $external >= 1, 'Cite at least one authoritative external source with a link.'],
        ];
        if ($slug !== '') {
            $checks[] = ['slug', 'Short slug containing the keyword', mb_strlen($slug) <= 75 && ($kw === '' || strpos(str_replace('-', ' ', $slug), $kw) !== false),
                'Use a short slug (≤75 chars) containing the focus keyword.'];
        }
        if ($target > 0) {
            $checks[] = ['length', "Length near target ($target words)", $words >= $target * 0.75,
                "Expand to about $target words (currently $words)."];
        }
        if (isset($a['faq'])) {
            $checks[] = ['faq', 'At least 3 FAQs', count((array) $a['faq']) >= 3, 'Add at least 3 FAQ questions with direct answers.'];
        }

        $out = [];
        $pass = 0;
        foreach ($checks as [$id, $label, $ok, $fix]) {
            $out[] = ['id' => $id, 'label' => $label, 'pass' => (bool) $ok, 'fix' => $fix];
            $pass += $ok ? 1 : 0;
        }
        return ['score' => $checks ? round($pass / count($checks), 3) : 0, 'checks' => $out, 'words' => $words];
    }

    public static function failed_fixes(array $result): array
    {
        return array_values(array_map(static function ($c) {
            return $c['fix'];
        }, array_filter($result['checks'], static function ($c) {
            return !$c['pass'];
        })));
    }

    private static function text(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(str_replace('<', ' <', $html))));
    }

    private static function norm(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(remove_accents($s))));
    }
}
