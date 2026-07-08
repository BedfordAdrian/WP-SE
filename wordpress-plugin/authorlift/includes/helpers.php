<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared helpers: ids, a seedable PRNG, and small utilities. Kept dependency-free
 * so the domain logic can be unit-tested outside a full WordPress runtime.
 */

/** Generate a short, prefixed, collision-resistant id. */
function authorlift_new_id($prefix = 'id') {
    return $prefix . '_' . substr(bin2hex(random_bytes(8)), 0, 12);
}

/** 32-bit integer multiply (emulates JS Math.imul) without float precision loss. */
function authorlift_imul($a, $b) {
    $a &= 0xFFFFFFFF;
    $b &= 0xFFFFFFFF;
    $ah = ($a >> 16) & 0xFFFF;
    $al = $a & 0xFFFF;
    $bh = ($b >> 16) & 0xFFFF;
    $bl = $b & 0xFFFF;
    return (($al * $bl) + (((($ah * $bl) + ($al * $bh)) & 0xFFFF) << 16)) & 0xFFFFFFFF;
}

/** Unsigned 32-bit right shift. */
function authorlift_ushr($x, $n) {
    return ($x & 0xFFFFFFFF) >> $n;
}

/** Deterministic 32-bit FNV-1a hash of a string -> unsigned int seed. */
function authorlift_hash_string($str) {
    $h = 0x811C9DC5;
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
        $h ^= ord($str[$i]);
        $h = authorlift_imul($h, 0x01000193);
    }
    return $h & 0xFFFFFFFF;
}

/**
 * A small, fast, seedable PRNG (mulberry32). Deterministic for a given seed so
 * generated content variants and simulated metrics are reproducible.
 */
class AuthorLift_Rng {
    private $a;

    public function __construct($seed) {
        $this->a = $seed & 0xFFFFFFFF;
    }

    public function next() {
        $this->a = ($this->a + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->a;
        $t = authorlift_imul($t ^ authorlift_ushr($t, 15), $t | 1);
        $t = ((($t + authorlift_imul($t ^ authorlift_ushr($t, 7), $t | 61)) & 0xFFFFFFFF) ^ $t) & 0xFFFFFFFF;
        return (($t ^ authorlift_ushr($t, 14)) & 0xFFFFFFFF) / 4294967296;
    }

    /** Integer in [$min, $max] inclusive. */
    public function int($min, $max) {
        return (int) floor($min + $this->next() * ($max - $min + 1));
    }

    /** Pick one element of an array (or $fallback if empty). */
    public function pick($arr, $fallback = '') {
        if (empty($arr)) {
            return $fallback;
        }
        $arr = array_values($arr);
        return $arr[(int) floor($this->next() * count($arr))];
    }
}

/** Round to $dp decimal places. */
function authorlift_round($n, $dp = 2) {
    $f = pow(10, $dp);
    return round($n * $f) / $f;
}

// ---- Time helpers (all timestamps are ISO-8601 UTC strings) -----------------

define('AUTHORLIFT_DAY_MS', 24 * 60 * 60 * 1000);

/** Coarse UTC offsets (hours) for planning best-time slots; DST ignored. */
function authorlift_tz_offsets() {
    return array(
        'UTC' => 0,
        'America/Los_Angeles' => -8,
        'America/Denver' => -7,
        'America/Chicago' => -6,
        'America/New_York' => -5,
        'America/Sao_Paulo' => -3,
        'Europe/London' => 0,
        'Europe/Paris' => 1,
        'Europe/Berlin' => 1,
        'Africa/Johannesburg' => 2,
        'Asia/Kolkata' => 5.5,
        'Asia/Singapore' => 8,
        'Asia/Tokyo' => 9,
        'Australia/Sydney' => 11,
    );
}

function authorlift_tz_offset_hours($tz) {
    $offsets = authorlift_tz_offsets();
    return isset($offsets[$tz]) ? $offsets[$tz] : 0;
}

/** Milliseconds since epoch for an ISO string (or now if null). Handles the
 * millisecond fraction that JavaScript's Date.toISOString() emits, which
 * strtotime() does not reliably parse on its own. */
function authorlift_ms($iso = null) {
    if ($iso === null) {
        return (int) round(microtime(true) * 1000);
    }
    $frac = 0;
    if (preg_match('/\.(\d{1,3})/', $iso, $m)) {
        $frac = (int) str_pad($m[1], 3, '0');
    }
    $clean = preg_replace('/\.\d+/', '', $iso);
    $ts = strtotime($clean);
    return $ts === false ? 0 : $ts * 1000 + $frac;
}

/** ISO-8601 UTC string for a millisecond timestamp. */
function authorlift_iso($ms) {
    return gmdate('Y-m-d\TH:i:s', (int) floor($ms / 1000)) . '.' . sprintf('%03d', $ms % 1000) . 'Z';
}

function authorlift_now_iso() {
    return authorlift_iso(authorlift_ms());
}

function authorlift_add_days($ms, $days) {
    return $ms + (int) round($days * AUTHORLIFT_DAY_MS);
}

function authorlift_start_of_utc_day($ms) {
    return (int) (floor($ms / AUTHORLIFT_DAY_MS) * AUTHORLIFT_DAY_MS);
}

function authorlift_days_between($aMs, $bMs) {
    return (int) round(($bMs - $aMs) / AUTHORLIFT_DAY_MS);
}

/** ISO timestamp for a given day (ms) at a target LOCAL hour in $tz. */
function authorlift_at_local_hour($dayMs, $localHour, $tz) {
    $offset = authorlift_tz_offset_hours($tz);
    $base = authorlift_start_of_utc_day($dayMs);
    $intoDay = (int) round(($localHour - $offset) * 60 * 60 * 1000);
    return $base + $intoDay;
}

/** Validate an ISO date string; return normalised ISO or null. */
function authorlift_parse_iso($value) {
    if (!is_string($value) || $value === '') {
        return null;
    }
    $clean = preg_replace('/\.\d+/', '', $value);
    $ts = strtotime($clean);
    if ($ts === false) {
        return null;
    }
    return authorlift_iso($ts * 1000);
}

/**
 * Validation exception carrying an offending field name; the REST layer turns
 * this into a 400 response.
 */
class AuthorLift_Validation_Exception extends Exception {
    public $field;
    public function __construct($message, $field = null) {
        parent::__construct($message);
        $this->field = $field;
    }
}

function authorlift_assert($condition, $message, $field = null) {
    if (!$condition) {
        throw new AuthorLift_Validation_Exception($message, $field);
    }
}

function authorlift_require_string($value, $field, $max = 5000, $min = 1) {
    authorlift_assert(is_string($value), "$field must be a string", $field);
    $trimmed = trim($value);
    authorlift_assert(strlen($trimmed) >= $min, "$field is required", $field);
    authorlift_assert(strlen($trimmed) <= $max, "$field must be at most $max characters", $field);
    return $trimmed;
}

function authorlift_optional_string($value, $field, $max = 5000) {
    if ($value === null || $value === '') {
        return null;
    }
    return authorlift_require_string($value, $field, $max);
}

function authorlift_require_one_of($value, $options, $field) {
    authorlift_assert(in_array($value, $options, true), "$field must be one of: " . implode(', ', $options), $field);
    return $value;
}

function authorlift_optional_one_of($value, $options, $field) {
    if ($value === null || $value === '') {
        return null;
    }
    return authorlift_require_one_of($value, $options, $field);
}

function authorlift_string_array($value, $field, $max = 50) {
    if ($value === null) {
        return array();
    }
    authorlift_assert(is_array($value), "$field must be an array", $field);
    $out = array();
    foreach ($value as $item) {
        if (is_string($item)) {
            $t = trim($item);
            if ($t !== '') {
                $out[] = $t;
            }
        }
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

function authorlift_optional_number($value, $field, $min = -INF, $max = INF) {
    if ($value === null || $value === '') {
        return null;
    }
    authorlift_assert(is_numeric($value), "$field must be a number", $field);
    $num = 0 + $value;
    authorlift_assert($num >= $min && $num <= $max, "$field is out of range", $field);
    return $num;
}
