# POS Go-Live PR #4 Codex Review - Round 1

Command: `codex review --base dev`

Scope reviewed: POS operator runbooks under `docs/pos-operations/`.

Result: CHANGES REQUESTED

Findings:

- P1: `docs/pos-operations/restore.md` restored `.db-wal` and `.db-shm` only when present in the backup, but did not remove stale sidecar files already present beside the target database. This could leave SQLite replaying old WAL/SHM content after restoring a cold backup.

Resolution:

- Updated the restore procedure to remove the target `.db`, `.db-wal`, and `.db-shm` files before copying the restored database set.
