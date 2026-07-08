/**
 * Tiny validation helpers. We keep validation explicit and dependency-free so
 * the API returns clear 400s instead of throwing deep in the domain layer.
 */

export class ValidationError extends Error {
  constructor(message, field) {
    super(message);
    this.name = 'ValidationError';
    this.field = field;
    this.status = 400;
  }
}

export function assert(condition, message, field) {
  if (!condition) throw new ValidationError(message, field);
}

export function requireString(value, field, { min = 1, max = 5000 } = {}) {
  assert(typeof value === 'string', `${field} must be a string`, field);
  const trimmed = value.trim();
  assert(trimmed.length >= min, `${field} must be at least ${min} character(s)`, field);
  assert(trimmed.length <= max, `${field} must be at most ${max} characters`, field);
  return trimmed;
}

export function optionalString(value, field, opts) {
  if (value === undefined || value === null || value === '') return undefined;
  return requireString(value, field, opts);
}

export function requireOneOf(value, options, field) {
  assert(options.includes(value), `${field} must be one of: ${options.join(', ')}`, field);
  return value;
}

export function optionalOneOf(value, options, field) {
  if (value === undefined || value === null) return undefined;
  return requireOneOf(value, options, field);
}

export function requireIsoDate(value, field) {
  assert(typeof value === 'string', `${field} must be an ISO date string`, field);
  const time = Date.parse(value);
  assert(!Number.isNaN(time), `${field} is not a valid date`, field);
  return new Date(time).toISOString();
}

export function optionalIsoDate(value, field) {
  if (value === undefined || value === null || value === '') return undefined;
  return requireIsoDate(value, field);
}

export function toStringArray(value, field, { max = 50 } = {}) {
  if (value === undefined || value === null) return [];
  assert(Array.isArray(value), `${field} must be an array`, field);
  assert(value.length <= max, `${field} may contain at most ${max} items`, field);
  return value
    .map((v) => (typeof v === 'string' ? v.trim() : ''))
    .filter((v) => v.length > 0);
}

export function optionalNumber(value, field, { min = -Infinity, max = Infinity } = {}) {
  if (value === undefined || value === null || value === '') return undefined;
  const num = Number(value);
  assert(!Number.isNaN(num), `${field} must be a number`, field);
  assert(num >= min && num <= max, `${field} must be between ${min} and ${max}`, field);
  return num;
}
