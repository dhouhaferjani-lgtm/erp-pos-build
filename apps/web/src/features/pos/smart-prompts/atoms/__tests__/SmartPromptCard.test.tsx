import { render, screen, fireEvent } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'
import { SmartPromptCard } from '../SmartPromptCard'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

const mockRecommendation = {
  product_id: '123',
  product_name: 'Avène Cleanance Comedomed',
  score: '0.85',
  reason: 'Complete your routine: moisturizer',
  strategy: 'routine_completion',
}

describe('SmartPromptCard', () => {
  it('renders product name and reason', () => {
    render(<SmartPromptCard recommendation={mockRecommendation} onAdd={vi.fn()} />)
    expect(screen.getByText('Avène Cleanance Comedomed')).toBeInTheDocument()
    expect(screen.getByText('Complete your routine: moisturizer')).toBeInTheDocument()
  })

  it('calls onAdd when add button is clicked', () => {
    const onAdd = vi.fn()
    render(<SmartPromptCard recommendation={mockRecommendation} onAdd={onAdd} />)
    fireEvent.click(screen.getByRole('button', { name: /add/i }))
    expect(onAdd).toHaveBeenCalledWith('123')
  })

  it('renders strategy badge', () => {
    render(<SmartPromptCard recommendation={mockRecommendation} onAdd={vi.fn()} />)
    expect(screen.getByText('strategy.routine_completion')).toBeInTheDocument()
  })
})
