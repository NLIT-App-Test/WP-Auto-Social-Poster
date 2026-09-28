<?php

namespace Admun\GrowthEngine\AI;

defined('ABSPATH') || exit;

/**
 * The questions the reviewer asks Jev. Each set asks independent questions over one shared state so
 * they run in a single parallel request. Scores become 0–1 values; Nouls become risk probabilities.
 */
class Judges
{
    /** What the writer is told when a dimension scores low. */
    const FIXES = [
        'goalFit' => 'Tie the article more directly to the campaign goal: make the reader want to take the goal\'s action, and make the call to action concrete.',
        'itemFit' => 'Deliver exactly what this checklist item asked for: its objective and angle, not a generic article on the topic.',
        'intent' => 'Answer the searcher\'s question for the focus keyword directly and early; cut sections that do not serve that intent.',
        'depth' => 'Add the subtopics a reader expects next, with concrete steps, examples and trade-offs.',
        'accuracy' => 'Keep every factual statement precise and verifiable; remove vague or dubious claims and link authoritative sources for facts.',
        'readability' => 'Use short paragraphs, descriptive H2/H3 headings, and lists or tables where they help scanning.',
        'answerFirst' => 'Open with a 40–60 word direct answer, and start each section with a one-sentence answer before elaborating.',
        'voice' => 'Match the brand voice consistently; remove generic marketing phrasing.',
    ];

    const RISK_FIXES = [
        'unverifiableClaim' => 'Remove or soften any specific statistic, result, quote or claim that is not clearly supported; keep only verifiable facts with sources.',
        'offensive' => 'Remove anything that could offend or alienate readers.',
        'forbiddenTopic' => 'Remove every mention of the brand\'s forbidden topics.',
        'misleading' => 'Remove promises of guaranteed outcomes and describe the product accurately.',
    ];

    /** Does the article serve the user's goal, this checklist item, SEO intent and factual standards? */
    public static function article(array $brand, array $campaign, array $item, array $draft): array
    {
        $q = [
            'goalFit' => Jev::score('How directly does `article` move `audience` toward `campaign.goal`?', [
                'Unrelated to the goal',
                'On topic but does not advance the goal',
                'Supports the goal with a clear next step',
                'Strongly drives the goal: motivated reader, specific call to action',
            ]),
            'itemFit' => Jev::score('How well does `article` deliver `item.objective` using `item.angle`?', [
                'Ignores the objective and angle',
                'Touches the objective but drifts into generic content',
                'Delivers the objective with the requested angle',
                'Delivers the objective precisely and memorably',
            ]),
            'intent' => Jev::score('How well does `article` satisfy the search intent behind `item.keyword`?', [
                'Misses the intent; a searcher would bounce',
                'Partly relevant; buries the main need',
                'Satisfies the main intent',
                'Fully satisfies the intent and anticipates follow-ups',
            ]),
            'depth' => Jev::score('How complete and substantive is `article.body`?', [
                'Thin, generic statements',
                'Covers basics, skips important subtopics',
                'Solid coverage with concrete detail',
                'Comprehensive, specific, and practically useful',
            ]),
            'accuracy' => Jev::score('How precise and trustworthy are the factual statements in `article`?', [
                'Vague, dubious or contradictory claims',
                'Mostly general statements with a few shaky claims',
                'Accurate, careful statements',
                'Precise, well-qualified statements with sources where facts are cited',
            ]),
            'readability' => Jev::score('How easy is `article.body` to scan and read on the web?', [
                'Walls of text', 'Readable but poorly structured', 'Clear headings and short paragraphs', 'Excellent structure with lists or tables where useful',
            ]),
            'answerFirst' => Jev::score('How well is `article` built for search snippets and AI answer engines, with direct quotable answers up front and at the start of sections?', [
                'No direct answers', 'Answers buried in paragraphs', 'Clear direct answers in most key places', 'Consistently answer-first and quotable',
            ]),
            'voice' => Jev::score('How well does `article` match `brand.voice`?', [
                'Contradicts the voice', 'Generic tone', 'Mostly on-voice', 'Unmistakably on-voice',
            ]),
        ] + self::risk_questions('`article`');

        $r = Jev::ask([
            'brand' => $brand,
            'campaign' => ['topic' => $campaign['topic'], 'goal' => $campaign['goal']],
            'audience' => $campaign['audience'] ?: 'general audience',
            'item' => ['keyword' => $item['keyword'], 'objective' => $item['objective'], 'angle' => $item['angle']],
            'article' => [
                'title' => $draft['title'],
                'meta_description' => $draft['meta_description'],
                'quick_answer' => $draft['quick_answer'],
                'body' => mb_substr(wp_strip_all_tags(str_replace('</', "\n</", $draft['content_html'])), 0, 60000),
                'faq' => $draft['faq'],
            ],
        ], $q);
        return self::shape($r, $q);
    }

    /** Is this social post good for its network, and does it push the goal? */
    public static function social(array $brand, array $campaign, string $platform, string $rules, string $text): array
    {
        $q = [
            'hook' => Jev::score('How strongly does the opening line of `post` make the audience stop scrolling and read on?', [
                'Forgettable opener', 'Clear but ordinary', 'Specific and intriguing', 'Sharp; very likely to stop the scroll',
            ]),
            'goalFit' => Jev::score('How directly does `post` push readers toward `campaign.goal`?', [
                'Unrelated to the goal', 'On topic but no push toward the goal', 'Clear push with a next step', 'Compelling, specific push toward the goal',
            ]),
            'platformFit' => Jev::score('How well does `post` follow `platform_rules`: length, structure, formatting and hashtags?', [
                'Looks wrong for the platform', 'Breaks some rules noticeably', 'Minor deviations', 'Native to the platform',
            ]),
            'voice' => Jev::score('How well does `post` match `brand.voice`?', [
                'Contradicts the voice', 'Generic', 'Mostly on-voice', 'Unmistakably on-voice',
            ]),
        ] + self::risk_questions('`post`');

        $r = Jev::ask([
            'brand' => $brand,
            'campaign' => ['topic' => $campaign['topic'], 'goal' => $campaign['goal']],
            'platform' => $platform,
            'platform_rules' => $rules,
            'post' => $text,
        ], $q);
        return self::shape($r, $q);
    }

    private static function risk_questions(string $subject): array
    {
        return [
            'unverifiableClaim' => Jev::noul(
                "Does $subject state a specific statistic, result, guarantee, customer quote, or award that is not supported by the information provided?",
                'Contains at least one specific factual claim not backed by the provided info',
                'Every factual claim is general opinion or backed by the provided info'
            ),
            'offensive' => Jev::noul("Could $subject reasonably offend, stereotype, mock, or alienate a group of people, or embarrass the brand publicly?"),
            'forbiddenTopic' => Jev::noul("Does $subject mention or allude to anything listed in `brand.forbidden`?"),
            'misleading' => Jev::noul("Does $subject overstate or misrepresent what `brand.product` does, or promise outcomes that cannot be guaranteed?"),
        ];
    }

    /** Split Jev answers into normalized scores and risk probabilities. */
    private static function shape(array $r, array $questions): array
    {
        $scores = [];
        $risks = [];
        foreach ($questions as $key => $question) {
            $a = $r['answers'][$key] ?? null;
            if (!$a) {
                continue;
            }
            if ($question['type'] === 'score') {
                $scores[$key] = [
                    'value' => round(Jev::norm($a, count($question['criteria'])), 3),
                    'confidence' => round((float) ($a['confidence'] ?? 0), 3),
                ];
            } else {
                $risks[$key] = round((float) $a['noul'], 3);
            }
        }
        return ['model' => $r['model'], 'scores' => $scores, 'risks' => $risks];
    }
}
