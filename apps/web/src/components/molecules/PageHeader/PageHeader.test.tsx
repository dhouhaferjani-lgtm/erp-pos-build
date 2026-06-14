import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PageHeader } from './PageHeader'

describe('PageHeader', () => {
  it('renders the title as an h1', () => {
    render(<PageHeader title="Products" />)

    const heading = screen.getByRole('heading', { level: 1, name: 'Products' })
    expect(heading).toBeInTheDocument()
    expect(heading.tagName).toBe('H1')
  })

  it('renders the subtitle when provided', () => {
    render(<PageHeader title="Products" subtitle="Manage your catalog" />)

    expect(screen.getByText('Manage your catalog')).toBeInTheDocument()
  })

  it('does not render a subtitle when none is provided', () => {
    render(<PageHeader title="Products" />)

    expect(screen.queryByText('Manage your catalog')).not.toBeInTheDocument()
  })

  it('renders the actions node', () => {
    render(
      <PageHeader
        title="Products"
        actions={<button type="button">New product</button>}
      />,
    )

    expect(
      screen.getByRole('button', { name: 'New product' }),
    ).toBeInTheDocument()
  })

  it('renders the breadcrumb slot when provided', () => {
    render(
      <PageHeader
        title="Products"
        breadcrumb={<nav>Home / Products</nav>}
      />,
    )

    expect(screen.getByText('Home / Products')).toBeInTheDocument()
  })
})
