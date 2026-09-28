<?php

namespace Admun\GrowthEngine\Social\Platforms;

use Admun\GrowthEngine\Social\Platform;
use Admun\GrowthEngine\Support\Http;

defined('ABSPATH') || exit;

/** Google Business Profile "What's new" posts. Each location is saved as its own account. */
class GoogleBusiness extends Platform
{
    public function key(): string { return 'gbp'; }
    public function label(): string { return 'Google Business Profile'; }
    public function char_limit(): int { return 1500; }

    public function rules(): string
    {
        return 'Google Business Profile update: 150–700 characters, local and practical, no hashtags, no phone numbers (a "Learn more" button links to the page).';
    }

    public function oauth(): ?array
    {
        return [
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'scopes' => ['https://www.googleapis.com/auth/business.manage'],
            'scope_sep' => ' ',
            'extra' => ['access_type' => 'offline', 'prompt' => 'consent'],
            'docs' => 'https://console.cloud.google.com/apis/credentials',
        ];
    }

    public function discover(array $token): array
    {
        $h = $this->bearer($token['access_token']);
        $accounts = Http::get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', $h);
        $out = [];
        foreach ($accounts['accounts'] ?? [] as $acc) {
            $locs = Http::get('https://mybusinessbusinessinformation.googleapis.com/v1/' . $acc['name'] . '/locations?readMask=name,title&pageSize=100', $h);
            foreach ($locs['locations'] ?? [] as $loc) {
                $out[] = [
                    'label' => $loc['title'],
                    'external_id' => $acc['name'] . '/' . $loc['name'],
                    'credentials' => $token,
                    'meta' => [],
                ];
            }
        }
        if (!$out) {
            throw new \RuntimeException('No Google Business Profile locations found for this Google account.');
        }
        return $out;
    }

    public function maybe_refresh(array &$account): bool
    {
        return $this->refresh_oauth2($account);
    }

    public function publish(array &$account, string $text, ?string $link, ?string $image): array
    {
        $post = [
            'languageCode' => substr(get_locale(), 0, 2),
            'summary' => $text,
            'topicType' => 'STANDARD',
        ];
        if ($image) {
            $post['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $image]];
        }
        if ($link) {
            $post['callToAction'] = ['actionType' => 'LEARN_MORE', 'url' => $link];
        }
        $res = Http::post('https://mybusiness.googleapis.com/v4/' . $account['external_id'] . '/localPosts', $post,
            $this->bearer($account['credentials']['access_token']));
        return ['id' => $res['name'], 'url' => $res['searchUrl'] ?? null];
    }
}
