<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  purchaseInvoicesApi,
  type PurchaseInvoice,
  type PurchaseInvoicePayload,
  type PurchaseInvoiceItem,
  type PurchaseDocumentKind,
  type ExchangeRateSource,
} from '@/api/purchaseInvoices'
import { codebooksApi, type VatRate, type Currency, type Unit } from '@/api/codebooks'
import { formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import VendorPicker from '@/components/purchase/VendorPicker.vue'
import PdfDropzone from '@/components/purchase/PdfDropzone.vue'
import PaymentCurrencyBlock from '@/components/purchase/PaymentCurrencyBlock.vue'
import ExchangeRateInput from '@/components/purchase/ExchangeRateInput.vue'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const toast = useToast()

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const invoiceId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const loaded = ref(false)
const submitting = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

const vatRates = ref<VatRate[]>([])
const currencies = ref<Currency[]>([])
const units = ref<Unit[]>([])

const today = new Date().toISOString().slice(0, 10)

const form = ref<{
  vendor_id: number | null
  vendor_invoice_number: string
  varsymbol: string
  document_kind: PurchaseDocumentKind
  issue_date: string
  tax_date: string
  due_date: string
  received_at: string
  currency_id: number | null
  exchange_rate: number | null
  exchange_rate_date: string
  exchange_rate_source: ExchangeRateSource
  reverse_charge: boolean
  language: 'cs' | 'en'
  note_above_items: string
  note_below_items: string
  advance_paid_amount: number
  payment_currency_id: number | null
  payment_exchange_rate: number | null
  paid_amount_payment_ccy: number | null
  paid_amount_invoice_ccy: number | null
  exchange_diff_base: number | null
  items: PurchaseInvoiceItem[]
}>({
  vendor_id: null,
  vendor_invoice_number: '',
  varsymbol: '',
  document_kind: 'invoice',
  issue_date: today,
  tax_date: today,
  due_date: today,
  received_at: today,
  currency_id: null,
  exchange_rate: null,
  exchange_rate_date: today,
  exchange_rate_source: 'cnb',
  reverse_charge: false,
  language: 'cs',
  note_above_items: '',
  note_below_items: '',
  advance_paid_amount: 0,
  payment_currency_id: null,
  payment_exchange_rate: null,
  paid_amount_payment_ccy: null,
  paid_amount_invoice_ccy: null,
  exchange_diff_base: null,
  items: [],
})

// PDF state
const existingPdf = ref<{ path: string; hash: string; size: number; name: string; uploadedAt: string } | null>(null)
const pdfUploading = ref(false)
const dropzoneVisible = ref(true)

// === Default vendor currency on selection ===
function onVendorSelected(v: any) {
  if (v && !isEdit.value) {
    // Pre-fill default currency from vendor.currency_default_id if available
    if (v.currency_default_id && form.value.currency_id === null) {
      form.value.currency_id = v.currency_default_id
    }
    if (v.language && !form.value.language) {
      form.value.language = v.language
    }
  }
}

const currencyCode = computed(() => {
  if (!form.value.currency_id) return ''
  return currencies.value.find(c => c.id === form.value.currency_id)?.code ?? ''
})

const showExchangeRate = computed(() => currencyCode.value && currencyCode.value !== 'CZK')

onMounted(async () => {
  await loadCodebooks()
  if (isEdit.value && invoiceId.value) {
    await loadInvoice(invoiceId.value)
  } else if (currencies.value.length > 0 && form.value.currency_id === null) {
    // Default na CZK měnu pokud existuje
    const czk = currencies.value.find(c => c.code === 'CZK')
    if (czk) form.value.currency_id = czk.id
  }
  loaded.value = true
})

async function loadCodebooks() {
  try {
    const [v, c, u] = await Promise.all([
      codebooksApi.vatRates(),
      codebooksApi.currencies(),
      codebooksApi.units(),
    ])
    vatRates.value = v
    currencies.value = c
    units.value = u
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function loadInvoice(id: number) {
  try {
    const inv = await purchaseInvoicesApi.get(id)
    populate(inv)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function populate(inv: PurchaseInvoice) {
  form.value.vendor_id = inv.vendor_id
  form.value.vendor_invoice_number = inv.vendor_invoice_number
  form.value.varsymbol = inv.varsymbol || ''
  form.value.document_kind = inv.document_kind
  form.value.issue_date = inv.issue_date
  form.value.tax_date = inv.tax_date || inv.issue_date
  form.value.due_date = inv.due_date
  form.value.received_at = inv.received_at
  form.value.currency_id = inv.currency_id
  form.value.exchange_rate = inv.exchange_rate
  form.value.exchange_rate_date = inv.exchange_rate_date || inv.issue_date
  form.value.exchange_rate_source = inv.exchange_rate_source
  form.value.reverse_charge = inv.reverse_charge
  form.value.language = inv.language
  form.value.note_above_items = inv.note_above_items || ''
  form.value.note_below_items = inv.note_below_items || ''
  form.value.advance_paid_amount = inv.advance_paid_amount
  form.value.payment_currency_id = inv.payment_currency_id
  form.value.payment_exchange_rate = inv.payment_exchange_rate
  form.value.paid_amount_payment_ccy = inv.paid_amount_payment_ccy
  form.value.paid_amount_invoice_ccy = inv.paid_amount_invoice_ccy
  form.value.exchange_diff_base = inv.exchange_diff_base
  form.value.items = inv.items.length > 0 ? inv.items : []

  if (inv.pdf_path) {
    existingPdf.value = {
      path: inv.pdf_path,
      hash: inv.pdf_hash || '',
      size: inv.pdf_size_bytes || 0,
      name: inv.pdf_original_name || 'invoice.pdf',
      uploadedAt: inv.pdf_uploaded_at || '',
    }
    dropzoneVisible.value = false
  }
}

function addItem() {
  form.value.items.push({
    description: '',
    quantity: 1,
    unit: units.value.find(u => u.is_default)?.code || 'ks',
    unit_price_without_vat: 0,
    vat_rate_id: vatRates.value.find(v => v.is_default)?.id || vatRates.value[0]?.id || 1,
    order_index: form.value.items.length,
  })
  // user začal editovat → schovej dropzone, ať se nepřeplňuje
  dropzoneVisible.value = false
}

function removeItem(idx: number) {
  form.value.items.splice(idx, 1)
}

// Per-item live calc preview (read-only, server přepočte při save)
function itemTotal(it: PurchaseInvoiceItem) {
  const base = Number(it.quantity || 0) * Number(it.unit_price_without_vat || 0)
  const rate = form.value.reverse_charge ? 0 : (vatRates.value.find(v => v.id === it.vat_rate_id)?.rate_percent || 0)
  const vat = base * rate / 100
  return { base: round2(base), vat: round2(vat), with: round2(base + vat) }
}
function round2(n: number) { return Math.round(n * 100) / 100 }

const totals = computed(() => {
  let base = 0, vat = 0
  for (const it of form.value.items) {
    const t = itemTotal(it)
    base += t.base; vat += t.vat
  }
  return { without_vat: round2(base), vat: round2(vat), with_vat: round2(base + vat) }
})

async function onPdfDropped(file: File) {
  // Pokud editujeme existující fakturu, upload rovnou.
  // Pro novou fakturu si soubor podržíme a uploadneme po prvním uložení (pro získání ID).
  if (isEdit.value && invoiceId.value) {
    await uploadPdfToInvoice(invoiceId.value, file)
  } else {
    pendingPdfFile.value = file
    toast.success('PDF bude nahráno po uložení faktury')
    // V tomto MVP nepokoušíme se extrahovat ISDOC client-side — bude až po save.
  }
}

const pendingPdfFile = ref<File | null>(null)

async function uploadPdfToInvoice(id: number, file: File) {
  pdfUploading.value = true
  try {
    const result = await purchaseInvoicesApi.uploadPdf(id, file)
    existingPdf.value = {
      path: result.pdf_path,
      hash: result.pdf_hash,
      size: result.pdf_size_bytes,
      name: result.pdf_original_name,
      uploadedAt: new Date().toISOString(),
    }
    dropzoneVisible.value = false
    toast.success('PDF nahráno')
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    pdfUploading.value = false
  }
}

function onPdfError(_code: string, message: string) {
  toast.error(message)
}

async function submit() {
  if (submitting.value) return
  submitting.value = true
  error.value = ''
  fieldErrors.value = {}
  try {
    const payload: PurchaseInvoicePayload = {
      vendor_id: form.value.vendor_id!,
      vendor_invoice_number: form.value.vendor_invoice_number,
      varsymbol: form.value.varsymbol || null,
      document_kind: form.value.document_kind,
      issue_date: form.value.issue_date,
      tax_date: form.value.tax_date || null,
      due_date: form.value.due_date,
      received_at: form.value.received_at,
      currency_id: form.value.currency_id!,
      exchange_rate: form.value.exchange_rate,
      exchange_rate_date: form.value.exchange_rate_date || null,
      exchange_rate_source: form.value.exchange_rate_source,
      reverse_charge: form.value.reverse_charge,
      language: form.value.language,
      note_above_items: form.value.note_above_items || null,
      note_below_items: form.value.note_below_items || null,
      advance_paid_amount: form.value.advance_paid_amount,
      payment_currency_id: form.value.payment_currency_id,
      payment_exchange_rate: form.value.payment_exchange_rate,
      paid_amount_payment_ccy: form.value.paid_amount_payment_ccy,
      paid_amount_invoice_ccy: form.value.paid_amount_invoice_ccy,
      exchange_diff_base: form.value.exchange_diff_base,
      items: form.value.items.map((it, i) => ({
        description: it.description,
        quantity: Number(it.quantity || 0),
        unit: it.unit,
        unit_price_without_vat: Number(it.unit_price_without_vat || 0),
        vat_rate_id: it.vat_rate_id,
        order_index: i,
        vat_classification_code: it.vat_classification_code,
      })),
    }
    let inv: PurchaseInvoice
    if (isEdit.value && invoiceId.value) {
      inv = await purchaseInvoicesApi.update(invoiceId.value, payload)
    } else {
      inv = await purchaseInvoicesApi.create(payload)
    }
    // Upload pending PDF pokud byl drop před save
    if (pendingPdfFile.value) {
      await uploadPdfToInvoice(inv.id, pendingPdfFile.value)
      pendingPdfFile.value = null
    }
    toast.success(isEdit.value ? 'Uloženo' : 'Vytvořeno')
    router.push(`/purchase-invoices/${inv.id}`)
  } catch (e: any) {
    const data = e?.response?.data?.error
    if (data?.fields) {
      fieldErrors.value = data.fields
    }
    error.value = apiErrorMessage(e)
  } finally {
    submitting.value = false
  }
}

function fieldErr(key: string): string | null {
  const errs = fieldErrors.value[key]
  return errs?.length ? errs[0] : null
}
</script>

<template>
  <div class="space-y-4 max-w-4xl mx-auto">
    <header class="flex items-center justify-between">
      <h1 class="text-xl font-semibold">
        {{ isEdit ? t('purchase_invoice.title_edit') : t('purchase_invoice.title_new') }}
      </h1>
      <RouterLink to="/purchase-invoices" class="text-sm text-neutral-600 hover:text-primary-700">
        {{ t('purchase_invoice.back_to_list') }}
      </RouterLink>
    </header>

    <div v-if="error" class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-md text-sm">
      {{ error }}
    </div>
    <div v-if="!loaded" class="text-center py-12 text-neutral-500">…</div>

    <form v-else @submit.prevent="submit" class="space-y-5">
      <!-- DRAG & DROP PDF (jen nahoře u nové faktury, schovaný po prvním interaction) -->
      <section v-if="!isEdit && dropzoneVisible">
        <PdfDropzone :uploading="pdfUploading" @file-dropped="onPdfDropped" @error="onPdfError" />
        <p class="text-xs text-neutral-500 mt-2">
          {{ t('purchase_invoice.extraction.ai_pending') }}
        </p>
      </section>

      <!-- Existující PDF na detail/edit -->
      <section v-if="existingPdf" class="p-3 border border-neutral-200 rounded-md bg-neutral-50 text-sm flex items-center justify-between">
        <div class="flex items-center gap-2">
          <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20"><path d="M9 2a1 1 0 0 0 0 2h1v9a1 1 0 1 0 2 0V4h1a1 1 0 1 0 0-2H9z"/></svg>
          <div>
            <div class="font-medium">{{ existingPdf.name }}</div>
            <div class="text-xs text-neutral-500">{{ Math.round(existingPdf.size / 1024) }} KiB</div>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <a
            v-if="invoiceId"
            :href="purchaseInvoicesApi.pdfUrl(invoiceId)"
            target="_blank"
            class="px-3 py-1.5 text-xs border border-neutral-300 rounded-md hover:bg-white"
          >
            {{ t('purchase_invoice.pdf.open') }}
          </a>
          <button
            type="button"
            @click="dropzoneVisible = true; existingPdf = null"
            class="cursor-pointer px-3 py-1.5 text-xs border border-neutral-300 rounded-md hover:bg-white"
          >
            {{ t('purchase_invoice.pdf.replace') }}
          </button>
        </div>
      </section>

      <!-- Replace dropzone když user vybere replace -->
      <section v-else-if="isEdit && dropzoneVisible">
        <PdfDropzone :uploading="pdfUploading" @file-dropped="onPdfDropped" @error="onPdfError" />
      </section>

      <!-- Vendor + document kind -->
      <section class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <VendorPicker
          v-model="form.vendor_id"
          @selected="onVendorSelected"
        />
        <div>
          <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.document_kind') }}</label>
          <select v-model="form.document_kind" class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm">
            <option value="invoice">{{ t('purchase_invoice.document_kind.invoice') }}</option>
            <option value="receipt">{{ t('purchase_invoice.document_kind.receipt') }}</option>
            <option value="credit_note">{{ t('purchase_invoice.document_kind.credit_note') }}</option>
            <option value="advance">{{ t('purchase_invoice.document_kind.advance') }}</option>
          </select>
        </div>
      </section>

      <!-- Vendor invoice number + our varsymbol -->
      <section class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.vendor_invoice_number') }} *</label>
          <input v-model="form.vendor_invoice_number" type="text" maxlength="50" required
                 class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm font-mono"
                 :class="fieldErr('vendor_invoice_number') ? 'border-red-300' : ''" />
          <p class="text-xs text-neutral-500 mt-0.5">{{ t('purchase_invoice.fields.vendor_invoice_number_hint') }}</p>
          <p v-if="fieldErr('vendor_invoice_number')" class="text-xs text-red-600">{{ fieldErr('vendor_invoice_number') }}</p>
        </div>
        <div>
          <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.varsymbol') }}</label>
          <input v-model="form.varsymbol" type="text" maxlength="20"
                 class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm font-mono"
                 placeholder="PF-202605-NNNN" />
          <p class="text-xs text-neutral-500 mt-0.5">{{ t('purchase_invoice.fields.varsymbol_hint') }}</p>
        </div>
      </section>

      <!-- Dates -->
      <section class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div>
          <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.issue_date') }} *</label>
          <input v-model="form.issue_date" type="date" required class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.tax_date') }}</label>
          <input v-model="form.tax_date" type="date" class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.due_date') }} *</label>
          <input v-model="form.due_date" type="date" required class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.received_at') }}</label>
          <input v-model="form.received_at" type="date" class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm" />
        </div>
      </section>

      <!-- Currency + exchange rate -->
      <section class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.currency') }} *</label>
          <select v-model="form.currency_id" required class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm">
            <option :value="null">—</option>
            <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }} — {{ c.label }}</option>
          </select>
        </div>
        <ExchangeRateInput
          v-if="showExchangeRate"
          v-model="form.exchange_rate"
          :currency="currencyCode"
          :rate-date="form.tax_date || form.issue_date"
          @cnb-loaded="(v) => { form.exchange_rate_date = v.rate_date; form.exchange_rate_source = 'cnb' }"
          @source-change="(s) => form.exchange_rate_source = s"
        />
      </section>

      <!-- Reverse charge + language -->
      <section class="flex flex-wrap items-center gap-4">
        <label class="inline-flex items-center gap-1.5 text-sm">
          <input type="checkbox" v-model="form.reverse_charge" />
          {{ t('purchase_invoice.fields.reverse_charge') }}
        </label>
        <div>
          <label class="text-sm text-neutral-700 mr-2">{{ t('purchase_invoice.fields.language') }}:</label>
          <select v-model="form.language" class="px-2 py-1 border border-neutral-300 rounded-md text-sm">
            <option value="cs">CS</option>
            <option value="en">EN</option>
          </select>
        </div>
      </section>

      <!-- Items -->
      <section>
        <header class="flex items-center justify-between mb-2">
          <h2 class="text-base font-medium">{{ t('purchase_invoice.items.title') }}</h2>
          <button type="button" @click="addItem" class="cursor-pointer text-sm text-primary-700 hover:text-primary-800">
            {{ t('purchase_invoice.items.add') }}
          </button>
        </header>
        <div v-if="form.items.length === 0" class="text-sm text-neutral-500 py-4 text-center bg-neutral-50 rounded">
          {{ t('purchase_invoice.items.empty') }}
        </div>
        <table v-else class="w-full text-sm border-collapse">
          <thead>
            <tr class="text-xs text-neutral-500">
              <th class="text-left py-1 pr-2 font-normal">{{ t('purchase_invoice.items.description') }}</th>
              <th class="text-right py-1 px-1 font-normal w-20">{{ t('purchase_invoice.items.quantity') }}</th>
              <th class="text-left py-1 px-1 font-normal w-20">{{ t('purchase_invoice.items.unit') }}</th>
              <th class="text-right py-1 px-1 font-normal w-28">{{ t('purchase_invoice.items.unit_price') }}</th>
              <th class="text-left py-1 px-1 font-normal w-24">{{ t('purchase_invoice.items.vat_rate') }}</th>
              <th class="text-right py-1 px-1 font-normal w-24">{{ t('purchase_invoice.items.total_with_vat') }}</th>
              <th class="w-8"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(it, i) in form.items" :key="i">
              <td class="py-1 pr-2">
                <input v-model="it.description" type="text" class="w-full px-2 py-1 border border-neutral-300 rounded text-sm" />
              </td>
              <td class="py-1 px-1">
                <input v-model.number="it.quantity" type="number" step="0.001" min="0" class="w-full px-2 py-1 border border-neutral-300 rounded text-sm text-right font-mono" />
              </td>
              <td class="py-1 px-1">
                <select v-model="it.unit" class="w-full px-1 py-1 border border-neutral-300 rounded text-sm">
                  <option v-for="u in units" :key="u.code" :value="u.code">{{ u.code }}</option>
                </select>
              </td>
              <td class="py-1 px-1">
                <input v-model.number="it.unit_price_without_vat" type="number" step="0.01" class="w-full px-2 py-1 border border-neutral-300 rounded text-sm text-right font-mono" />
              </td>
              <td class="py-1 px-1">
                <select v-model.number="it.vat_rate_id" class="w-full px-1 py-1 border border-neutral-300 rounded text-sm">
                  <option v-for="v in vatRates" :key="v.id" :value="v.id">{{ v.rate_percent }}%</option>
                </select>
              </td>
              <td class="py-1 px-1 text-right font-mono">{{ formatMoney(itemTotal(it).with, currencyCode) }}</td>
              <td class="py-1 px-1">
                <button type="button" @click="removeItem(i)" class="cursor-pointer text-neutral-400 hover:text-red-600" :title="t('purchase_invoice.items.remove')">✕</button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Totals preview -->
      <section v-if="form.items.length > 0" class="flex justify-end">
        <table class="text-sm">
          <tr><td class="pr-4 text-neutral-600">{{ t('purchase_invoice.totals.without_vat') }}:</td><td class="text-right font-mono">{{ formatMoney(totals.without_vat, currencyCode) }}</td></tr>
          <tr><td class="pr-4 text-neutral-600">{{ t('purchase_invoice.totals.vat') }}:</td><td class="text-right font-mono">{{ formatMoney(totals.vat, currencyCode) }}</td></tr>
          <tr class="font-medium border-t border-neutral-200"><td class="pr-4">{{ t('purchase_invoice.totals.with_vat') }}:</td><td class="text-right font-mono">{{ formatMoney(totals.with_vat, currencyCode) }}</td></tr>
        </table>
      </section>

      <!-- Payment currency block -->
      <section v-if="form.currency_id">
        <PaymentCurrencyBlock
          :invoice-currency-id="form.currency_id"
          :invoice-currency="currencyCode"
          :total-with-vat="totals.with_vat"
          :currencies="currencies"
          :invoice-exchange-rate="form.exchange_rate"
          :payment-currency-id="form.payment_currency_id"
          :payment-exchange-rate="form.payment_exchange_rate"
          :paid-amount-payment-ccy="form.paid_amount_payment_ccy"
          :paid-amount-invoice-ccy="form.paid_amount_invoice_ccy"
          :exchange-diff-base="form.exchange_diff_base"
          @update:payment-currency-id="(v) => form.payment_currency_id = v"
          @update:payment-exchange-rate="(v) => form.payment_exchange_rate = v"
          @update:paid-amount-payment-ccy="(v) => form.paid_amount_payment_ccy = v"
          @update:paid-amount-invoice-ccy="(v) => form.paid_amount_invoice_ccy = v"
          @update:exchange-diff-base="(v) => form.exchange_diff_base = v"
        />
      </section>

      <!-- Notes -->
      <section class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.note_above_items') }}</label>
          <textarea v-model="form.note_above_items" rows="2" class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm"></textarea>
        </div>
        <div>
          <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.note_below_items') }}</label>
          <textarea v-model="form.note_below_items" rows="2" class="w-full px-3 py-1.5 border border-neutral-300 rounded-md text-sm"></textarea>
        </div>
      </section>

      <!-- Submit -->
      <div class="flex items-center justify-end gap-2 pt-4 border-t border-neutral-200">
        <RouterLink to="/purchase-invoices" class="px-4 py-1.5 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">
          {{ t('purchase_invoice.actions.back') }}
        </RouterLink>
        <button type="submit" :disabled="submitting" class="cursor-pointer px-4 py-1.5 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md disabled:opacity-50">
          {{ submitting ? '…' : t('purchase_invoice.actions.save') }}
        </button>
      </div>
    </form>
  </div>
</template>
