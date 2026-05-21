<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { crmApi, type CrmOverview, type CrmMonthlyRow, type TopClient, type TopVendor } from '@/api/crm'
import { formatMoney } from '@/composables/useFormat'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const overview = ref<CrmOverview | null>(null)
const monthly = ref<CrmMonthlyRow[]>([])
const topClients = ref<TopClient[]>([])
const topVendors = ref<TopVendor[]>([])
const loading = ref(true)
const recomputing = ref(false)

// Filters
const periodMonths = ref(12)
const currencyFilter = ref<string>('')

const availableCurrencies = computed(() => overview.value?.currencies || [])

// Auto-select default currency = first one (typicky CZK)
watch(availableCurrencies, (curs) => {
  if (curs.length > 0 && !currencyFilter.value) {
    currencyFilter.value = curs[0]
  }
})

async function loadAll() {
  loading.value = true
  try {
    const cur = currencyFilter.value || undefined
    const [ov, mo, tc, tv] = await Promise.all([
      crmApi.overview(),
      crmApi.monthly(periodMonths.value, cur),
      crmApi.topClients(periodMonths.value, 10, cur),
      crmApi.topVendors(periodMonths.value, 10, cur),
    ])
    overview.value = ov
    monthly.value = mo
    topClients.value = tc
    topVendors.value = tv
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

async function recompute() {
  if (recomputing.value) return
  recomputing.value = true
  try {
    const r = await crmApi.recompute()
    toast.success(t('crm.recompute_done', { ms: r.elapsed_ms }))
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    recomputing.value = false
  }
}

// Derived: filter overview na vybranou měnu
const currentMonthKpi = computed(() => {
  if (!overview.value) return null
  return overview.value.current_month.find(k => k.currency === currencyFilter.value) || overview.value.current_month[0] || null
})
const lastMonthKpi = computed(() => {
  if (!overview.value) return null
  return overview.value.last_month.find(k => k.currency === currencyFilter.value) || overview.value.last_month[0] || null
})
const ytdKpi = computed(() => {
  if (!overview.value) return null
  return overview.value.ytd.find(k => k.currency === currencyFilter.value) || overview.value.ytd[0] || null
})

// Trend % vs last month
function trendPct(current: number, last: number): number {
  if (last === 0) return current > 0 ? 100 : 0
  return Math.round(((current - last) / Math.abs(last)) * 100)
}

// Chart max — pro proportional bar widths
const chartMaxValue = computed(() => {
  let max = 0
  for (const m of monthly.value) {
    if (m.revenue > max) max = m.revenue
    if (m.costs > max) max = m.costs
  }
  return max
})

function barWidthPct(value: number): number {
  if (chartMaxValue.value === 0) return 0
  return Math.round((value / chartMaxValue.value) * 100)
}

function formatMonthLabel(period: string): string {
  // "2026-05" → "kvě 26" (cz) nebo "May 26"
  const [y, m] = period.split('-')
  if (!y || !m) return period
  const date = new Date(Number(y), Number(m) - 1, 1)
  return date.toLocaleDateString('cs-CZ', { month: 'short', year: '2-digit' })
}

watch([periodMonths, currencyFilter], () => {
  if (currencyFilter.value) loadAll()
})

onMounted(loadAll)
</script>

<template>
  <div>
    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('crm.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('crm.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <select v-model.number="periodMonths" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option :value="3">{{ t('crm.last_n_months', { n: 3 }) }}</option>
          <option :value="6">{{ t('crm.last_n_months', { n: 6 }) }}</option>
          <option :value="12">{{ t('crm.last_n_months', { n: 12 }) }}</option>
          <option :value="24">{{ t('crm.last_n_months', { n: 24 }) }}</option>
        </select>
        <select v-if="availableCurrencies.length > 1" v-model="currencyFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option v-for="c in availableCurrencies" :key="c" :value="c">{{ c }}</option>
        </select>
        <button
          v-if="auth.user?.role === 'admin'"
          type="button" @click="recompute" :disabled="recomputing"
          :title="t('crm.recompute_hint')"
          class="cursor-pointer h-9 px-3 border border-neutral-300 hover:bg-neutral-50 text-sm rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/>
          </svg>
          {{ recomputing ? '…' : t('crm.recompute') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="bg-white border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <div v-else-if="!overview || overview.currencies.length === 0" class="bg-white border border-neutral-200 rounded-lg shadow-sm p-8 text-center">
      <p class="text-neutral-600 mb-2">{{ t('crm.no_data') }}</p>
      <p class="text-sm text-neutral-500 mb-4">{{ t('crm.no_data_hint') }}</p>
      <button v-if="auth.user?.role === 'admin'" type="button" @click="recompute" :disabled="recomputing"
        class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
        {{ t('crm.recompute_now') }}
      </button>
    </div>

    <div v-else class="space-y-4">
      <!-- ═══ KPI cards ═══ -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Revenue -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('crm.kpi.revenue') }}</span>
            <svg class="w-5 h-5 text-success-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2m2 4h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm7-5a2 2 0 1 1-4 0 2 2 0 0 1 4 0z"/>
            </svg>
          </div>
          <div class="text-2xl font-bold text-neutral-900 font-mono">
            {{ formatMoney(currentMonthKpi?.revenue || 0, currencyFilter) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.kpi.this_month') }}
            <span v-if="lastMonthKpi" class="ml-2"
              :class="trendPct(currentMonthKpi?.revenue || 0, lastMonthKpi.revenue) >= 0 ? 'text-success-600' : 'text-danger-500'">
              {{ trendPct(currentMonthKpi?.revenue || 0, lastMonthKpi.revenue) >= 0 ? '▲' : '▼' }}
              {{ Math.abs(trendPct(currentMonthKpi?.revenue || 0, lastMonthKpi.revenue)) }}%
            </span>
          </div>
          <div class="text-xs text-neutral-400 mt-3 pt-2 border-t border-neutral-100">
            <div>YTD: <span class="font-mono">{{ formatMoney(ytdKpi?.revenue || 0, currencyFilter) }}</span></div>
            <div class="mt-0.5">{{ currentMonthKpi?.invoice_count || 0 }} {{ t('crm.kpi.invoices') }}</div>
          </div>
        </div>

        <!-- Costs -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('crm.kpi.costs') }}</span>
            <svg class="w-5 h-5 text-danger-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2"/>
            </svg>
          </div>
          <div class="text-2xl font-bold text-neutral-900 font-mono">
            {{ formatMoney(currentMonthKpi?.costs || 0, currencyFilter) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.kpi.this_month') }}
            <span v-if="lastMonthKpi" class="ml-2"
              :class="trendPct(currentMonthKpi?.costs || 0, lastMonthKpi.costs) >= 0 ? 'text-danger-500' : 'text-success-600'">
              {{ trendPct(currentMonthKpi?.costs || 0, lastMonthKpi.costs) >= 0 ? '▲' : '▼' }}
              {{ Math.abs(trendPct(currentMonthKpi?.costs || 0, lastMonthKpi.costs)) }}%
            </span>
          </div>
          <div class="text-xs text-neutral-400 mt-3 pt-2 border-t border-neutral-100">
            <div>YTD: <span class="font-mono">{{ formatMoney(ytdKpi?.costs || 0, currencyFilter) }}</span></div>
            <div class="mt-0.5">{{ currentMonthKpi?.purchase_count || 0 }} {{ t('crm.kpi.purchases') }}</div>
          </div>
        </div>

        <!-- Profit -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('crm.kpi.profit') }}</span>
            <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
            </svg>
          </div>
          <div class="text-2xl font-bold font-mono"
            :class="(currentMonthKpi?.profit || 0) >= 0 ? 'text-success-600' : 'text-danger-500'">
            {{ formatMoney(currentMonthKpi?.profit || 0, currencyFilter) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.kpi.this_month') }}
            <span v-if="currentMonthKpi && currentMonthKpi.revenue > 0" class="ml-2">
              · {{ Math.round((currentMonthKpi.profit / currentMonthKpi.revenue) * 100) }}% {{ t('crm.kpi.margin') }}
            </span>
          </div>
          <div class="text-xs text-neutral-400 mt-3 pt-2 border-t border-neutral-100">
            <div>YTD: <span class="font-mono">{{ formatMoney(ytdKpi?.profit || 0, currencyFilter) }}</span></div>
          </div>
        </div>
      </div>

      <!-- ═══ Monthly trend chart (HTML/CSS bars — no chart.js dependency) ═══ -->
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
            {{ t('crm.monthly_trend') }} ({{ t('crm.last_n_months', { n: periodMonths }) }})
          </h3>
          <div class="flex items-center gap-3 text-xs">
            <span class="flex items-center gap-1">
              <span class="inline-block w-3 h-3 rounded-sm bg-success-500"></span>
              {{ t('crm.kpi.revenue') }}
            </span>
            <span class="flex items-center gap-1">
              <span class="inline-block w-3 h-3 rounded-sm bg-danger-500"></span>
              {{ t('crm.kpi.costs') }}
            </span>
          </div>
        </header>
        <div v-if="monthly.length === 0" class="p-8 text-center text-neutral-500 text-sm">
          {{ t('crm.no_chart_data') }}
        </div>
        <div v-else class="p-4 space-y-2">
          <div v-for="m in monthly" :key="m.period + m.currency" class="grid grid-cols-[60px_1fr_120px] gap-2 items-center text-xs">
            <div class="text-neutral-600 font-medium">{{ formatMonthLabel(m.period) }}</div>
            <div class="space-y-1">
              <div class="flex items-center gap-2">
                <div class="bg-success-500 h-3 rounded-sm" :style="{ width: barWidthPct(m.revenue) + '%' }"></div>
                <span class="font-mono text-neutral-700">{{ formatMoney(m.revenue, m.currency) }}</span>
              </div>
              <div class="flex items-center gap-2">
                <div class="bg-danger-500 h-3 rounded-sm" :style="{ width: barWidthPct(m.costs) + '%' }"></div>
                <span class="font-mono text-neutral-700">{{ formatMoney(m.costs, m.currency) }}</span>
              </div>
            </div>
            <div class="text-right font-mono"
              :class="m.profit >= 0 ? 'text-success-600' : 'text-danger-500'">
              {{ m.profit >= 0 ? '+' : '' }}{{ formatMoney(m.profit, m.currency) }}
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ Top klienti + Top vendoři side by side ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Top clients -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.top_clients') }}
            </h3>
          </header>
          <div v-if="topClients.length === 0" class="p-8 text-center text-neutral-500 text-sm">
            {{ t('crm.no_data') }}
          </div>
          <table v-else class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="c in topClients" :key="c.client_id + c.currency" class="hover:bg-neutral-50">
                <td class="px-5 py-2.5">
                  <RouterLink :to="`/clients/${c.client_id}`" class="font-medium text-primary-700 hover:underline">
                    {{ c.company_name }}
                  </RouterLink>
                  <div class="text-xs text-neutral-500 mt-0.5">{{ c.invoice_count }} {{ t('crm.kpi.invoices') }}</div>
                </td>
                <td class="px-3 py-2.5 text-right font-mono text-neutral-900">
                  {{ formatMoney(c.revenue, c.currency) }}
                </td>
                <td class="px-5 py-2.5 text-right text-xs text-neutral-500 font-mono">
                  {{ c.percent_share.toFixed(1) }}%
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Top vendors -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.top_vendors') }}
            </h3>
          </header>
          <div v-if="topVendors.length === 0" class="p-8 text-center text-neutral-500 text-sm">
            {{ t('crm.no_data_vendors') }}
          </div>
          <table v-else class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="v in topVendors" :key="v.vendor_id + v.currency" class="hover:bg-neutral-50">
                <td class="px-5 py-2.5">
                  <RouterLink :to="`/clients/${v.vendor_id}`" class="font-medium text-primary-700 hover:underline">
                    {{ v.company_name }}
                  </RouterLink>
                  <div class="text-xs text-neutral-500 mt-0.5">{{ v.purchase_count }} {{ t('crm.kpi.purchases') }}</div>
                </td>
                <td class="px-3 py-2.5 text-right font-mono text-neutral-900">
                  {{ formatMoney(v.costs, v.currency) }}
                </td>
                <td class="px-5 py-2.5 text-right text-xs text-neutral-500 font-mono">
                  {{ v.percent_share.toFixed(1) }}%
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
