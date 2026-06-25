# H-7.4 Batch Write-Off Posting — Independent Fallback Review

## Scope

Second adversarial pass because a true Opus reviewer was not available in this runtime. Review lens: failure semantics, catch boundaries, backward compatibility, and hidden residual draft writers.

## Findings

No BLOCKER/HIGH/MEDIUM findings.

## Checks

- `BatchWriteOffService` already receives `userId` from the controller, so this change does not invent a system actor or rely on auth state inside the GL layer.
- `User::findOrFail()` happens before the journal transaction; if the actor id is invalid, no GL header or lines are created.
- The existing `catch (\RuntimeException)` around missing GL account setup remains unchanged. Actor lookup failures are not swallowed by that catch path, which is appropriate because invalid write-off actors are not optional accounting configuration.
- The tenant isolation regression for batch write-off product lookup remains green.
- A source-type scan found `UninvoicedDeliveryNoteService` draft adjustment writers. Those are separate from batch write-off and should be handled as a follow-up item before the overall session is closed.

## Residual Risk

This change does not make write-off GL creation idempotent by movement id. That behavior was pre-existing and was not part of this lifecycle hardening slice.
