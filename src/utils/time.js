/**
 * Time helpers. All persisted timestamps are ISO-8601 UTC strings; scheduling
 * math is done in plain UTC milliseconds. "Local hour" slots are approximated by
 * applying a fixed UTC offset for the author's timezone (see TZ_OFFSETS) which
 * is sufficient for planning best-time-to-post slots without a tz database.
 */

const DAY_MS = 24 * 60 * 60 * 1000;

// Coarse UTC offsets (hours) for the timezones we surface in the UI. This avoids
// bundling a full IANA database; DST is intentionally ignored for planning.
export const TZ_OFFSETS = {
  'UTC': 0,
  'America/Los_Angeles': -8,
  'America/Denver': -7,
  'America/Chicago': -6,
  'America/New_York': -5,
  'America/Sao_Paulo': -3,
  'Europe/London': 0,
  'Europe/Paris': 1,
  'Europe/Berlin': 1,
  'Africa/Johannesburg': 2,
  'Asia/Kolkata': 5.5,
  'Asia/Singapore': 8,
  'Asia/Tokyo': 9,
  'Australia/Sydney': 11,
};

export function tzOffsetHours(timezone) {
  return TZ_OFFSETS[timezone] ?? 0;
}

export function toIso(date) {
  return new Date(date).toISOString();
}

export function nowIso() {
  return new Date().toISOString();
}

export function addDays(date, days) {
  return new Date(new Date(date).getTime() + days * DAY_MS);
}

export function addMinutes(date, minutes) {
  return new Date(new Date(date).getTime() + minutes * 60 * 1000);
}

export function startOfUtcDay(date) {
  const d = new Date(date);
  d.setUTCHours(0, 0, 0, 0);
  return d;
}

/**
 * Return an ISO timestamp for the given calendar day at a target LOCAL hour,
 * translated back to UTC using the timezone's fixed offset.
 */
export function atLocalHour(day, localHour, timezone) {
  const offset = tzOffsetHours(timezone);
  const base = startOfUtcDay(day);
  // localHour local == (localHour - offset) UTC
  const utcMillisIntoDay = (localHour - offset) * 60 * 60 * 1000;
  return new Date(base.getTime() + utcMillisIntoDay);
}

export function daysBetween(a, b) {
  return Math.round((new Date(b).getTime() - new Date(a).getTime()) / DAY_MS);
}

export function isSameUtcDay(a, b) {
  return startOfUtcDay(a).getTime() === startOfUtcDay(b).getTime();
}

export { DAY_MS };
