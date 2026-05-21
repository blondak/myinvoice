import { api } from './client'

/**
 * Externí integrace — iDoklad, Fakturoid (fáze 2b), Anthropic AI (fáze 2c).
 * Credentials BYOK (Bring Your Own Key), šifrované at-rest přes SecretEncryption.
 */

export interface IdokladCredentialsStatus {
  configured: boolean
  client_id: string | null
}

export interface IdokladCredentialsUpdateResult {
  saved: boolean
  test_ok: boolean
  test_error: string | null
}

export interface ImportJob {
  id: number
  supplier_id: number
  source: 'idoklad' | 'fakturoid' | 'pdf_isdoc_inbox' | 'pdf_ai'
  status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled'
  params: Record<string, unknown> | null
  total_items: number | null
  processed: number
  created_count: number
  skipped_count: number
  failed_count: number
  current_step: string | null
  log_text: string | null
  last_error: string | null
  cancel_requested: boolean
  started_at: string | null
  finished_at: string | null
  created_by: number
  created_at: string
}

export interface IdokladStartParams {
  include_clients?: boolean
  include_issued?: boolean
  include_received?: boolean
  /** Incremental sync — jen DateLastChange >= idoklad_last_imported_at bookmark */
  incremental?: boolean
  /** Stáhne PDF přílohy (vydané: rendered; přijaté: první PDF attachment od dodavatele) */
  download_attachments?: boolean
  dry_run?: boolean
}

export const integrationsApi = {
  // iDoklad credentials
  getIdokladCreds: () =>
    api.get<IdokladCredentialsStatus>('/admin/imports/idoklad/credentials').then(r => r.data),
  setIdokladCreds: (clientId: string, clientSecret: string) =>
    api.put<IdokladCredentialsUpdateResult>('/admin/imports/idoklad/credentials', {
      client_id: clientId, client_secret: clientSecret,
    }).then(r => r.data),
  deleteIdokladCreds: () =>
    api.delete<{ ok: boolean }>('/admin/imports/idoklad/credentials').then(r => r.data),

  // Import jobs
  startIdoklad: (params: IdokladStartParams = {}) =>
    api.post<{ job_id: number; status: string; params: IdokladStartParams }>(
      '/admin/imports/idoklad/start', params,
    ).then(r => r.data),
  getJob: (id: number) =>
    api.get<ImportJob>(`/admin/imports/${id}`).then(r => r.data),
  cancelJob: (id: number) =>
    api.post<{ ok: boolean; cancel_requested: boolean }>(`/admin/imports/${id}/cancel`).then(r => r.data),
}
