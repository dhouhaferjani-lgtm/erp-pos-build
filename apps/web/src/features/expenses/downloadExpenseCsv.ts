import type { AxiosResponse } from 'axios'

function responseFilename(response: AxiosResponse<Blob>, fallback: string): string {
  const contentDisposition: unknown = response.headers['content-disposition']
  const match = typeof contentDisposition === 'string'
    ? /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(contentDisposition)
    : null

  return match?.[1]?.replace(/['"]/g, '') ?? fallback
}

export function downloadExpenseCsv(
  response: AxiosResponse<Blob>,
  fallback = 'expenses.csv',
): void {
  const blob = new Blob([response.data], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = responseFilename(response, fallback)
  link.hidden = true
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
