<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { purchaseInvoicesApi, type PurchaseInvoice, type PurchaseInvoiceStatus } from '@/api/purchaseInvoices'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const invoice = ref<PurchaseInvoice | null>(null)
const loading = ref(true)
const error = ref('')
const acting = ref(false)

const id = computed(() => Number(route.params.id))

onMounted(load)

async function load() {
  loading.value = true
  try {
    invoice.value = await purchaseInvoicesApi.get(id.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

async function transition(target: PurchaseInvoiceStatus) {
  if (!invoice.value) return
  if (target === 'cancelled' && !confirm(t('purchase_invoice.confirm.cancel'))) return
  acting.value = true
  try {
    invoice.value = await purchaseInvoicesApi.transition(invoice.value.id, target)
    toast.success(t(`purchase_invoice.transition.success_${target}`))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    acting.value = false
  }
}

async function remove() {
  if (!invoice.value) return
  if (!confirm(t('purchase_invoice.confirm.delete_draft'))) return
  try {
    await purchaseInvoicesApi.delete(invoice.value.id)
    toast.success('Smazáno')
    router.push('/purchase-invoices')
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

const statusBadgeClass = (s: PurchaseInvoiceStatus): string => ({
  draft: 'bg-neutral-100 text-neutral-700',
  received: 'bg-blue-100 text-blue-800',
  booked: 'bg-indigo-100 text-indigo-800',
  paid: 'bg-green-100 text-green-800',
  cancelled: 'bg-red-100 text-red-800',
}[s])

// Allowed transitions per status (sync s backend state machine)
const allowedTransitions = computed<PurchaseInvoiceStatus[]>(() => {
  if (!invoice.value) return []
  switch (invoice.value.status) {
    case 'draft':    return ['received', 'cancelled']
    case 'received': return ['booked', 'paid', 'cancelled']
    case 'booked':   return ['paid', 'cancelled']
    default:         return []
  }
})

const canEdit = computed(() => invoice.value?.status === 'draft')
const canDelete = computed(() => invoice.value?.status === 'draft')
</script>

<template>
  <div class="space-y-4 max-w-4xl mx-auto">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <RouterLink to="/purchase-invoices" class="text-sm text-neutral-600 hover:text-primary-700">
          {{ t('purchase_invoice.back_to_list') }}
        </RouterLink>
        <h1 class="text-xl font-semibold mt-1">{{ t('purchase_invoice.title_detail') }}</h1>
      </div>
      <span v-if="invoice" class="px-3 py-1 rounded-full text-sm" :class="statusBadgeClass(invoice.status)">
        {{ t(`purchase_invoice.status.${invoice.status}`) }}
      </span>
    </header>

    <div v-if="loading" class="text-center py-12 text-neutral-500">…</div>
    <div v-else-if="error" class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-md text-sm">{{ error }}</div>
    <template v-else-if="invoice">
      <!-- Hlavička: vendor + číslo dokladu + datumy -->
      <section class="bg-white border border-neutral-200 rounded-lg p-4 space-y-3">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
          <div>
            <div class="text-xs text-neutral-500">{{ t('purchase_invoice.fields.vendor') }}</div>
            <div class="text-lg font-medium">{{ invoice.vendor_company_name }}</div>
            <div class="text-xs text-neutral-500 font-mono">
              <span v-if="invoice.vendor_ic">{{ t('vendor.ic') }} {{ invoice.vendor_ic }}</span>
              <span v-if="invoice.vendor_dic" class="ml-2">{{ t('vendor.dic') }} {{ invoice.vendor_dic }}</span>
            </div>
          </div>
          <div class="text-right">
            <div class="text-xs text-neutral-500">{{ t('purchase_invoice.fields.vendor_invoice_number') }}</div>
            <div class="font-mono">{{ invoice.vendor_invoice_number }}</div>
            <div v-if="invoice.varsymbol" class="text-xs text-neutral-500 mt-0.5 font-mono">
              {{ t('purchase_invoice.fields.varsymbol') }}: {{ invoice.varsymbol }}
            </div>
          </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-sm pt-3 border-t border-neutral-100">
          <div>
            <div class="text-xs text-neutral-500">{{ t('purchase_invoice.fields.issue_date') }}</div>
            <div>{{ formatDate(invoice.issue_date) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500">{{ t('purchase_invoice.fields.tax_date') }}</div>
            <div>{{ invoice.tax_date ? formatDate(invoice.tax_date) : '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500">{{ t('purchase_invoice.fields.due_date') }}</div>
            <div>{{ formatDate(invoice.due_date) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500">{{ t('purchase_invoice.fields.received_at') }}</div>
            <div>{{ formatDate(invoice.received_at) }}</div>
          </div>
        </div>
      </section>

      <!-- Položky -->
      <section class="bg-white border border-neutral-200 rounded-lg overflow-hidden">
        <h2 class="text-base font-medium px-4 py-2 border-b border-neutral-100">{{ t('purchase_invoice.items.title') }}</h2>
        <table class="w-full text-sm">
          <thead>
            <tr class="text-xs text-neutral-500 border-b border-neutral-100">
              <th class="text-left py-2 px-4 font-normal">{{ t('purchase_invoice.items.description') }}</th>
              <th class="text-right py-2 px-2 font-normal">{{ t('purchase_invoice.items.quantity') }}</th>
              <th class="text-left py-2 px-2 font-normal">{{ t('purchase_invoice.items.unit') }}</th>
              <th class="text-right py-2 px-2 font-normal">{{ t('purchase_invoice.items.unit_price') }}</th>
              <th class="text-right py-2 px-2 font-normal">{{ t('purchase_invoice.items.vat_rate') }}</th>
              <th class="text-right py-2 px-4 font-normal">{{ t('purchase_invoice.items.total_with_vat') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="it in invoice.items" :key="it.id" class="border-b border-neutral-50">
              <td class="py-2 px-4">{{ it.description }}</td>
              <td class="py-2 px-2 text-right font-mono">{{ it.quantity }}</td>
              <td class="py-2 px-2">{{ it.unit }}</td>
              <td class="py-2 px-2 text-right font-mono">{{ formatMoney(it.unit_price_without_vat, invoice.currency) }}</td>
              <td class="py-2 px-2 text-right">{{ it.vat_rate_snapshot }}%</td>
              <td class="py-2 px-4 text-right font-mono">{{ formatMoney(it.total_with_vat, invoice.currency) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Totals + VAT breakdown -->
      <section class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="bg-white border border-neutral-200 rounded-lg p-4">
          <h3 class="font-medium mb-2">{{ t('purchase_invoice.vat_breakdown.title') }}</h3>
          <table class="w-full text-sm">
            <thead>
              <tr class="text-xs text-neutral-500">
                <th class="text-left py-1 font-normal">{{ t('purchase_invoice.vat_breakdown.rate') }}</th>
                <th class="text-right py-1 font-normal">{{ t('purchase_invoice.vat_breakdown.base') }}</th>
                <th class="text-right py-1 font-normal">{{ t('purchase_invoice.vat_breakdown.vat') }}</th>
                <th class="text-right py-1 font-normal">{{ t('purchase_invoice.vat_breakdown.with_vat') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in invoice.vat_breakdown" :key="b.vat_rate" class="border-t border-neutral-100">
                <td class="py-1">{{ b.vat_rate }}%</td>
                <td class="py-1 text-right font-mono">{{ formatMoney(b.without_vat, invoice.currency) }}</td>
                <td class="py-1 text-right font-mono">{{ formatMoney(b.vat, invoice.currency) }}</td>
                <td class="py-1 text-right font-mono">{{ formatMoney(b.with_vat, invoice.currency) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="bg-white border border-neutral-200 rounded-lg p-4">
          <h3 class="font-medium mb-2">{{ t('purchase_invoice.totals.with_vat') }}</h3>
          <table class="w-full text-sm">
            <tr><td class="text-neutral-600">{{ t('purchase_invoice.totals.without_vat') }}</td><td class="text-right font-mono">{{ formatMoney(invoice.total_without_vat, invoice.currency) }}</td></tr>
            <tr><td class="text-neutral-600">{{ t('purchase_invoice.totals.vat') }}</td><td class="text-right font-mono">{{ formatMoney(invoice.total_vat, invoice.currency) }}</td></tr>
            <tr class="font-medium border-t border-neutral-100"><td>{{ t('purchase_invoice.totals.with_vat') }}</td><td class="text-right font-mono">{{ formatMoney(invoice.total_with_vat, invoice.currency) }}</td></tr>
            <tr v-if="invoice.advance_paid_amount > 0"><td class="text-neutral-600">{{ t('purchase_invoice.totals.advance_paid') }}</td><td class="text-right font-mono">−{{ formatMoney(invoice.advance_paid_amount, invoice.currency) }}</td></tr>
            <tr class="font-medium border-t border-neutral-200"><td>{{ t('purchase_invoice.totals.to_pay') }}</td><td class="text-right font-mono">{{ formatMoney(invoice.amount_to_pay, invoice.currency) }}</td></tr>
          </table>
        </div>
      </section>

      <!-- PDF section -->
      <section class="bg-white border border-neutral-200 rounded-lg p-4">
        <h3 class="font-medium mb-2">{{ t('purchase_invoice.pdf.title') }}</h3>
        <div v-if="invoice.pdf_path" class="flex items-center justify-between">
          <div class="flex items-center gap-2 text-sm">
            <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20"><path d="M9 2a1 1 0 0 0 0 2h1v9a1 1 0 1 0 2 0V4h1a1 1 0 1 0 0-2H9z"/></svg>
            <div>
              <div class="font-medium">{{ invoice.pdf_original_name || 'invoice.pdf' }}</div>
              <div class="text-xs text-neutral-500">{{ Math.round((invoice.pdf_size_bytes ?? 0) / 1024) }} KiB</div>
            </div>
          </div>
          <a :href="purchaseInvoicesApi.pdfUrl(invoice.id)" target="_blank"
             class="px-3 py-1.5 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">
            {{ t('purchase_invoice.pdf.download') }}
          </a>
        </div>
        <p v-else class="text-sm text-neutral-500">{{ t('purchase_invoice.pdf.no_pdf') }}</p>
      </section>

      <!-- Actions -->
      <section class="flex flex-wrap gap-2 pt-4 border-t border-neutral-200">
        <RouterLink v-if="canEdit" :to="`/purchase-invoices/${invoice.id}/edit`"
                    class="px-3 py-1.5 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">
          {{ t('purchase_invoice.actions.edit') }}
        </RouterLink>
        <button v-for="target in allowedTransitions" :key="target" type="button"
                @click="transition(target)" :disabled="acting"
                class="cursor-pointer px-3 py-1.5 text-sm rounded-md disabled:opacity-50"
                :class="target === 'cancelled' ? 'border border-red-300 text-red-700 hover:bg-red-50' : 'bg-primary-600 text-white hover:bg-primary-700'">
          {{ t(`purchase_invoice.actions.mark_${target}`) }}
        </button>
        <button v-if="canDelete" type="button" @click="remove"
                class="cursor-pointer px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 rounded-md ml-auto">
          {{ t('purchase_invoice.actions.delete') }}
        </button>
      </section>
    </template>
  </div>
</template>
