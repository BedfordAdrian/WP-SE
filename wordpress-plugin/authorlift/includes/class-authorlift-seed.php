<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Seed a real author (Mo Fanning) and book (Lisa Doyle is Absolutely Fine), a
 * completed launch campaign published through the simulated publisher, and a
 * live sustain campaign.
 *
 * HONESTY: engagement/follower/sales numbers are ILLUSTRATIVE SAMPLE DATA (the
 * store is flagged demoData:true so the UI labels them). Book metadata is from
 * public sources; no quotes/reviews are fabricated on the author's behalf.
 */
class AuthorLift_Seed {

    public static function run($store, $nowIso = null) {
        $store->reset();
        $store->set_autoflush(false);
        $store->update_settings(array('demoData' => true, 'currencySymbol' => '£', 'activePublisher' => 'simulated'));

        $nowMs = $nowIso ? authorlift_ms($nowIso) : authorlift_ms();
        $rng = new AuthorLift_Rng(20260708);

        $author = AuthorLift_Author::save($store, array(
            'penName' => 'Mo Fanning',
            'realName' => 'Mo Fanning',
            'tagline' => 'Funny, heartfelt romantic comedies for grown-ups.',
            'bio' => "Mo Fanning is a British author of romantic comedy and uplit, based in the West Midlands. His award-winning novels — including Husbands and Rainbows and Lollipops — find the funny side of life's messier moments.",
            'website' => 'https://mofanning.co.uk',
            'timezone' => 'Europe/London',
            'brandVoice' => array('warm', 'witty', 'honest', 'self-deprecating', 'big-hearted'),
            'handles' => array('facebook' => 'mofanningbooks', 'instagram' => 'mofanningbooks'),
        ));

        $releaseMs = authorlift_add_days($nowMs, -26);
        $book = AuthorLift_Books::create($store, array(
            'title' => 'Lisa Doyle is Absolutely Fine',
            'genre' => 'Romantic Comedy',
            'subgenres' => array('Uplit', 'Commercial Fiction'),
            'tagline' => 'Four glasses of wine. One imaginary fiancé. What could possibly go wrong?',
            'blurb' => "Lisa Doyle has four glasses of wine in her and a fiancé who doesn't exist — so she invents one. A man called Brian. The trouble is, Brian shares his name with her very married boss, and now the real Brian is starting to look at her in ways that suggest this might all have been a terrible idea. A grown-up romantic comedy about love, pressure, friendship, and the exhausting performance of holding it all together when you're quietly falling apart.",
            'keywords' => array('fake fiancé', 'British romcom', 'messy heroine', 'workplace romance'),
            'tropes' => array('fake engagement', 'workplace romance', 'one little lie that spirals', 'messy heroine'),
            'comps' => array('Mhairi McFarlane', "Beth O'Leary", 'Marian Keyes'),
            'quotes' => array(),
            'reviews' => array(),
            'buyLinks' => array(
                'universal' => 'https://books2read.com/absolutely',
                'signed' => 'https://shop.mofanning.co.uk/products/lisa-doyle-is-absolutely-fine-1',
            ),
            'prices' => array('ebook' => 3.99, 'audiobook' => 12.99, 'paperback' => 9.99, 'hardcover' => 16.99),
            'releaseDate' => authorlift_iso($releaseMs),
            'status' => 'released',
        ));

        // Completed launch campaign, published via the simulated publisher.
        $launch = AuthorLift_Campaigns::create($store, array(
            'name' => 'Lisa Doyle is Absolutely Fine — Launch',
            'type' => 'launch',
            'bookId' => $book['id'],
            'startDate' => authorlift_iso(authorlift_add_days($releaseMs, -42)),
            'endDate' => authorlift_iso(authorlift_add_days($releaseMs, 14)),
            'goal' => array('metric' => 'sales', 'target' => 400),
            'notes' => 'Six-week ramp into release week, blitz on launch day, two weeks of sustain and reviews.',
        ));
        AuthorLift_Planner::plan($store, $launch['id'], array('now' => authorlift_iso($nowMs)));
        foreach (AuthorLift_Posts::all($store, array('campaignId' => $launch['id'])) as $post) {
            if ($post['status'] !== 'published' && $post['scheduledAt']) {
                AuthorLift_Scheduler::publish_post($store, $post['id'], $post['scheduledAt']);
            }
        }
        AuthorLift_Campaigns::update($store, $launch['id'], array('status' => 'completed'));

        // Live sustain campaign (forward-looking, real usable plan).
        $sustain = AuthorLift_Campaigns::create($store, array(
            'name' => 'Lisa Doyle — Summer Sustain',
            'type' => 'evergreen',
            'bookId' => $book['id'],
            'startDate' => authorlift_iso(authorlift_add_days($nowMs, -7)),
            'endDate' => authorlift_iso(authorlift_add_days($nowMs, 30)),
            'goal' => array('metric' => 'subscribers', 'target' => 400),
            'notes' => 'Keep momentum after launch: reviews, reader questions, behind-the-scenes and newsletter growth.',
        ));
        AuthorLift_Planner::plan($store, $sustain['id'], array('now' => authorlift_iso($nowMs)));

        // ILLUSTRATIVE sample sales.
        $salesStart = authorlift_add_days($releaseMs, -70);
        $salesDays = authorlift_days_between($salesStart, $nowMs);
        for ($i = 0; $i <= $salesDays; $i++) {
            $dayMs = authorlift_add_days($salesStart, $i);
            $off = authorlift_days_between($releaseMs, $dayMs);
            if ($off < -14) { $units = $rng->int(2, 6); }
            elseif ($off < 0) { $units = $rng->int(4, 10); }
            elseif ($off <= 2) { $units = $rng->int(35, 80); }
            elseif ($off <= 7) { $units = $rng->int(20, 40); }
            elseif ($off <= 21) { $units = $rng->int(10, 20); }
            else { $units = $rng->int(4, 9); }
            AuthorLift_Audience::record_sale($store, array(
                'bookId' => $book['id'],
                'date' => authorlift_iso($dayMs),
                'units' => $units,
                'revenue' => round($units * 3.0, 2),
                'channel' => 'amazon',
                'source' => 'sample',
            ));
        }

        // ILLUSTRATIVE sample audience growth.
        $platformsStart = array('facebook' => 1200, 'instagram' => 900);
        $growthPerWeek = array('facebook' => 25, 'instagram' => 65);
        $followStart = authorlift_add_days($nowMs, -84);
        for ($week = 0; $week <= 12; $week++) {
            $dayMs = authorlift_add_days($followStart, $week * 7);
            $off = authorlift_days_between($releaseMs, $dayMs);
            $boost = ($off >= -7 && $off <= 21) ? $rng->int(60, 220) : 0;
            foreach ($platformsStart as $platform => $base) {
                $count = (int) round($base + $growthPerWeek[$platform] * $week + $boost);
                AuthorLift_Audience::record_followers($store, array('platform' => $platform, 'date' => authorlift_iso($dayMs), 'count' => $count));
            }
            AuthorLift_Audience::record_subscribers($store, array(
                'date' => authorlift_iso($dayMs),
                'count' => (int) round(260 + $week * 32 + (($off >= -7 && $off <= 21) ? $rng->int(40, 140) : 0)),
            ));
        }

        $store->set_autoflush(true);
        $store->flush();

        return array(
            'author' => $author['penName'],
            'book' => $book['title'],
            'campaigns' => array($launch['name'], $sustain['name']),
        );
    }
}
