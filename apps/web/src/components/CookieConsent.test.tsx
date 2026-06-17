import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { CookieConsent } from './CookieConsent'

const COOKIE_CONSENT_KEY = 'autoerp-cookie-consent'

/**
 * jsdom reports offsetHeight as 0; override it so the component can measure a
 * realistic bar height and we can assert the reserved space matches.
 */
const BAR_HEIGHT = 80
let offsetHeightSpy: PropertyDescriptor | undefined

describe('CookieConsent', () => {
  beforeEach(() => {
    localStorage.removeItem(COOKIE_CONSENT_KEY)
    document.body.style.paddingBottom = ''
    offsetHeightSpy = Object.getOwnPropertyDescriptor(
      HTMLElement.prototype,
      'offsetHeight'
    )
    Object.defineProperty(HTMLElement.prototype, 'offsetHeight', {
      configurable: true,
      get() {
        return BAR_HEIGHT
      },
    })
  })

  afterEach(() => {
    if (offsetHeightSpy) {
      Object.defineProperty(HTMLElement.prototype, 'offsetHeight', offsetHeightSpy)
    }
    document.body.style.paddingBottom = ''
  })

  it('reserves body space equal to the bar height while visible', () => {
    renderWithProviders(<CookieConsent />)
    expect(screen.getByText(/essential cookies/i)).toBeInTheDocument()
    expect(document.body.style.paddingBottom).toBe(`${BAR_HEIGHT}px`)
  })

  it('removes the reserved body space after accepting', async () => {
    const user = userEvent.setup()
    renderWithProviders(<CookieConsent />)

    await user.click(screen.getByRole('button', { name: /accept/i }))

    expect(document.body.style.paddingBottom).toBe('')
  })

  it('does not reserve body space when consent was already given', () => {
    localStorage.setItem(COOKIE_CONSENT_KEY, 'accepted')
    renderWithProviders(<CookieConsent />)
    expect(document.body.style.paddingBottom).toBe('')
  })

  it('cleans up reserved body space on unmount', () => {
    const { unmount } = renderWithProviders(<CookieConsent />)
    expect(document.body.style.paddingBottom).toBe(`${BAR_HEIGHT}px`)
    unmount()
    expect(document.body.style.paddingBottom).toBe('')
  })
})
