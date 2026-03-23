# Windows Desktop Fixes: Customer Display Safety, Fullscreen, Localization

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 3 critical desktop app issues — customer display lock-out, Windows 10 fullscreen, incomplete French localization.

**Architecture:** Tauri 2 desktop app at `apps/pos/`. Rust backend at `apps/pos/src-tauri/src/`. React frontend at `apps/pos/src/`.

**Tech Stack:** Rust, Tauri 2, React 19, TypeScript strict, Zustand 5

---

## Task 1: Fix Customer Display — Prevent Lock-Out on Single Monitor (CRITICAL)

**Files:**
- Modify: `apps/pos/src-tauri/src/commands/display.rs:103-112`
- Modify: `apps/pos/src/App.tsx:172-184`
- Modify: `apps/pos/src/pages/CustomerDisplayPage.tsx`

### The Bug

When user enables customer display with only one monitor:
1. Rust auto-detect falls back to primary monitor (`display.rs:111`)
2. A fullscreen, always-on-top, skip-taskbar, decoration-less window opens on the only screen
3. Main app is hidden behind it — no escape mechanism
4. State persists in localStorage — locks out on every restart

### Fix 1a: Rust — Error instead of fallback to primary

- [ ] **Step 1: Change auto-detect to reject single-monitor setups**

In `apps/pos/src-tauri/src/commands/display.rs`, replace lines 103-112:

```rust
// Auto-detect: pick first non-primary monitor, or fall back to primary
monitors
    .iter()
    .find(|m| {
        let name = m.name().map(|n| n.to_string());
        name != primary_name
    })
    .unwrap_or_else(|| &monitors[0])
```

With:

```rust
// Auto-detect: pick first non-primary monitor; error if none exists
monitors
    .iter()
    .find(|m| {
        let name = m.name().map(|n| n.to_string());
        name != primary_name
    })
    .ok_or_else(|| "No secondary monitor found. Connect a second screen to use the customer display.".to_string())?
```

### Fix 1b: App.tsx — Validate before auto-opening, disable on error

- [ ] **Step 2: Add safety guard in auto-open effect**

In `apps/pos/src/App.tsx`, the auto-open effect (lines 172-184) silently catches errors. Change it to disable the customer display on failure so it doesn't retry every restart:

Replace:
```typescript
const autoOpen = async () => {
  try {
    await openCustomerDisplay(cfdMonitorIndex ?? undefined);
    setIsOpen(true);
    await sendIdleScreen(cfdIdleImagePath);
  } catch {
    // Non-critical — display may not be connected
  }
};
```

With:
```typescript
const autoOpen = async () => {
  try {
    await openCustomerDisplay(cfdMonitorIndex ?? undefined);
    setIsOpen(true);
    await sendIdleScreen(cfdIdleImagePath);
  } catch (error) {
    console.warn('Customer display failed to open:', error);
    // Disable to prevent retry loop on restart
    useCustomerDisplayStore.getState().setEnabled(false);
    setIsOpen(false);
  }
};
```

### Fix 1c: Add Escape key safety hatch to CustomerDisplayPage

- [ ] **Step 3: Add escape key listener**

In `apps/pos/src/pages/CustomerDisplayPage.tsx`, add a `useEffect` that listens for `Escape` key and closes the display:

```typescript
useEffect(() => {
  const handleKeyDown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
      void (async () => {
        try {
          const { invoke } = await import('@tauri-apps/api/core');
          await invoke('close_customer_display');
        } catch {
          // Fallback: close the window directly
          const { getCurrentWindow } = await import('@tauri-apps/api/window');
          await getCurrentWindow().close();
        }
      })();
    }
  };
  window.addEventListener('keydown', handleKeyDown);
  return () => window.removeEventListener('keydown', handleKeyDown);
}, []);
```

Read the file first to find the right insertion point and check existing imports.

- [ ] **Step 4: Verify Rust compiles**

Run: `cd apps/pos && cargo check --manifest-path src-tauri/Cargo.toml`

- [ ] **Step 5: Run frontend checks**

Run: `cd apps/pos && pnpm typecheck && pnpm vitest run`

- [ ] **Step 6: Commit**

```bash
git commit -m "fix(pos): prevent customer display from locking out single-monitor setups — error instead of fallback, escape key safety, auto-disable on failure"
```

---

## Task 2: Fix Windows 10 Fullscreen + Add Exit Fullscreen Button

**Files:**
- Modify: `apps/pos/src/pages/SettingsPage.tsx:32-45` (fix toggleFullscreen)
- Modify: `apps/pos/src/components/pos/Header.tsx` or wherever the POS header is (add minimize/exit button)

### Fix 2a: Consolidate fullscreen logic with decorations + alwaysOnTop

- [ ] **Step 1: Fix `toggleFullscreen` in SettingsPage.tsx**

Replace lines 32-45:
```typescript
async function toggleFullscreen(enabled: boolean): Promise<void> {
  try {
    if (isTauriEnvironment()) {
      const { getCurrentWindow } = await import('@tauri-apps/api/window');
      await getCurrentWindow().setFullscreen(enabled);
    } else if (enabled) {
      await document.documentElement.requestFullscreen?.();
    } else if (document.fullscreenElement) {
      await document.exitFullscreen?.();
    }
  } catch {
    // Fullscreen may be blocked by browser policy — ignore
  }
}
```

With:
```typescript
async function toggleFullscreen(enabled: boolean): Promise<void> {
  try {
    if (isTauriEnvironment()) {
      const { getCurrentWindow } = await import('@tauri-apps/api/window');
      const win = getCurrentWindow();
      if (enabled) {
        await win.setDecorations(false);
        await win.setFullscreen(true);
        await win.setAlwaysOnTop(true);
      } else {
        await win.setAlwaysOnTop(false);
        await win.setFullscreen(false);
        await win.setDecorations(true);
      }
    } else if (enabled) {
      await document.documentElement.requestFullscreen?.();
    } else if (document.fullscreenElement) {
      await document.exitFullscreen?.();
    }
  } catch {
    // Fullscreen may be blocked by browser policy — ignore
  }
}
```

- [ ] **Step 2: Update App.tsx fullscreen effect to include alwaysOnTop**

In `apps/pos/src/App.tsx`, update the fullscreen effect to also set `alwaysOnTop`:

```typescript
if (fullscreen) {
  await win.setDecorations(false);
  await win.setFullscreen(true);
  await win.setAlwaysOnTop(true);
} else {
  await win.setAlwaysOnTop(false);
  await win.setFullscreen(false);
  await win.setDecorations(true);
}
```

### Fix 2b: Add minimize/exit button in POS header

- [ ] **Step 3: Add an exit fullscreen button to the POS header**

Find the POS header component (likely in the POS layout or Header component). Add a small button (icon only) that:
- When in fullscreen: shows a `Minimize2` or `Shrink` icon, clicking it exits fullscreen mode
- Uses `useSettingsStore` to toggle `fullscreen` to `false`
- Also calls `toggleFullscreen(false)` directly

The button should be subtle (small, gray, in the header bar) so it doesn't clutter the POS UI but provides a way out.

Read the header/layout components first to find where to add it.

Also add a `t()` key for the tooltip.

- [ ] **Step 4: Run checks**

Run: `cd apps/pos && pnpm typecheck && pnpm vitest run`

- [ ] **Step 5: Commit**

```bash
git commit -m "fix(pos): fix Windows 10 fullscreen with alwaysOnTop + add exit fullscreen button in header"
```

---

## Task 3: Fix French Localization — 3 Hardcoded English Strings

**Files:**
- Modify: `apps/pos/src/components/settings/CustomerDisplaySettings.tsx:318,333`
- Modify: `apps/pos/src/pages/SettingsPage.tsx:561`
- Modify: `apps/pos/src/locales/en/pos.json` (add keys)
- Modify: `apps/pos/src/locales/fr/pos.json` (add keys)

### Three spots to fix:

- [ ] **Step 1: Fix "Primary" badge**

In `CustomerDisplaySettings.tsx:318`, replace hardcoded "Primary" with `t('settings.cfd.primaryBadge')`.

Add to `en/pos.json`: `"primaryBadge": "Primary"` inside the `settings.cfd` section.
Add to `fr/pos.json`: `"primaryBadge": "Principal"`.

- [ ] **Step 2: Fix "Monitor N" fallback**

In `CustomerDisplaySettings.tsx:333`, replace `` `Monitor ${index + 1}` `` with `t('settings.cfd.monitorFallback', { number: index + 1 })`.

Add to `en/pos.json`: `"monitorFallback": "Monitor {{number}}"` inside `settings.cfd`.
Add to `fr/pos.json`: `"monitorFallback": "Écran {{number}}"`.

- [ ] **Step 3: Fix raw shift.status**

In `SettingsPage.tsx:561`, replace `{shift.status}` with `{t(`shift.statusLabel.${shift.status}`)}`.

Add to `en/pos.json` inside the `shift` section:
```json
"statusLabel": {
  "open": "Open",
  "closed": "Closed"
}
```

Add to `fr/pos.json`:
```json
"statusLabel": {
  "open": "Ouvert",
  "closed": "Fermé"
}
```

- [ ] **Step 4: Run checks**

Run: `cd apps/pos && pnpm typecheck && pnpm vitest run`

- [ ] **Step 5: Commit**

```bash
git commit -m "fix(pos): complete French localization — translate Primary badge, Monitor fallback, shift status"
```

---

## Task Dependencies

```
Task 1 (customer display) ── CRITICAL, do first
Task 2 (fullscreen) ── independent
Task 3 (localization) ── independent
```
