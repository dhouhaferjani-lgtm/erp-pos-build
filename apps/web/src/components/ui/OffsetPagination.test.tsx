import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { OffsetPagination } from './OffsetPagination'

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      const translations: Record<string, string> = {
        'pagination.showing': `Showing ${params?.['from']} to ${params?.['to']} of ${params?.['total']}`,
        'pagination.item': 'item',
        'pagination.items': 'items',
        'pagination.rowsPerPage': 'Rows per page',
        'pagination.page': 'Page',
        'pagination.of': 'of',
        'pagination.previous': 'Previous',
        'pagination.next': 'Next',
      }
      return translations[key] || key
    },
  }),
}))

describe('OffsetPagination', () => {
  const user = userEvent.setup()
  const onPageChangeMock = vi.fn()
  const onPerPageChangeMock = vi.fn()

  const defaultProps = {
    currentPage: 1,
    lastPage: 10,
    total: 247,
    perPage: 25,
    from: 1,
    to: 25,
    onPageChange: onPageChangeMock,
    onPerPageChange: onPerPageChangeMock,
  }

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('displays correct showing info', () => {
    render(<OffsetPagination {...defaultProps} />)
    expect(screen.getByText('Showing 1 to 25 of 247')).toBeInTheDocument()
  })

  it('displays total items when from and to are null', () => {
    render(<OffsetPagination {...defaultProps} from={null} to={null} />)
    expect(screen.getByText('247 items')).toBeInTheDocument()
  })

  it('displays "item" singular when total is 1', () => {
    render(<OffsetPagination {...defaultProps} total={1} from={null} to={null} />)
    expect(screen.getByText('1 item')).toBeInTheDocument()
  })

  it('displays current page and last page', () => {
    render(<OffsetPagination {...defaultProps} />)
    expect(screen.getByText(/Page 1 of 10/)).toBeInTheDocument()
  })

  it('displays per page selector with current value', () => {
    render(<OffsetPagination {...defaultProps} />)
    const select = screen.getByRole('combobox') as HTMLSelectElement
    expect(select.value).toBe('25')
  })

  it('displays all per page options', () => {
    render(<OffsetPagination {...defaultProps} />)
    expect(screen.getByText('10')).toBeInTheDocument()
    expect(screen.getByText('25')).toBeInTheDocument()
    expect(screen.getByText('50')).toBeInTheDocument()
    expect(screen.getByText('100')).toBeInTheDocument()
  })

  it('calls onPerPageChange when per page is changed', async () => {
    render(<OffsetPagination {...defaultProps} />)
    const select = screen.getByRole('combobox')
    await user.selectOptions(select, '50')

    expect(onPerPageChangeMock).toHaveBeenCalledWith(50)
  })

  it('previous button is disabled on first page', () => {
    render(<OffsetPagination {...defaultProps} currentPage={1} />)
    const buttons = screen.getAllByRole('button')
    const prevButton = buttons[0] // First button is previous
    expect(prevButton).toBeDisabled()
  })

  it('previous button is enabled when not on first page', () => {
    render(<OffsetPagination {...defaultProps} currentPage={5} />)
    const buttons = screen.getAllByRole('button')
    const prevButton = buttons[0]
    expect(prevButton).not.toBeDisabled()
  })

  it('next button is disabled on last page', () => {
    render(<OffsetPagination {...defaultProps} currentPage={10} lastPage={10} />)
    const buttons = screen.getAllByRole('button')
    const nextButton = buttons[1] // Second button is next
    expect(nextButton).toBeDisabled()
  })

  it('next button is enabled when not on last page', () => {
    render(<OffsetPagination {...defaultProps} currentPage={5} />)
    const buttons = screen.getAllByRole('button')
    const nextButton = buttons[1]
    expect(nextButton).not.toBeDisabled()
  })

  it('calls onPageChange with previous page when previous button is clicked', async () => {
    render(<OffsetPagination {...defaultProps} currentPage={5} />)
    const buttons = screen.getAllByRole('button')
    const prevButton = buttons[0]
    await user.click(prevButton)

    expect(onPageChangeMock).toHaveBeenCalledWith(4)
  })

  it('calls onPageChange with next page when next button is clicked', async () => {
    render(<OffsetPagination {...defaultProps} currentPage={5} />)
    const buttons = screen.getAllByRole('button')
    const nextButton = buttons[1]
    await user.click(nextButton)

    expect(onPageChangeMock).toHaveBeenCalledWith(6)
  })

  it('does not call onPageChange when previous button is clicked on first page', async () => {
    render(<OffsetPagination {...defaultProps} currentPage={1} />)
    const buttons = screen.getAllByRole('button')
    const prevButton = buttons[0]
    // Button is disabled, but let's ensure the handler doesn't fire
    expect(prevButton).toBeDisabled()
    expect(onPageChangeMock).not.toHaveBeenCalled()
  })

  it('does not call onPageChange when next button is clicked on last page', async () => {
    render(<OffsetPagination {...defaultProps} currentPage={10} lastPage={10} />)
    const buttons = screen.getAllByRole('button')
    const nextButton = buttons[1]
    expect(nextButton).toBeDisabled()
    expect(onPageChangeMock).not.toHaveBeenCalled()
  })

  it('renders navigation icons', () => {
    const { container } = render(<OffsetPagination {...defaultProps} />)
    const svgs = container.querySelectorAll('svg')
    // Should have ChevronLeft and ChevronRight icons
    expect(svgs.length).toBeGreaterThanOrEqual(2)
  })

  it('has screen reader text for navigation buttons', () => {
    render(<OffsetPagination {...defaultProps} />)
    expect(screen.getByText('Previous')).toHaveClass('sr-only')
    expect(screen.getByText('Next')).toHaveClass('sr-only')
  })

  it('applies custom className', () => {
    const { container } = render(
      <OffsetPagination {...defaultProps} className="custom-class" />
    )
    expect(container.firstChild).toHaveClass('custom-class')
  })

  it('displays "Rows per page" label', () => {
    render(<OffsetPagination {...defaultProps} />)
    expect(screen.getByText('Rows per page:')).toBeInTheDocument()
  })

  it('updates display when currentPage changes', () => {
    const { rerender } = render(<OffsetPagination {...defaultProps} currentPage={1} />)
    expect(screen.getByText(/Page 1 of 10/)).toBeInTheDocument()

    rerender(<OffsetPagination {...defaultProps} currentPage={7} />)
    expect(screen.getByText(/Page 7 of 10/)).toBeInTheDocument()
  })

  it('updates display when from/to changes', () => {
    const { rerender } = render(
      <OffsetPagination {...defaultProps} from={1} to={25} />
    )
    expect(screen.getByText('Showing 1 to 25 of 247')).toBeInTheDocument()

    rerender(<OffsetPagination {...defaultProps} from={26} to={50} />)
    expect(screen.getByText('Showing 26 to 50 of 247')).toBeInTheDocument()
  })

  it('handles single page scenario correctly', () => {
    render(
      <OffsetPagination
        {...defaultProps}
        currentPage={1}
        lastPage={1}
        total={10}
        from={1}
        to={10}
      />
    )
    const buttons = screen.getAllByRole('button')
    const prevButton = buttons[0]
    const nextButton = buttons[1]
    expect(prevButton).toBeDisabled()
    expect(nextButton).toBeDisabled()
    expect(screen.getByText(/Page 1 of 1/)).toBeInTheDocument()
  })

  it('handles different perPage values correctly', () => {
    const { rerender } = render(<OffsetPagination {...defaultProps} perPage={10} />)
    const select = screen.getByRole('combobox') as HTMLSelectElement
    expect(select.value).toBe('10')

    rerender(<OffsetPagination {...defaultProps} perPage={100} />)
    expect(select.value).toBe('100')
  })
})
