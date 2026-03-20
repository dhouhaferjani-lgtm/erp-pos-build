import { describe, it, expect } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useRegisterForm } from '../hooks/useRegisterForm'

describe('useRegisterForm', () => {
  it('initializes at step 1 with empty form data', () => {
    const { result } = renderHook(() => useRegisterForm())
    expect(result.current.currentStep).toBe(1)
    expect(result.current.formData.name).toBe('')
    expect(result.current.formData.email).toBe('')
  })

  it('validates step 1 — requires name, email, password', () => {
    const { result } = renderHook(() => useRegisterForm())
    const errors = result.current.validateStep(1)
    expect(errors.name).toBeTruthy()
    expect(errors.email).toBeTruthy()
    expect(errors.password).toBeTruthy()
  })

  it('advances to next step when validation passes', () => {
    const { result } = renderHook(() => useRegisterForm())

    act(() => {
      result.current.updateField('name', 'John Doe')
      result.current.updateField('email', 'john@example.com')
      result.current.updateField('password', 'strongpassword123')
    })

    act(() => { result.current.goToNext() })

    expect(result.current.currentStep).toBe(2)
  })

  it('does not advance when validation fails', () => {
    const { result } = renderHook(() => useRegisterForm())

    act(() => { result.current.goToNext() })

    expect(result.current.currentStep).toBe(1)
    expect(Object.keys(result.current.errors).length).toBeGreaterThan(0)
  })

  it('goes back preserving data', () => {
    const { result } = renderHook(() => useRegisterForm())

    act(() => {
      result.current.updateField('name', 'John Doe')
      result.current.updateField('email', 'john@example.com')
      result.current.updateField('password', 'strongpassword123')
    })
    act(() => { result.current.goToNext() })
    act(() => { result.current.goBack() })

    expect(result.current.currentStep).toBe(1)
    expect(result.current.formData.name).toBe('John Doe')
  })

  it('builds the correct submission payload', () => {
    const { result } = renderHook(() => useRegisterForm())

    act(() => {
      result.current.updateField('name', 'John Doe')
      result.current.updateField('email', 'john@example.com')
      result.current.updateField('password', 'strongpassword123')
      result.current.updateField('countryCode', 'FR')
      result.current.updateField('vertical', 'retail')
      result.current.updateField('companyName', 'Test Corp')
      result.current.updateField('phoneLocal', '612345678')
    })

    const payload = result.current.buildPayload()

    expect(payload.name).toBe('John Doe')
    expect(payload.password_confirmation).toBe('strongpassword123')
    expect(payload.phone).toBe('+33612345678')
    expect(payload.currency).toBe('EUR')
    expect(payload.locale).toBe('fr')
    expect(payload.timezone).toBe('Europe/Paris')
    expect(payload.platform).toBe('web')
  })
})
