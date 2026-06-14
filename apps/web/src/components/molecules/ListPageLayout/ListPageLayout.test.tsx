import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ListPageLayout } from './ListPageLayout'

describe('ListPageLayout', () => {
  it('renders the title via PageHeader as an h1', () => {
    render(
      <ListPageLayout title="Products">
        <table />
      </ListPageLayout>,
    )

    const heading = screen.getByRole('heading', { level: 1, name: 'Products' })
    expect(heading).toBeInTheDocument()
    expect(heading.tagName).toBe('H1')
  })

  it('renders the header actions slot', () => {
    render(
      <ListPageLayout
        title="Products"
        actions={<button type="button">Add product</button>}
      >
        <table />
      </ListPageLayout>,
    )

    expect(
      screen.getByRole('button', { name: 'Add product' }),
    ).toBeInTheDocument()
  })

  it('renders the filters slot when provided', () => {
    render(
      <ListPageLayout
        title="Products"
        filters={<input aria-label="Search products" />}
      >
        <table />
      </ListPageLayout>,
    )

    expect(screen.getByLabelText('Search products')).toBeInTheDocument()
  })

  it('does not render the filters region when filters are absent', () => {
    render(
      <ListPageLayout title="Products">
        <table />
      </ListPageLayout>,
    )

    expect(
      screen.queryByLabelText('Search products'),
    ).not.toBeInTheDocument()
  })

  it('renders the children (table body)', () => {
    render(
      <ListPageLayout title="Products">
        <div>The product table</div>
      </ListPageLayout>,
    )

    expect(screen.getByText('The product table')).toBeInTheDocument()
  })

  it('renders the pagination slot when provided', () => {
    render(
      <ListPageLayout
        title="Products"
        pagination={<nav aria-label="Pagination">Page 1 of 5</nav>}
      >
        <table />
      </ListPageLayout>,
    )

    expect(screen.getByText('Page 1 of 5')).toBeInTheDocument()
  })

  it('does not render the pagination region when pagination is absent', () => {
    render(
      <ListPageLayout title="Products">
        <table />
      </ListPageLayout>,
    )

    expect(
      screen.queryByRole('navigation', { name: 'Pagination' }),
    ).not.toBeInTheDocument()
  })
})
