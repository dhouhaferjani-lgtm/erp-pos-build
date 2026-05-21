import { useMemo, useState, type FormEvent } from 'react'
import { useMutation } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, FileSearch, Loader2 } from 'lucide-react'
import { getErrorMessage } from '@/lib/api'
import { bestEffortParseQuarantine, type BestEffortParseResponse } from '../api/quarantineResolutionApi'

export function QuarantineResolveAssistPage() {
  const [quarantineId, setQuarantineId] = useState('')
  const [result, setResult] = useState<BestEffortParseResponse | null>(null)

  const parseMutation = useMutation({
    mutationFn: bestEffortParseQuarantine,
    onSuccess: (response) => {
      setResult(response)
    },
  })

  const parsedJson = useMemo(
    () => JSON.stringify(result?.parsed ?? {}, null, 2),
    [result?.parsed],
  )

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const trimmed = quarantineId.trim()
    if (trimmed.length === 0) return
    parseMutation.mutate(trimmed)
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold text-gray-900">
          <FileSearch className="h-6 w-6 text-gray-500" />
          Quarantine resolution assist
        </h1>
        <p className="mt-1 text-sm text-gray-600">
          Prefill canonical payload fields from a parse-failed fiscal event or quarantine row.
        </p>
      </div>

      <form onSubmit={handleSubmit} className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
        <label htmlFor="quarantine-id" className="block text-sm font-medium text-gray-700">
          Quarantine or fiscal event id
        </label>
        <div className="mt-2 flex flex-col gap-3 sm:flex-row">
          <input
            id="quarantine-id"
            value={quarantineId}
            onChange={(event) => {
              setQuarantineId(event.target.value)
            }}
            placeholder="00000000-0000-4000-8000-000000000000"
            className="min-w-0 flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <button
            type="submit"
            disabled={parseMutation.isPending || quarantineId.trim().length === 0}
            className="inline-flex items-center justify-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-400"
          >
            {parseMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileSearch className="h-4 w-4" />}
            Parse
          </button>
        </div>
      </form>

      {parseMutation.isError && (
        <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
          {getErrorMessage(parseMutation.error)}
        </div>
      )}

      {result && (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
          <section className="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div className="border-b border-gray-200 px-4 py-3">
              <div className="flex flex-wrap items-center gap-2">
                <span className="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">
                  {result.event_type}
                </span>
                <span className="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">
                  {result.source}
                </span>
                {result.defects.length === 0 ? (
                  <span className="inline-flex items-center gap-1 rounded bg-green-100 px-2 py-1 text-xs font-medium text-green-800">
                    <CheckCircle2 className="h-3.5 w-3.5" />
                    No defects
                  </span>
                ) : (
                  <span className="inline-flex items-center gap-1 rounded bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800">
                    <AlertTriangle className="h-3.5 w-3.5" />
                    {result.defects.length} defects
                  </span>
                )}
              </div>
            </div>
            <textarea
              readOnly
              value={parsedJson}
              aria-label="Parsed payload JSON"
              className="h-[560px] w-full resize-none border-0 bg-gray-950 p-4 font-mono text-xs text-gray-100 outline-none"
            />
          </section>

          <section className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <h2 className="text-sm font-semibold text-gray-900">Defects</h2>
            {result.defects.length === 0 ? (
              <p className="mt-3 text-sm text-gray-600">The payload passed strict parsing.</p>
            ) : (
              <ul className="mt-3 space-y-3">
                {result.defects.map((defect) => (
                  <li key={`${defect.path}:${defect.code}:${defect.message}`} className="rounded-md border border-amber-200 bg-amber-50 p-3">
                    <div className="font-mono text-xs font-semibold text-amber-900">{defect.path}</div>
                    <div className="mt-1 text-xs font-medium uppercase text-amber-700">{defect.code}</div>
                    <p className="mt-2 text-sm text-amber-900">{defect.message}</p>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      )}
    </div>
  )
}
