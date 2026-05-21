import { api } from './client'

export interface DphPriznaniLine {
  base: number
  vat: number
  count: number
  label: string
}

export interface DphPriznaniPreview {
  summary: {
    period: string
    lines: Record<string, DphPriznaniLine>
    total_vat_output: number
    total_vat_input: number
    tax_due: number
    is_excess_deduction: boolean
  }
  warnings: string[]
}

export const reportsApi = {
  dphPreview: (year: number, month: number) =>
    api.get<DphPriznaniPreview>('/reports/dphdp3/preview', { params: { year, month } }).then(r => r.data),

  /** URL na download endpoint — frontend ho otevírá v novém okně */
  dphDownloadUrl: (year: number, month: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/dphdp3?${params.toString()}`
  },
}
