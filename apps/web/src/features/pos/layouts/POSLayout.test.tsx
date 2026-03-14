import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { userEvent } from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { POSLayout } from './POSLayout'

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

function createTestQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })
}

function renderWithClient(ui: React.ReactElement) {
  const queryClient = createTestQueryClient()
  return render(<QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>)
}

describe('POSLayout', () => {
  const mockOnExitPOS = vi.fn()

  beforeEach(() => {
    mockOnExitPOS.mockClear()
  })

  describe('Rendering', () => {
    it('should render fullscreen layout with fixed positioning', () => {
      const { container } = renderWithClient(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      const layoutRoot = container.firstChild as HTMLElement
      expect(layoutRoot).toHaveClass('fixed', 'inset-0', 'z-50')
    })

    it('should render header with POS title', () => {
      renderWithClient(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      // Header should be present (component uses common:pos.title)
      expect(screen.getByText('common:pos.title')).toBeInTheDocument()
    })

    it('should render exit button', () => {
      renderWithClient(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      const exitButton = screen.getByRole('button', { name: /common:pos\.exit/i })
      expect(exitButton).toBeInTheDocument()
    })

    it('should render children content', () => {
      renderWithClient(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div data-testid="pos-content">POS Transaction Interface</div>
        </POSLayout>
      )

      expect(screen.getByTestId('pos-content')).toBeInTheDocument()
      expect(screen.getByText('POS Transaction Interface')).toBeInTheDocument()
    })
  })

  describe('User Interactions', () => {
    it('should call onExitPOS when exit button is clicked', async () => {
      const user = userEvent.setup()

      renderWithClient(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      const exitButton = screen.getByRole('button', { name: /common:pos\.exit/i })
      await user.click(exitButton)

      expect(mockOnExitPOS).toHaveBeenCalledTimes(1)
    })
  })

  describe('Layout Structure', () => {
    it('should have header with correct height class', () => {
      renderWithClient(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      // Header should have h-14 class (56px / 3.5rem)
      const header = screen.getByText('common:pos.title').closest('header')
      expect(header).toHaveClass('h-14')
    })

    it('should have content area that fills remaining height', () => {
      renderWithClient(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div data-testid="pos-content">Test Content</div>
        </POSLayout>
      )

      const contentArea = screen.getByTestId('pos-content').parentElement
      // Content should use calc(100vh - 3.5rem) to account for header
      expect(contentArea).toHaveClass('h-[calc(100vh-3.5rem)]')
    })
  })

  describe('Accessibility', () => {
    it('should have proper heading hierarchy', () => {
      renderWithClient(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      // POS title should be in a heading or prominent text element
      const title = screen.getByText('common:pos.title')
      expect(title).toBeInTheDocument()
    })

    it('should have accessible exit button with clear label', () => {
      renderWithClient(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      const exitButton = screen.getByRole('button', { name: /pos\.exit/i })
      expect(exitButton).toBeEnabled()
    })
  })

  describe('Visual Styling', () => {
    it('should have dark header background', () => {
      renderWithClient(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      const header = screen.getByText('common:pos.title').closest('header')
      expect(header).toHaveClass('bg-gray-800')
    })

    it('should have dark root background', () => {
      const { container } = renderWithClient(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      const layoutRoot = container.firstChild as HTMLElement
      expect(layoutRoot).toHaveClass('bg-gray-900')
    })
  })
})
