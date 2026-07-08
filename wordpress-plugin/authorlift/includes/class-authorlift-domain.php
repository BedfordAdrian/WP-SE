<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Reference enums shared across the domain. */
class AuthorLift_Enums {
    const PLATFORMS = array('twitter', 'instagram', 'facebook', 'tiktok', 'threads', 'newsletter');
    const BOOK_STATUSES = array('draft', 'preorder', 'released');
    const GENRES = array(
        'Romance', 'Romantic Comedy', 'Romantasy', 'Fantasy', 'Science Fiction', 'Thriller',
        'Mystery', 'Horror', 'Literary Fiction', 'Historical Fiction', 'Young Adult',
        'Nonfiction', 'Memoir', 'Self-Help',
    );
    const CAMPAIGN_TYPES = array('launch', 'preorder', 'sale', 'newsletter_growth', 'evergreen');
    const CAMPAIGN_STATUSES = array('planning', 'active', 'completed', 'archived');
    const GOAL_METRICS = array('sales', 'subscribers', 'followers', 'engagement');
    const POST_STATUSES = array('draft', 'scheduled', 'published', 'failed');
    const POST_TYPES = array(
        'teaser', 'quote_card', 'cover_reveal', 'preorder_push', 'countdown', 'launch_day',
        'review_highlight', 'behind_the_scenes', 'trope_appeal', 'character_spotlight',
        'giveaway', 'newsletter_cta', 'sale_announcement', 'milestone', 'question_engagement',
    );
    const SALES_CHANNELS = array('amazon', 'kobo', 'apple', 'barnesnoble', 'audible', 'direct', 'other');
}

// ---------------------------------------------------------------- Author -----
class AuthorLift_Author {

    public static function normalize($input, $existing = array()) {
        $m = array_merge($existing, $input);
        $timezones = array_keys(authorlift_tz_offsets());
        return array(
            'penName' => authorlift_require_string(isset($m['penName']) ? $m['penName'] : null, 'penName', 120),
            'realName' => authorlift_optional_string(isset($m['realName']) ? $m['realName'] : null, 'realName', 120),
            'tagline' => authorlift_optional_string(isset($m['tagline']) ? $m['tagline'] : null, 'tagline', 200),
            'bio' => authorlift_optional_string(isset($m['bio']) ? $m['bio'] : null, 'bio', 2000),
            'website' => authorlift_optional_string(isset($m['website']) ? $m['website'] : null, 'website', 300),
            'timezone' => isset($m['timezone']) && $m['timezone'] ? authorlift_require_one_of($m['timezone'], $timezones, 'timezone') : 'America/New_York',
            'brandVoice' => authorlift_string_array(isset($m['brandVoice']) ? $m['brandVoice'] : null, 'brandVoice', 12),
            'handles' => self::normalize_handles(isset($m['handles']) && is_array($m['handles']) ? $m['handles'] : array()),
        );
    }

    private static function normalize_handles($handles) {
        $out = array();
        foreach (AuthorLift_Enums::PLATFORMS as $platform) {
            if ($platform === 'newsletter') {
                continue;
            }
            if (isset($handles[$platform]) && is_string($handles[$platform]) && trim($handles[$platform]) !== '') {
                $out[$platform] = ltrim(trim($handles[$platform]), '@');
            }
        }
        return $out;
    }

    public static function get($store) {
        return $store->get_author();
    }

    public static function save($store, $input) {
        $existing = $store->get_author();
        $author = self::normalize($input, is_array($existing) ? $existing : array());
        return $store->set_author($author);
    }
}

// ----------------------------------------------------------------- Books -----
class AuthorLift_Books {

    public static function normalize($input, $existing = array()) {
        $m = array_merge($existing, $input);
        return array(
            'title' => authorlift_require_string(isset($m['title']) ? $m['title'] : null, 'title', 300),
            'series' => authorlift_optional_string(isset($m['series']) ? $m['series'] : null, 'series', 200),
            'seriesNumber' => authorlift_optional_number(isset($m['seriesNumber']) ? $m['seriesNumber'] : null, 'seriesNumber', 0, 999),
            'genre' => isset($m['genre']) && $m['genre'] ? authorlift_require_one_of($m['genre'], AuthorLift_Enums::GENRES, 'genre') : 'Literary Fiction',
            'subgenres' => authorlift_string_array(isset($m['subgenres']) ? $m['subgenres'] : null, 'subgenres', 10),
            'blurb' => authorlift_optional_string(isset($m['blurb']) ? $m['blurb'] : null, 'blurb', 3000),
            'tagline' => authorlift_optional_string(isset($m['tagline']) ? $m['tagline'] : null, 'tagline', 200),
            'keywords' => authorlift_string_array(isset($m['keywords']) ? $m['keywords'] : null, 'keywords', 30),
            'tropes' => authorlift_string_array(isset($m['tropes']) ? $m['tropes'] : null, 'tropes', 30),
            'comps' => authorlift_string_array(isset($m['comps']) ? $m['comps'] : null, 'comps', 15),
            'quotes' => authorlift_string_array(isset($m['quotes']) ? $m['quotes'] : null, 'quotes', 50),
            'reviews' => self::normalize_reviews(isset($m['reviews']) ? $m['reviews'] : null),
            'buyLinks' => self::normalize_buy_links(isset($m['buyLinks']) ? $m['buyLinks'] : null),
            'price' => authorlift_optional_number(isset($m['price']) ? $m['price'] : null, 'price', 0, 1000),
            'releaseDate' => authorlift_parse_iso(isset($m['releaseDate']) ? $m['releaseDate'] : null),
            'status' => isset($m['status']) && $m['status'] ? authorlift_require_one_of($m['status'], AuthorLift_Enums::BOOK_STATUSES, 'status') : 'draft',
            'coverImageUrl' => authorlift_optional_string(isset($m['coverImageUrl']) ? $m['coverImageUrl'] : null, 'coverImageUrl', 500),
        );
    }

    private static function normalize_reviews($reviews) {
        if (!is_array($reviews)) {
            return array();
        }
        $out = array();
        foreach (array_slice($reviews, 0, 100) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $text = isset($r['text']) && is_string($r['text']) ? trim(substr($r['text'], 0, 500)) : '';
            if ($text === '') {
                continue;
            }
            $rating = isset($r['rating']) ? (int) $r['rating'] : 5;
            $rating = max(1, min(5, $rating));
            $out[] = array(
                'source' => isset($r['source']) && is_string($r['source']) ? trim(substr($r['source'], 0, 120)) : 'Reader',
                'rating' => $rating,
                'text' => $text,
            );
        }
        return $out;
    }

    private static function normalize_buy_links($links) {
        if (!is_array($links)) {
            return array();
        }
        $allowed = array('amazon', 'kobo', 'apple', 'barnesnoble', 'universal', 'audible');
        $out = array();
        foreach ($allowed as $key) {
            if (isset($links[$key]) && is_string($links[$key]) && trim($links[$key]) !== '') {
                $out[$key] = trim($links[$key]);
            }
        }
        return $out;
    }

    public static function all($store) {
        return $store->all('books');
    }
    public static function get($store, $id) {
        return $store->get('books', $id);
    }
    public static function create($store, $input) {
        return $store->insert('books', self::normalize($input));
    }
    public static function update($store, $id, $input) {
        $existing = $store->get('books', $id);
        if (!$existing) {
            return null;
        }
        return $store->update('books', $id, self::normalize($input, $existing));
    }
    public static function delete($store, $id) {
        foreach ($store->find('posts', function ($p) use ($id) { return $p['bookId'] === $id; }) as $post) {
            $store->update('posts', $post['id'], array('bookId' => null));
        }
        foreach ($store->find('campaigns', function ($c) use ($id) { return $c['bookId'] === $id; }) as $campaign) {
            $store->update('campaigns', $campaign['id'], array('bookId' => null));
        }
        return $store->remove('books', $id);
    }
}

// ------------------------------------------------------------- Campaigns -----
class AuthorLift_Campaigns {

    public static function normalize($input, $existing = array()) {
        $m = array_merge($existing, $input);
        $goal = null;
        if (isset($m['goal']) && is_array($m['goal']) && !empty($m['goal']['metric'])) {
            $goal = array(
                'metric' => authorlift_require_one_of($m['goal']['metric'], AuthorLift_Enums::GOAL_METRICS, 'goal.metric'),
                'target' => authorlift_optional_number(isset($m['goal']['target']) ? $m['goal']['target'] : 0, 'goal.target', 0) ?: 0,
            );
        }
        $start = authorlift_parse_iso(isset($m['startDate']) ? $m['startDate'] : null);
        authorlift_assert($start !== null, 'startDate is required', 'startDate');
        $end = authorlift_parse_iso(isset($m['endDate']) ? $m['endDate'] : null);
        if ($end !== null) {
            authorlift_assert(authorlift_ms($end) >= authorlift_ms($start), 'endDate must be on or after startDate', 'endDate');
        }
        return array(
            'name' => authorlift_require_string(isset($m['name']) ? $m['name'] : null, 'name', 200),
            'type' => isset($m['type']) && $m['type'] ? authorlift_require_one_of($m['type'], AuthorLift_Enums::CAMPAIGN_TYPES, 'type') : 'launch',
            'bookId' => isset($m['bookId']) ? $m['bookId'] : null,
            'startDate' => $start,
            'endDate' => $end,
            'goal' => $goal,
            'status' => isset($m['status']) && $m['status'] ? authorlift_require_one_of($m['status'], AuthorLift_Enums::CAMPAIGN_STATUSES, 'status') : 'planning',
            'notes' => authorlift_optional_string(isset($m['notes']) ? $m['notes'] : null, 'notes', 2000),
        );
    }

    public static function all($store) {
        $list = $store->all('campaigns');
        usort($list, function ($a, $b) {
            return authorlift_ms($b['startDate']) - authorlift_ms($a['startDate']);
        });
        return $list;
    }
    public static function get($store, $id) {
        return $store->get('campaigns', $id);
    }
    public static function create($store, $input) {
        if (!empty($input['bookId'])) {
            authorlift_assert($store->get('books', $input['bookId']), 'bookId does not reference an existing book', 'bookId');
        }
        return $store->insert('campaigns', self::normalize($input));
    }
    public static function update($store, $id, $input) {
        $existing = $store->get('campaigns', $id);
        if (!$existing) {
            return null;
        }
        if (!empty($input['bookId'])) {
            authorlift_assert($store->get('books', $input['bookId']), 'bookId does not reference an existing book', 'bookId');
        }
        return $store->update('campaigns', $id, self::normalize($input, $existing));
    }
    public static function delete($store, $id, $delete_posts = false) {
        foreach ($store->find('posts', function ($p) use ($id) { return $p['campaignId'] === $id; }) as $post) {
            if ($delete_posts && $post['status'] !== 'published') {
                $store->remove('posts', $post['id']);
            } else {
                $store->update('posts', $post['id'], array('campaignId' => null));
            }
        }
        return $store->remove('campaigns', $id);
    }
}

// ----------------------------------------------------------------- Posts -----
class AuthorLift_Posts {

    public static function empty_metrics() {
        return array('impressions' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => 0);
    }

    public static function normalize($input, $existing = array()) {
        $m = array_merge($existing, $input);
        $status = isset($m['status']) && $m['status'] ? authorlift_require_one_of($m['status'], AuthorLift_Enums::POST_STATUSES, 'status') : 'draft';
        $hashtags = array();
        foreach (authorlift_string_array(isset($m['hashtags']) ? $m['hashtags'] : null, 'hashtags', 30) as $h) {
            $hashtags[] = (strpos($h, '#') === 0) ? $h : '#' . preg_replace('/\s+/', '', $h);
        }
        $post = array(
            'platform' => authorlift_require_one_of(isset($m['platform']) ? $m['platform'] : null, AuthorLift_Enums::PLATFORMS, 'platform'),
            'type' => isset($m['type']) && $m['type'] ? authorlift_require_one_of($m['type'], AuthorLift_Enums::POST_TYPES, 'type') : 'teaser',
            'body' => authorlift_require_string(isset($m['body']) ? $m['body'] : null, 'body', 5000),
            'hashtags' => $hashtags,
            'cta' => authorlift_optional_string(isset($m['cta']) ? $m['cta'] : null, 'cta', 300),
            'mediaSuggestion' => authorlift_optional_string(isset($m['mediaSuggestion']) ? $m['mediaSuggestion'] : null, 'mediaSuggestion', 500),
            'bookId' => isset($m['bookId']) ? $m['bookId'] : null,
            'campaignId' => isset($m['campaignId']) ? $m['campaignId'] : null,
            'scheduledAt' => authorlift_parse_iso(isset($m['scheduledAt']) ? $m['scheduledAt'] : null),
            'status' => $status,
            'publishedAt' => isset($m['publishedAt']) ? $m['publishedAt'] : null,
            'externalId' => isset($m['externalId']) ? $m['externalId'] : null,
            'error' => isset($m['error']) ? $m['error'] : null,
            'metrics' => isset($m['metrics']) && is_array($m['metrics']) ? $m['metrics'] : self::empty_metrics(),
            'publishedVia' => isset($m['publishedVia']) ? $m['publishedVia'] : null,
        );
        if ($post['status'] === 'scheduled') {
            authorlift_assert($post['scheduledAt'], 'scheduledAt is required to schedule a post', 'scheduledAt');
        }
        return $post;
    }

    public static function all($store, $filters = array()) {
        $list = $store->find('posts', function ($p) use ($filters) {
            if (!empty($filters['status']) && $p['status'] !== $filters['status']) {
                return false;
            }
            if (!empty($filters['platform']) && $p['platform'] !== $filters['platform']) {
                return false;
            }
            if (!empty($filters['campaignId']) && $p['campaignId'] !== $filters['campaignId']) {
                return false;
            }
            if (!empty($filters['bookId']) && $p['bookId'] !== $filters['bookId']) {
                return false;
            }
            return true;
        });
        usort($list, array('AuthorLift_Posts', 'compare'));
        return $list;
    }

    public static function compare($a, $b) {
        $at = $a['scheduledAt'] ? authorlift_ms($a['scheduledAt']) : PHP_INT_MAX;
        $bt = $b['scheduledAt'] ? authorlift_ms($b['scheduledAt']) : PHP_INT_MAX;
        if ($at !== $bt) {
            return $at - $bt;
        }
        return authorlift_ms($a['createdAt']) - authorlift_ms($b['createdAt']);
    }

    public static function get($store, $id) {
        return $store->get('posts', $id);
    }

    private static function validate_refs($store, $input) {
        if (!empty($input['bookId'])) {
            authorlift_assert($store->get('books', $input['bookId']), 'bookId does not reference an existing book', 'bookId');
        }
        if (!empty($input['campaignId'])) {
            authorlift_assert($store->get('campaigns', $input['campaignId']), 'campaignId does not reference an existing campaign', 'campaignId');
        }
    }

    public static function create($store, $input) {
        self::validate_refs($store, $input);
        return $store->insert('posts', self::normalize($input));
    }

    public static function update($store, $id, $input) {
        $existing = $store->get('posts', $id);
        if (!$existing) {
            return null;
        }
        authorlift_assert($existing['status'] !== 'published', 'Published posts cannot be edited', 'status');
        self::validate_refs($store, array_merge($existing, $input));
        return $store->update('posts', $id, self::normalize($input, $existing));
    }

    public static function delete($store, $id) {
        return $store->remove('posts', $id);
    }

    public static function schedule($store, $id, $scheduledAt) {
        $existing = $store->get('posts', $id);
        if (!$existing) {
            return null;
        }
        authorlift_assert($existing['status'] !== 'published', 'Published posts cannot be rescheduled', 'status');
        $when = authorlift_parse_iso($scheduledAt);
        if ($when === null) {
            $when = $existing['scheduledAt'];
        }
        authorlift_assert($when, 'scheduledAt is required', 'scheduledAt');
        return $store->update('posts', $id, array('status' => 'scheduled', 'scheduledAt' => $when, 'error' => null));
    }
}

// -------------------------------------------------------------- Audience -----
class AuthorLift_Audience {

    public static function record_sale($store, $input) {
        $bookId = isset($input['bookId']) ? $input['bookId'] : null;
        if ($bookId) {
            authorlift_assert($store->get('books', $bookId), 'bookId does not reference an existing book', 'bookId');
        }
        $date = authorlift_parse_iso(isset($input['date']) ? $input['date'] : null);
        authorlift_assert($date !== null, 'date is required', 'date');
        return $store->insert('sales', array(
            'bookId' => $bookId,
            'date' => $date,
            'units' => authorlift_optional_number(isset($input['units']) ? $input['units'] : 0, 'units', 0, 1000000) ?: 0,
            'revenue' => authorlift_optional_number(isset($input['revenue']) ? $input['revenue'] : 0, 'revenue', 0, 100000000) ?: 0,
            'channel' => isset($input['channel']) && $input['channel'] ? authorlift_require_one_of($input['channel'], AuthorLift_Enums::SALES_CHANNELS, 'channel') : 'other',
            'source' => authorlift_optional_string(isset($input['source']) ? $input['source'] : null, 'source', 200),
        ));
    }

    public static function list_sales($store, $filters = array()) {
        $list = $store->find('sales', function ($s) use ($filters) {
            if (!empty($filters['bookId']) && $s['bookId'] !== $filters['bookId']) {
                return false;
            }
            if (!empty($filters['from']) && authorlift_ms($s['date']) < authorlift_ms($filters['from'])) {
                return false;
            }
            if (!empty($filters['to']) && authorlift_ms($s['date']) > authorlift_ms($filters['to'])) {
                return false;
            }
            return true;
        });
        usort($list, function ($a, $b) { return authorlift_ms($a['date']) - authorlift_ms($b['date']); });
        return $list;
    }

    public static function record_followers($store, $input) {
        $date = authorlift_parse_iso(isset($input['date']) ? $input['date'] : null);
        authorlift_assert($date !== null, 'date is required', 'date');
        return $store->insert('followerSnapshots', array(
            'platform' => authorlift_require_one_of(isset($input['platform']) ? $input['platform'] : null, AuthorLift_Enums::PLATFORMS, 'platform'),
            'date' => $date,
            'count' => authorlift_optional_number(isset($input['count']) ? $input['count'] : 0, 'count', 0) ?: 0,
        ));
    }

    public static function list_follower_snapshots($store, $platform = null) {
        $list = $store->find('followerSnapshots', function ($s) use ($platform) {
            return $platform ? $s['platform'] === $platform : true;
        });
        usort($list, function ($a, $b) { return authorlift_ms($a['date']) - authorlift_ms($b['date']); });
        return $list;
    }

    public static function record_subscribers($store, $input) {
        $date = authorlift_parse_iso(isset($input['date']) ? $input['date'] : null);
        authorlift_assert($date !== null, 'date is required', 'date');
        return $store->insert('subscriberSnapshots', array(
            'date' => $date,
            'count' => authorlift_optional_number(isset($input['count']) ? $input['count'] : 0, 'count', 0) ?: 0,
        ));
    }

    public static function list_subscriber_snapshots($store) {
        $list = $store->all('subscriberSnapshots');
        usort($list, function ($a, $b) { return authorlift_ms($a['date']) - authorlift_ms($b['date']); });
        return $list;
    }
}
