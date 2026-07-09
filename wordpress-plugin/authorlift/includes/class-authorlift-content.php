<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Recommendation heuristics: when to post, how many hashtags, and which tags to
 * use. Encodes widely-cited book-marketing conventions as data.
 */
class AuthorLift_Recommendations {

    const BEST_TIMES = array(
        'twitter' => array(9, 12, 17, 20),
        'bluesky' => array(8, 12, 17, 21),
        'instagram' => array(11, 14, 19, 21),
        'facebook' => array(9, 13, 19),
        'tiktok' => array(7, 12, 19, 22),
        'threads' => array(10, 13, 18, 21),
        'newsletter' => array(8, 10),
    );

    const HASHTAG_LIMITS = array(
        'twitter' => 3, 'bluesky' => 4, 'instagram' => 12, 'facebook' => 3, 'tiktok' => 5, 'threads' => 5, 'newsletter' => 0,
    );

    const CADENCE_PER_WEEK = array(
        'twitter' => 5, 'bluesky' => 5, 'instagram' => 4, 'facebook' => 3, 'tiktok' => 3, 'threads' => 4, 'newsletter' => 1,
    );

    private static function genre_hashtags() {
        return array(
            'Romance' => array('#Romance', '#RomanceBooks', '#RomanceReads', '#BookBoyfriend', '#HEA'),
            'Romantic Comedy' => array('#RomCom', '#RomanticComedy', '#UpLit', '#BeachRead', '#FeelGood', '#WomensFiction'),
            'Romantasy' => array('#Romantasy', '#FantasyRomance', '#Booktok', '#EnemiesToLovers'),
            'Fantasy' => array('#Fantasy', '#FantasyBooks', '#EpicFantasy', '#Bookdragon', '#SFF'),
            'Science Fiction' => array('#SciFi', '#ScienceFiction', '#SFF', '#SpaceOpera', '#SciFiBooks'),
            'Thriller' => array('#Thriller', '#ThrillerBooks', '#Suspense', '#CrimeFiction', '#PageTurner'),
            'Mystery' => array('#Mystery', '#MysteryBooks', '#Whodunit', '#CozyMystery', '#CrimeReads'),
            'Horror' => array('#Horror', '#HorrorBooks', '#HorrorCommunity', '#Creepy', '#ScaryReads'),
            'Literary Fiction' => array('#LiteraryFiction', '#LitFic', '#BookishThoughts', '#ReadingCommunity'),
            'Historical Fiction' => array('#HistoricalFiction', '#HistFic', '#HistoricalRomance', '#BookishHistory'),
            'Young Adult' => array('#YA', '#YoungAdult', '#YABooks', '#YALit', '#TeenReads'),
            'Nonfiction' => array('#Nonfiction', '#NonfictionBooks', '#ReadNonfiction'),
            'Memoir' => array('#Memoir', '#Memoirs', '#TrueStory', '#LifeStories'),
            'Self-Help' => array('#SelfHelp', '#PersonalGrowth', '#SelfImprovement', '#Mindset'),
        );
    }

    private static function community_hashtags() {
        return array(
            'twitter' => array('#BookTwitter', '#WritingCommunity', '#IndieAuthor', '#amreading'),
            'bluesky' => array('#BookSky', '#WritingCommunity', '#IndieAuthor', '#amreading'),
            'instagram' => array('#Bookstagram', '#BookstagramCommunity', '#IndieAuthor', '#amreading', '#booklover', '#currentlyreading'),
            'facebook' => array('#IndieAuthor', '#amreading'),
            'tiktok' => array('#BookTok', '#BookTokMadeMeReadIt', '#IndieAuthor'),
            'threads' => array('#BookThreads', '#amreading'),
            'newsletter' => array(),
        );
    }

    private static function to_tag($text) {
        $cleaned = preg_replace('/[^a-zA-Z0-9 ]/', '', str_replace('&', 'and', (string) $text));
        $cleaned = trim($cleaned);
        if ($cleaned === '') {
            return null;
        }
        $parts = preg_split('/\s+/', $cleaned);
        $parts = array_map('ucfirst', $parts);
        return '#' . implode('', $parts);
    }

    public static function suggest_hashtags($book, $platform = 'twitter') {
        $limit = isset(self::HASHTAG_LIMITS[$platform]) ? self::HASHTAG_LIMITS[$platform] : 3;
        if ($limit === 0) {
            return array();
        }
        $genreMap = self::genre_hashtags();
        $genreTags = isset($book['genre'], $genreMap[$book['genre']]) ? $genreMap[$book['genre']] : array();
        $tropeSource = array_merge(
            isset($book['tropes']) ? $book['tropes'] : array(),
            isset($book['keywords']) ? $book['keywords'] : array()
        );
        $tropeTags = array();
        foreach ($tropeSource as $t) {
            $tag = self::to_tag($t);
            if ($tag) {
                $tropeTags[] = $tag;
            }
        }
        $communityMap = self::community_hashtags();
        $communityTags = isset($communityMap[$platform]) ? $communityMap[$platform] : array();

        $ordered = array_merge($genreTags, $tropeTags, $communityTags);
        $seen = array();
        $result = array();
        foreach ($ordered as $tag) {
            $key = strtolower($tag);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $tag;
            if (count($result) >= $limit) {
                break;
            }
        }
        return $result;
    }

    public static function best_times_for($platform) {
        return isset(self::BEST_TIMES[$platform]) ? self::BEST_TIMES[$platform] : array(9, 12, 18);
    }

    public static function cadence_for($platform) {
        return isset(self::CADENCE_PER_WEEK[$platform]) ? self::CADENCE_PER_WEEK[$platform] : 3;
    }
}

/**
 * The Content Studio turns structured book metadata into ready-to-post social
 * copy. Transparent, template-driven, deterministic (seeded), and offline.
 */
class AuthorLift_Content {

    const PLATFORM_LIMITS = array(
        'twitter' => 280, 'bluesky' => 300, 'threads' => 500, 'instagram' => 2200, 'facebook' => 5000, 'tiktok' => 2200, 'newsletter' => 100000,
    );

    private static function emoji_for($type) {
        $map = array(
            'teaser' => '👀', 'quote_card' => '📖', 'cover_reveal' => '✨', 'preorder_push' => '🛒',
            'countdown' => '⏳', 'launch_day' => '🎉', 'review_highlight' => '⭐', 'behind_the_scenes' => '🎬',
            'trope_appeal' => '💫', 'character_spotlight' => '🎭', 'giveaway' => '🎁', 'newsletter_cta' => '💌',
            'sale_announcement' => '🔥', 'milestone' => '🏆', 'question_engagement' => '💬',
        );
        return isset($map[$type]) ? $map[$type] : '📚';
    }

    private static function first_sentence($text, $max = 180) {
        if (!$text) {
            return '';
        }
        if (preg_match('/[^.!?]+[.!?]/u', $text, $m)) {
            $sentence = trim($m[0]);
        } else {
            $sentence = trim($text);
        }
        if (mb_strlen($sentence) > $max) {
            return mb_substr($sentence, 0, $max - 1) . '…';
        }
        return $sentence;
    }

    private static function price_string($book) {
        if (!isset($book['price']) || !is_numeric($book['price'])) {
            return '';
        }
        return ($book['price'] == 0) ? 'FREE' : number_format((float) $book['price'], 2);
    }

    private static function primary_link($book) {
        $links = isset($book['buyLinks']) ? $book['buyLinks'] : array();
        foreach (array('universal', 'amazon', 'apple', 'kobo', 'barnesnoble', 'audible') as $k) {
            if (!empty($links[$k])) {
                return $links[$k];
            }
        }
        return '';
    }

    private static function cta_for($book, $author) {
        $link = self::primary_link($book);
        $status = isset($book['status']) ? $book['status'] : 'draft';
        if ($status === 'released') {
            return $link ? "Grab your copy: $link" : 'Available now everywhere books are sold.';
        }
        if ($status === 'preorder') {
            return $link ? "Pre-order now: $link" : 'Pre-order available now!';
        }
        $website = isset($author['website']) ? $author['website'] : '';
        return $website ? "Join my newsletter for the release date: $website" : 'Follow for the release date!';
    }

    private static function build_context($author, $book, $referenceMs, $rng) {
        $title = !empty($book['title']) ? $book['title'] : 'my new book';
        $trope = $rng->pick(isset($book['tropes']) ? $book['tropes'] : array());
        $quote = $rng->pick(isset($book['quotes']) ? $book['quotes'] : array());
        $comp = $rng->pick(isset($book['comps']) ? $book['comps'] : array());
        $review = $rng->pick(isset($book['reviews']) ? $book['reviews'] : array(), null);
        $daysToRelease = !empty($book['releaseDate'])
            ? authorlift_days_between($referenceMs !== null ? $referenceMs : authorlift_ms(), authorlift_ms($book['releaseDate']))
            : null;
        $blurb = isset($book['blurb']) ? $book['blurb'] : '';
        $tagline = isset($book['tagline']) ? $book['tagline'] : '';
        $hook = self::first_sentence($blurb);
        if ($hook === '') {
            $hook = $tagline ?: "You won't want to put \"$title\" down.";
        }
        return array(
            'penName' => !empty($author['penName']) ? $author['penName'] : 'the author',
            'title' => $title,
            'tagline' => $tagline,
            'hook' => $hook,
            'genre' => !empty($book['genre']) ? $book['genre'] : 'book',
            'trope' => $trope,
            'quote' => $quote,
            'comp' => $comp,
            'reviewText' => $review ? self::first_sentence($review['text'], 160) : '',
            'reviewStars' => $review ? str_repeat('⭐', isset($review['rating']) ? $review['rating'] : 5) : '⭐⭐⭐⭐⭐',
            'daysToRelease' => $daysToRelease,
            'price' => self::price_string($book),
            'cta' => self::cta_for($book, $author),
            'link' => self::primary_link($book),
            'website' => isset($author['website']) ? $author['website'] : '',
            'imprint' => isset($book['publisher']) ? $book['publisher'] : '',
        );
    }

    private static function templates() {
        return array(
            'teaser' => array(
                function ($c) { return 'What if ' . lcfirst($c['hook']) . "\n\n\"{$c['title']}\" — coming for your heart (and your sleep schedule)."; },
                function ($c) { return ($c['tagline'] ?: $c['hook']) . "\n\nThis is the " . strtolower($c['genre']) . " I've been dying to share with you. Meet \"{$c['title']}.\""; },
                function ($c) { return "Some stories whisper. This one grabs you by the collar.\n\n\"{$c['title']}\" " . ($c['comp'] ? "— for readers who loved {$c['comp']}." : ''); },
            ),
            'quote_card' => array(
                function ($c) { return $c['quote'] ? "\"{$c['quote']}\"\n\n— from \"{$c['title']}\"" : "A line I can't stop thinking about, from \"{$c['title']}.\" (Screenshot-worthy pages inside.)"; },
                function ($c) { return $c['quote'] ? "{$c['quote']}\n\nJust one of the moments waiting for you in \"{$c['title']}.\"" : "The kind of sentence you dog-ear. \"{$c['title']}\" is full of them."; },
            ),
            'cover_reveal' => array(
                function ($c) { return "IT'S HERE. 🎉 The cover for \"{$c['title']}\" — and I am OBSESSED.\n\n" . ($c['tagline'] ?: $c['hook']) . "\n\nWhat do you think?"; },
                function ($c) { return "Say hello to \"{$c['title']}.\" 👋 Months of work, and this cover captures it perfectly.\n\n" . ($c['comp'] ? "If {$c['comp']} lives on your shelf, make room." : 'Coming soon.'); },
            ),
            'preorder_push' => array(
                function ($c) { return "Pre-orders are LIVE for \"{$c['title']}.\" 🛒\n\nEvery pre-order tells the algorithm this book matters on release day — and it means the world to me.\n\n{$c['cta']}"; },
                function ($c) { return "{$c['hook']}\n\nDon't wait for release day — pre-order \"{$c['title']}\" now and it lands on your device the moment it's out. {$c['cta']}"; },
            ),
            'countdown' => array(
                function ($c) {
                    $d = $c['daysToRelease'];
                    if ($d === 0) { return "TODAY. \"{$c['title']}\" is HERE. 🎉 {$c['cta']}"; }
                    if ($d === 1) { return "1 SLEEP to go. 😱 \"{$c['title']}\" releases tomorrow. Are you ready? {$c['cta']}"; }
                    if (is_int($d) && $d > 0) { return "{$d} days until \"{$c['title']}.\" ⏳\n\n" . ($c['tagline'] ?: $c['hook']) . "\n\n{$c['cta']}"; }
                    return "Release day is almost here for \"{$c['title']}\"! {$c['cta']}";
                },
            ),
            'launch_day' => array(
                function ($c) { return "IT'S RELEASE DAY!!! 🎉📚\n\n\"{$c['title']}\" is officially out in the world" . ($c['imprint'] ? " from {$c['imprint']}" : '') . ". {$c['hook']}\n\n{$c['cta']}"; },
                function ($c) { return "The day is finally here. \"{$c['title']}\" is LIVE. 🎉\n\nThank you for being here for this. Now go meet " . ($c['trope'] ? "the {$c['trope']}" : 'these characters') . " I love so much.\n\n{$c['cta']}"; },
            ),
            'review_highlight' => array(
                function ($c) { return $c['reviewText'] ? "{$c['reviewStars']}\n\n\"{$c['reviewText']}\"\n\nReviews like this make it all worth it. \"{$c['title']}\" — {$c['cta']}" : "The early reviews for \"{$c['title']}\" are rolling in and I'm floored. {$c['reviewStars']} {$c['cta']}"; },
                function ($c) { return $c['reviewText'] ? "A reader just said this about \"{$c['title']}\":\n\n\"{$c['reviewText']}\" {$c['reviewStars']}\n\n{$c['cta']}" : "Readers are loving \"{$c['title']}.\" {$c['reviewStars']} Have you met it yet? {$c['cta']}"; },
            ),
            'behind_the_scenes' => array(
                function ($c) { return "Behind the scenes of \"{$c['title']}\": " . ($c['trope'] ? "I wrote the {$c['trope']} scene four times before it felt right." : 'some chapters took a dozen drafts to get right.') . " 🎬\n\nWriting is rewriting. Worth every pass."; },
                function ($c) { return "People ask where \"{$c['title']}\" came from. The honest answer: " . strtolower_first_word($c['hook']) . " That question wouldn't leave me alone until I wrote it."; },
            ),
            'trope_appeal' => array(
                function ($c) { return $c['trope'] ? "If you love {$c['trope']}, \"{$c['title']}\" was written for you. 💫\n\n{$c['cta']}" : "Tell me your favorite trope and I'll tell you why \"{$c['title']}\" delivers. 💫"; },
                function ($c) { return $c['trope'] ? "POV: you open \"{$c['title']}\" and it's {$c['trope']}, done right. 😌\n\n{$c['cta']}" : "\"{$c['title']}\" checks every box on your " . strtolower($c['genre']) . " wishlist. {$c['cta']}"; },
            ),
            'character_spotlight' => array(
                function ($c) { return "Character spotlight 🎭 The heart of \"{$c['title']}\" is a character who " . ($c['trope'] ? "lives and breathes {$c['trope']}" : 'refuses to be who everyone expects') . ".\n\nYou're going to want to know them."; },
                function ($c) { return "Some characters you write. Others move in and refuse to leave. The lead of \"{$c['title']}\" is firmly the second kind. 🎭"; },
            ),
            'giveaway' => array(
                function ($c) { return "🎁 GIVEAWAY! I'm giving away signed copies of \"{$c['title']}.\"\n\nTo enter: follow, like & tag a friend who needs their next " . strtolower($c['genre']) . " obsession. Winner picked soon!"; },
                function ($c) { return "🎁 Want to win \"{$c['title']}\" before release? Follow + repost to enter. Spreading the word is everything for an indie author — thank you. 🙏"; },
            ),
            'newsletter_cta' => array(
                function ($c) { return "My newsletter subscribers get cover reveals, bonus scenes, and early looks first. 💌\n\n" . ($c['website'] ? "Join us: {$c['website']}" : 'Link in bio to join.'); },
                function ($c) { return "Never miss a release. 💌 I send one thoughtful email a month — no spam, just stories.\n\n" . ($c['website'] ? "Sign up: {$c['website']}" : 'Newsletter link in bio.'); },
            ),
            'sale_announcement' => array(
                function ($c) { return "🔥 SALE! \"{$c['title']}\" is " . ($c['price'] ?: 'on sale') . " for a limited time.\n\n{$c['hook']}\n\n{$c['cta']}"; },
                function ($c) { return "Been eyeing \"{$c['title']}\"? Now's the moment — it's " . ($c['price'] ?: 'discounted') . " this week only. 🔥 {$c['cta']}"; },
            ),
            'milestone' => array(
                function ($c) { return "I still can't believe it — \"{$c['title']}\" just hit a milestone I dreamed about. 🏆 Thank you for reading, reviewing, and shouting about it. This is all you."; },
                function ($c) { return "Pinch me. 🏆 \"{$c['title']}\" wouldn't be here without this community. Whatever comes next, I'm so grateful you're part of it."; },
            ),
            'question_engagement' => array(
                function ($c) { return "Question for the timeline 💬: what's the last " . strtolower($c['genre']) . " that kept you up past midnight? (I'll go first: writing \"{$c['title']}\" did it to me.)"; },
                function ($c) { return "Help me settle a debate 💬: " . ($c['trope'] ? "is {$c['trope']} the best trope, or THE best trope?" : 'what makes you one-click a book instantly?') . " Reply below 👇"; },
            ),
        );
    }

    public static function fit_to_platform($body, $hashtags, $platform) {
        $limit = isset(self::PLATFORM_LIMITS[$platform]) ? self::PLATFORM_LIMITS[$platform] : 2200;
        $outBody = trim($body);
        $tagStr = count($hashtags) ? "\n\n" . implode(' ', $hashtags) : '';

        if (mb_strlen($outBody) + mb_strlen($tagStr) <= $limit) {
            return array('body' => $outBody, 'hashtags' => $hashtags, 'text' => $outBody . $tagStr);
        }

        $kept = array();
        foreach ($hashtags as $tag) {
            $candidate = array_merge($kept, array($tag));
            $candidateStr = "\n\n" . implode(' ', $candidate);
            if (mb_strlen($outBody) + mb_strlen($candidateStr) <= $limit) {
                $kept[] = $tag;
            } else {
                break;
            }
        }
        $keptStr = count($kept) ? "\n\n" . implode(' ', $kept) : '';

        if (mb_strlen($outBody) + mb_strlen($keptStr) > $limit) {
            $outBody = mb_substr($outBody, 0, max(0, $limit - 1)) . '…';
            $kept = array();
            $keptStr = '';
        }
        return array('body' => $outBody, 'hashtags' => $kept, 'text' => $outBody . $keptStr);
    }

    public static function generate($store, $options) {
        $postType = isset($options['postType']) ? $options['postType'] : 'teaser';
        $platform = isset($options['platform']) ? $options['platform'] : 'twitter';
        $bookId = isset($options['bookId']) ? $options['bookId'] : null;
        $campaignId = isset($options['campaignId']) ? $options['campaignId'] : null;
        $referenceDate = isset($options['referenceDate']) ? $options['referenceDate'] : null;

        if (!in_array($postType, AuthorLift_Enums::POST_TYPES, true)) {
            throw new AuthorLift_Validation_Exception("Unknown post type: $postType", 'postType');
        }
        if (!isset(self::PLATFORM_LIMITS[$platform])) {
            throw new AuthorLift_Validation_Exception("Unknown platform: $platform", 'platform');
        }

        $author = $store->get_author();
        $book = $bookId ? $store->get('books', $bookId) : null;
        if ($bookId && !$book) {
            throw new AuthorLift_Validation_Exception('bookId does not reference an existing book', 'bookId');
        }
        $campaign = $campaignId ? $store->get('campaigns', $campaignId) : null;
        $effectiveBook = $book ? $book : array('title' => 'your book', 'genre' => 'Fiction', 'status' => 'draft');

        $seedInput = isset($options['seed']) ? $options['seed'] : '';
        if (is_numeric($seedInput)) {
            $seedValue = (int) $seedInput;
        } else {
            $seedValue = authorlift_hash_string(($bookId ?: 'nobook') . ":$postType:$platform:$seedInput");
        }
        $rng = new AuthorLift_Rng($seedValue);

        $referenceMs = $referenceDate ? authorlift_ms($referenceDate) : null;
        $ctx = self::build_context(is_array($author) ? $author : array(), $effectiveBook, $referenceMs, $rng);

        $templates = self::templates();
        $variants = isset($templates[$postType]) ? $templates[$postType] : $templates['teaser'];
        if (isset($options['variantIndex']) && is_numeric($options['variantIndex'])) {
            $idx = ((int) $options['variantIndex'] % count($variants) + count($variants)) % count($variants);
        } else {
            $idx = (int) floor($rng->next() * count($variants));
        }
        $rawBody = trim(call_user_func($variants[$idx], $ctx));

        $hashtags = $platform === 'newsletter' ? array() : AuthorLift_Recommendations::suggest_hashtags($effectiveBook, $platform);
        $fitted = self::fit_to_platform($rawBody, $hashtags, $platform);

        return array(
            'type' => $postType,
            'platform' => $platform,
            'bookId' => $book ? $book['id'] : null,
            'campaignId' => $campaign ? $campaign['id'] : null,
            'body' => $fitted['body'],
            'hashtags' => $fitted['hashtags'],
            'cta' => $ctx['cta'],
            'mediaSuggestion' => self::suggest_media($postType, $ctx),
            'preview' => $fitted['text'],
            'emoji' => self::emoji_for($postType),
        );
    }

    private static function suggest_media($postType, $ctx) {
        switch ($postType) {
            case 'quote_card':
                return 'Quote graphic on a branded background: "' . mb_substr($ctx['quote'] ?: $ctx['hook'], 0, 80) . '"';
            case 'cover_reveal':
                return 'The full-resolution book cover (this is the star of the post).';
            case 'countdown':
                return 'Countdown graphic showing "' . ($ctx['daysToRelease'] !== null ? $ctx['daysToRelease'] : 'X') . ' days" over the cover.';
            case 'launch_day':
                return 'Cover mockup on a device/shelf, or a short celebratory video.';
            case 'review_highlight':
                return 'Review screenshot styled as a graphic with a star rating.';
            case 'behind_the_scenes':
                return 'A candid writing-desk photo or a short talking-head clip.';
            case 'giveaway':
                return 'Photo of the physical prize (signed copy + any swag).';
            case 'newsletter_cta':
                return 'A mockup of the reader magnet / freebie subscribers receive.';
            default:
                return 'Book cover or an on-brand graphic.';
        }
    }
}

/** Lowercase just the first word's first letter for a natural sentence join. */
function strtolower_first_word($text) {
    return lcfirst($text);
}
