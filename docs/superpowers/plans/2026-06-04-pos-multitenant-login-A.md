# POS Desktop Multi-Tenant Login (Sub-Spec A) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the POS desktop client (`apps/pos`) handle the backend's email-first, multi-tenant `/auth/login` flow — auto-selecting a device-bound persisted tenant, showing a business picker when needed, and self-healing a stale binding.

**Architecture:** Frontend-only change in `apps/pos`. `authStore.login()` becomes email-first: it POSTs without `tenant_id`, handles a `requires_org_selection` union response by auto-selecting a persisted `LOGIN_TENANT_ID` (one extra POST) or returning the org list to the UI, and persists the resolved tenant best-effort. `LoginPage` gains a name+slug business picker with a concurrency guard and its own abort controller. No backend or `apps/web` changes.

**Tech Stack:** React 19, TypeScript (strict), Zustand 5, Vitest + Testing Library, Tauri plugin-store. Spec: `docs/superpowers/specs/2026-06-03-pos-multitenant-login-design.md`. Codex reviews: `docs/superpowers/reviews/2026-06-03-pos-multitenant-login-codex-review.md` (+ `-r2`).

**Backend contract (already on `dev`, unchanged):** `POST /auth/login` returns, on HTTP 200, EITHER `{ user, token, tokenType, deviceId }` OR `{ requires_org_selection: true, organizations: [{ tenant_id, name, slug }] }` (the latter when the email maps to >1 tenant; credentials are NOT validated on that call). Explicit `tenant_id` in the body binds that tenant and validates credentials. Failures: `422` `{ error: { code: 'VALIDATION_ERROR', message } }` (wrong password / unknown tenant / inactive / no organizations — the translated message differentiates them) and `403` `{ error: { code: 'ORGANIZATION_UNAVAILABLE', message } }` (suspended/archived). `apiPost`/`api.ts` already throw `ApiRequestError(status, apiMessage, code)` for non-2xx, so `getErrorMessage(err)` surfaces the server message verbatim.

**Conventions to follow (verified in the codebase):**
- `apiPost<T>(url, body, opts)` returns `response.data` (already unwrapped) and accepts `opts: { timeoutMs?, signal? }`.
- Tests mock `@/lib/api` (`apiPost`/`apiGet` as `vi.fn()`), `@/lib/storage`, `@/lib/echo`, `@tauri-apps/plugin-os`, `@/lib/device`. Follow the existing mock blocks in `apps/pos/src/stores/__tests__/authStore.test.ts` and `apps/pos/src/pages/__tests__/LoginPage.test.tsx`.
- All user-facing strings use `t(...)` from the `pos` namespace; keys live in `apps/pos/src/locales/en/pos.json` and `apps/pos/src/locales/fr/pos.json` under `auth`.
- Run the POS test suite with: `cd apps/pos && pnpm vitest run <path>`.

---

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `apps/pos/src/lib/storage.ts` | Tauri key/value persistence + key registry | Add `LOGIN_TENANT_ID` key |
| `apps/pos/src/stores/authStore.ts` | Auth state + login/logout | New login union types, email-first flow, auto-select, best-effort tenant persist, new return type, `UnexpectedLoginResponseError`, in-flight guard |
| `apps/pos/src/pages/LoginPage.tsx` | Login + company picker UI | Business (org) picker with concurrency guard + own AbortController; surface server error message |
| `apps/pos/src/locales/en/pos.json`, `fr/pos.json` | i18n | New `auth.*` keys for the picker |
| `apps/pos/src/stores/__tests__/authStore.test.ts` | authStore unit tests | New tests |
| `apps/pos/src/pages/__tests__/LoginPage.test.tsx` | LoginPage component tests | New tests |

**Task order:** 1 (storage key) → 2 (types + email-first union handling) → 3 (auto-select + best-effort persist) → 4 (in-flight guard + return type) → 5 (LoginPage picker + abort) → 6 (i18n) → 7 (full-suite + typecheck/lint gate).

---

## Task 1: Add the `LOGIN_TENANT_ID` storage key

**Files:**
- Modify: `apps/pos/src/lib/storage.ts:14-24` (the `StorageKeys` object)

- [ ] **Step 1: Add the key**

In `apps/pos/src/lib/storage.ts`, add `LOGIN_TENANT_ID` to the `StorageKeys` object (do NOT add it to `ENCRYPTED_KEYS` — it is a tenant UUID, not a secret):

```ts
export const StorageKeys = {
  TOKEN: 'auth_token',
  SERVER_URL: 'server_url',
  USER: 'user',
  COMPANY_ID: 'company_id',
  COMPANIES: 'companies',
  TERMINAL: 'terminal',
  PENDING_TERMINAL_ID: 'pending_terminal_id',
  SHIFT: 'current_shift',
  C2_MIGRATION_BANNER: 'c2_migration_banner',
  // Sub-Spec A: device-bound preferred tenant for email-first login.
  // Non-authoritative hint (auto-selects the org picker); NOT encrypted.
  LOGIN_TENANT_ID: 'login_tenant_id',
} as const;
```

- [ ] **Step 2: Verify it compiles**

Run: `cd apps/pos && pnpm tsc --noEmit`
Expected: no new errors.

- [ ] **Step 3: Commit**

```bash
git add apps/pos/src/lib/storage.ts
git commit -m "feat(pos-login): add LOGIN_TENANT_ID storage key"
```

---

## Task 2: Login response union + email-first POST (no auto-select yet)

This task introduces the types and makes `login()` POST email-first and handle the two response shapes. Auto-select is added in Task 3; for now, a `requires_org_selection` response returns the org list to the caller.

**Files:**
- Modify: `apps/pos/src/stores/authStore.ts` (imports, new types near `User`/`Company` ~line 14-46; `login` signature in `AuthActions` ~line 56-60; `login` body ~line 145-242)
- Test: `apps/pos/src/stores/__tests__/authStore.test.ts`

- [ ] **Step 1: Write the failing tests**

Add to `apps/pos/src/stores/__tests__/authStore.test.ts`. First extend the existing `@/lib/storage` mock's `StorageKeys` to include `LOGIN_TENANT_ID: 'login_tenant_id'` (add the line inside the existing `StorageKeys: { ... }` block). Then add:

```ts
describe('login — email-first multi-tenant', () => {
  beforeEach(() => {
    vi.mocked(getStoredValue).mockResolvedValue(null);
    useAuthStore.setState({
      user: null, token: null, companies: [], companyId: null,
      isAuthenticated: false, isLoading: false,
    });
  });

  it('single-tenant email: authenticates and POSTs without tenant_id', async () => {
    vi.mocked(apiPost).mockResolvedValueOnce({
      user: mockUser, token: 'tok-1', tokenType: 'Bearer', deviceId: 'dev-1',
    });
    vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

    const result = await useAuthStore.getState().login('test@example.com', 'password123');

    expect(result).toEqual({ status: 'authenticated' });
    expect(apiPost).toHaveBeenCalledTimes(1);
    const body = vi.mocked(apiPost).mock.calls[0][1] as Record<string, unknown>;
    expect(body).not.toHaveProperty('tenant_id');
    expect(useAuthStore.getState().isAuthenticated).toBe(true);
  });

  it('multi-tenant email with no stored tenant: returns requires_org_selection', async () => {
    vi.mocked(apiPost).mockResolvedValueOnce({
      requires_org_selection: true,
      organizations: [
        { tenant_id: 't-1', name: 'Alpha', slug: 'alpha' },
        { tenant_id: 't-2', name: 'Beta', slug: 'beta' },
      ],
    });

    const result = await useAuthStore.getState().login('multi@example.com', 'password123');

    expect(result).toEqual({
      status: 'requires_org_selection',
      organizations: [
        { tenant_id: 't-1', name: 'Alpha', slug: 'alpha' },
        { tenant_id: 't-2', name: 'Beta', slug: 'beta' },
      ],
    });
    expect(apiPost).toHaveBeenCalledTimes(1);
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    expect(apiGet).not.toHaveBeenCalled(); // never fetched companies
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd apps/pos && pnpm vitest run src/stores/__tests__/authStore.test.ts -t "email-first multi-tenant"`
Expected: FAIL (`login` returns `undefined`/`void`, not the union; or type error on the new shape).

- [ ] **Step 3: Add types and update the `AuthActions.login` signature**

In `apps/pos/src/stores/authStore.ts`, after the `Company` interface (around line 46), add:

```ts
export interface Organization {
  tenant_id: string;
  name: string;
  slug: string;
}

interface LoginSuccessResponse {
  user: User;
  token: string;
  tokenType: string;
  deviceId: string | null;
}

interface OrgSelectionResponse {
  requires_org_selection: true;
  organizations: Organization[];
}

type LoginApiResponse = LoginSuccessResponse | OrgSelectionResponse;

export type LoginOutcome =
  | { status: 'authenticated' }
  | { status: 'requires_org_selection'; organizations: Organization[] };

/** Thrown when the backend returns an org-picker shape for a call that
 *  already supplied an explicit tenant_id (contract violation). Prevents
 *  any re-POST loop. */
export class UnexpectedLoginResponseError extends Error {
  constructor() {
    super('Unexpected login response: org selection returned for an explicit tenant.');
    this.name = 'UnexpectedLoginResponseError';
  }
}

function isOrgSelection(r: LoginApiResponse): r is OrgSelectionResponse {
  return 'requires_org_selection' in r;
}
```

Change the `login` signature in the `AuthActions` interface (around line 56-60) to:

```ts
  login: (
    email: string,
    password: string,
    opts?: { signal?: AbortSignal; tenantId?: string },
  ) => Promise<LoginOutcome>;
```

- [ ] **Step 4: Rewrite the `login` body for email-first handling**

Replace the current `login: async (...) => { ... }` implementation (lines ~145-242) with the following. This keeps the existing T1.1 transactional companies-fetch + persist exactly, but factors the authenticated tail into a local helper so Task 3's auto-select can reuse it, and branches on the response union. (Task 3 fills in the auto-select; here the org-selection branch returns immediately.)

```ts
  login: async (
    email: string,
    password: string,
    opts?: { signal?: AbortSignal; tenantId?: string },
  ): Promise<LoginOutcome> => {
    const serverUrl = getServerUrl();
    set({ isLoading: true, serverUrl });

    // Local helper: given an authenticated login response, run the existing
    // transactional companies-fetch + persist, then best-effort persist the
    // device tenant hint. Reused by the auto-select re-POST (Task 3).
    const completeAuthentication = async (
      res: LoginSuccessResponse,
    ): Promise<void> => {
      const { user, token } = res;

      // T1.1: snapshot prior auth, write a temp token for the companies
      // fetch, restore verbatim on failure. (Unchanged behavior.)
      const priorAuth = {
        token: get().token,
        user: get().user,
        companies: get().companies,
        companyId: get().companyId,
        isAuthenticated: get().isAuthenticated,
      };
      set({ token });

      let companies: Company[];
      try {
        companies = await apiGet<Company[]>('/user/companies', undefined, {
          signal: opts?.signal,
        });
      } catch (error) {
        set(priorAuth);
        throw error;
      }

      await setStoredValue(StorageKeys.TOKEN, token);
      await setStoredValue(StorageKeys.USER, user);
      await setStoredValue(StorageKeys.COMPANIES, companies);

      set({ user, token, companies, isAuthenticated: true });

      if (companies.length === 1 && companies[0]) {
        const companyId = companies[0].id;
        await setStoredValue(StorageKeys.COMPANY_ID, companyId);
        set({ companyId });
      }

      // Best-effort, non-authoritative device tenant hint (MAJOR 4):
      // a failure here must NEVER throw or corrupt the committed auth state.
      try {
        await setStoredValue(StorageKeys.LOGIN_TENANT_ID, user.tenantId);
      } catch (e) {
        console.warn('[auth] failed to persist LOGIN_TENANT_ID (non-fatal):', e);
      }
    };

    try {
      const body: Record<string, unknown> = {
        email,
        password,
        device_id: getDeviceId(),
        device_name: 'IziPOS Desktop',
        platform: getTauriPlatform(),
      };
      if (opts?.tenantId) body.tenant_id = opts.tenantId;

      const response = await apiPost<LoginApiResponse>('/auth/login', body, {
        signal: opts?.signal,
      });

      if (isOrgSelection(response)) {
        // An explicit-tenant call must never receive a picker shape.
        if (opts?.tenantId) throw new UnexpectedLoginResponseError();
        // Task 3 inserts auto-select here. For now, surface the list.
        return { status: 'requires_org_selection', organizations: response.organizations };
      }

      await completeAuthentication(response);
      return { status: 'authenticated' };
    } catch (error) {
      console.error('[auth] Login failed:', error);
      throw error;
    } finally {
      set({ isLoading: false });
    }
  },
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd apps/pos && pnpm vitest run src/stores/__tests__/authStore.test.ts`
Expected: PASS (new tests + pre-existing login tests). If any pre-existing test asserted `login` resolves to `undefined`, update it to `{ status: 'authenticated' }`.

- [ ] **Step 6: Commit**

```bash
git add apps/pos/src/stores/authStore.ts apps/pos/src/stores/__tests__/authStore.test.ts
git commit -m "feat(pos-login): email-first login response union handling"
```

---

## Task 3: Auto-select persisted tenant + best-effort persist (self-heal)

**Files:**
- Modify: `apps/pos/src/stores/authStore.ts` (the org-selection branch inside `login`)
- Test: `apps/pos/src/stores/__tests__/authStore.test.ts`

- [ ] **Step 1: Write the failing tests**

```ts
describe('login — auto-select persisted tenant', () => {
  beforeEach(() => {
    useAuthStore.setState({
      user: null, token: null, companies: [], companyId: null,
      isAuthenticated: false, isLoading: false,
    });
  });

  it('persisted tenant in org list: re-POSTs with tenant_id, no picker', async () => {
    vi.mocked(getStoredValue).mockImplementation(async (key: string) =>
      key === 'login_tenant_id' ? 't-2' : null,
    );
    vi.mocked(apiPost)
      .mockResolvedValueOnce({
        requires_org_selection: true,
        organizations: [
          { tenant_id: 't-1', name: 'Alpha', slug: 'alpha' },
          { tenant_id: 't-2', name: 'Beta', slug: 'beta' },
        ],
      })
      .mockResolvedValueOnce({
        user: { ...mockUser, tenantId: 't-2' }, token: 'tok-2', tokenType: 'Bearer', deviceId: null,
      });
    vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

    const result = await useAuthStore.getState().login('multi@example.com', 'password123');

    expect(result).toEqual({ status: 'authenticated' });
    expect(apiPost).toHaveBeenCalledTimes(2);
    const secondBody = vi.mocked(apiPost).mock.calls[1][1] as Record<string, unknown>;
    expect(secondBody.tenant_id).toBe('t-2');
    expect(useAuthStore.getState().isAuthenticated).toBe(true);
  });

  it('persisted tenant NOT in org list (stale): returns picker, no re-POST', async () => {
    vi.mocked(getStoredValue).mockImplementation(async (key: string) =>
      key === 'login_tenant_id' ? 't-stale' : null,
    );
    vi.mocked(apiPost).mockResolvedValueOnce({
      requires_org_selection: true,
      organizations: [{ tenant_id: 't-1', name: 'Alpha', slug: 'alpha' }, { tenant_id: 't-2', name: 'Beta', slug: 'beta' }],
    });

    const result = await useAuthStore.getState().login('multi@example.com', 'password123');

    expect(result).toEqual({
      status: 'requires_org_selection',
      organizations: [{ tenant_id: 't-1', name: 'Alpha', slug: 'alpha' }, { tenant_id: 't-2', name: 'Beta', slug: 'beta' }],
    });
    expect(apiPost).toHaveBeenCalledTimes(1);
  });

  it('manual pick with tenantId persists LOGIN_TENANT_ID', async () => {
    vi.mocked(getStoredValue).mockResolvedValue(null);
    vi.mocked(apiPost).mockResolvedValueOnce({
      user: { ...mockUser, tenantId: 't-2' }, token: 'tok-2', tokenType: 'Bearer', deviceId: null,
    });
    vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

    await useAuthStore.getState().login('multi@example.com', 'password123', { tenantId: 't-2' });

    expect(setStoredValue).toHaveBeenCalledWith('login_tenant_id', 't-2');
  });

  it('explicit tenant returns picker shape: throws, no loop, no auth', async () => {
    vi.mocked(apiPost).mockResolvedValueOnce({
      requires_org_selection: true, organizations: [],
    });

    await expect(
      useAuthStore.getState().login('multi@example.com', 'password123', { tenantId: 't-1' }),
    ).rejects.toThrow(/Unexpected login response/);
    expect(apiPost).toHaveBeenCalledTimes(1);
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
  });

  it('wrong password after auto-select: error surfaced, tenant NOT cleared', async () => {
    vi.mocked(getStoredValue).mockImplementation(async (key: string) =>
      key === 'login_tenant_id' ? 't-2' : null,
    );
    vi.mocked(apiPost)
      .mockResolvedValueOnce({
        requires_org_selection: true,
        organizations: [{ tenant_id: 't-1', name: 'A', slug: 'a' }, { tenant_id: 't-2', name: 'B', slug: 'b' }],
      })
      .mockRejectedValueOnce(new ApiRequestError(422, 'The provided credentials are incorrect.', 'VALIDATION_ERROR'));

    await expect(
      useAuthStore.getState().login('multi@example.com', 'wrong'),
    ).rejects.toThrow(/credentials/);
    expect(removeStoredValue).not.toHaveBeenCalledWith('login_tenant_id');
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
  });
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd apps/pos && pnpm vitest run src/stores/__tests__/authStore.test.ts -t "auto-select"`
Expected: FAIL (only 1 `apiPost` call; no re-POST logic yet).

- [ ] **Step 3: Implement auto-select in the org-selection branch**

In `login`, replace the placeholder org-selection branch from Task 2:

```ts
      if (isOrgSelection(response)) {
        // An explicit-tenant call must never receive a picker shape.
        if (opts?.tenantId) throw new UnexpectedLoginResponseError();
        // Task 3 inserts auto-select here. For now, surface the list.
        return { status: 'requires_org_selection', organizations: response.organizations };
      }
```

with:

```ts
      if (isOrgSelection(response)) {
        // Contract: an explicit-tenant call can never get a picker shape.
        if (opts?.tenantId) throw new UnexpectedLoginResponseError();

        // Auto-select the device-bound persisted tenant IF it is one of the
        // returned orgs. Exactly one extra POST (no recursion / no loop).
        const storedTenantId = await getStoredValue<string>(StorageKeys.LOGIN_TENANT_ID);
        const match =
          storedTenantId != null &&
          response.organizations.some((o) => o.tenant_id === storedTenantId);

        if (match) {
          const second = await apiPost<LoginApiResponse>('/auth/login', {
            email,
            password,
            tenant_id: storedTenantId,
            device_id: getDeviceId(),
            device_name: 'IziPOS Desktop',
            platform: getTauriPlatform(),
          }, { signal: opts?.signal });

          if (isOrgSelection(second)) throw new UnexpectedLoginResponseError();
          await completeAuthentication(second);
          return { status: 'authenticated' };
        }

        // Stale or no stored tenant → let the UI show the picker.
        return { status: 'requires_org_selection', organizations: response.organizations };
      }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/pos && pnpm vitest run src/stores/__tests__/authStore.test.ts`
Expected: PASS (all auth tests).

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/stores/authStore.ts apps/pos/src/stores/__tests__/authStore.test.ts
git commit -m "feat(pos-login): auto-select persisted tenant with self-heal"
```

---

## Task 4: In-flight guard (single-flight) on `login()`

Prevents concurrent `login()` calls (e.g. a double-click on a picker button) from racing the T1.1 temp-token write.

**Files:**
- Modify: `apps/pos/src/stores/authStore.ts` (top of `login`)
- Test: `apps/pos/src/stores/__tests__/authStore.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
it('rejects a concurrent login while one is already in flight', async () => {
  useAuthStore.setState({ isLoading: false, isAuthenticated: false });
  let resolveFirst: (v: unknown) => void = () => {};
  vi.mocked(getStoredValue).mockResolvedValue(null);
  vi.mocked(apiPost).mockImplementationOnce(
    () => new Promise((res) => { resolveFirst = res; }),
  );
  vi.mocked(apiGet).mockResolvedValue(mockCompanies);

  const first = useAuthStore.getState().login('a@example.com', 'password123');
  // Second call while the first is pending:
  await expect(
    useAuthStore.getState().login('a@example.com', 'password123'),
  ).rejects.toThrow(/already in progress/);

  resolveFirst({ user: mockUser, token: 'tok', tokenType: 'Bearer', deviceId: null });
  await first;
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd apps/pos && pnpm vitest run src/stores/__tests__/authStore.test.ts -t "concurrent login"`
Expected: FAIL (no guard; second call proceeds).

- [ ] **Step 3: Add the guard**

At the very top of `login` (before `set({ isLoading: true, ... })`):

```ts
    if (get().isLoading) {
      throw new Error('A login is already in progress.');
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/pos && pnpm vitest run src/stores/__tests__/authStore.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/stores/authStore.ts apps/pos/src/stores/__tests__/authStore.test.ts
git commit -m "feat(pos-login): single-flight guard on login()"
```

---

## Task 5: LoginPage business picker + dedicated AbortController

**Files:**
- Modify: `apps/pos/src/pages/LoginPage.tsx`
- Test: `apps/pos/src/pages/__tests__/LoginPage.test.tsx`

- [ ] **Step 1: Write the failing tests**

Extend the `@/lib/storage` mock in `LoginPage.test.tsx` `StorageKeys` with `LOGIN_TENANT_ID: 'login_tenant_id'`. Add:

```ts
describe('LoginPage — business picker', () => {
  beforeEach(() => {
    vi.mocked(getStoredValue).mockResolvedValue(null);
    useConnectivityStore.setState({ isOnline: true });
    useAuthStore.setState({
      user: null, token: null, companies: [], companyId: null,
      isAuthenticated: false, isLoading: false,
    });
  });

  it('renders the org picker (name + slug) when login requires selection', async () => {
    vi.mocked(apiPost).mockResolvedValueOnce({
      requires_org_selection: true,
      organizations: [
        { tenant_id: 't-1', name: 'Alpha', slug: 'alpha' },
        { tenant_id: 't-2', name: 'Beta', slug: 'beta' },
      ],
    });
    render(<LoginPage />);
    fireEvent.change(screen.getByLabelText('auth.email'), { target: { value: 'm@e.com' } });
    fireEvent.change(screen.getByLabelText('auth.password'), { target: { value: 'password123' } });
    await act(async () => { fireEvent.submit(screen.getByRole('button', { name: 'auth.signIn' }).closest('form')!); });

    expect(await screen.findByTestId('org-picker')).toBeInTheDocument();
    expect(screen.getByText('Alpha')).toBeInTheDocument();
    expect(screen.getByText('alpha')).toBeInTheDocument(); // slug shown
  });

  it('selecting an org re-invokes login with tenantId', async () => {
    vi.mocked(apiPost)
      .mockResolvedValueOnce({
        requires_org_selection: true,
        organizations: [{ tenant_id: 't-1', name: 'Alpha', slug: 'alpha' }, { tenant_id: 't-2', name: 'Beta', slug: 'beta' }],
      })
      .mockResolvedValueOnce({ user: mockUser, token: 'tok', tokenType: 'Bearer', deviceId: null });
    vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

    render(<LoginPage />);
    fireEvent.change(screen.getByLabelText('auth.email'), { target: { value: 'm@e.com' } });
    fireEvent.change(screen.getByLabelText('auth.password'), { target: { value: 'password123' } });
    await act(async () => { fireEvent.submit(screen.getByRole('button', { name: 'auth.signIn' }).closest('form')!); });
    await screen.findByTestId('org-picker');

    await act(async () => { fireEvent.click(screen.getByRole('button', { name: /Beta/ })); });

    const lastCall = vi.mocked(apiPost).mock.calls.at(-1)!;
    expect((lastCall[1] as Record<string, unknown>).tenant_id).toBe('t-2');
  });

  it('aborting a manual pick commits no auth state (fresh controller)', async () => {
    vi.useFakeTimers();
    // First POST resolves the picker; the pick POST hangs so we can abort it.
    vi.mocked(apiPost)
      .mockResolvedValueOnce({
        requires_org_selection: true,
        organizations: [{ tenant_id: 't-1', name: 'Alpha', slug: 'alpha' }, { tenant_id: 't-2', name: 'Beta', slug: 'beta' }],
      })
      .mockImplementationOnce((_url, _body, opts) =>
        new Promise((_res, rej) => {
          (opts as { signal?: AbortSignal })?.signal?.addEventListener('abort', () =>
            rej(new DOMException('Aborted', 'AbortError')),
          );
        }),
      );

    render(<LoginPage />);
    fireEvent.change(screen.getByLabelText('auth.email'), { target: { value: 'm@e.com' } });
    fireEvent.change(screen.getByLabelText('auth.password'), { target: { value: 'password123' } });
    await act(async () => { fireEvent.submit(screen.getByRole('button', { name: 'auth.signIn' }).closest('form')!); });
    await screen.findByTestId('org-picker');

    // Start the pick (hangs), advance past the still-trying threshold, cancel.
    await act(async () => { fireEvent.click(screen.getByRole('button', { name: /Beta/ })); });
    await act(async () => { vi.advanceTimersByTime(8000); });
    await act(async () => { fireEvent.click(screen.getByTestId('org-pick-cancel')); });

    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    expect(useAuthStore.getState().token).toBeNull();
    vi.useRealTimers();
  });
});
```

Note: the pick-cancel control appears after `STILL_TRYING_THRESHOLD_MS` (8s) while a
pick is pending — the same affordance pattern as the login Cancel button. The store's
`isLoading`-driven `showStillTrying` effect drives its visibility.

(Import `useConnectivityStore`, `getStoredValue`, `apiGet`, and `mockUser`/`mockCompanies` fixtures into the test file, matching the existing import block and adding fixtures like those in `authStore.test.ts`.)

- [ ] **Step 2: Run to verify they fail**

Run: `cd apps/pos && pnpm vitest run src/pages/__tests__/LoginPage.test.tsx -t "business picker"`
Expected: FAIL (no `org-picker` testid; login outcome not consumed).

- [ ] **Step 3: Implement the picker in `LoginPage.tsx`**

Add state + a dedicated abort controller for the pick, consume the `LoginOutcome`, and render the picker. Concrete edits:

Add imports/state near the top of the component (after line 25):

```tsx
  const [organizations, setOrganizations] = useState<Organization[] | null>(null);
  const [pendingTenantId, setPendingTenantId] = useState<string | null>(null);
  // Dedicated controller for picker selections — the submit-path controller
  // has already settled by the time the picker renders (Codex r2 F-1).
  const pickAbortRef = useRef<AbortController | null>(null);
```

Import the type: change line 3 to also import `Organization`:

```tsx
import { useAuthStore, type Company, type Organization } from '@/stores/authStore';
```

Replace the `try { await login(...) ... }` block inside `handleLogin` (lines 56-63) so it consumes the outcome:

```tsx
      const outcome = await login(email, password, { signal: controller.signal });

      if (outcome.status === 'requires_org_selection') {
        setOrganizations(outcome.organizations);
        return;
      }

      // Authenticated — check if company selection is needed.
      const state = useAuthStore.getState();
      if (state.companies.length > 1 && !state.companyId) {
        setShowCompanySelect(true);
      }
```

Add a handler for picking an org (after `handleCompanySelect`, ~line 87):

```tsx
  async function handleOrgSelect(org: Organization) {
    if (pendingTenantId) return; // ignore repeat / concurrent clicks
    setPendingTenantId(org.tenant_id);
    setError(null);
    const controller = new AbortController();
    pickAbortRef.current = controller;
    try {
      const outcome = await login(email, password, {
        signal: controller.signal,
        tenantId: org.tenant_id,
      });
      if (outcome.status === 'authenticated') {
        setOrganizations(null);
        const state = useAuthStore.getState();
        if (state.companies.length > 1 && !state.companyId) {
          setShowCompanySelect(true);
        }
      }
    } catch (err) {
      if (!controller.signal.aborted) setError(getErrorMessage(err));
    } finally {
      if (pickAbortRef.current === controller) pickAbortRef.current = null;
      setPendingTenantId(null);
    }
  }

  function handleCancelPick() {
    pickAbortRef.current?.abort();
  }
```

Render the picker (add before the `if (showCompanySelect ...)` block, ~line 89):

```tsx
  if (organizations) {
    return (
      <div className="flex h-screen items-center justify-center bg-gray-50">
        <div className="w-full max-w-md rounded-lg bg-white p-8 shadow-md">
          <h2 className="mb-6 text-center text-xl font-bold text-gray-900">
            {t('auth.selectOrganization')}
          </h2>
          <div className="space-y-3" data-testid="org-picker">
            {organizations.map((org) => (
              <button
                key={org.tenant_id}
                type="button"
                disabled={pendingTenantId !== null}
                onClick={() => void handleOrgSelect(org)}
                className="w-full rounded-lg border border-gray-200 p-4 text-left transition hover:border-blue-300 hover:bg-blue-50 disabled:opacity-50"
              >
                <div className="font-medium text-gray-900">{org.name}</div>
                <div className="text-sm text-gray-500">{org.slug}</div>
              </button>
            ))}
          </div>
          {showStillTrying && pendingTenantId && (
            <button
              type="button"
              data-testid="org-pick-cancel"
              onClick={handleCancelPick}
              className="mt-4 w-full rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
              {t('auth.cancel')}
            </button>
          )}
        </div>
      </div>
    );
  }
```

Also, ensure the form's error path surfaces the **server** message: confirm `handleLogin`'s `catch` already calls `setError(getErrorMessage(err))` (it does, line 70) — no change needed; this is how inactive (`account_not_active`) and `ORGANIZATION_UNAVAILABLE` messages surface distinctly.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/pos && pnpm vitest run src/pages/__tests__/LoginPage.test.tsx`
Expected: PASS (new + pre-existing).

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/pages/LoginPage.tsx apps/pos/src/pages/__tests__/LoginPage.test.tsx
git commit -m "feat(pos-login): business picker UI with concurrency guard + abort"
```

---

## Task 6: i18n keys

**Files:**
- Modify: `apps/pos/src/locales/en/pos.json`, `apps/pos/src/locales/fr/pos.json` (the `auth` object)

- [ ] **Step 1: Add the English key**

In `apps/pos/src/locales/en/pos.json`, inside `auth`, add after `selectCompany`:

```json
    "selectOrganization": "Select Your Business",
```

- [ ] **Step 2: Add the French key**

In `apps/pos/src/locales/fr/pos.json`, inside `auth`, add the matching key:

```json
    "selectOrganization": "Sélectionnez votre établissement",
```

- [ ] **Step 3: Verify both JSON files parse**

Run: `cd apps/pos && node -e "require('./src/locales/en/pos.json');require('./src/locales/fr/pos.json');console.log('ok')"`
Expected: `ok`.

- [ ] **Step 4: Commit**

```bash
git add apps/pos/src/locales/en/pos.json apps/pos/src/locales/fr/pos.json
git commit -m "feat(pos-login): i18n keys for business picker"
```

---

## Task 7: Full-suite green + typecheck + lint gate

**Files:** none (verification only)

- [ ] **Step 1: Typecheck**

Run: `cd apps/pos && pnpm tsc --noEmit`
Expected: no errors. Fix any `Organization`/`LoginOutcome` import or signature mismatches.

- [ ] **Step 2: Lint**

Run: `cd apps/pos && pnpm lint`
Expected: no new errors (no hardcoded strings, no `any`).

- [ ] **Step 3: Run the POS test suite**

Run: `cd apps/pos && pnpm vitest run src/stores/__tests__/authStore.test.ts src/pages/__tests__/LoginPage.test.tsx`
Expected: all PASS.

- [ ] **Step 4: Run the broader POS suite to catch regressions**

Run: `cd apps/pos && pnpm vitest run`
Expected: no NEW failures vs. the pre-change baseline. (Note: per memory, some unrelated suites may have pre-existing failures — compare against the baseline captured before Task 1, do not fix unrelated reds.)

- [ ] **Step 5: Final commit (if any fixups were needed)**

```bash
git add -A
git commit -m "chore(pos-login): typecheck + lint fixups for multi-tenant login"
```

---

## Self-review notes (author)

- **Spec coverage:** email-first POST (T2), auto-select + self-heal (T3), best-effort non-authoritative `LOGIN_TENANT_ID` (T2 helper + T3), one-shot anti-loop + `UnexpectedLoginResponseError` (T2/T3), abort threaded through all POSTs + companies (T2 helper reuses `opts.signal`; manual-pick fresh controller T5), picker concurrency guard (T4 store guard + T5 disabled buttons), name+slug picker (T5), distinct error surfacing via `getErrorMessage` (T5 step 3), i18n (T6). Acceptance criteria 1-7 all mapped.
- **Deferred (correctly out of scope):** `logout()` does not clear `LOGIN_TENANT_ID` (Sub-Spec B); no audit-event emission (Sub-Spec C). Interim reset = clear app data.
- **Abort test for manual pick** (Codex r2 F-1): explicit test in T5 ("aborting a manual pick commits no auth state") using a dedicated `pickAbortRef` controller, satisfying the spec's testing requirement.
- **Type consistency:** `Organization { tenant_id, name, slug }`, `LoginOutcome`, `LoginApiResponse`, `completeAuthentication`, `isOrgSelection`, `UnexpectedLoginResponseError` named identically across tasks.
