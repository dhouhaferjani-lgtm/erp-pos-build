# web.tanstack-keys Batch 54 — Opus Review

Commit reviewed: c970e935
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: attachment hooks — callsites web.tanstack-keys.182-185 (4)
Scanner delta: 301 → 297 (-4)

## Summary

`useAttachments`, `useUploadAttachment`, `useDeleteAttachment`, `useAttachmentConfig` wrapped. Exact-key invalidation using `tenantScopedKey(['attachments', documentId])`; tenant suffix prevents cross-tenant invalidation. Production hooks exercised in tests with per-call counters + tenant-B markers.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-54-63-opus-review.md`.
