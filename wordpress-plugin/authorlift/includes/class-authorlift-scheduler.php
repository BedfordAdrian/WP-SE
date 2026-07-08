<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Publisher registry + the simulated default adapter.
 *
 * A publisher is an array: [ 'name' => string, 'simulated' => bool,
 * 'publish' => callable($post, $context) : ['externalId','publishedAt','metrics'] ].
 * Register real network adapters via the `authorlift_publishers` filter.
 */
class AuthorLift_Publishers {

    const IMPRESSION_FACTOR = array(
        'twitter' => 1.2, 'instagram' => 1.6, 'facebook' => 0.7, 'tiktok' => 3.5, 'threads' => 1.1, 'newsletter' => 0.45,
    );

    const TYPE_REACH = array(
        'launch_day' => 1.8, 'cover_reveal' => 1.5, 'giveaway' => 2.2, 'sale_announcement' => 1.6,
        'quote_card' => 1.3, 'countdown' => 1.2, 'review_highlight' => 1.1, 'teaser' => 1.0,
        'trope_appeal' => 1.15, 'character_spotlight' => 1.0, 'behind_the_scenes' => 0.95,
        'question_engagement' => 1.25, 'milestone' => 1.05, 'newsletter_cta' => 0.9, 'preorder_push' => 1.1,
    );

    const CLICK_RATE = array(
        'preorder_push' => 0.09, 'launch_day' => 0.11, 'sale_announcement' => 0.13, 'newsletter_cta' => 0.08,
        'countdown' => 0.06, 'cover_reveal' => 0.03, 'giveaway' => 0.05, 'review_highlight' => 0.05,
        'quote_card' => 0.02, 'teaser' => 0.03, 'trope_appeal' => 0.035, 'character_spotlight' => 0.02,
        'behind_the_scenes' => 0.015, 'question_engagement' => 0.01, 'milestone' => 0.02,
    );

    private static function jitter($rng, $spread = 0.35) {
        return 1 - $spread / 2 + $rng->next() * $spread;
    }

    public static function simulated_metrics($post, $reach = 3000) {
        $rng = new AuthorLift_Rng(authorlift_hash_string((isset($post['id']) ? $post['id'] : $post['type']) . ':' . $post['platform']));
        $impressionFactor = isset(self::IMPRESSION_FACTOR[$post['platform']]) ? self::IMPRESSION_FACTOR[$post['platform']] : 1;
        $typeReach = isset(self::TYPE_REACH[$post['type']]) ? self::TYPE_REACH[$post['type']] : 1;

        $impressions = (int) round($reach * $impressionFactor * $typeReach * self::jitter($rng));
        $likeRate = (0.02 + $rng->next() * 0.03) * ($post['platform'] === 'tiktok' ? 1.4 : 1);
        $likes = (int) round($impressions * $likeRate);
        $comments = (int) round($likes * (0.05 + $rng->next() * 0.08));
        $shares = (int) round($likes * (0.08 + $rng->next() * 0.1));
        $clickRate = (isset(self::CLICK_RATE[$post['type']]) ? self::CLICK_RATE[$post['type']] : 0.02) * self::jitter($rng, 0.2);
        $clicks = (int) round($impressions * $clickRate);

        return array(
            'impressions' => max(0, $impressions),
            'likes' => max(0, $likes),
            'comments' => max(0, $comments),
            'shares' => max(0, $shares),
            'clicks' => max(0, $clicks),
        );
    }

    public static function registry() {
        $registry = array(
            'simulated' => array(
                'name' => 'simulated',
                'simulated' => true,
                'publish' => function ($post, $context) {
                    $reach = isset($context['reach']) ? $context['reach'] : 3000;
                    $nowMs = isset($context['now']) ? authorlift_ms($context['now']) : authorlift_ms();
                    return array(
                        'externalId' => 'sim_' . dechex(authorlift_hash_string($post['id'] . ':' . (isset($post['scheduledAt']) ? $post['scheduledAt'] : ''))),
                        'publishedAt' => authorlift_iso($nowMs),
                        'metrics' => self::simulated_metrics($post, $reach),
                    );
                },
            ),
        );
        // Allow real network adapters to register.
        $registry = apply_filters('authorlift_publishers', $registry);
        return $registry;
    }

    public static function get($name) {
        $registry = self::registry();
        return isset($registry[$name]) ? $registry[$name] : null;
    }

    public static function get_active($store) {
        $settings = $store->get_settings();
        $name = isset($settings['activePublisher']) ? $settings['activePublisher'] : 'simulated';
        $publisher = self::get($name);
        return $publisher ? $publisher : self::get('simulated');
    }

    public static function listing() {
        $out = array();
        foreach (self::registry() as $p) {
            $out[] = array('name' => $p['name'], 'simulated' => !empty($p['simulated']));
        }
        return $out;
    }
}

/**
 * The scheduling engine. WP-Cron calls run_due() every few minutes.
 */
class AuthorLift_Scheduler {

    const DEFAULT_REACH = array(
        'twitter' => 2500, 'instagram' => 3500, 'facebook' => 1500, 'tiktok' => 6000, 'threads' => 1800, 'newsletter' => 1200,
    );

    private static function reach_for($store, $platform) {
        if ($platform === 'newsletter') {
            $subs = AuthorLift_Audience::list_subscriber_snapshots($store);
            return count($subs) ? $subs[count($subs) - 1]['count'] : self::DEFAULT_REACH['newsletter'];
        }
        $snaps = AuthorLift_Audience::list_follower_snapshots($store, $platform);
        if (count($snaps)) {
            return $snaps[count($snaps) - 1]['count'];
        }
        return isset(self::DEFAULT_REACH[$platform]) ? self::DEFAULT_REACH[$platform] : 2000;
    }

    public static function publish_post($store, $postId, $nowIso = null) {
        $post = $store->get('posts', $postId);
        if (!$post) {
            return null;
        }
        if ($post['status'] === 'published') {
            return $post;
        }
        $nowMs = $nowIso ? authorlift_ms($nowIso) : authorlift_ms();
        $publisher = AuthorLift_Publishers::get_active($store);
        $author = $store->get_author();
        $book = !empty($post['bookId']) ? $store->get('books', $post['bookId']) : null;
        $reach = self::reach_for($store, $post['platform']);

        try {
            $result = call_user_func($publisher['publish'], $post, array(
                'author' => $author, 'book' => $book, 'reach' => $reach, 'now' => authorlift_iso($nowMs),
            ));
            return $store->update('posts', $postId, array(
                'status' => 'published',
                'publishedAt' => isset($result['publishedAt']) ? $result['publishedAt'] : authorlift_iso($nowMs),
                'externalId' => isset($result['externalId']) ? $result['externalId'] : null,
                'error' => null,
                'metrics' => isset($result['metrics']) ? $result['metrics'] : $post['metrics'],
                'publishedVia' => $publisher['name'],
            ));
        } catch (Exception $e) {
            return $store->update('posts', $postId, array('status' => 'failed', 'error' => $e->getMessage()));
        }
    }

    public static function run_due($store, $nowIso = null) {
        $nowMs = $nowIso ? authorlift_ms($nowIso) : authorlift_ms();
        $due = $store->find('posts', function ($p) use ($nowMs) {
            return $p['status'] === 'scheduled' && $p['scheduledAt'] && authorlift_ms($p['scheduledAt']) <= $nowMs;
        });
        usort($due, function ($a, $b) { return authorlift_ms($a['scheduledAt']) - authorlift_ms($b['scheduledAt']); });

        $store->set_autoflush(false);
        $published = array();
        $failed = array();
        foreach ($due as $post) {
            $updated = self::publish_post($store, $post['id'], authorlift_iso($nowMs));
            if ($updated && $updated['status'] === 'published') {
                $published[] = $updated['id'];
            } elseif ($updated && $updated['status'] === 'failed') {
                $failed[] = $updated['id'];
            }
        }
        $store->set_autoflush(true);
        $store->flush();
        return array('published' => $published, 'failed' => $failed);
    }
}
