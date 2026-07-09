<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST controller. Routes live under /wp-json/authorlift/v1/* and require the
 * `manage_options` capability (plus the standard REST cookie nonce). Responses
 * mirror the standalone Node API shape so the dashboard frontend is portable.
 */
class AuthorLift_REST {

    const NS = 'authorlift/v1';

    private function store() {
        return AuthorLift_Store::instance();
    }

    public function permission() {
        return current_user_can('manage_options');
    }

    /** Wrap a handler so validation errors become 400s and crashes become 500s. */
    private function wrap($handler) {
        return function ($request) use ($handler) {
            try {
                return $handler($request);
            } catch (AuthorLift_Validation_Exception $e) {
                return new WP_REST_Response(array('error' => $e->getMessage(), 'field' => $e->field), 400);
            } catch (Exception $e) {
                return new WP_REST_Response(array('error' => 'Internal server error'), 500);
            }
        };
    }

    private function route($path, $methods, $handler) {
        register_rest_route(self::NS, $path, array(
            'methods' => $methods,
            'callback' => $this->wrap($handler),
            'permission_callback' => array($this, 'permission'),
        ));
    }

    private function body($request) {
        $json = $request->get_json_params();
        return is_array($json) ? $json : array();
    }

    private function not_found($what = 'Resource') {
        return new WP_REST_Response(array('error' => "$what not found"), 404);
    }

    public function register_routes() {
        $store = $this->store();

        // Meta / health.
        $this->route('/meta', 'GET', function () {
            return array(
                'version' => AUTHORLIFT_VERSION,
                'platforms' => AuthorLift_Enums::PLATFORMS,
                'postTypes' => AuthorLift_Enums::POST_TYPES,
                'postStatuses' => AuthorLift_Enums::POST_STATUSES,
                'genres' => AuthorLift_Enums::GENRES,
                'campaignTypes' => AuthorLift_Enums::CAMPAIGN_TYPES,
                'campaignStatuses' => AuthorLift_Enums::CAMPAIGN_STATUSES,
                'goalMetrics' => AuthorLift_Enums::GOAL_METRICS,
                'bookStatuses' => AuthorLift_Enums::BOOK_STATUSES,
                'salesChannels' => AuthorLift_Enums::SALES_CHANNELS,
                'timezones' => array_keys(authorlift_tz_offsets()),
                'platformLimits' => AuthorLift_Content::PLATFORM_LIMITS,
            );
        });

        // Author.
        $this->route('/author', 'GET', function () use ($store) {
            return AuthorLift_Author::get($store);
        });
        $this->route('/author', 'PUT', function ($r) use ($store) {
            return AuthorLift_Author::save($store, $this->body($r));
        });

        // Books.
        $this->route('/books', 'GET', function () use ($store) {
            return AuthorLift_Books::all($store);
        });
        $this->route('/books', 'POST', function ($r) use ($store) {
            return new WP_REST_Response(AuthorLift_Books::create($store, $this->body($r)), 201);
        });
        $this->route('/books/(?P<id>[A-Za-z0-9_]+)', 'GET', function ($r) use ($store) {
            $b = AuthorLift_Books::get($store, $r['id']);
            return $b ? $b : $this->not_found('Book');
        });
        $this->route('/books/(?P<id>[A-Za-z0-9_]+)', 'PUT', function ($r) use ($store) {
            $b = AuthorLift_Books::update($store, $r['id'], $this->body($r));
            return $b ? $b : $this->not_found('Book');
        });
        $this->route('/books/(?P<id>[A-Za-z0-9_]+)', 'DELETE', function ($r) use ($store) {
            return AuthorLift_Books::delete($store, $r['id']) ? new WP_REST_Response(null, 204) : $this->not_found('Book');
        });

        // Campaigns.
        $this->route('/campaigns', 'GET', function () use ($store) {
            return AuthorLift_Campaigns::all($store);
        });
        $this->route('/campaigns', 'POST', function ($r) use ($store) {
            return new WP_REST_Response(AuthorLift_Campaigns::create($store, $this->body($r)), 201);
        });
        $this->route('/campaigns/(?P<id>[A-Za-z0-9_]+)', 'GET', function ($r) use ($store) {
            $c = AuthorLift_Campaigns::get($store, $r['id']);
            return $c ? $c : $this->not_found('Campaign');
        });
        $this->route('/campaigns/(?P<id>[A-Za-z0-9_]+)', 'PUT', function ($r) use ($store) {
            $c = AuthorLift_Campaigns::update($store, $r['id'], $this->body($r));
            return $c ? $c : $this->not_found('Campaign');
        });
        $this->route('/campaigns/(?P<id>[A-Za-z0-9_]+)', 'DELETE', function ($r) use ($store) {
            $deletePosts = $r->get_param('deletePosts') === 'true';
            return AuthorLift_Campaigns::delete($store, $r['id'], $deletePosts) ? new WP_REST_Response(null, 204) : $this->not_found('Campaign');
        });
        $this->route('/campaigns/(?P<id>[A-Za-z0-9_]+)/plan', 'POST', function ($r) use ($store) {
            $body = $this->body($r);
            $result = AuthorLift_Planner::plan($store, $r['id'], array('dryRun' => !empty($body['dryRun'])));
            return $result ? $result : $this->not_found('Campaign');
        });
        $this->route('/campaigns/(?P<id>[A-Za-z0-9_]+)/report', 'GET', function ($r) use ($store) {
            $report = AuthorLift_Metrics::campaign_report($store, $r['id']);
            return $report ? $report : $this->not_found('Campaign');
        });

        // Posts.
        $this->route('/posts', 'GET', function ($r) use ($store) {
            return AuthorLift_Posts::all($store, array(
                'status' => $r->get_param('status'),
                'platform' => $r->get_param('platform'),
                'campaignId' => $r->get_param('campaignId'),
                'bookId' => $r->get_param('bookId'),
            ));
        });
        $this->route('/posts', 'POST', function ($r) use ($store) {
            return new WP_REST_Response(AuthorLift_Posts::create($store, $this->body($r)), 201);
        });
        $this->route('/posts/(?P<id>[A-Za-z0-9_]+)', 'GET', function ($r) use ($store) {
            $p = AuthorLift_Posts::get($store, $r['id']);
            return $p ? $p : $this->not_found('Post');
        });
        $this->route('/posts/(?P<id>[A-Za-z0-9_]+)', 'PUT', function ($r) use ($store) {
            $p = AuthorLift_Posts::update($store, $r['id'], $this->body($r));
            return $p ? $p : $this->not_found('Post');
        });
        $this->route('/posts/(?P<id>[A-Za-z0-9_]+)', 'DELETE', function ($r) use ($store) {
            return AuthorLift_Posts::delete($store, $r['id']) ? new WP_REST_Response(null, 204) : $this->not_found('Post');
        });
        $this->route('/posts/(?P<id>[A-Za-z0-9_]+)/schedule', 'POST', function ($r) use ($store) {
            $body = $this->body($r);
            $p = AuthorLift_Posts::schedule($store, $r['id'], isset($body['scheduledAt']) ? $body['scheduledAt'] : null);
            return $p ? $p : $this->not_found('Post');
        });
        $this->route('/posts/(?P<id>[A-Za-z0-9_]+)/publish', 'POST', function ($r) use ($store) {
            if (!AuthorLift_Posts::get($store, $r['id'])) {
                return $this->not_found('Post');
            }
            return AuthorLift_Scheduler::publish_post($store, $r['id']);
        });

        // Content Studio.
        $this->route('/content/generate', 'POST', function ($r) use ($store) {
            return AuthorLift_Content::generate($store, $this->body($r));
        });

        // Calendar.
        $this->route('/calendar', 'GET', function ($r) use ($store) {
            $from = $r->get_param('from');
            $to = $r->get_param('to');
            $fromMs = $from ? authorlift_ms($from) : -PHP_INT_MAX;
            $toMs = $to ? authorlift_ms($to) : PHP_INT_MAX;
            $posts = $store->find('posts', function ($p) use ($fromMs, $toMs) {
                if (!$p['scheduledAt']) {
                    return false;
                }
                $t = authorlift_ms($p['scheduledAt']);
                return $t >= $fromMs && $t <= $toMs;
            });
            usort($posts, function ($a, $b) { return authorlift_ms($a['scheduledAt']) - authorlift_ms($b['scheduledAt']); });
            return $posts;
        });

        // Sales & audience.
        $this->route('/sales', 'GET', function ($r) use ($store) {
            return AuthorLift_Audience::list_sales($store, array(
                'bookId' => $r->get_param('bookId'), 'from' => $r->get_param('from'), 'to' => $r->get_param('to'),
            ));
        });
        $this->route('/sales', 'POST', function ($r) use ($store) {
            return new WP_REST_Response(AuthorLift_Audience::record_sale($store, $this->body($r)), 201);
        });
        $this->route('/followers', 'POST', function ($r) use ($store) {
            return new WP_REST_Response(AuthorLift_Audience::record_followers($store, $this->body($r)), 201);
        });
        $this->route('/subscribers', 'POST', function ($r) use ($store) {
            return new WP_REST_Response(AuthorLift_Audience::record_subscribers($store, $this->body($r)), 201);
        });

        // Analytics.
        $this->route('/analytics/overview', 'GET', function () use ($store) {
            return AuthorLift_Metrics::overview($store);
        });
        $this->route('/analytics/sales-timeseries', 'GET', function ($r) use ($store) {
            return AuthorLift_Metrics::sales_timeseries($store, array(
                'bookId' => $r->get_param('bookId'), 'from' => $r->get_param('from'), 'to' => $r->get_param('to'),
            ));
        });
        $this->route('/analytics/followers', 'GET', function ($r) use ($store) {
            return AuthorLift_Audience::list_follower_snapshots($store, $r->get_param('platform'));
        });
        $this->route('/analytics/subscribers', 'GET', function () use ($store) {
            return AuthorLift_Audience::list_subscriber_snapshots($store);
        });

        // Recommendations.
        $this->route('/recommendations/hashtags', 'GET', function ($r) use ($store) {
            $book = $r->get_param('bookId') ? AuthorLift_Books::get($store, $r->get_param('bookId')) : array();
            return AuthorLift_Recommendations::suggest_hashtags(is_array($book) ? $book : array(), $r->get_param('platform') ?: 'twitter');
        });
        $this->route('/recommendations/best-times', 'GET', function ($r) {
            return AuthorLift_Recommendations::best_times_for($r->get_param('platform') ?: 'twitter');
        });

        // Settings & publishers.
        $this->route('/settings', 'GET', function () use ($store) {
            return $store->get_settings();
        });
        $this->route('/settings', 'PUT', function ($r) use ($store) {
            $patch = $this->body($r);
            if (!empty($patch['activePublisher']) && !AuthorLift_Publishers::get($patch['activePublisher'])) {
                return new WP_REST_Response(array('error' => 'Unknown publisher: ' . $patch['activePublisher']), 400);
            }
            if (isset($patch['currencySymbol']) && !in_array($patch['currencySymbol'], array('$', '£', '€'), true)) {
                return new WP_REST_Response(array('error' => 'Unsupported currency', 'field' => 'currencySymbol'), 400);
            }
            return $store->update_settings($patch);
        });
        $this->route('/publishers', 'GET', function () {
            return AuthorLift_Publishers::listing();
        });

        // Clear sample/demo data and exit demo mode, keeping the author profile.
        $this->route('/data/reset', 'POST', function () use ($store) {
            $author = $store->get_author();
            $settings = $store->get_settings();
            $store->reset();
            if ($author) {
                $store->set_author($author);
            }
            $store->update_settings(array(
                'demoData' => false,
                'currencySymbol' => isset($settings['currencySymbol']) ? $settings['currencySymbol'] : '$',
                'activePublisher' => isset($settings['activePublisher']) ? $settings['activePublisher'] : 'simulated',
            ));
            return array('ok' => true, 'settings' => $store->get_settings());
        });
    }
}
