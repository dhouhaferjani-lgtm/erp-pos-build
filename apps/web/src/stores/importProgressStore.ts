import { create } from 'zustand'

/**
 * Import status enum matching backend ImportStatus
 */
export type ImportStatus = App.Modules.Import.Domain.Enums.ImportStatus

/**
 * Active import progress state
 */
export interface ImportProgress {
  importJobId: string
  status: ImportStatus
  totalRows: number
  processedRows: number
  successfulRows: number
  failedRows: number
  progressPercentage: number
  importType: string
  originalFilename: string
  errorMessage?: string
  completedAt?: string
  isSuccess?: boolean
  isPartialSuccess?: boolean
}

/**
 * Import progress store state
 */
interface ImportProgressState {
  /** Map of active imports by job ID */
  activeImports: Map<string, ImportProgress>
  /** IDs of imports that just completed (for auto-dismiss) */
  completedImportIds: Set<string>
}

/**
 * Import progress store actions
 */
interface ImportProgressActions {
  /** Update progress for an import job */
  updateProgress: (data: {
    import_job_id: string
    status: ImportStatus
    total_rows: number
    processed_rows: number
    successful_rows: number
    failed_rows: number
    progress_percentage: number
    import_type: string
    original_filename: string
  }) => void

  /** Mark an import as completed */
  completeImport: (data: {
    import_job_id: string
    status: ImportStatus
    total_rows: number
    successful_rows: number
    failed_rows: number
    import_type: string
    original_filename: string
    error_message?: string
    completed_at: string
    is_success: boolean
    is_partial_success: boolean
  }) => void

  /** Remove an import from the active list */
  removeImport: (importJobId: string) => void

  /** Get progress for a specific import */
  getImportProgress: (importJobId: string) => ImportProgress | undefined

  /** Check if there are any active imports */
  hasActiveImports: () => boolean

  /** Clear all completed imports */
  clearCompleted: () => void
}

/**
 * Import progress store type
 */
type ImportProgressStore = ImportProgressState & ImportProgressActions

/**
 * Auto-dismiss delay for completed imports (5 seconds)
 */
const AUTO_DISMISS_DELAY = 5000

/**
 * Import progress store for tracking real-time import updates.
 *
 * This store is updated via WebSocket events from the backend
 * and provides global visibility into import progress across all pages.
 */
export const useImportProgressStore = create<ImportProgressStore>((set, get) => ({
  activeImports: new Map(),
  completedImportIds: new Set(),

  updateProgress: (data) => {
    set((state) => {
      const newImports = new Map(state.activeImports)
      newImports.set(data.import_job_id, {
        importJobId: data.import_job_id,
        status: data.status,
        totalRows: data.total_rows,
        processedRows: data.processed_rows,
        successfulRows: data.successful_rows,
        failedRows: data.failed_rows,
        progressPercentage: data.progress_percentage,
        importType: data.import_type,
        originalFilename: data.original_filename,
      })
      return { activeImports: newImports }
    })
  },

  completeImport: (data) => {
    set((state) => {
      const newImports = new Map(state.activeImports)
      const newCompletedIds = new Set(state.completedImportIds)

      const progressData: ImportProgress = {
        importJobId: data.import_job_id,
        status: data.status,
        totalRows: data.total_rows,
        processedRows: data.total_rows,
        successfulRows: data.successful_rows,
        failedRows: data.failed_rows,
        progressPercentage: 100,
        importType: data.import_type,
        originalFilename: data.original_filename,
        completedAt: data.completed_at,
        isSuccess: data.is_success,
        isPartialSuccess: data.is_partial_success,
      }
      // Only set errorMessage if it's defined (for exactOptionalPropertyTypes)
      if (data.error_message !== undefined) {
        progressData.errorMessage = data.error_message
      }
      newImports.set(data.import_job_id, progressData)

      newCompletedIds.add(data.import_job_id)

      return {
        activeImports: newImports,
        completedImportIds: newCompletedIds,
      }
    })

    // Auto-dismiss after delay
    setTimeout(() => {
      get().removeImport(data.import_job_id)
    }, AUTO_DISMISS_DELAY)
  },

  removeImport: (importJobId) => {
    set((state) => {
      const newImports = new Map(state.activeImports)
      const newCompletedIds = new Set(state.completedImportIds)

      newImports.delete(importJobId)
      newCompletedIds.delete(importJobId)

      return {
        activeImports: newImports,
        completedImportIds: newCompletedIds,
      }
    })
  },

  getImportProgress: (importJobId) => {
    return get().activeImports.get(importJobId)
  },

  hasActiveImports: () => {
    return get().activeImports.size > 0
  },

  clearCompleted: () => {
    set((state) => {
      const newImports = new Map(state.activeImports)
      const completedIds = Array.from(state.completedImportIds)

      completedIds.forEach((id) => {
        newImports.delete(id)
      })

      return {
        activeImports: newImports,
        completedImportIds: new Set(),
      }
    })
  },
}))
