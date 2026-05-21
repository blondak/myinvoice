import { api } from './client'

export interface CrmKpi {
  period?: string | null
  currency: string
  revenue: number
  revenue_net: number
  costs: number
  costs_net: number
  profit: number
  invoice_count: number
  purchase_count: number
  vat_output: number
  vat_input: number
}

export interface CrmOverview {
  current_month: CrmKpi[]
  last_month: CrmKpi[]
  ytd: CrmKpi[]
  currencies: string[]
}

export interface CrmMonthlyRow extends CrmKpi {
  period: string
}

export interface TopClient {
  client_id: number
  company_name: string
  revenue: number
  invoice_count: number
  currency: string
  percent_share: number
}

export interface TopVendor {
  vendor_id: number
  company_name: string
  costs: number
  purchase_count: number
  currency: string
  percent_share: number
}

export const crmApi = {
  overview: () =>
    api.get<CrmOverview>('/crm/overview').then(r => r.data),
  monthly: (months = 12, currency?: string) =>
    api.get<CrmMonthlyRow[]>('/crm/monthly', { params: { months, currency } }).then(r => r.data),
  topClients: (months = 12, limit = 10, currency?: string) =>
    api.get<TopClient[]>('/crm/top-clients', { params: { months, limit, currency } }).then(r => r.data),
  topVendors: (months = 12, limit = 10, currency?: string) =>
    api.get<TopVendor[]>('/crm/top-vendors', { params: { months, limit, currency } }).then(r => r.data),
  recompute: () =>
    api.post<{ ok: boolean; elapsed_ms: number }>('/crm/recompute', {}).then(r => r.data),
}
