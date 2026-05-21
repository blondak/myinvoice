<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  purchaseInvoicesApi,
  type PurchaseMonthGroup,
  type PurchaseListMeta,
  type PurchaseInvoiceStatus,
  type PurchaseDocumentKind,
} from '@/api/purchaseInvoices'
import { formatMoney, formatDate, formatMonth } from '@/composables/useFormat'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const { t } = useI18n()
const router = useRouter()

const groups = ref<PurchaseMonthGroup[]>([])
const meta = ref<PurchaseListMeta>({ total: 0 })
const loading = ref(true)
const error = ref('')

const filterStatus = ref<PurchaseInvoiceStatus | ''>('')
const filterKind   = ref<PurchaseDocumentKind | ''>('')
const filterUnpaid = ref(false)
const filterOverdue = ref(false)
const searchQ = ref('')

// Scan inbox moved to /admin/import (admin/accountant only) — viz tab "Přijaté".

// Export ZIP state
const exportMonth = ref<string>((() => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
})())
const showExportPicker = ref(false)
function triggerExport() {
  window.open(purchaseInvoicesApi.exportUrl(exportMonth.value, 'tax'), '_blank')
  showExportPicker.value = false
}

onMounted(load)
watch([filterStatus, filterKind, filterUnpaid, filterOverdue, searchQ], () => {
  if (loadDebounce) clearTimeout(loadDebounce)
  loadDebounce = setTimeout(load, 300)
})

let loadDebounce: ReturnType<typeof setTimeout> | null = null

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await purchaseInvoicesApi.listGrouped({
      status:        filterStatus.value || undefined,
      document_kind: filterKind.value   || undefined,
      unpaid_only:   filterUnpaid.value || undefined,
      overdue:       filterOverdue.value || undefined,
      q:             searchQ.value || undefined,
      per_page: 100,
    })
    groups.value = res.data
    meta.value = res.meta
  } catch (e: any) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

// scan-inbox flow přesunut do /admin/import (admin/accountant only).

const statusBadgeClass = (s: PurchaseInvoiceStatus): string => {
  switch (s) {
    case 'draft':     return 'bg-neutral-100 text-neutral-700'
    case 'received':  return 'bg-blue-100 text-blue-800'
    case 'booked':    return 'bg-indigo-100 text-indigo-800'
    case 'paid':      return 'bg-green-100 text-green-800'
    case 'cancelled': return 'bg-red-100 text-red-800'
  }
}

const isOverdue = (dueDate: string, status: PurchaseInvoiceStatus): boolean => {
  if (status !== 'received' && status !== 'booked') return false
  return new Date(dueDate) < new Date(new Date().toISOString().slice(0, 10))
}
</script>

<template>
  <div class="space-y-4">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('purchase_invoice.title') }}</h1>
        <p class="text-sm text-neutral-500">{{ t('purchase_invoice.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 relative">
        <button
          type="button"
          @click="showExportPicker = !showExportPicker"
          class="cursor-pointer px-3 py-1.5 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50"
          :title="t('purchase_invoice.export.title')"
        >📦 {{ t('purchase_invoice.export.btn') }}</button>
        <RouterLink
          v-if="auth.user?.role === 'admin' || auth.user?.role === 'accountant'"
          to="/admin/import"
          class="px-3 py-1.5 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50"
          :title="t('purchase_invoice.scan_inbox.title')"
        >📥 {{ t('purchase_invoice.scan_inbox_btn') }}</RouterLink>
        <RouterLink
          to="/purchase-invoices/new"
          class="px-3 py-1.5 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md"
        >
          {{ t('purchase_invoice.new') }}
        </RouterLink>

        <!-- Mini export picker popover -->
        <div v-if="showExportPicker"
             class="absolute right-0 top-full mt-2 w-72 bg-white border border-neutral-200 rounded-lg shadow-lg p-4 z-20">
          <label class="block text-sm font-medium text-neutral-700 mb-1">
            {{ t('purchase_invoice.export.month_label') }}
          </label>
          <input v-model="exportMonth" type="month"
                 class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
          <p class="text-xs text-neutral-500 mt-2">{{ t('purchase_invoice.export.hint') }}</p>
          <div class="flex items-center justify-end gap-2 mt-3">
            <button type="button" @click="showExportPicker = false"
                    class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">
              {{ t('common.cancel') }}
            </button>
            <button type="button" @click="triggerExport"
                    class="cursor-pointer px-3 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md">
              {{ t('purchase_invoice.export.download') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-2 p-3 bg-neutral-50 rounded-md">
      <input
        v-model="searchQ"
        type="text"
        :placeholder="t('purchase_invoice.filters.search_placeholder')"
        class="px-3 py-1.5 border border-neutral-300 rounded-md text-sm min-w-[200px]"
      />
      <select v-model="filterStatus" class="px-3 py-1.5 border border-neutral-300 rounded-md text-sm">
        <option value="">{{ t('purchase_invoice.filters.all_statuses') }}</option>
        <option value="draft">{{ t('purchase_invoice.status.draft') }}</option>
        <option value="received">{{ t('purchase_invoice.status.received') }}</option>
        <option value="booked">{{ t('purchase_invoice.status.booked') }}</option>
        <option value="paid">{{ t('purchase_invoice.status.paid') }}</option>
        <option value="cancelled">{{ t('purchase_invoice.status.cancelled') }}</option>
      </select>
      <select v-model="filterKind" class="px-3 py-1.5 border border-neutral-300 rounded-md text-sm">
        <option value="">{{ t('purchase_invoice.filters.all_kinds') }}</option>
        <option value="invoice">{{ t('purchase_invoice.document_kind.invoice') }}</option>
        <option value="receipt">{{ t('purchase_invoice.document_kind.receipt') }}</option>
        <option value="credit_note">{{ t('purchase_invoice.document_kind.credit_note') }}</option>
        <option value="advance">{{ t('purchase_invoice.document_kind.advance') }}</option>
      </select>
      <label class="inline-flex items-center gap-1.5 text-sm">
        <input type="checkbox" v-model="filterUnpaid" />
        {{ t('purchase_invoice.filters.unpaid_only') }}
      </label>
      <label class="inline-flex items-center gap-1.5 text-sm">
        <input type="checkbox" v-model="filterOverdue" />
        {{ t('purchase_invoice.filters.overdue') }}
      </label>
    </div>

    <!-- Loading / Error / Empty -->
    <div v-if="loading" class="text-center py-12 text-neutral-500">…</div>
    <div v-else-if="error" class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-md text-sm">
      {{ error }}
    </div>
    <div v-else-if="groups.length === 0" class="text-center py-12">
      <p class="text-neutral-500">{{ searchQ || filterStatus || filterKind ? t('purchase_invoice.empty_filtered') : t('purchase_invoice.empty') }}</p>
    </div>

    <!-- Grouped by month -->
    <div v-else class="space-y-6">
      <section v-for="group in groups" :key="group.month">
        <header class="flex items-baseline justify-between mb-2 pb-1 border-b border-neutral-200">
          <h2 class="text-base font-medium">{{ formatMonth(group.month) }}</h2>
          <div class="flex flex-wrap items-center gap-3 text-xs text-neutral-600">
            <span>{{ t('purchase_invoice.summary_count', { count: group.count }) }}</span>
            <span v-for="tc in group.totals_per_currency" :key="tc.currency">
              {{ formatMoney(tc.with_vat, tc.currency) }}
            </span>
          </div>
        </header>

        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs text-neutral-500 border-b border-neutral-100">
              <th class="py-2 pl-1 font-normal">#</th>
              <th class="py-2 font-normal">{{ t('purchase_invoice.fields.vendor') }}</th>
              <th class="py-2 font-normal">{{ t('purchase_invoice.fields.vendor_invoice_number') }}</th>
              <th class="py-2 font-normal">{{ t('purchase_invoice.fields.tax_date') }}</th>
              <th class="py-2 font-normal">{{ t('purchase_invoice.fields.due_date') }}</th>
              <th class="py-2 font-normal text-right">{{ t('purchase_invoice.totals.with_vat') }}</th>
              <th class="py-2 font-normal">{{ t('purchase_invoice.status.draft') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="inv in group.invoices"
              :key="inv.id"
              class="border-b border-neutral-50 hover:bg-neutral-50 cursor-pointer"
              @click="router.push(`/purchase-invoices/${inv.id}`)"
            >
              <td class="py-2 pl-1 font-mono text-xs text-neutral-500">{{ inv.varsymbol || '—' }}</td>
              <td class="py-2 font-medium">{{ inv.vendor_company_name }}</td>
              <td class="py-2 font-mono text-xs">{{ inv.vendor_invoice_number }}</td>
              <td class="py-2 text-neutral-600">{{ formatDate(inv.tax_date || inv.issue_date) }}</td>
              <td class="py-2" :class="isOverdue(inv.due_date, inv.status) ? 'text-red-600 font-medium' : 'text-neutral-600'">
                {{ formatDate(inv.due_date) }}
              </td>
              <td class="py-2 text-right font-mono">{{ formatMoney(inv.total_with_vat, inv.currency, inv.currency_decimals) }}</td>
              <td class="py-2">
                <span class="px-2 py-0.5 rounded-full text-xs" :class="statusBadgeClass(inv.status)">
                  {{ t(`purchase_invoice.status.${inv.status}`) }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </div>

  </div>
</template>
