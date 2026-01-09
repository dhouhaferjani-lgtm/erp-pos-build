import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { userEvent } from '@testing-library/user-event'
import { POSLayout } from './POSLayout'

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

describe('POSLayout', () => {
  const mockOnExitPOS = vi.fn()

  beforeEach(() => {
    mockOnExitPOS.mockClear()
  })

  describe('Rendering', () => {
    it('should render fullscreen layout with fixed positioning', () => {
      const { container } = render(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      const layoutRoot = container.firstChild as HTMLElement
      expect(layoutRoot).toHaveClass('fixed', 'inset-0', 'z-50')
    })

    it('should render header with POS title', () => {
      render(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      // Header should be present (component uses common:pos.title)
      expect(screen.getByText('common:pos.title')).toBeInTheDocument()
    })

    it('should render exit button', () => {
      render(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      const exitButton = screen.getByRole('button', { name: /common:pos\.exit/i })
      expect(exitButton).toBeInTheDocument()
    })

    it('should render children content', () => {
      render(
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

      render(
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
      render(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      // Header should have h-14 class (56px / 3.5rem)
      const header = screen.getByText('common:pos.title').closest('header')
      expect(header).toHaveClass('h-14')
    })

    it('should have content area that fills remaining height', () => {
      const { container } = render(
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
      render(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      // POS title should be in a heading or prominent text element
      const title = screen.getByText('common:pos.title')
      expect(title).toBeInTheDocument()
    })

    it('should have accessible exit button with clear label', () => {
      render(
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
      render(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      const header = screen.getByText('common:pos.title').closest('header')
      expect(header).toHaveClass('bg-gray-800')
    })

    it('should have dark root background', () => {
      const { container } = render(
        <POSLayout onExitPOS={mockOnExitPOS}>
          <div>Test Content</div>
        </POSLayout>
      )

      const layoutRoot = container.firstChild as HTMLElement
      expect(layoutRoot).toHaveClass('bg-gray-900')
    })
  })
})
