import { useState } from 'react'
import { getCountryDefaults, getDialCode } from '../config/countryData'

export interface RegisterFormData {
  name: string
  email: string
  password: string
  countryCode: string
  vertical: string
  companyName: string
  phoneLocal: string
  acceptedTerms: boolean
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  company_name: string
  country_code: string
  vertical: string
  phone: string
  currency: string
  locale: string
  timezone: string
  platform: string
}

export type FormErrors = Partial<Record<keyof RegisterFormData, string>>

const INITIAL_FORM_DATA: RegisterFormData = {
  name: '',
  email: '',
  password: '',
  countryCode: '',
  vertical: '',
  companyName: '',
  phoneLocal: '',
  acceptedTerms: false,
}

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export function useRegisterForm() {
  const [currentStep, setCurrentStep] = useState(1)
  const [formData, setFormData] = useState<RegisterFormData>({ ...INITIAL_FORM_DATA })
  const [errors, setErrors] = useState<FormErrors>({})

  function updateField<K extends keyof RegisterFormData>(field: K, value: RegisterFormData[K]): void {
    setFormData(prev => ({ ...prev, [field]: value }))
    setErrors(prev => {
      const next = { ...prev }
      delete next[field]
      return next
    })
  }

  function validateStep(step: number): FormErrors {
    const errs: FormErrors = {}

    if (step === 1) {
      if (!formData.name.trim()) errs.name = 'Name is required'
      if (!formData.email.trim()) {
        errs.email = 'Email is required'
      } else if (!EMAIL_REGEX.test(formData.email)) {
        errs.email = 'Email is invalid'
      }
      if (!formData.password) {
        errs.password = 'Password is required'
      } else if (formData.password.length < 10) {
        errs.password = 'Password must be at least 10 characters'
      } else if (!/[a-z]/.test(formData.password)) {
        errs.password = 'Password must contain a lowercase letter'
      } else if (!/[A-Z]/.test(formData.password)) {
        errs.password = 'Password must contain an uppercase letter'
      } else if (!/[0-9]/.test(formData.password)) {
        errs.password = 'Password must contain a number'
      } else if (!/[^a-zA-Z0-9]/.test(formData.password)) {
        errs.password = 'Password must contain a symbol'
      }
    }

    if (step === 2) {
      if (!formData.countryCode) errs.countryCode = 'Country is required'
      if (!formData.vertical) errs.vertical = 'Vertical is required'
    }

    if (step === 3) {
      if (!formData.companyName.trim() || formData.companyName.trim().length < 2) {
        errs.companyName = 'Company name must be at least 2 characters'
      }
    }

    if (step === 4) {
      if (!formData.acceptedTerms) errs.acceptedTerms = 'You must accept the terms'
    }

    return errs
  }

  function goToNext(): boolean {
    const errs = validateStep(currentStep)
    if (Object.keys(errs).length > 0) {
      setErrors(errs)
      return false
    }
    setErrors({})
    setCurrentStep(prev => prev + 1)
    return true
  }

  function goBack(): void {
    setErrors({})
    setCurrentStep(prev => Math.max(1, prev - 1))
  }

  function goToStep(step: number): void {
    setErrors({})
    setCurrentStep(step)
  }

  function buildPayload(): RegisterPayload {
    const defaults = getCountryDefaults(formData.countryCode)
    const dialCode = getDialCode(formData.countryCode)
    const phone = dialCode ? `${dialCode}${formData.phoneLocal}` : formData.phoneLocal
    // Extract base language code: 'fr-FR' → 'fr', 'en-GB' → 'en'
    const locale = defaults.locale.split('-')[0] ?? defaults.locale

    return {
      name: formData.name,
      email: formData.email,
      password: formData.password,
      password_confirmation: formData.password,
      company_name: formData.companyName,
      country_code: formData.countryCode,
      vertical: formData.vertical,
      phone,
      currency: defaults.currency,
      locale,
      timezone: defaults.timezone,
      platform: 'web',
    }
  }

  return {
    currentStep,
    formData,
    errors,
    updateField,
    validateStep,
    goToNext,
    goBack,
    goToStep,
    buildPayload,
  }
}
