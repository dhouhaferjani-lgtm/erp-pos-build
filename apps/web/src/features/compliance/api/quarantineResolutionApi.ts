import { api } from '@/lib/api'

export interface ParseDefect {
  path: string
  code: string
  message: string
}

export interface BestEffortParseResponse {
  id: string
  source: 'fiscal_events' | 'fiscal_event_quarantine'
  fiscal_event_id: string | null
  event_type: string
  parsed: Record<string, unknown>
  defects: ParseDefect[]
}

export async function bestEffortParseQuarantine(
  id: string
): Promise<BestEffortParseResponse> {
  const response = await api.post<{ data: BestEffortParseResponse }>(
    `/fiscal/quarantine/${id}/best-effort-parse`,
    {}
  )
  return response.data.data
}
