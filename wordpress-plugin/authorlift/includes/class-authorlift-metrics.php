<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analytics: engagement aggregation, audience growth, and the sales-bump metric
 * that compares in-window sales against a matched baseline before it.
 */
class AuthorLift_Metrics {

    public static function engagement_rate($m) {
        if (empty($m['impressions'])) {
            return 0;
        }
        $engaged = (isset($m['likes']) ? $m['likes'] : 0) + (isset($m['comments']) ? $m['comments'] : 0) + (isset($m['shares']) ? $m['shares'] : 0);
        return $engaged / $m['impressions'];
    }

    public static function sum_metrics($posts) {
        $t = array('impressions' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => 0);
        foreach ($posts as $p) {
            $m = isset($p['metrics']) ? $p['metrics'] : array();
            foreach ($t as $k => $v) {
                $t[$k] += isset($m[$k]) ? $m[$k] : 0;
            }
        }
        return $t;
    }

    public static function current_followers($store) {
        $byPlatform = array();
        foreach ($store->all('followerSnapshots') as $snap) {
            $p = $snap['platform'];
            if (!isset($byPlatform[$p]) || authorlift_ms($snap['date']) >= authorlift_ms($byPlatform[$p]['date'])) {
                $byPlatform[$p] = $snap;
            }
        }
        $platforms = array();
        $total = 0;
        foreach ($byPlatform as $p => $snap) {
            $platforms[$p] = $snap['count'];
            $total += $snap['count'];
        }
        return array('total' => $total, 'platforms' => (object) $platforms);
    }

    public static function overview($store, $nowIso = null) {
        $nowMs = $nowIso ? authorlift_ms($nowIso) : authorlift_ms();
        $published = AuthorLift_Posts::all($store, array('status' => 'published'));
        $scheduled = AuthorLift_Posts::all($store, array('status' => 'scheduled'));
        $totals = self::sum_metrics($published);

        $next7 = array_filter($scheduled, function ($p) use ($nowMs) {
            $t = authorlift_ms($p['scheduledAt']);
            return $t >= $nowMs && $t <= $nowMs + 7 * AUTHORLIFT_DAY_MS;
        });

        $sales30 = AuthorLift_Audience::list_sales($store, array('from' => authorlift_iso($nowMs - 30 * AUTHORLIFT_DAY_MS)));
        $units30 = 0;
        $rev30 = 0;
        foreach ($sales30 as $s) {
            $units30 += $s['units'];
            $rev30 += $s['revenue'];
        }

        $subs = AuthorLift_Audience::list_subscriber_snapshots($store);

        $totals['engagementRate'] = authorlift_round(self::engagement_rate($totals), 4);
        return array(
            'publishedPosts' => count($published),
            'scheduledPosts' => count($scheduled),
            'postsNext7Days' => count($next7),
            'engagement' => $totals,
            'followers' => self::current_followers($store),
            'subscribers' => count($subs) ? $subs[count($subs) - 1]['count'] : 0,
            'sales30d' => array('units' => $units30, 'revenue' => authorlift_round($rev30, 2)),
        );
    }

    private static function pct($current, $base) {
        if (!$base) {
            return null;
        }
        return authorlift_round((($current - $base) / $base) * 100, 1);
    }

    public static function sales_bump($store, $args) {
        $bookId = isset($args['bookId']) ? $args['bookId'] : null;
        $startMs = authorlift_ms($args['windowStart']);
        $endMs = authorlift_ms($args['windowEnd']);
        $windowDays = max(1, authorlift_days_between($startMs, $endMs) + 1);
        $baseDays = isset($args['baselineDays']) && $args['baselineDays'] ? $args['baselineDays'] : $windowDays;
        $baselineStart = authorlift_add_days($startMs, -$baseDays);
        // Cover the FULL final day of the window (mirrors the baseline boundary).
        $windowEndExclusive = authorlift_add_days($startMs, $windowDays) - 1;

        $inWindow = AuthorLift_Audience::list_sales($store, array(
            'bookId' => $bookId,
            'from' => authorlift_iso($startMs),
            'to' => authorlift_iso($windowEndExclusive),
        ));
        $inBaseline = AuthorLift_Audience::list_sales($store, array(
            'bookId' => $bookId,
            'from' => authorlift_iso($baselineStart),
            'to' => authorlift_iso($startMs - 1),
        ));

        $windowUnits = 0; $windowRevenue = 0; $baselineUnits = 0; $baselineRevenue = 0;
        foreach ($inWindow as $s) { $windowUnits += $s['units']; $windowRevenue += $s['revenue']; }
        foreach ($inBaseline as $s) { $baselineUnits += $s['units']; $baselineRevenue += $s['revenue']; }

        $windowDailyUnits = $windowUnits / $windowDays;
        $baselineDailyUnits = $baselineUnits / $baseDays;
        $windowDailyRevenue = $windowRevenue / $windowDays;
        $baselineDailyRevenue = $baselineRevenue / $baseDays;

        return array(
            'windowStart' => authorlift_iso($startMs),
            'windowEnd' => authorlift_iso($endMs),
            'windowDays' => $windowDays,
            'baselineDays' => $baseDays,
            'window' => array(
                'units' => $windowUnits,
                'revenue' => authorlift_round($windowRevenue, 2),
                'dailyUnits' => authorlift_round($windowDailyUnits, 2),
                'dailyRevenue' => authorlift_round($windowDailyRevenue, 2),
            ),
            'baseline' => array(
                'units' => $baselineUnits,
                'revenue' => authorlift_round($baselineRevenue, 2),
                'dailyUnits' => authorlift_round($baselineDailyUnits, 2),
                'dailyRevenue' => authorlift_round($baselineDailyRevenue, 2),
            ),
            'uplift' => array(
                'units' => authorlift_round($windowUnits - ($baselineDailyUnits * $windowDays), 1),
                'unitsPct' => self::pct($windowDailyUnits, $baselineDailyUnits),
                'revenue' => authorlift_round($windowRevenue - ($baselineDailyRevenue * $windowDays), 2),
                'revenuePct' => self::pct($windowDailyRevenue, $baselineDailyRevenue),
            ),
        );
    }

    public static function campaign_report($store, $campaignId) {
        $campaign = $store->get('campaigns', $campaignId);
        if (!$campaign) {
            return null;
        }
        $posts = AuthorLift_Posts::all($store, array('campaignId' => $campaignId));
        $published = array_filter($posts, function ($p) { return $p['status'] === 'published'; });
        $totals = self::sum_metrics($published);

        $windowStart = $campaign['startDate'];
        $book = !empty($campaign['bookId']) ? $store->get('books', $campaign['bookId']) : null;
        $windowEnd = $campaign['endDate'];
        if (!$windowEnd) {
            $isLaunch = in_array($campaign['type'], array('launch', 'preorder'), true);
            $anchor = ($isLaunch && $book && !empty($book['releaseDate'])) ? $book['releaseDate'] : $campaign['startDate'];
            $windowEnd = authorlift_iso(authorlift_add_days(authorlift_ms($anchor), 14));
        }

        $bump = self::sales_bump($store, array('bookId' => $campaign['bookId'], 'windowStart' => $windowStart, 'windowEnd' => $windowEnd));
        $followerDelta = self::followers_delta($store, $windowStart, $windowEnd);
        $subscriberDelta = self::delta_over_window(AuthorLift_Audience::list_subscriber_snapshots($store), $windowStart, $windowEnd);
        $goalProgress = self::goal_progress($campaign, $bump, $followerDelta, $subscriberDelta, $totals);

        $engagement = $totals;
        $engagement['engagementRate'] = authorlift_round(self::engagement_rate($totals), 4);
        $engagement['clickThroughRate'] = $totals['impressions'] ? authorlift_round($totals['clicks'] / $totals['impressions'], 4) : 0;

        return array(
            'campaign' => $campaign,
            'postCounts' => self::count_by_status($posts),
            'engagement' => $engagement,
            'salesBump' => $bump,
            'followerDelta' => $followerDelta,
            'subscriberDelta' => $subscriberDelta,
            'goalProgress' => $goalProgress,
        );
    }

    private static function goal_progress($campaign, $bump, $followerDelta, $subscriberDelta, $totals) {
        if (empty($campaign['goal']) || empty($campaign['goal']['metric'])) {
            return null;
        }
        $metric = $campaign['goal']['metric'];
        $target = $campaign['goal']['target'];
        $actual = 0;
        if ($metric === 'sales') {
            $actual = $bump['window']['units'];
        } elseif ($metric === 'followers') {
            $actual = $followerDelta['delta'];
        } elseif ($metric === 'subscribers') {
            $actual = $subscriberDelta['delta'];
        } elseif ($metric === 'engagement') {
            $actual = $totals['likes'] + $totals['comments'] + $totals['shares'];
        }
        return array(
            'metric' => $metric,
            'target' => $target,
            'actual' => $actual,
            'pctOfTarget' => $target > 0 ? authorlift_round(($actual / $target) * 100, 1) : null,
            'met' => $target > 0 ? ($actual >= $target) : null,
        );
    }

    private static function delta_over_window($snapshots, $from, $to) {
        $fromMs = authorlift_ms($from);
        $toMs = authorlift_ms($to);
        $before = array_values(array_filter($snapshots, function ($s) use ($fromMs) { return authorlift_ms($s['date']) <= $fromMs; }));
        $within = array_values(array_filter($snapshots, function ($s) use ($toMs) { return authorlift_ms($s['date']) <= $toMs; }));
        if (count($before)) {
            $startValue = $before[count($before) - 1]['count'];
        } elseif (count($within)) {
            $startValue = $within[0]['count'];
        } else {
            $startValue = 0;
        }
        $endValue = count($within) ? $within[count($within) - 1]['count'] : $startValue;
        return array('start' => $startValue, 'end' => $endValue, 'delta' => $endValue - $startValue);
    }

    private static function followers_delta($store, $from, $to) {
        $platforms = array();
        foreach ($store->all('followerSnapshots') as $s) {
            $platforms[$s['platform']] = true;
        }
        $start = 0; $end = 0; $byPlatform = array();
        foreach (array_keys($platforms) as $platform) {
            $d = self::delta_over_window(AuthorLift_Audience::list_follower_snapshots($store, $platform), $from, $to);
            $start += $d['start'];
            $end += $d['end'];
            $byPlatform[$platform] = $d['delta'];
        }
        return array('start' => $start, 'end' => $end, 'delta' => $end - $start, 'byPlatform' => (object) $byPlatform);
    }

    private static function count_by_status($posts) {
        $counts = array('total' => count($posts), 'draft' => 0, 'scheduled' => 0, 'published' => 0, 'failed' => 0);
        foreach ($posts as $p) {
            $counts[$p['status']] = (isset($counts[$p['status']]) ? $counts[$p['status']] : 0) + 1;
        }
        return $counts;
    }

    public static function sales_timeseries($store, $args = array()) {
        $sales = AuthorLift_Audience::list_sales($store, $args);
        $buckets = array();
        foreach ($sales as $sale) {
            $key = substr(authorlift_iso(authorlift_start_of_utc_day(authorlift_ms($sale['date']))), 0, 10);
            if (!isset($buckets[$key])) {
                $buckets[$key] = array('date' => $key, 'units' => 0, 'revenue' => 0);
            }
            $buckets[$key]['units'] += $sale['units'];
            $buckets[$key]['revenue'] += $sale['revenue'];
        }
        $out = array_values($buckets);
        foreach ($out as &$b) {
            $b['revenue'] = authorlift_round($b['revenue'], 2);
        }
        unset($b);
        usort($out, function ($a, $b) { return strcmp($a['date'], $b['date']); });
        return $out;
    }
}
