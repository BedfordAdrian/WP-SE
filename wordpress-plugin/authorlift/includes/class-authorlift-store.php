<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Document store backed by a single (non-autoloaded) WordPress option holding a
 * JSON-serialisable array. Mirrors the standalone Node store so the same domain
 * logic ports directly. For the data volumes an author generates (hundreds of
 * posts/sales) a single option is perfectly adequate.
 */
class AuthorLift_Store {

    const COLLECTIONS = array('books', 'campaigns', 'posts', 'sales', 'followerSnapshots', 'subscriberSnapshots');

    private static $instance = null;
    private $data;
    private $autoflush = true;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $raw = get_option(AUTHORLIFT_OPTION, null);
        if (is_array($raw)) {
            $this->data = $this->normalize($raw);
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $this->data = is_array($decoded) ? $this->normalize($decoded) : $this->empty_data();
        } else {
            $this->data = $this->empty_data();
        }
    }

    private function empty_data() {
        $data = array(
            'author' => null,
            'settings' => array('activePublisher' => 'simulated'),
        );
        foreach (self::COLLECTIONS as $c) {
            $data[$c] = array();
        }
        return $data;
    }

    private function normalize($raw) {
        $data = array_merge($this->empty_data(), $raw);
        foreach (self::COLLECTIONS as $c) {
            if (!isset($data[$c]) || !is_array($data[$c])) {
                $data[$c] = array();
            } else {
                $data[$c] = array_values($data[$c]);
            }
        }
        if (!isset($data['settings']) || !is_array($data['settings'])) {
            $data['settings'] = array('activePublisher' => 'simulated');
        }
        return $data;
    }

    public function persist() {
        // autoload = no: this option is only read inside the plugin.
        update_option(AUTHORLIFT_OPTION, $this->data, false);
    }

    public function set_autoflush($on) {
        $this->autoflush = (bool) $on;
    }

    private function touch() {
        if ($this->autoflush) {
            $this->persist();
        }
    }

    public function flush() {
        $this->persist();
    }

    public function raw() {
        return $this->data;
    }

    public function reset() {
        $this->data = $this->empty_data();
        $this->touch();
    }

    private function assert_collection($collection) {
        if (!in_array($collection, self::COLLECTIONS, true)) {
            throw new Exception("Unknown collection: $collection");
        }
    }

    // ---- Author (singleton) -------------------------------------------------
    public function get_author() {
        return $this->data['author'];
    }
    public function set_author($author) {
        $this->data['author'] = $author;
        $this->touch();
        return $author;
    }

    // ---- Settings -----------------------------------------------------------
    public function get_settings() {
        return $this->data['settings'];
    }
    public function update_settings($patch) {
        $this->data['settings'] = array_merge($this->data['settings'], $patch);
        $this->touch();
        return $this->data['settings'];
    }

    // ---- Collections --------------------------------------------------------
    public function all($collection) {
        $this->assert_collection($collection);
        return $this->data[$collection];
    }

    public function find($collection, $predicate) {
        $this->assert_collection($collection);
        return array_values(array_filter($this->data[$collection], $predicate));
    }

    public function get($collection, $id) {
        $this->assert_collection($collection);
        foreach ($this->data[$collection] as $doc) {
            if ($doc['id'] === $id) {
                return $doc;
            }
        }
        return null;
    }

    public function insert($collection, $doc) {
        $this->assert_collection($collection);
        $prefix = rtrim($collection, 's');
        if ($collection === 'followerSnapshots') {
            $prefix = 'follower_snap';
        } elseif ($collection === 'subscriberSnapshots') {
            $prefix = 'subscriber_snap';
        }
        $now = authorlift_now_iso();
        $record = array_merge(
            array('id' => authorlift_new_id($prefix), 'createdAt' => $now),
            $doc,
            array('updatedAt' => $now)
        );
        if (empty($record['id'])) {
            $record['id'] = authorlift_new_id($prefix);
        }
        $this->data[$collection][] = $record;
        $this->touch();
        return $record;
    }

    public function update($collection, $id, $patch) {
        $this->assert_collection($collection);
        foreach ($this->data[$collection] as $i => $doc) {
            if ($doc['id'] === $id) {
                $merged = array_merge($doc, $patch);
                $merged['id'] = $doc['id'];
                $merged['createdAt'] = $doc['createdAt'];
                $merged['updatedAt'] = authorlift_now_iso();
                $this->data[$collection][$i] = $merged;
                $this->touch();
                return $merged;
            }
        }
        return null;
    }

    public function remove($collection, $id) {
        $this->assert_collection($collection);
        foreach ($this->data[$collection] as $i => $doc) {
            if ($doc['id'] === $id) {
                array_splice($this->data[$collection], $i, 1);
                $this->touch();
                return true;
            }
        }
        return false;
    }
}
