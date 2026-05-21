<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { purchaseInvoicesApi } from '@/api/purchaseInvoices'

const { t } = useI18n()

type Format = 'pdf-zip' | 'pohoda' | 'isdoc'
type DateBy = 'tax' | 'issue' | 'received'

const format = ref<Format>('pdf-zip')
const dateBy = ref<DateBy>('tax')
const month = ref<string>((() => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
})())

function download() {
  // PDF ZIP je live, ostatní formáty zatím placeholder (fáze 5/6 plánu integrace forku).
  if (format.value === 'pdf-zip') {
    window.open(purchaseInvoicesApi.exportUrl(month.value, dateBy.value), '_blank')
    return
  }
  // Future: /api/purchase-invoices/export?format=pohoda|isdoc — implementace v fázi 5/6
  // (vyžaduje VAT klasifikační kódy + naše vlastní PurchaseInvoicePdfRenderer pro fallback).
}

const isComingSoon = computed(() => format.value !== 'pdf-zip')
</script>

<template>
  <div class="max-w-3xl space-y-4">
    <div>
      <h1 class="text-2xl font-semibold">{{ t('purchase_invoice.export.page_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('purchase_invoice.export.page_subtitle') }}</p>
    </div>

    <!-- Box: nastavení exportu -->
    <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
      <!-- Formát výběr -->
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-2">{{ t('purchase_invoice.export.format_label') }}</label>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
          <label
            v-for="opt in [
              { val: 'pdf-zip' as Format, label: t('purchase_invoice.export.fmt_pdf'),    hint: t('purchase_invoice.export.fmt_pdf_hint') },
              { val: 'pohoda' as Format,  label: t('purchase_invoice.export.fmt_pohoda'), hint: t('purchase_invoice.export.fmt_pohoda_hint') },
              { val: 'isdoc' as Format,   label: t('purchase_invoice.export.fmt_isdoc'),  hint: t('purchase_invoice.export.fmt_isdoc_hint') },
            ]"
            :key="opt.val"
            class="cursor-pointer block p-3 border rounded-md transition"
            :class="format === opt.val
              ? 'border-primary-500 bg-primary-50 ring-2 ring-primary-500/20'
              : 'border-neutral-200 hover:border-neutral-300 hover:bg-neutral-50'"
          >
            <input type="radio" :value="opt.val" v-model="format" class="sr-only" />
            <div class="flex items-center gap-2">
              <svg v-if="opt.val === 'pdf-zip'"  class="w-5 h-5 text-danger-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9 2a1 1 0 0 0 0 2h1v9a1 1 0 1 0 2 0V4h1a1 1 0 1 0 0-2H9z"/></svg>
              <svg v-else-if="opt.val === 'pohoda'" class="w-5 h-5 text-warning-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414"/></svg>
              <svg v-else class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2"/></svg>
              <span class="text-sm font-medium">{{ opt.label }}</span>
            </div>
            <p class="text-xs text-neutral-500 mt-1">{{ opt.hint }}</p>
            <span v-if="opt.val !== 'pdf-zip'" class="inline-block mt-1 text-[10px] uppercase tracking-wide bg-neutral-100 text-neutral-500 px-1.5 py-0.5 rounded">
              {{ t('purchase_invoice.export.coming_in_phase', { phase: opt.val === 'pohoda' ? '5' : '6' }) }}
            </span>
          </label>
        </div>
      </div>

      <!-- Měsíc -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.export.month_label') }}</label>
          <input v-model="month" type="month" required
                 class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.export.date_by_label') }}</label>
          <select v-model="dateBy" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm">
            <option value="tax">{{ t('purchase_invoice.fields.tax_date') }}</option>
            <option value="issue">{{ t('purchase_invoice.fields.issue_date') }}</option>
            <option value="received">{{ t('purchase_invoice.fields.received_at') }}</option>
          </select>
        </div>
      </div>

      <!-- Info pro PDF: priorita vendor PDF -->
      <div v-if="format === 'pdf-zip'" class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700">
        <strong>{{ t('purchase_invoice.export.pdf_priority_title') }}:</strong>
        {{ t('purchase_invoice.export.pdf_priority_hint') }}
      </div>

      <div v-else class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-600">
        <strong>{{ t('purchase_invoice.export.coming_soon_title') }}:</strong>
        {{ format === 'pohoda'
          ? t('purchase_invoice.export.pohoda_coming_hint')
          : t('purchase_invoice.export.isdoc_coming_hint') }}
      </div>

      <div class="flex items-center justify-end gap-2 pt-2">
        <button
          type="button"
          @click="download"
          :disabled="isComingSoon"
          class="cursor-pointer px-4 h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 disabled:cursor-not-allowed text-white text-sm font-medium rounded-md inline-flex items-center gap-2"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('purchase_invoice.export.download') }}
        </button>
      </div>
    </div>
  </div>
</template>
