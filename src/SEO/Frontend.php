<?php

namespace Admun\GrowthEngine\SEO;

defined('ABSPATH') || exit;

/**
 * SEO output for campaign articles: FAQPage / HowTo JSON-LD, Article JSON-LD and meta tags when no
 * SEO plugin is active (SEO plugins print their own), and the quick-answer box style.
 */
class Frontend
{
    public static function register(): void
    {
        add_action('wp_head', [self::class, 'head'], 5);
        add_filter('pre_get_document_title', [self::class, 'title'], 20);
    }

    public static function title($title)
    {
        if (is_singular() && SeoBridge::active() === 'none') {
            $t = SeoBridge::meta_title((int) get_queried_object_id());
            if ($t !== '') {
                return $t;
            }
        }
        return $title;
    }

    public static function head(): void
    {
        if (!is_singular()) {
            return;
        }
        $id = (int) get_queried_object_id();
        if (!get_post_meta($id, '_age_item', true)) {
            return; // Only articles made by a campaign.
        }

        $own = SeoBridge::active() === 'none';
        if ($own) {
            $desc = SeoBridge::meta_description($id);
            $title = SeoBridge::meta_title($id) ?: get_the_title($id);
            $img = get_the_post_thumbnail_url($id, 'large');
            if ($desc !== '') {
                echo '<meta name="description" content="' . esc_attr($desc) . "\" />\n";
                echo '<meta property="og:description" content="' . esc_attr($desc) . "\" />\n";
            }
            echo '<meta property="og:type" content="article" />' . "\n";
            echo '<meta property="og:title" content="' . esc_attr($title) . "\" />\n";
            echo '<meta property="og:url" content="' . esc_url(get_permalink($id)) . "\" />\n";
            if ($img) {
                echo '<meta property="og:image" content="' . esc_url($img) . "\" />\n";
                echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
            }
        }

        $graph = [];
        $faq = get_post_meta($id, '_age_faq', true);
        if (is_array($faq) && $faq) {
            $graph[] = ['@type' => 'FAQPage', 'mainEntity' => array_map(static function ($f) {
                return ['@type' => 'Question', 'name' => $f['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']]];
            }, $faq)];
        }
        $howto = get_post_meta($id, '_age_howto', true);
        if (is_array($howto) && !empty($howto['applicable']) && !empty($howto['steps'])) {
            $graph[] = ['@type' => 'HowTo', 'name' => $howto['name'] ?: get_the_title($id), 'step' => array_map(static function ($s, $i) {
                return ['@type' => 'HowToStep', 'position' => $i + 1, 'name' => $s['name'], 'text' => $s['text']];
            }, $howto['steps'], array_keys($howto['steps']))];
        }
        if ($own) {
            $graph[] = array_filter([
                '@type' => 'Article',
                'headline' => get_the_title($id),
                'description' => SeoBridge::meta_description($id) ?: null,
                'datePublished' => get_the_date('c', $id),
                'dateModified' => get_the_modified_date('c', $id),
                'author' => ['@type' => 'Person', 'name' => get_the_author_meta('display_name', (int) get_post_field('post_author', $id))],
                'publisher' => ['@type' => 'Organization', 'name' => get_bloginfo('name'), 'url' => home_url('/')],
                'image' => get_the_post_thumbnail_url($id, 'full') ?: null,
                'mainEntityOfPage' => get_permalink($id),
            ]);
        }
        if ($graph) {
            echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
        }
        echo '<style>.age-quick-answer{border-left:4px solid currentColor;padding:.75em 1em;margin:0 0 1.5em;background:rgba(127,127,127,.08)}.age-quick-answer p{margin:0}</style>' . "\n";
    }
}
