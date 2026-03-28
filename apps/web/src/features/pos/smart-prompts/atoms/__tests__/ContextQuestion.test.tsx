import { render, screen, fireEvent } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'
import { ContextQuestion } from '../ContextQuestion'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

const mockField = {
  key: 'skin_type',
  labelKey: 'smart-prompts:skin_type_question',
  options: [
    { value: 'normal', labelKey: 'smart-prompts:skin_type.normal' },
    { value: 'oily', labelKey: 'smart-prompts:skin_type.oily' },
    { value: 'dry', labelKey: 'smart-prompts:skin_type.dry' },
  ],
}

describe('ContextQuestion', () => {
  it('renders all options', () => {
    render(<ContextQuestion field={mockField} value={null} onChange={vi.fn()} />)
    expect(screen.getByText('smart-prompts:skin_type.normal')).toBeInTheDocument()
    expect(screen.getByText('smart-prompts:skin_type.oily')).toBeInTheDocument()
    expect(screen.getByText('smart-prompts:skin_type.dry')).toBeInTheDocument()
  })

  it('calls onChange when option clicked', () => {
    const onChange = vi.fn()
    render(<ContextQuestion field={mockField} value={null} onChange={onChange} />)
    fireEvent.click(screen.getByText('smart-prompts:skin_type.oily'))
    expect(onChange).toHaveBeenCalledWith('oily')
  })

  it('highlights selected value', () => {
    render(<ContextQuestion field={mockField} value="oily" onChange={vi.fn()} />)
    const oilyButton = screen.getByText('smart-prompts:skin_type.oily')
    expect(oilyButton.closest('button')).toHaveAttribute('aria-pressed', 'true')
  })
})
