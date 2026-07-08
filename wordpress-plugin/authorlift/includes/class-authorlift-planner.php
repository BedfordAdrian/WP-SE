<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The Campaign Planner converts a campaign + book into a full, dated posting
 * schedule (a proven book-marketing "playbook") and optionally writes every
 * post into the scheduler.
 */
class AuthorLift_Planner {

    const AWARENESS_TYPES = array('teaser', 'trope_appeal', 'behind_the_scenes', 'character_spotlight', 'quote_card', 'newsletter_cta');
    const RAMP_TYPES = array('preorder_push', 'countdown', 'quote_card', 'review_highlight', 'trope_appeal');
    const SUSTAIN_TYPES = array('review_highlight', 'question_engagement', 'milestone', 'newsletter_cta');
    const SALE_TYPES = array('quote_card', 'review_highlight', 'trope_appeal', 'question_engagement');
    const NEWSLETTER_TYPES = array('newsletter_cta', 'giveaway', 'behind_the_scenes', 'quote_card', 'teaser');
    const EVERGREEN_TYPES = array('teaser', 'quote_card', 'trope_appeal', 'review_highlight', 'question_engagement', 'behind_the_scenes');

    private static function social_platforms($author) {
        $handles = (is_array($author) && isset($author['handles'])) ? $author['handles'] : array();
        $withHandles = array();
        foreach (AuthorLift_Enums::PLATFORMS as $p) {
            if ($p !== 'newsletter' && !empty($handles[$p])) {
                $withHandles[] = $p;
            }
        }
        if (count($withHandles) > 0) {
            return $withHandles;
        }
        return array('twitter', 'instagram', 'facebook', 'tiktok', 'threads');
    }

    private static function all_platforms($author) {
        $social = self::social_platforms($author);
        $social[] = 'newsletter';
        return $social;
    }

    private static function fill_window($fromOffset, $toOffset, $stepDays, $types, $platforms) {
        $beats = array();
        if (count($platforms) === 0 || $toOffset < $fromOffset) {
            return $beats;
        }
        $offset = $fromOffset;
        $i = 0;
        while ($offset <= $toOffset) {
            $beats[] = array(
                'offset' => $offset,
                'postType' => $types[$i % count($types)],
                'platform' => $platforms[$i % count($platforms)],
            );
            $offset += $stepDays;
            $i++;
        }
        return $beats;
    }

    private static function launch_beats($startOffset, $endOffset, $platforms) {
        $beats = array();
        $preStart = max($startOffset, -42);

        $revealOffset = max($preStart, -35);
        if ($revealOffset <= -7) {
            foreach ($platforms as $p) {
                $beats[] = array('offset' => $revealOffset, 'postType' => 'cover_reveal', 'platform' => $p);
            }
        }

        $beats = array_merge($beats, self::fill_window($preStart, -15, 3, self::AWARENESS_TYPES, $platforms));
        $beats = array_merge($beats, self::fill_window(-14, -2, 2, self::RAMP_TYPES, $platforms));
        foreach ($platforms as $p) {
            $beats[] = array('offset' => -1, 'postType' => 'countdown', 'platform' => $p);
        }
        foreach ($platforms as $p) {
            $beats[] = array('offset' => 0, 'postType' => 'launch_day', 'platform' => $p);
        }
        $beats = array_merge($beats, self::fill_window(2, min($endOffset, 21), 3, self::SUSTAIN_TYPES, $platforms));
        return $beats;
    }

    private static function sale_beats($durationDays, $platforms) {
        $beats = array();
        foreach ($platforms as $p) {
            $beats[] = array('offset' => 0, 'postType' => 'sale_announcement', 'platform' => $p);
        }
        $beats = array_merge($beats, self::fill_window(1, max(1, $durationDays - 1), 2, self::SALE_TYPES, $platforms));
        foreach ($platforms as $p) {
            $beats[] = array('offset' => max(1, $durationDays), 'postType' => 'sale_announcement', 'platform' => $p);
        }
        return $beats;
    }

    private static function resolve_window($campaign, $book) {
        $windowStart = authorlift_ms($campaign['startDate']);
        $isLaunch = in_array($campaign['type'], array('launch', 'preorder'), true);
        $anchor = ($isLaunch && !empty($book['releaseDate'])) ? authorlift_ms($book['releaseDate']) : $windowStart;
        if (!empty($campaign['endDate'])) {
            $windowEnd = authorlift_ms($campaign['endDate']);
        } elseif ($isLaunch) {
            $windowEnd = authorlift_add_days($anchor, 14);
        } else {
            $windowEnd = authorlift_add_days($windowStart, 30);
        }
        return array('windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'anchor' => $anchor, 'isLaunch' => $isLaunch);
    }

    private static function build_beats($campaign, $book, $author) {
        $w = self::resolve_window($campaign, $book);
        $social = self::social_platforms($author);
        $withNewsletter = self::all_platforms($author);
        $durationDays = max(1, authorlift_days_between($w['windowStart'], $w['windowEnd']));

        if ($w['isLaunch']) {
            $startOffset = authorlift_days_between($w['anchor'], $w['windowStart']);
            $endOffset = authorlift_days_between($w['anchor'], $w['windowEnd']);
            $beats = self::launch_beats($startOffset, $endOffset, $withNewsletter);
        } elseif ($campaign['type'] === 'sale') {
            $beats = self::sale_beats($durationDays, $social);
        } elseif ($campaign['type'] === 'newsletter_growth') {
            $beats = self::fill_window(0, $durationDays, 2, self::NEWSLETTER_TYPES, $withNewsletter);
            foreach ($social as $p) {
                $beats[] = array('offset' => 1, 'postType' => 'giveaway', 'platform' => $p);
            }
        } else {
            $beats = self::fill_window(0, $durationDays, 3, self::EVERGREEN_TYPES, $withNewsletter);
        }

        return array('beats' => $beats, 'windowStart' => $w['windowStart'], 'windowEnd' => $w['windowEnd'], 'anchor' => $w['anchor']);
    }

    public static function plan($store, $campaignId, $options = array()) {
        $dryRun = !empty($options['dryRun']);
        $nowMs = isset($options['now']) ? authorlift_ms($options['now']) : authorlift_ms();

        $campaign = $store->get('campaigns', $campaignId);
        if (!$campaign) {
            return null;
        }
        $book = !empty($campaign['bookId']) ? $store->get('books', $campaign['bookId']) : null;
        $author = $store->get_author();
        $tz = (is_array($author) && !empty($author['timezone'])) ? $author['timezone'] : 'UTC';

        $built = self::build_beats($campaign, is_array($book) ? $book : array(), is_array($author) ? $author : array());
        $slotCounter = array();
        $items = array();

        foreach ($built['beats'] as $beat) {
            $dayMs = authorlift_add_days($built['anchor'], $beat['offset']);
            $hours = AuthorLift_Recommendations::best_times_for($beat['platform']);
            $count = isset($slotCounter[$beat['platform']]) ? $slotCounter[$beat['platform']] : 0;
            $slotCounter[$beat['platform']] = $count + 1;
            $hour = $hours[$count % count($hours)];
            $scheduledMs = authorlift_at_local_hour($dayMs, $hour, $tz);

            if ($scheduledMs < $built['windowStart'] - 12 * 3600 * 1000) {
                continue;
            }
            if ($scheduledMs > $built['windowEnd'] + 12 * 3600 * 1000) {
                continue;
            }

            $seed = authorlift_hash_string($campaignId . ':' . $beat['offset'] . ':' . $beat['platform'] . ':' . $beat['postType']);
            $content = AuthorLift_Content::generate($store, array(
                'bookId' => $campaign['bookId'],
                'campaignId' => $campaignId,
                'postType' => $beat['postType'],
                'platform' => $beat['platform'],
                'referenceDate' => authorlift_iso($scheduledMs),
                'seed' => $seed,
            ));

            $content['scheduledAt'] = authorlift_iso($scheduledMs);
            $content['status'] = $scheduledMs > $nowMs ? 'scheduled' : 'draft';
            $content['offset'] = $beat['offset'];
            $items[] = $content;
        }

        usort($items, function ($a, $b) { return authorlift_ms($a['scheduledAt']) - authorlift_ms($b['scheduledAt']); });

        $summary = self::summarize($items, $built, $campaign);

        if ($dryRun) {
            return array('dryRun' => true, 'campaign' => $campaign, 'summary' => $summary, 'posts' => $items);
        }

        $created = array();
        foreach ($items as $item) {
            $created[] = AuthorLift_Posts::create($store, array(
                'platform' => $item['platform'],
                'type' => $item['type'],
                'body' => $item['body'],
                'hashtags' => $item['hashtags'],
                'cta' => $item['cta'],
                'mediaSuggestion' => $item['mediaSuggestion'],
                'bookId' => $item['bookId'],
                'campaignId' => $campaignId,
                'scheduledAt' => $item['scheduledAt'],
                'status' => $item['status'],
            ));
        }

        if ($campaign['status'] === 'planning') {
            $store->update('campaigns', $campaignId, array('status' => 'active'));
        }

        return array('dryRun' => false, 'campaign' => $store->get('campaigns', $campaignId), 'summary' => $summary, 'posts' => $created);
    }

    private static function summarize($items, $built, $campaign) {
        $byPlatform = array();
        $byType = array();
        $scheduled = 0;
        $drafts = 0;
        foreach ($items as $item) {
            $byPlatform[$item['platform']] = (isset($byPlatform[$item['platform']]) ? $byPlatform[$item['platform']] : 0) + 1;
            $byType[$item['type']] = (isset($byType[$item['type']]) ? $byType[$item['type']] : 0) + 1;
            if ($item['status'] === 'scheduled') {
                $scheduled++;
            } elseif ($item['status'] === 'draft') {
                $drafts++;
            }
        }
        return array(
            'totalPosts' => count($items),
            'scheduled' => $scheduled,
            'drafts' => $drafts,
            'byPlatform' => (object) $byPlatform,
            'byType' => (object) $byType,
            'windowStart' => authorlift_iso($built['windowStart']),
            'windowEnd' => authorlift_iso($built['windowEnd']),
            'launchDate' => authorlift_iso($built['anchor']),
            'campaignType' => $campaign['type'],
        );
    }
}
