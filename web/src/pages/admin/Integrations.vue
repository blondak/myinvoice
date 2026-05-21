<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { integrationsApi, type IdokladCredentialsStatus, type ImportJob } from '@/api/integrations'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const toast = useToast()

type Tab = 'idoklad' | 'fakturoid' | 'ai'
const tab = ref<Tab>('idoklad')

// ── iDoklad credentials state ─────────────────────────────────────────
const idokladStatus = ref<IdokladCredentialsStatus | null>(null)
const idokladClientId = ref('')
const idokladClientSecret = ref('')
const idokladSaving = ref(false)
const idokladTestMsg = ref<{ ok: boolean; text: string } | null>(null)
const showSecret = ref(false)

async function loadIdokladStatus() {
  try {
    idokladStatus.value = await integrationsApi.getIdokladCreds()
    if (idokladStatus.value?.client_id) idokladClientId.value = idokladStatus.value.client_id
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function saveIdokladCreds() {
  if (!idokladClientId.value || !idokladClientSecret.value) {
    toast.error('Vyplň oboje pole (client_id i client_secret).')
    return
  }
  idokladSaving.value = true
  idokladTestMsg.value = null
  try {
    const r = await integrationsApi.setIdokladCreds(idokladClientId.value, idokladClientSecret.value)
    if (r.test_ok) {
      idokladTestMsg.value = { ok: true, text: t('integrations.idoklad.test_success') }
      idokladClientSecret.value = ''  // clear sensitive field
      await loadIdokladStatus()
    } else {
      idokladTestMsg.value = { ok: false, text: r.test_error || 'Test connectivity selhal' }
    }
  } catch (e) {
    idokladTestMsg.value = { ok: false, text: apiErrorMessage(e) }
  } finally {
    idokladSaving.value = false
  }
}

async function deleteIdokladCreds() {
  if (!confirm(t('integrations.idoklad.delete_confirm'))) return
  try {
    await integrationsApi.deleteIdokladCreds()
    idokladStatus.value = null
    idokladClientId.value = ''
    idokladClientSecret.value = ''
    idokladTestMsg.value = null
    toast.success(t('integrations.idoklad.deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

// ── iDoklad import job state ──────────────────────────────────────────
const startParams = ref({
  include_clients: true,
  include_issued: true,
  include_received: true,
  incremental: false,
  download_attachments: false,
  dry_run: false,
})
const currentJob = ref<ImportJob | null>(null)
const starting = ref(false)
let pollTimer: ReturnType<typeof setInterval> | null = null

async function startImport() {
  if (starting.value) return
  starting.value = true
  try {
    const r = await integrationsApi.startIdoklad(startParams.value)
    toast.success(t('integrations.idoklad.started', { jobId: r.job_id }))
    await pollJob(r.job_id)
  } catch (e: any) {
    toast.error(apiErrorMessage(e))
  } finally {
    starting.value = false
  }
}

async function pollJob(jobId: number) {
  // Initial fetch
  currentJob.value = await integrationsApi.getJob(jobId)
  if (pollTimer) clearInterval(pollTimer)
  // Poll každé 2s dokud queued/running
  pollTimer = setInterval(async () => {
    if (!currentJob.value) return
    try {
      currentJob.value = await integrationsApi.getJob(jobId)
      if (['completed', 'failed', 'cancelled'].includes(currentJob.value.status)) {
        if (pollTimer) clearInterval(pollTimer)
        pollTimer = null
      }
    } catch (e) {
      if (pollTimer) clearInterval(pollTimer)
      pollTimer = null
    }
  }, 2000)
}

async function cancelImport() {
  if (!currentJob.value) return
  if (!confirm(t('integrations.idoklad.cancel_confirm'))) return
  try {
    await integrationsApi.cancelJob(currentJob.value.id)
    toast.success(t('integrations.idoklad.cancel_requested'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

const isJobRunning = computed(() =>
  currentJob.value && ['queued', 'running'].includes(currentJob.value.status)
)

const progressPercent = computed(() => {
  if (!currentJob.value || !currentJob.value.total_items) return null
  return Math.round((currentJob.value.processed / currentJob.value.total_items) * 100)
})

onMounted(() => {
  loadIdokladStatus()
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})
</script>

<template>
  <div class="max-w-4xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('integrations.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('integrations.subtitle') }}</p>
    </div>

    <!-- Tabs: iDoklad / Fakturoid / AI -->
    <div class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button
        v-for="tt in (['idoklad', 'fakturoid', 'ai'] as const)" :key="tt"
        @click="tab = tt"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap inline-flex items-center gap-1.5"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'"
      >
        {{ t('integrations.' + tt + '.tab') }}
        <span v-if="tt !== 'idoklad'" class="text-[10px] uppercase tracking-wide bg-neutral-100 text-neutral-500 px-1.5 py-0.5 rounded">
          {{ t('integrations.coming_soon') }}
        </span>
      </button>
    </div>

    <!-- ════ iDoklad tab ════ -->
    <div v-if="tab === 'idoklad'" class="space-y-4">
      <!-- Box: credentials -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-2">{{ t('integrations.idoklad.credentials_title') }}</h2>
        <p class="text-xs text-neutral-500 mb-4">{{ t('integrations.idoklad.credentials_hint') }}</p>

        <div class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700 mb-4" v-if="idokladStatus?.configured">
          <strong>✓ {{ t('integrations.idoklad.configured') }}</strong>
          <span v-if="idokladStatus.client_id" class="ml-2 font-mono text-xs">{{ idokladStatus.client_id.slice(0, 12) }}…</span>
        </div>

        <div class="space-y-3">
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.idoklad.client_id') }} *</label>
            <input v-model="idokladClientId" type="text" maxlength="256"
                   class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                   placeholder="01234567-89ab-cdef-0123-456789abcdef" />
          </div>
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.idoklad.client_secret') }} *</label>
            <div class="flex gap-2">
              <input v-model="idokladClientSecret" :type="showSecret ? 'text' : 'password'" maxlength="512"
                     class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                     :placeholder="idokladStatus?.configured ? t('integrations.idoklad.secret_placeholder_existing') : ''" />
              <button type="button" @click="showSecret = !showSecret"
                      class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
                {{ showSecret ? '🙈' : '👁' }}
              </button>
            </div>
            <p class="text-xs text-neutral-500 mt-1">{{ t('integrations.idoklad.secret_hint') }}</p>
          </div>
        </div>

        <div v-if="idokladTestMsg" class="mt-3 rounded-md px-3 py-2 text-sm"
             :class="idokladTestMsg.ok ? 'bg-success-50 text-success-600 border border-success-500/40' : 'bg-danger-50 text-danger-500 border border-danger-500/40'">
          {{ idokladTestMsg.text }}
        </div>

        <div class="flex items-center justify-between gap-2 mt-4 pt-4 border-t border-neutral-100">
          <button v-if="idokladStatus?.configured" type="button" @click="deleteIdokladCreds"
                  class="cursor-pointer h-10 px-4 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md">
            {{ t('integrations.idoklad.delete') }}
          </button>
          <span v-else></span>
          <button type="button" @click="saveIdokladCreds" :disabled="idokladSaving"
                  class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ idokladSaving ? '…' : t('integrations.idoklad.save_and_test') }}
          </button>
        </div>

        <details class="mt-4 text-xs text-neutral-500">
          <summary class="cursor-pointer hover:text-neutral-700">{{ t('integrations.idoklad.how_to_get') }}</summary>
          <ol class="mt-2 list-decimal list-inside space-y-1">
            <li>{{ t('integrations.idoklad.step1') }}</li>
            <li>{{ t('integrations.idoklad.step2') }}</li>
            <li>{{ t('integrations.idoklad.step3') }}</li>
            <li>{{ t('integrations.idoklad.step4') }}</li>
          </ol>
        </details>
      </div>

      <!-- Box: import controls -->
      <div v-if="idokladStatus?.configured" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-3">{{ t('integrations.idoklad.run_title') }}</h2>

        <div v-if="!isJobRunning" class="space-y-3">
          <p class="text-sm text-neutral-600">{{ t('integrations.idoklad.run_hint') }}</p>
          <div class="grid grid-cols-2 gap-3">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.include_clients" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.include_clients') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.include_issued" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.include_issued') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.include_received" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.include_received') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.incremental" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span :title="t('integrations.idoklad.incremental_hint')">{{ t('integrations.idoklad.incremental') }}</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.download_attachments" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span :title="t('integrations.idoklad.download_attachments_hint')">{{ t('integrations.idoklad.download_attachments') }}</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.dry_run" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.dry_run') }}
            </label>
          </div>
          <button type="button" @click="startImport" :disabled="starting"
                  class="cursor-pointer w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0 0 10 9.87v4.263a1 1 0 0 0 1.555.832l3.197-2.132a1 1 0 0 0 0-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
            {{ starting ? '…' : t('integrations.idoklad.start_import') }}
          </button>
        </div>

        <div v-if="currentJob" class="space-y-3">
          <div class="flex items-center justify-between text-sm">
            <div>
              <span class="font-medium">Job #{{ currentJob.id }}</span>
              <span class="ml-2 px-2 py-0.5 text-xs rounded border"
                    :class="{
                      'bg-neutral-100 text-neutral-600 border-neutral-200': currentJob.status === 'queued',
                      'bg-primary-50 text-primary-700 border-primary-500/40': currentJob.status === 'running',
                      'bg-success-50 text-success-600 border-success-500/40': currentJob.status === 'completed',
                      'bg-danger-50 text-danger-500 border-danger-500/40': currentJob.status === 'failed',
                      'bg-warning-50 text-warning-600 border-warning-500/40': currentJob.status === 'cancelled',
                    }">
                {{ t('integrations.idoklad.status.' + currentJob.status) }}
              </span>
            </div>
            <button v-if="isJobRunning" type="button" @click="cancelImport"
                    :disabled="currentJob.cancel_requested"
                    class="cursor-pointer h-8 px-3 text-xs border border-danger-500/50 text-danger-500 hover:bg-danger-50 disabled:opacity-50 rounded-md">
              {{ currentJob.cancel_requested ? t('integrations.idoklad.cancelling') : t('integrations.idoklad.cancel') }}
            </button>
          </div>

          <div v-if="currentJob.current_step" class="text-sm text-neutral-600">{{ currentJob.current_step }}</div>

          <div v-if="progressPercent !== null" class="space-y-1">
            <div class="w-full h-2 bg-neutral-100 rounded-full overflow-hidden">
              <div class="h-full bg-primary-500 transition-all" :style="{ width: progressPercent + '%' }"></div>
            </div>
            <div class="text-xs text-neutral-500 font-mono">
              {{ currentJob.processed }} / {{ currentJob.total_items }} ({{ progressPercent }}%)
            </div>
          </div>

          <div class="grid grid-cols-3 gap-2 text-sm">
            <div class="bg-success-50 border border-success-500/40 rounded p-2">
              <div class="text-xs text-success-600">{{ t('integrations.idoklad.created') }}</div>
              <div class="font-mono font-semibold text-success-600">{{ currentJob.created_count }}</div>
            </div>
            <div class="bg-warning-50 border border-warning-500/40 rounded p-2">
              <div class="text-xs text-warning-600">{{ t('integrations.idoklad.skipped') }}</div>
              <div class="font-mono font-semibold text-warning-600">{{ currentJob.skipped_count }}</div>
            </div>
            <div class="bg-danger-50 border border-danger-500/40 rounded p-2">
              <div class="text-xs text-danger-500">{{ t('integrations.idoklad.failed') }}</div>
              <div class="font-mono font-semibold text-danger-500">{{ currentJob.failed_count }}</div>
            </div>
          </div>

          <div v-if="currentJob.last_error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
            {{ currentJob.last_error }}
          </div>

          <details v-if="currentJob.log_text" class="text-xs">
            <summary class="cursor-pointer text-neutral-600 hover:text-neutral-900">{{ t('integrations.idoklad.log') }}</summary>
            <pre class="mt-2 max-h-72 overflow-y-auto bg-neutral-900 text-neutral-100 p-3 rounded font-mono text-[11px] whitespace-pre-wrap">{{ currentJob.log_text }}</pre>
          </details>
        </div>
      </div>
    </div>

    <!-- ════ Fakturoid / AI placeholdery ════ -->
    <div v-else class="bg-white border border-neutral-200 rounded-lg p-8 shadow-sm text-center">
      <div class="text-neutral-500 text-sm">
        <strong>{{ t('integrations.coming_soon') }}</strong> —
        {{ tab === 'fakturoid' ? t('integrations.fakturoid.coming_hint') : t('integrations.ai.coming_hint') }}
      </div>
    </div>
  </div>
</template>
