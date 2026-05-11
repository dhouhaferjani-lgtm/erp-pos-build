import { describe, expect, it } from 'vitest';
import { coerceSyncError } from '@/lib/sync/coerceSyncError';

describe('coerceSyncError', () => {
  it('returns the message for Error instances', () => {
    expect(coerceSyncError(new Error('boom'))).toBe('boom');
  });

  it('preserves raw string throwables (Tauri plugin-sql lock signature)', () => {
    // Bug 5 anchor: the SQLite plugin throws the lock failure as a string,
    // not an Error instance. Migration v32's recovery LIKE-match relies on
    // this exact signature reaching the `sync_error` column.
    const lockMessage =
      'error returned from database: (code: 5) database is locked';
    expect(coerceSyncError(lockMessage)).toBe(lockMessage);
  });

  it('JSON-stringifies plain object throwables', () => {
    expect(coerceSyncError({ code: 5, message: 'locked' })).toBe(
      '{"code":5,"message":"locked"}',
    );
  });

  it('falls back to String() for objects whose JSON.stringify throws', () => {
    const cyclical: Record<string, unknown> = {};
    cyclical.self = cyclical;
    const result = coerceSyncError(cyclical);
    expect(result).toBe('[object Object]');
  });

  it('coerces numbers to string', () => {
    expect(coerceSyncError(42)).toBe('42');
  });

  it('coerces booleans to string', () => {
    expect(coerceSyncError(false)).toBe('false');
  });

  it('coerces null to string', () => {
    expect(coerceSyncError(null)).toBe('null');
  });

  it('coerces undefined to string', () => {
    expect(coerceSyncError(undefined)).toBe('undefined');
  });

  it('preserves Error subclass messages', () => {
    class CustomError extends Error {}
    expect(coerceSyncError(new CustomError('custom'))).toBe('custom');
  });
});
