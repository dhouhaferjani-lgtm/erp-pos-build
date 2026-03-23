import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, it, expect, vi } from 'vitest'
import { Modal, ModalHeader, ModalContent, ModalFooter } from './Modal'

describe('Modal', () => {
  it('renders nothing when isOpen is false', () => {
    render(
      <Modal isOpen={false} onClose={vi.fn()}>
        <div>Content</div>
      </Modal>
    )
    expect(screen.queryByText('Content')).not.toBeInTheDocument()
  })

  it('renders content when isOpen is true', () => {
    render(
      <Modal isOpen={true} onClose={vi.fn()}>
        <div>Modal content</div>
      </Modal>
    )
    expect(screen.getByText('Modal content')).toBeInTheDocument()
  })

  it('renders via portal at document.body level', () => {
    const { container } = render(
      <div data-testid="parent">
        <Modal isOpen={true} onClose={vi.fn()}>
          <div data-testid="modal-child">Inside modal</div>
        </Modal>
      </div>
    )

    // Modal content should NOT be inside the parent container
    const parent = container.querySelector('[data-testid="parent"]')
    expect(parent?.querySelector('[data-testid="modal-child"]')).toBeNull()

    // But should exist in the document (rendered via portal to body)
    expect(screen.getByTestId('modal-child')).toBeInTheDocument()
  })

  it('does not create nested forms when modal with form is inside a parent form', () => {
    render(
      <form data-testid="outer-form">
        <Modal isOpen={true} onClose={vi.fn()}>
          <form data-testid="inner-form">
            <input type="text" />
            <button type="submit">Submit</button>
          </form>
        </Modal>
      </form>
    )

    const outerForm = screen.getByTestId('outer-form')
    const innerForm = screen.getByTestId('inner-form')

    // The inner form should NOT be a descendant of the outer form
    expect(outerForm.contains(innerForm)).toBe(false)
  })

  it('renders title in header when provided', () => {
    render(
      <Modal isOpen={true} onClose={vi.fn()} title="Test Title">
        <div>Content</div>
      </Modal>
    )
    expect(screen.getByText('Test Title')).toBeInTheDocument()
  })

  it('calls onClose when close button is clicked', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()

    render(
      <Modal isOpen={true} onClose={onClose} title="Test">
        <div>Content</div>
      </Modal>
    )

    await user.click(screen.getByLabelText('Close'))
    expect(onClose).toHaveBeenCalledOnce()
  })
})

describe('ModalHeader', () => {
  it('renders title text', () => {
    render(<ModalHeader title="Header Title" onClose={vi.fn()} />)
    expect(screen.getByText('Header Title')).toBeInTheDocument()
  })

  it('renders custom children instead of title', () => {
    render(
      <ModalHeader onClose={vi.fn()}>
        <span>Custom Header</span>
      </ModalHeader>
    )
    expect(screen.getByText('Custom Header')).toBeInTheDocument()
  })
})

describe('ModalContent', () => {
  it('renders children', () => {
    render(<ModalContent><p>Body text</p></ModalContent>)
    expect(screen.getByText('Body text')).toBeInTheDocument()
  })
})

describe('ModalFooter', () => {
  it('renders children', () => {
    render(<ModalFooter><button>Save</button></ModalFooter>)
    expect(screen.getByText('Save')).toBeInTheDocument()
  })
})
