// Fixture: a CREATE TABLE on a non-guarded resource (`app_settings`). The
// scanner MUST NOT flag this — only tables in the canonical guarded list
// (mirrored from PhpPresentationExistsScanner::DEFAULT_GUARDED_TABLES) are
// in scope for the tenant-isolation gate.

export const createAppSettingsTable = `
  CREATE TABLE app_settings (
    key TEXT PRIMARY KEY,
    value TEXT
  )
`;
