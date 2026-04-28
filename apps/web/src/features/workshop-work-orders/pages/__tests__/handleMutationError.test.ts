import { describe, it, expect, vi, beforeEach } from 'vitest'
import { AxiosError, AxiosHeaders } from 'axios'
import { toast } from 'sonner'
import { createMutationErrorHandler } from '../handleMutationError'

vi.mock('sonner', () => ({
  toast: {
    error: vi.fn(),
  },
}))

function makeAxiosError(status: number, code: string): AxiosError {
  const err = new AxiosError(
    'Request failed',
    String(status),
    undefined,
    undefined,
    {
      status,
      statusText: '',
      headers: {},
      config: { headers: new AxiosHeaders() },
      data: { error: { code, message: 'backend message' } },
    },
  )
  return err
}

describe('createMutationErrorHandler', () => {
  beforeEach(() => {
    vi.mocked(toast.error).mockClear()
  })

  it('toasts the work_order_no_lines key for a 422 WORK_ORDER_NO_LINES error', () => {
    const t = vi.fn((key: string) => `tr:${key}`)
    const handle = createMutationErrorHandler(t)

    handle(makeAxiosError(422, 'WORK_ORDER_NO_LINES'))

    expect(t).toHaveBeenCalledWith('transitions.errors.work_order_no_lines')
    expect(toast.error).toHaveBeenCalledTimes(1)
    expect(toast.error).toHaveBeenCalledWith('tr:transitions.errors.work_order_no_lines')
  })

  it('toasts the invalid_transition key for a 422 INVALID_TRANSITION error', () => {
    const t = vi.fn((key: string) => `tr:${key}`)
    const handle = createMutationErrorHandler(t)

    handle(makeAxiosError(422, 'INVALID_TRANSITION'))

    expect(t).toHaveBeenCalledWith('transitions.errors.invalid_transition')
    expect(toast.error).toHaveBeenCalledWith('tr:transitions.errors.invalid_transition')
  })

  it('toasts the work_order_stale key for a 409 WORK_ORDER_STALE error', () => {
    const t = vi.fn((key: string) => `tr:${key}`)
    const handle = createMutationErrorHandler(t)

    handle(makeAxiosError(409, 'WORK_ORDER_STALE'))

    expect(t).toHaveBeenCalledWith('transitions.errors.work_order_stale')
    expect(toast.error).toHaveBeenCalledWith('tr:transitions.errors.work_order_stale')
  })

  it('falls back to the generic key for unrecognised AxiosErrors', () => {
    const t = vi.fn((key: string) => `tr:${key}`)
    const handle = createMutationErrorHandler(t)
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined)

    handle(makeAxiosError(500, 'INTERNAL'))

    expect(t).toHaveBeenCalledWith('transitions.errors.generic')
    expect(toast.error).toHaveBeenCalledWith('tr:transitions.errors.generic')
    expect(consoleSpy).toHaveBeenCalledTimes(1)

    consoleSpy.mockRestore()
  })

  it('falls back to the generic key for non-Axios errors', () => {
    const t = vi.fn((key: string) => `tr:${key}`)
    const handle = createMutationErrorHandler(t)
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined)

    handle(new Error('boom'))

    expect(t).toHaveBeenCalledWith('transitions.errors.generic')
    expect(toast.error).toHaveBeenCalledWith('tr:transitions.errors.generic')
    expect(consoleSpy).toHaveBeenCalledTimes(1)

    consoleSpy.mockRestore()
  })
})
