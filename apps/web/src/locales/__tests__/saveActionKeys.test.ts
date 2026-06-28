import { describe, it, expect } from 'vitest'
import en from '../en/common.json'
import fr from '../fr/common.json'
import ar from '../ar/common.json'

const REQUIRED_ACTIONS = ['saveAndNew', 'saveAndClose', 'openSaveMenu'] as const
const REQUIRED_CONFIRM = ['unsavedChangesTitle', 'unsavedChangesBody', 'leaveWithoutSaving', 'stayOnPage'] as const

describe.each([['en', en], ['fr', fr], ['ar', ar]])('common save-action keys (%s)', (_name, bundle) => {
  const b = bundle as { actions: Record<string, string>; confirmation: Record<string, string> }
  it.each(REQUIRED_ACTIONS)('has actions.%s', (k) => {
    expect(typeof b.actions[k]).toBe('string')
    expect(b.actions[k].length).toBeGreaterThan(0)
  })
  it.each(REQUIRED_CONFIRM)('has confirmation.%s', (k) => {
    expect(typeof b.confirmation[k]).toBe('string')
    expect(b.confirmation[k].length).toBeGreaterThan(0)
  })
})
