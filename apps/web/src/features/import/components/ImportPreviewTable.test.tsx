import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { ImportPreviewTable } from './ImportPreviewTable'
import type { ImportPreview } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: { count?: number }) => (
      options?.count === undefined ? key : `${key}:${String(options.count)}`
    ),
  }),
}))

const preview: ImportPreview = {
  headers: ['name', 'placement_path'],
  rows: [{
    row_number: 2,
    data: { name: 'Brake pad', placement_path: 'A1 > R2' },
    is_valid: true,
    errors: {},
  }],
  summary: { total_rows: 1, valid_rows: 1, invalid_rows: 0 },
  placement: {
    max_depth: 2,
    nodes_to_create: [{ path: 'A1/R2', node_type: 'rack' }],
    placements_to_set: [{ row_number: 2, location_code: 'MAIN', path: 'A1/R2', node_id: null }],
  },
}

describe('ImportPreviewTable placement dry run', () => {
  it('shows planned nodes and placements before commit', () => {
    render(<ImportPreviewTable preview={preview} />)

    expect(screen.getByText('preview.nodesToCreate:1')).toBeInTheDocument()
    expect(screen.getByText('preview.placementsToSet:1')).toBeInTheDocument()
    expect(screen.getByText('MAIN: A1/R2')).toBeInTheDocument()
  })

  it('keeps legacy previews without placement metadata compatible', () => {
    const legacyPreview: ImportPreview = {
      headers: preview.headers,
      rows: preview.rows,
      summary: preview.summary,
    }

    render(<ImportPreviewTable preview={legacyPreview} />)

    expect(screen.queryByText('preview.nodesToCreate:1')).not.toBeInTheDocument()
    expect(screen.getByText('Brake pad')).toBeInTheDocument()
  })
})
