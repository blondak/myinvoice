<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi, type DphPriznaniPreview } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'

const { t } = useI18n()

const now = new Date()
const year = ref(now.getFullYear())
const month = ref(now.getMonth() + 1) // currentMonth (1-12)

const preview = ref<DphPriznaniPreview | null>(null)
const loading = ref(false)
const error = ref('')

async function loadPreview() {
  loading.value = true
  error.value = ''
  try {
    preview.value = await reportsApi.dphPreview(year.value, month.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function downloadXml() {
  if (!preview.value) return
  // Open in new tab — server vrátí Content-Disposition: attachment
  window.open(reportsApi.dphDownloadUrl(year.value, month.value), '_blank')
}

const monthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) =>
    new Date(2000, i, 1).toLocaleDateString('cs-CZ', { month: 'long' })
  )
)

const yearOptions = computed(() => {
  const cur = now.getFullYear()
  return [cur, cur - 1, cur - 2, cur - 3]
})

const linesSorted = computed(() => {
  if (!preview.value) return []
  return Object.entries(preview.value.summary.lines)
    .map(([line, data]) => ({ line, ...data }))
    .sort((a, b) => Number(a.line) - Number(b.line))
})

const outputLines = computed(() => linesSorted.value.filter(l => Number(l.line) < 40))
const inputLines = computed(() => linesSorted.value.filter(l => Number(l.line) >= 40))

watch([year, month], loadPreview)
onMounted(loadPreview)
</script>

<template>
  <div class="max-w-5xl">
    <!-- ⚠️ Prominent disclaimer — povinné per memory feedback -->
    <div class="bg-danger-50 border-2 border-danger-500 rounded-lg p-4 mb-4">
      <div class="flex items-start gap-3">
        <svg class="w-6 h-6 text-danger-600 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-1-8a1 1 0 0 0-1 1v3a1 1 0 0 0 2 0V6a1 1 0 0 0-1-1z" clip-rule="evenodd"/>
        </svg>
        <div class="text-sm text-danger-700">
          <p class="font-semibold mb-1">{{ t('reports.disclaimer_title') }}</p>
          <p>{{ t('reports.disclaimer_body') }}</p>
        </div>
      </div>
    </div>

    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.dph.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.dph.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <select v-model.number="month" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <button
          type="button" @click="downloadXml" :disabled="loading || !preview"
          class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('reports.dph.download_xml') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="bg-white border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div v-else-if="preview" class="space-y-4">
      <!-- Warnings -->
      <div v-if="preview.warnings.length > 0" class="bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700">
        <strong>{{ t('reports.dph.warnings') }}:</strong>
        <ul class="mt-1 list-disc list-inside">
          <li v-for="w in preview.warnings" :key="w">{{ w }}</li>
        </ul>
      </div>

      <!-- Rekapitulace -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.dph.vat_output') }}</div>
          <div class="text-2xl font-bold font-mono text-neutral-900">
            {{ formatMoney(preview.summary.total_vat_output, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.dph.vat_output_hint') }}</div>
        </div>
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.dph.vat_input') }}</div>
          <div class="text-2xl font-bold font-mono text-neutral-900">
            {{ formatMoney(preview.summary.total_vat_input, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.dph.vat_input_hint') }}</div>
        </div>
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ preview.summary.is_excess_deduction ? t('reports.dph.excess_deduction') : t('reports.dph.tax_due') }}
          </div>
          <div class="text-2xl font-bold font-mono"
            :class="preview.summary.is_excess_deduction ? 'text-success-600' : 'text-danger-500'">
            {{ formatMoney(Math.abs(preview.summary.tax_due), 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ preview.summary.is_excess_deduction ? t('reports.dph.excess_deduction_hint') : t('reports.dph.tax_due_hint') }}
          </div>
        </div>
      </div>

      <!-- DPH na výstupu (řádky 1-29) -->
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">
            {{ t('reports.dph.output_section') }}
          </h3>
        </header>
        <div v-if="outputLines.length === 0" class="p-8 text-center text-neutral-500 text-sm">
          {{ t('reports.dph.no_output_lines') }}
        </div>
        <table v-else class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-5 py-2 w-16">{{ t('reports.dph.line') }}</th>
              <th class="text-left px-3 py-2">{{ t('reports.dph.description') }}</th>
              <th class="text-right px-3 py-2">{{ t('reports.dph.base') }}</th>
              <th class="text-right px-5 py-2">{{ t('reports.dph.vat') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="l in outputLines" :key="l.line" class="hover:bg-neutral-50">
              <td class="px-5 py-2.5 font-mono text-neutral-700 font-medium">{{ l.line }}</td>
              <td class="px-3 py-2.5 text-neutral-700">{{ l.label }}</td>
              <td class="px-3 py-2.5 text-right font-mono">{{ formatMoney(l.base, 'CZK') }}</td>
              <td class="px-5 py-2.5 text-right font-mono">{{ formatMoney(l.vat, 'CZK') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- DPH na vstupu (řádky 40+) -->
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">
            {{ t('reports.dph.input_section') }}
          </h3>
        </header>
        <div v-if="inputLines.length === 0" class="p-8 text-center text-neutral-500 text-sm">
          {{ t('reports.dph.no_input_lines') }}
        </div>
        <table v-else class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-5 py-2 w-16">{{ t('reports.dph.line') }}</th>
              <th class="text-left px-3 py-2">{{ t('reports.dph.description') }}</th>
              <th class="text-right px-3 py-2">{{ t('reports.dph.base') }}</th>
              <th class="text-right px-5 py-2">{{ t('reports.dph.vat') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="l in inputLines" :key="l.line" class="hover:bg-neutral-50">
              <td class="px-5 py-2.5 font-mono text-neutral-700 font-medium">{{ l.line }}</td>
              <td class="px-3 py-2.5 text-neutral-700">{{ l.label }}</td>
              <td class="px-3 py-2.5 text-right font-mono">{{ formatMoney(l.base, 'CZK') }}</td>
              <td class="px-5 py-2.5 text-right font-mono">{{ formatMoney(l.vat, 'CZK') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Tip pro správné vyplnění -->
      <div v-if="outputLines.length === 0 && inputLines.length === 0" class="bg-primary-50 border border-primary-200 rounded-md p-3 text-sm text-primary-700">
        💡 {{ t('reports.dph.no_data_hint') }}
      </div>
    </div>
  </div>
</template>
