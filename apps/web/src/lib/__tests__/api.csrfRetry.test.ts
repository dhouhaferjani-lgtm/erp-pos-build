import { describe, it, expect, vi, beforeEach, afterEach, type MockInstance } from 'vitest'
import axios, { AxiosHeaders, type AxiosAdapter, type AxiosResponse } from 'axios'
import { api } from '../api'

/**
 * Cross-lane finding (FE gate MINOR-2 + the imports lane's gate, 2026-08-06).
 *
 * The response interceptor's whole error body was gated on `isApiError(error)`,
 * which requires a `{error: {...}}` envelope. Laravel's CSRF failure is a bare
 * `{"message":"CSRF token mismatch."}` (419 is an HttpException, deliberately
 * left untyped by the API's catch-all renderer), so `isApiError` returned false
 * and the auto-refresh-and-retry branch was UNREACHABLE — every CSRF expiry
 * surfaced to the user as a hard failure.
 *
 * The 419 branch must therefore key on STATUS alone.
 */
describe('api interceptor — 419 CSRF auto-retry', () => {
  const originalAdapter = api.defaults.adapter
  let csrfCookieSpy: MockInstance<typeof axios.get>

  beforeEach(() => {
    vi.restoreAllMocks()
    // ensureCsrfCookie() uses the bare axios instance, not `api`.
    csrfCookieSpy = vi.spyOn(axios, 'get').mockResolvedValue({ data: '' } as AxiosResponse)
  })

  afterEach(() => {
    if (originalAdapter === undefined) {
      delete api.defaults.adapter
    } else {
      api.defaults.adapter = originalAdapter
    }
  })

  function makeResponse(status: number, data: unknown, config: unknown): AxiosResponse {
    return {
      status,
      statusText: '',
      data,
      headers: {},
      config,
    } as AxiosResponse
  }

  it('retries once after refreshing the CSRF cookie when the body has no error envelope', async () => {
    let calls = 0

    const adapter: AxiosAdapter = async (config) => {
      calls += 1
      if (calls === 1) {
        // Laravel's real CSRF response shape — note: NO `error` envelope.
        return Promise.reject(
          new axios.AxiosError(
            'Request failed with status code 419',
            '419',
            config,
            {},
            makeResponse(419, { message: 'CSRF token mismatch.' }, config),
          ),
        )
      }
      return Promise.resolve(makeResponse(200, { data: { ok: true } }, config))
    }

    api.defaults.adapter = adapter

    const result = await api.get('/probe')

    expect(csrfCookieSpy).toHaveBeenCalledWith('/sanctum/csrf-cookie', expect.anything())
    expect(calls).toBe(2)
    expect(result.status).toBe(200)
  })

  it('does not retry forever when the replayed request also 419s', async () => {
    let calls = 0

    const adapter: AxiosAdapter = async (config) => {
      calls += 1
      return Promise.reject(
        new axios.AxiosError(
          'Request failed with status code 419',
          '419',
          config,
          {},
          makeResponse(419, { message: 'CSRF token mismatch.' }, config),
        ),
      )
    }

    api.defaults.adapter = adapter

    await expect(api.get('/probe')).rejects.toThrow()

    // One original attempt + exactly one replay.
    expect(calls).toBe(2)
  })

  it('leaves non-419 responses to the existing handling', async () => {
    let calls = 0

    const adapter: AxiosAdapter = async (config) => {
      calls += 1
      return Promise.reject(
        new axios.AxiosError(
          'Request failed with status code 500',
          '500',
          config,
          {},
          makeResponse(500, { message: 'Server Error' }, config),
        ),
      )
    }

    api.defaults.adapter = adapter

    await expect(api.get('/probe')).rejects.toThrow()

    expect(calls).toBe(1)
    expect(csrfCookieSpy).not.toHaveBeenCalled()
  })

  /**
   * FE gate round 2, MINOR-R2-1 (2026-08-06) — `return await client.request(config)`
   * used to sit INSIDE the `try`, so a failed REPLAY was caught by
   * `catch (csrfError)`, mislabelled "Failed to refresh CSRF token", and the
   * caller received the ORIGINAL 419 instead of the replay's real error. A
   * replay that 500s must propagate as a 500, not a swallowed CSRF failure.
   */
  it('propagates the replay error when the retried request fails for a reason other than CSRF', async () => {
    let calls = 0

    const adapter: AxiosAdapter = async (config) => {
      calls += 1
      if (calls === 1) {
        return Promise.reject(
          new axios.AxiosError(
            'Request failed with status code 419',
            '419',
            config,
            {},
            makeResponse(419, { message: 'CSRF token mismatch.' }, config),
          ),
        )
      }
      return Promise.reject(
        new axios.AxiosError(
          'Request failed with status code 500',
          '500',
          config,
          {},
          makeResponse(500, { message: 'Server Error' }, config),
        ),
      )
    }

    api.defaults.adapter = adapter

    await expect(api.get('/probe')).rejects.toMatchObject({
      response: expect.objectContaining({ status: 500 }) as unknown,
    })

    expect(calls).toBe(2)
  })

  it('keeps the AxiosHeaders instance usable on the replayed config', async () => {
    let calls = 0
    let replayHeaders: unknown = null

    const adapter: AxiosAdapter = async (config) => {
      calls += 1
      if (calls === 1) {
        return Promise.reject(
          new axios.AxiosError(
            'Request failed with status code 419',
            '419',
            config,
            {},
            makeResponse(419, { message: 'CSRF token mismatch.' }, config),
          ),
        )
      }
      replayHeaders = config.headers
      return Promise.resolve(makeResponse(200, { data: { ok: true } }, config))
    }

    api.defaults.adapter = adapter

    await api.get('/probe')

    expect(replayHeaders).toBeInstanceOf(AxiosHeaders)
  })
})
