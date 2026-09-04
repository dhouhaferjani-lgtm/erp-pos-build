import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { ImportProgress } from './ImportProgress'

// Renders against the real i18n instance loaded in src/test/setup.ts, so the
// aria-label key must actually resolve — a wrong key path (DEV-QA-050, where the
// component pointed at `wizard.progressAriaLabel` instead of
// `wizard.execute.progressAriaLabel`) surfaces here as the raw key string.
describe('ImportProgress', () => {
  const steps = [
    { key: 'upload', label: 'Upload' },
    { key: 'map', label: 'Map' },
    { key: 'execute', label: 'Execute' },
  ]

  it('resolves the progress aria-label to real copy, not the raw i18n key', () => {
    render(<ImportProgress steps={steps} currentStep={1} completedSteps={[0]} />)

    const nav = screen.getByRole('navigation')
    expect(nav).toHaveAccessibleName('Import progress')
    expect(nav.getAttribute('aria-label')).not.toContain('wizard.')
  })
})
