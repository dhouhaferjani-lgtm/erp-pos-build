import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { CatalogBanner } from '../components/CatalogBanner'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, string>) => {
      const translations: Record<string, string> = {
        'barcodeLookup.catalogFound': 'Data from Syneriva Catalog',
        'barcodeLookup.confidence': `Confidence: ${opts?.['tier'] ?? ''}`,
        'barcodeLookup.notInCatalog': 'Not in catalog',
        'barcodeLookup.catalogUnavailable': 'Catalog unavailable',
      }
      return translations[key] ?? key
    },
  }),
}))

describe('CatalogBanner', () => {
  it('renders nothing for idle state', () => {
    const { container } = render(<CatalogBanner state="idle" />)
    expect(container.firstChild).toBeNull()
  })

  it('renders nothing for searching state', () => {
    const { container } = render(<CatalogBanner state="searching" />)
    expect(container.firstChild).toBeNull()
  })

  it('renders success banner for found state', () => {
    render(<CatalogBanner state="found" confidenceTier="high" />)
    expect(screen.getByText('Data from Syneriva Catalog')).toBeInTheDocument()
    expect(screen.getByText('Confidence: high')).toBeInTheDocument()
  })

  it('renders warning banner for not_found state', () => {
    render(<CatalogBanner state="not_found" />)
    expect(screen.getByText('Not in catalog')).toBeInTheDocument()
  })

  it('renders error banner for error state', () => {
    render(<CatalogBanner state="error" />)
    expect(screen.getByText('Catalog unavailable')).toBeInTheDocument()
  })
})
