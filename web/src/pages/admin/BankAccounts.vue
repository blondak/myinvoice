<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import {
  settingsApi,
  type BankEmailAccountMapping,
  type BankEmailImapSettings,
  type BankEmailProcessedMessage,
  type BankEmailProvider,
  type CurrencyAccount,
} from '@/api/settings'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const currencies = ref<CurrencyAccount[]>([])
const mappings = ref<BankEmailAccountMapping[]>([])
const providers = ref<BankEmailProvider[]>([])
const imapAccounts = ref<BankEmailImapSettings[]>([])
const messages = ref<BankEmailProcessedMessage[]>([])
const loading = ref(false)
const saving = ref(false)
const testingAccountId = ref<number | null>(null)
const browsingFolders = ref(false)
const scanning = ref(false)
const editingCurrency = ref<number | null>(null)
const editingCurrencyLabel = ref('')
const currencyFormOpen = ref(false)
const imapFormOpen = ref(false)
const editingImapId = ref<number | null>(null)
const providerFormOpen = ref(false)
const parserText = ref('')
const parserSender = ref('info@rb.cz')
const parserSubject = ref('Pohyb na účtě')
const parserProviderId = ref<number | null>(null)
const parserResult = ref<Record<string, any> | null>(null)
const scanSummary = ref<Record<string, any> | null>(null)
const bankEmailLoadError = ref<string | null>(null)
const folderOptions = ref<string[]>([])

const currencyDraft = reactive<Partial<CurrencyAccount>>({})
const imapDraft = reactive<Partial<BankEmailImapSettings> & { password?: string }>(defaultImapDraft())
const regexFieldDefinitions = [
  { key: 'variable_symbol', label: 'Variabilní symbol', required: true },
  { key: 'amount', label: 'Částka', required: true },
  { key: 'currency', label: 'Měna', required: true },
  { key: 'posted_at', label: 'Datum platby', required: true },
  { key: 'recipient_account', label: 'Cílový účet', required: true },
  { key: 'counterparty_account', label: 'Protiúčet', required: false },
  { key: 'counterparty_name', label: 'Název protistrany', required: false },
  { key: 'constant_symbol', label: 'Konstantní symbol', required: false },
  { key: 'message', label: 'Zpráva', required: false },
  { key: 'bank_ref', label: 'Reference banky', required: false },
] as const
type RegexFieldKey = typeof regexFieldDefinitions[number]['key']
interface RegexProviderDraft {
  id: number | null
  name: string
  code: string
  enabled: boolean
  sender_whitelist: string
  subject_pattern: string
  body_pattern: string
  field_patterns: Record<RegexFieldKey, string>
  normalizer_config_json: string
}
const providerDraft = reactive<RegexProviderDraft>(defaultRegexProviderDraft())
const availableCurrencyCodes = computed(() => {
  const codes = new Set(['CZK', 'EUR', 'USD', 'GBP'])
  for (const currency of currencies.value) codes.add(currency.code)
  return [...codes].sort()
})

function defaultImapDraft(): Partial<BankEmailImapSettings> & { password?: string } {
  return {
    id: null,
    name: '',
    enabled: true,
    host: '',
    port: 993,
    encryption: 'ssl',
    validate_cert: true,
    username: '',
    password: '',
    folder: 'INBOX',
    max_messages_per_run: 50,
    process_from_date: null,
    success_action: 'none',
    success_flag: 'MyInvoiceProcessed',
    success_move_folder: '',
    failure_action: 'none',
    failure_flag: 'MyInvoiceFailed',
    failure_move_folder: '',
    retry_failed: false,
    max_attempts: 3,
  }
}

function defaultFieldPatterns(): Record<RegexFieldKey, string> {
  return regexFieldDefinitions.reduce((acc, field) => {
    acc[field.key] = ''
    return acc
  }, {} as Record<RegexFieldKey, string>)
}

function defaultRegexProviderDraft(): RegexProviderDraft {
  return {
    id: null,
    name: '',
    code: '',
    enabled: true,
    sender_whitelist: '',
    subject_pattern: '',
    body_pattern: '',
    field_patterns: defaultFieldPatterns(),
    normalizer_config_json: '{}',
  }
}

async function load() {
  loading.value = true
  try {
    bankEmailLoadError.value = null
    const [currenciesResult, overviewResult] = await Promise.allSettled([
      settingsApi.listCurrencies(),
      settingsApi.getBankEmailOverview(),
    ])
    if (currenciesResult.status === 'fulfilled') {
      currencies.value = currenciesResult.value
    } else {
      toast.error(apiErrorMessage(currenciesResult.reason, 'Bankovní účty se nepodařilo načíst.'))
    }
    if (overviewResult.status === 'fulfilled') {
      const overview = overviewResult.value
      mappings.value = overview.mappings.map(normalizeMappingForUi)
      providers.value = overview.providers
      imapAccounts.value = overview.imap_accounts ?? (overview.imap?.id ? [overview.imap] : [])
      messages.value = overview.messages
    } else {
      bankEmailLoadError.value = apiErrorMessage(overviewResult.reason, 'Konfiguraci bankovních avíz se nepodařilo načíst.')
      mappings.value = []
      providers.value = []
      imapAccounts.value = []
      messages.value = []
    }
  } finally {
    loading.value = false
  }
}

onMounted(load)

function startEditCurrency(c: CurrencyAccount) {
  editingCurrency.value = c.id
  editingCurrencyLabel.value = c.label
  currencyFormOpen.value = true
  Object.assign(currencyDraft, { ...c })
}

async function saveCurrency() {
  const payload: Partial<CurrencyAccount> = {
    label: currencyDraft.label,
    is_active: currencyDraft.is_active,
    is_default: currencyDraft.is_default,
    account_number: currencyDraft.account_number || null,
    bank_code: currencyDraft.bank_code || null,
    bank_name: currencyDraft.bank_name || null,
    iban: currencyDraft.iban || null,
    bic: currencyDraft.bic || null,
  }
  if (editingCurrency.value !== null) {
    await settingsApi.updateCurrency(editingCurrency.value, payload)
    toast.success('Bankovní účet uložen.')
  } else {
    await settingsApi.createCurrency({ ...payload, code: currencyDraft.code })
    toast.success('Bankovní účet přidán.')
  }
  closeCurrencyForm()
  await load()
}

function startNewCurrencyAccount(code = 'CZK') {
  const isFirstForCode = !currencies.value.some(c => c.code === code)
  Object.assign(currencyDraft, {
    code,
    label: `${code} - bankovní účet`,
    is_active: true,
    is_default: isFirstForCode,
    account_number: null,
    bank_code: null,
    bank_name: null,
    iban: null,
    bic: null,
  })
  editingCurrency.value = null
  editingCurrencyLabel.value = ''
  currencyFormOpen.value = true
}

function applyNewCurrencyCode() {
  const code = String(currencyDraft.code || 'CZK').toUpperCase()
  currencyDraft.code = code
  if (!currencyDraft.label || /^[A-Z]{3} - bankovní účet$/.test(String(currencyDraft.label))) {
    currencyDraft.label = `${code} - bankovní účet`
  }
  currencyDraft.is_default = !currencies.value.some(c => c.code === code)
}

function closeCurrencyForm() {
  editingCurrency.value = null
  editingCurrencyLabel.value = ''
  currencyFormOpen.value = false
  Object.keys(currencyDraft).forEach(key => delete currencyDraft[key as keyof CurrencyAccount])
}

async function removeCurrency(c: CurrencyAccount) {
  if (!window.confirm(`Smazat účet ${c.label}?`)) return
  await settingsApi.deleteCurrency(c.id)
  toast.success('Bankovní účet smazán.')
  await load()
}

async function saveMappings() {
  await settingsApi.updateBankEmailMappings(mappings.value.map(m => ({
    currency_id: m.currency_id,
    imap_account_id: m.imap_account_id === 0 ? null : m.imap_account_id,
    provider_id: m.provider_id,
    enabled: m.imap_account_id === 0 ? false : m.enabled,
    amount_tolerance: m.amount_tolerance,
  })))
  toast.success('Mapování bankovních avíz uloženo.')
  await load()
}

function normalizeMappingForUi(mapping: BankEmailAccountMapping): BankEmailAccountMapping {
  if (mapping.imap_account_id === null && !mapping.enabled) {
    return { ...mapping, imap_account_id: 0 }
  }
  return mapping
}

function onMappingImapChange(mapping: BankEmailAccountMapping, value: string) {
  if (value === '0') {
    mapping.enabled = false
  }
}

function startNewImapAccount() {
  Object.assign(imapDraft, defaultImapDraft(), { name: 'Nový IMAP účet' })
  editingImapId.value = null
  folderOptions.value = []
  imapFormOpen.value = true
}

function startEditImapAccount(account: BankEmailImapSettings) {
  Object.assign(imapDraft, { ...account, password: '' })
  editingImapId.value = account.id
  folderOptions.value = []
  imapFormOpen.value = true
}

function closeImapForm() {
  Object.assign(imapDraft, defaultImapDraft())
  editingImapId.value = null
  folderOptions.value = []
  imapFormOpen.value = false
}

async function saveImapAccount() {
  saving.value = true
  try {
    if (editingImapId.value !== null) {
      await settingsApi.updateBankEmailImapAccount(editingImapId.value, imapDraft)
      toast.success('IMAP účet uložen.')
    } else {
      await settingsApi.createBankEmailImapAccount(imapDraft)
      toast.success('IMAP účet vytvořen.')
    }
    closeImapForm()
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, 'IMAP účet se nepodařilo uložit.'))
  } finally {
    saving.value = false
  }
}

async function testImapAccount(account: BankEmailImapSettings) {
  if (account.id === null) return
  testingAccountId.value = account.id
  try {
    const r = await settingsApi.testBankEmailImapAccount(account.id)
    toast.success(`${account.name}: ${r.message}`)
  } catch (e) {
    toast.error(apiErrorMessage(e, 'Test IMAP připojení selhal.'))
  } finally {
    testingAccountId.value = null
  }
}

async function browseImapFolders() {
  browsingFolders.value = true
  folderOptions.value = []
  try {
    const result = await settingsApi.browseBankEmailImapFolders(imapDraft, editingImapId.value)
    folderOptions.value = result.folders ?? []
    if (folderOptions.value.length > 0) {
      toast.success(`Načteno složek: ${folderOptions.value.length}.`)
    } else {
      toast.info('Připojení funguje, ale server nevrátil žádné složky.')
    }
  } catch (e) {
    toast.error(apiErrorMessage(e, 'Složky se nepodařilo načíst.'))
  } finally {
    browsingFolders.value = false
  }
}

function selectImapFolder(folder: string) {
  imapDraft.folder = folder
  folderOptions.value = []
}

async function deleteImapAccount(account: BankEmailImapSettings) {
  if (account.id === null) return
  if (!window.confirm(`Smazat IMAP účet ${account.name}? Zpracované záznamy zůstanou zachované.`)) return
  await settingsApi.deleteBankEmailImapAccount(account.id)
  toast.success('IMAP účet smazán.')
  await load()
}

async function runScan() {
  scanning.value = true
  try {
    scanSummary.value = await settingsApi.scanBankEmailNotices()
    toast.success('Scan bankovních avíz dokončen.')
    messages.value = await settingsApi.listBankEmailMessages()
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, 'Scan selhal.'))
  } finally {
    scanning.value = false
  }
}

function providerOwnerLabel(provider: BankEmailProvider): string {
  return provider.supplier_id === null ? 'Systémový' : 'Dodavatel'
}

function parserTypeLabel(parserType: BankEmailProvider['parser_type']): string {
  if (parserType === 'raiffeisenbank') return 'Raiffeisenbank'
  return 'Regex'
}

async function testParser() {
  parserResult.value = null
  try {
    const r = await settingsApi.testBankEmailParser({
      provider_id: parserProviderId.value,
      sender: parserSender.value,
      subject: parserSubject.value,
      text: parserText.value,
    })
    parserResult.value = r.parsed
    toast.success(`Parser: ${r.provider.name}`)
  } catch (e) {
    toast.error(apiErrorMessage(e, 'Parser nenašel platební údaje.'))
  }
}

function providerCodeFromName(name: string): string {
  return name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'provider'
}

function startNewRegexProvider() {
  Object.assign(providerDraft, defaultRegexProviderDraft())
  providerFormOpen.value = true
}

function startEditProvider(provider: BankEmailProvider) {
  if (provider.supplier_id === null || provider.parser_type !== 'regex') return
  const patterns = defaultFieldPatterns()
  for (const field of regexFieldDefinitions) {
    patterns[field.key] = String(provider.field_patterns?.[field.key] ?? '')
  }
  Object.assign(providerDraft, {
    id: provider.id,
    name: provider.name,
    code: provider.code,
    enabled: provider.enabled,
    sender_whitelist: provider.sender_whitelist ?? '',
    subject_pattern: provider.subject_pattern ?? '',
    body_pattern: provider.body_pattern ?? '',
    field_patterns: patterns,
    normalizer_config_json: JSON.stringify(provider.normalizer_config ?? {}, null, 2),
  })
  providerFormOpen.value = true
}

function closeProviderForm() {
  Object.assign(providerDraft, defaultRegexProviderDraft())
  providerFormOpen.value = false
}

function syncProviderCode() {
  if (providerDraft.id !== null || providerDraft.code.trim() !== '') return
  providerDraft.code = providerCodeFromName(providerDraft.name)
}

async function saveProvider() {
  let normalizerConfig: Record<string, unknown>
  try {
    normalizerConfig = JSON.parse(providerDraft.normalizer_config_json || '{}')
  } catch {
    toast.error('Normalizer config musí být validní JSON objekt.')
    return
  }
  if (normalizerConfig === null || Array.isArray(normalizerConfig) || typeof normalizerConfig !== 'object') {
    toast.error('Normalizer config musí být validní JSON objekt.')
    return
  }

  const fieldPatterns: Record<string, string> = {}
  for (const field of regexFieldDefinitions) {
    const value = providerDraft.field_patterns[field.key].trim()
    if (value !== '') {
      fieldPatterns[field.key] = value
    }
  }

  const payload: Partial<BankEmailProvider> = {
    name: providerDraft.name.trim(),
    code: providerDraft.code.trim() || providerCodeFromName(providerDraft.name),
    parser_type: 'regex',
    enabled: providerDraft.enabled,
    sender_whitelist: providerDraft.sender_whitelist.trim() || null,
    subject_pattern: providerDraft.subject_pattern.trim() || null,
    body_pattern: providerDraft.body_pattern.trim() || null,
    field_patterns: fieldPatterns,
    normalizer_config: normalizerConfig,
  }

  try {
    if (providerDraft.id !== null) {
      await settingsApi.updateBankEmailProvider(providerDraft.id, payload)
      toast.success('Provider uložen.')
    } else {
      await settingsApi.createBankEmailProvider(payload)
      toast.success('Provider vytvořen.')
    }
    closeProviderForm()
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, 'Provider se nepodařilo uložit.'))
  }
}

async function removeProvider(provider: BankEmailProvider) {
  if (provider.supplier_id === null) return
  if (!window.confirm(`Smazat provider ${provider.name}?`)) return
  await settingsApi.deleteBankEmailProvider(provider.id)
  toast.success('Provider smazán.')
  await load()
}

async function deleteMessage(m: BankEmailProcessedMessage) {
  if (!window.confirm(`Smazat záznam zpracování #${m.id}? Transakce ani faktura se tím nesmažou.`)) return
  await settingsApi.deleteBankEmailMessage(m.id)
  toast.success('Záznam zpracování smazán.')
  messages.value = await settingsApi.listBankEmailMessages()
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">Bankovní účty</h1>
      <p class="text-sm text-neutral-500 mt-0.5">Měny, bankovní účty dodavatele, IMAP polling a mapování bankovních e-mailových avíz.</p>
    </div>

    <div v-if="loading" class="text-sm text-neutral-500">Načítám…</div>

    <div v-else class="space-y-5">
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
          <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">Měny + bankovní účty</h2>
            <p class="text-xs text-neutral-500 mt-0.5">Bankovní účty dodavatele používané v dokladech a bankovních výpisech.</p>
          </div>
          <button type="button" @click="startNewCurrencyAccount()"
            class="cursor-pointer h-9 px-3 bg-surface border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
            Nový bankovní účet
          </button>
        </header>

        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">Měna</th>
                <th class="px-3 py-2 text-left font-medium">Účet</th>
                <th class="px-3 py-2 text-left font-medium">Účet CZ</th>
                <th class="px-3 py-2 text-left font-medium">IBAN</th>
                <th class="px-3 py-2 text-left font-medium">BIC</th>
                <th class="px-3 py-2 text-center font-medium">Výchozí</th>
                <th class="px-3 py-2 text-center font-medium">Aktivní</th>
                <th class="px-3 py-2 text-right"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-if="currencies.length === 0">
                <td colspan="8" class="px-3 py-4 text-sm text-neutral-500">
                  Pro aktuálního dodavatele nejsou evidované žádné bankovní účty.
                </td>
              </tr>
              <tr v-for="c in currencies" :key="c.id">
                <td class="px-3 py-2 font-mono">{{ c.code }} <span class="text-xs text-neutral-500">{{ c.symbol }}</span></td>
                <td class="px-3 py-2">{{ c.label }}</td>
                <td class="px-3 py-2 font-mono text-xs">
                  {{ c.account_number || '—' }}<span v-if="c.bank_code"> / {{ c.bank_code }}</span>
                </td>
                <td class="px-3 py-2 font-mono text-xs">{{ c.iban || '—' }}</td>
                <td class="px-3 py-2 font-mono text-xs">{{ c.bic || '—' }}</td>
                <td class="px-3 py-2 text-center">
                  <span v-if="c.is_default" class="text-primary-600">✓</span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-center">
                  <span v-if="c.is_active" class="text-success-600">✓</span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <button type="button" @click="startEditCurrency(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs">Upravit</button>
                  <button v-if="(c.invoices_count ?? 0) === 0" type="button" @click="removeCurrency(c)"
                    class="cursor-pointer text-danger-600 hover:text-danger-700 text-xs ml-2">Smazat</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="px-5 py-3 border-t border-neutral-200 bg-neutral-50 text-xs text-neutral-600">
          Pro více účtů ve stejné měně použij „Nový bankovní účet” a vyber stejný kód měny.
        </div>
      </section>

      <div v-if="bankEmailLoadError" class="bg-warning-50 border border-warning-200 text-warning-700 rounded-lg px-4 py-3 text-sm">
        {{ bankEmailLoadError }}
      </div>

      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
          <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">Mapování bankovních avíz</h2>
            <p class="text-xs text-neutral-500 mt-0.5">Vazba bankovní účet → IMAP účet → parser určuje, které avízo se má párovat k danému účtu.</p>
          </div>
          <button type="button" @click="saveMappings"
            class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
            Uložit mapování
          </button>
        </header>

        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">Bankovní účet</th>
                <th class="px-3 py-2 text-left font-medium">IMAP účet</th>
                <th class="px-3 py-2 text-left font-medium">Parser</th>
                <th class="px-3 py-2 text-left font-medium">Tolerance</th>
                <th class="px-3 py-2 text-center font-medium">Aktivní</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-if="mappings.length === 0">
                <td colspan="5" class="px-3 py-4 text-sm text-neutral-500">
                  Pro aktuálního dodavatele nejsou evidované žádné bankovní účty k mapování.
                </td>
              </tr>
              <tr v-for="mapping in mappings" :key="mapping.currency_id">
                <td class="px-3 py-2">
                  <div class="font-medium">{{ mapping.label }}</div>
                  <div class="text-xs text-neutral-500">
                    <span class="font-mono">{{ mapping.currency_code }}</span>
                    <span v-if="mapping.account_number" class="font-mono">
                      · {{ mapping.account_number }}<span v-if="mapping.bank_code"> / {{ mapping.bank_code }}</span>
                    </span>
                    <span v-if="mapping.bank_name"> · {{ mapping.bank_name }}</span>
                  </div>
                </td>
                <td class="px-3 py-2">
                  <select v-model.number="mapping.imap_account_id" @change="onMappingImapChange(mapping, ($event.target as HTMLSelectElement).value)"
                    class="h-9 w-56 px-2 bg-surface border border-neutral-300 rounded-md text-sm">
                    <option :value="0">Žádný IMAP účet</option>
                    <option :value="null">Všechny IMAP účty</option>
                    <option v-for="account in imapAccounts" :key="account.id ?? account.name" :value="account.id">
                      {{ account.name }}
                    </option>
                  </select>
                </td>
                <td class="px-3 py-2">
                  <select v-model.number="mapping.provider_id"
                    class="h-9 w-56 px-2 bg-surface border border-neutral-300 rounded-md text-sm">
                    <option :value="null">Automatický výběr</option>
                    <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                  </select>
                </td>
                <td class="px-3 py-2">
                  <input v-model.number="mapping.amount_tolerance" type="number" min="0" step="0.01"
                    class="h-9 w-28 px-2 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
                </td>
                <td class="px-3 py-2 text-center">
                  <input v-model="mapping.enabled" type="checkbox" :disabled="mapping.imap_account_id === 0"
                    class="rounded border-neutral-300 text-primary-600 disabled:opacity-40" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
          <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">IMAP účty pro bankovní avíza</h2>
            <p class="text-xs text-neutral-500 mt-0.5">Každá banka může používat vlastní schránku. Polling používá read-only fetch a zprávy neoznačuje jako přečtené.</p>
          </div>
          <button type="button" @click="startNewImapAccount"
            class="cursor-pointer h-9 px-3 bg-surface border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
            Nový IMAP účet
          </button>
        </header>

        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">Název</th>
                <th class="px-3 py-2 text-left font-medium">Server</th>
                <th class="px-3 py-2 text-left font-medium">Složka</th>
                <th class="px-3 py-2 text-left font-medium">Limit</th>
                <th class="px-3 py-2 text-left font-medium">Poslední scan</th>
                <th class="px-3 py-2 text-center font-medium">Aktivní</th>
                <th class="px-3 py-2 text-right"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-if="imapAccounts.length === 0">
                <td colspan="7" class="px-3 py-4 text-sm text-neutral-500">Není nastavený žádný IMAP účet.</td>
              </tr>
              <tr v-for="account in imapAccounts" :key="account.id ?? account.name">
                <td class="px-3 py-2">
                  <div class="font-medium">{{ account.name }}</div>
                  <div class="text-xs text-neutral-500">{{ account.username || 'bez uživatele' }}</div>
                </td>
                <td class="px-3 py-2 font-mono text-xs">{{ account.host || '—' }}<span v-if="account.port">:{{ account.port }}</span></td>
                <td class="px-3 py-2">{{ account.folder || 'INBOX' }}</td>
                <td class="px-3 py-2">{{ account.max_messages_per_run }}</td>
                <td class="px-3 py-2 text-xs">
                  <div>{{ account.last_scan_at || '—' }}</div>
                  <div v-if="account.last_scan_status" :class="account.last_scan_status === 'ok' ? 'text-success-600' : 'text-danger-600'">
                    {{ account.last_scan_status }}<span v-if="account.last_scan_message"> · {{ account.last_scan_message }}</span>
                  </div>
                </td>
                <td class="px-3 py-2 text-center">{{ account.enabled ? 'Ano' : 'Ne' }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <button type="button" @click="testImapAccount(account)" :disabled="testingAccountId === account.id"
                    class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs disabled:opacity-50">
                    {{ testingAccountId === account.id ? 'Testuji…' : 'Test' }}
                  </button>
                  <button type="button" @click="startEditImapAccount(account)" class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs ml-2">Upravit</button>
                  <button type="button" @click="deleteImapAccount(account)" class="cursor-pointer text-danger-600 hover:text-danger-700 text-xs ml-2">Smazat</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="imapFormOpen" class="border-t border-neutral-200 p-5">
          <h3 class="text-sm font-semibold mb-3">{{ editingImapId === null ? 'Nový IMAP účet' : 'Upravit IMAP účet' }}</h3>
          <div class="grid md:grid-cols-3 gap-4">
            <label class="flex items-center gap-2 text-sm md:col-span-3">
              <input v-model="imapDraft.enabled" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              Povolit zpracování bankovních avíz z tohoto účtu
            </label>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">Název</label>
              <input v-model="imapDraft.name" type="text" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">Host</label>
              <input v-model="imapDraft.host" type="text" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">Port</label>
              <input v-model.number="imapDraft.port" type="number" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">Šifrování</label>
              <select v-model="imapDraft.encryption" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm">
                <option value="ssl">SSL</option>
                <option value="tls">TLS</option>
                <option value="none">Bez šifrování</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">Uživatel</label>
              <input v-model="imapDraft.username" type="text" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">Heslo</label>
              <input v-model="imapDraft.password" type="password" :placeholder="imapDraft.has_password ? 'Uloženo, ponech prázdné' : ''"
                class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">Složka</label>
              <div class="flex gap-2">
                <input v-model="imapDraft.folder" type="text" class="min-w-0 flex-1 h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
                <button type="button" @click="browseImapFolders" :disabled="browsingFolders"
                  class="cursor-pointer h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm hover:bg-neutral-50 disabled:opacity-50">
                  {{ browsingFolders ? 'Načítám…' : 'Procházet' }}
                </button>
              </div>
              <select v-if="folderOptions.length > 0" :value="imapDraft.folder" @change="selectImapFolder(($event.target as HTMLSelectElement).value)"
                class="mt-2 w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm">
                <option v-for="folder in folderOptions" :key="folder" :value="folder">{{ folder }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">Max. zpráv na běh</label>
              <input v-model.number="imapDraft.max_messages_per_run" type="number" min="1" max="500"
                class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">Zpracovat od data</label>
              <input v-model="imapDraft.process_from_date" type="date"
                class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <label class="flex items-center gap-2 text-sm mt-7">
              <input v-model="imapDraft.validate_cert" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              Ověřit certifikát serveru
            </label>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">Po úspěchu</label>
              <select v-model="imapDraft.success_action" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm">
                <option value="none">Neměnit zprávu</option>
                <option value="add_flag">Přidat flag</option>
                <option value="move">Přesunout</option>
                <option value="mark_seen">Označit jako přečtené</option>
              </select>
            </div>
            <div v-if="imapDraft.success_action === 'add_flag'">
              <label class="block text-sm font-medium text-neutral-700 mb-1">Success flag</label>
              <input v-model="imapDraft.success_flag" type="text" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
            <div v-if="imapDraft.success_action === 'move'">
              <label class="block text-sm font-medium text-neutral-700 mb-1">Cílová složka</label>
              <input v-model="imapDraft.success_move_folder" type="text" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div class="mt-4 flex justify-end gap-2">
            <button type="button" @click="closeImapForm"
              class="cursor-pointer h-9 px-3 bg-surface border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
              Zrušit
            </button>
            <button type="button" @click="saveImapAccount" :disabled="saving"
              class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md disabled:opacity-50">
              {{ saving ? 'Ukládám…' : 'Uložit IMAP účet' }}
            </button>
          </div>
        </div>
      </section>

      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">Parser provideri</h2>
            <p class="text-xs text-neutral-500 mt-0.5">Registrované parsery pro bankovní e-mailová avíza a jejich rychlý test.</p>
          </div>
          <button type="button" @click="startNewRegexProvider"
            class="cursor-pointer h-9 px-3 bg-surface border border-neutral-300 rounded-md text-sm hover:bg-neutral-50">
            Nový regex provider
          </button>
        </header>
        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">Název</th>
                <th class="px-3 py-2 text-left font-medium">Vlastník</th>
                <th class="px-3 py-2 text-left font-medium">Parser</th>
                <th class="px-3 py-2 text-left font-medium">Pravidla</th>
                <th class="px-3 py-2 text-center font-medium">Aktivní</th>
                <th class="px-3 py-2 text-right"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-if="providers.length === 0">
                <td colspan="6" class="px-3 py-4 text-sm text-neutral-500">Není registrovaný žádný provider.</td>
              </tr>
              <tr v-for="p in providers" :key="p.id">
                <td class="px-3 py-2">
                  <div class="font-medium">{{ p.name }}</div>
                  <div class="text-xs text-neutral-500 font-mono">{{ p.code }}</div>
                </td>
                <td class="px-3 py-2">
                  <span class="inline-flex items-center rounded-full border border-neutral-200 px-2 py-0.5 text-xs">
                    {{ providerOwnerLabel(p) }}
                  </span>
                </td>
                <td class="px-3 py-2">{{ parserTypeLabel(p.parser_type) }}</td>
                <td class="px-3 py-2 text-xs text-neutral-600">
                  <div v-if="p.sender_whitelist">Odesílatel: {{ p.sender_whitelist }}</div>
                  <div v-if="p.subject_pattern">Předmět: <span class="font-mono">{{ p.subject_pattern }}</span></div>
                  <div v-if="p.body_pattern">Tělo: <span class="font-mono">{{ p.body_pattern }}</span></div>
                  <span v-if="!p.sender_whitelist && !p.subject_pattern && !p.body_pattern">—</span>
                </td>
                <td class="px-3 py-2 text-center">
                  <span :class="p.enabled ? 'text-success-600' : 'text-neutral-500'">{{ p.enabled ? 'Ano' : 'Ne' }}</span>
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <button v-if="p.supplier_id !== null && p.parser_type === 'regex'" type="button" @click="startEditProvider(p)"
                    class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs">
                    Upravit
                  </button>
                  <button v-if="p.supplier_id !== null" type="button" @click="removeProvider(p)"
                    class="cursor-pointer text-danger-600 hover:text-danger-700 text-xs ml-2">
                    Smazat
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="p-5 border-t border-neutral-200">
            <h3 class="text-sm font-medium mb-2">Test parseru</h3>
            <select v-model.number="parserProviderId" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm mb-2">
              <option :value="null">Automatický výběr</option>
              <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <div class="grid md:grid-cols-2 gap-3 mb-2">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">Odesílatel</label>
                <input v-model="parserSender" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">Předmět</label>
                <input v-model="parserSubject" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
              </div>
            </div>
            <textarea v-model="parserText" rows="8" class="w-full p-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono"
              placeholder="Vlož text nebo HTML e-mailu…"></textarea>
            <button type="button" @click="testParser"
              class="cursor-pointer mt-2 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
              Otestovat parser
            </button>
            <pre v-if="parserResult" class="mt-3 text-xs bg-neutral-50 border border-neutral-200 rounded-md p-3 overflow-auto">{{ parserResult }}</pre>
        </div>
      </section>

      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">Zpracované e-maily</h2>
            <p class="text-xs text-neutral-500 mt-0.5">Emergency smazání smaže pouze deduplikační záznam, ne transakci ani fakturu.</p>
          </div>
          <button type="button" @click="runScan" :disabled="scanning"
            class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md disabled:opacity-50">
            {{ scanning ? '…' : 'Spustit scan' }}
          </button>
        </header>
        <div v-if="scanSummary" class="px-5 py-2 text-xs border-b border-neutral-200 bg-neutral-50">
          Zpracováno: {{ scanSummary.processed ?? 0 }}, spárováno: {{ scanSummary.matched ?? 0 }},
          známé: {{ scanSummary.known_skipped ?? 0 }}, staré: {{ scanSummary.old_skipped ?? 0 }},
          chyby: {{ scanSummary.errors ?? 0 }}
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">ID</th>
                <th class="px-3 py-2 text-left font-medium">IMAP účet</th>
                <th class="px-3 py-2 text-left font-medium">Message-ID</th>
                <th class="px-3 py-2 text-left font-medium">Stav</th>
                <th class="px-3 py-2 text-left font-medium">Provider</th>
                <th class="px-3 py-2 text-left font-medium">Platba</th>
                <th class="px-3 py-2 text-left font-medium">Transakce</th>
                <th class="px-3 py-2 text-right"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="m in messages" :key="m.id">
                <td class="px-3 py-2 font-mono text-xs">#{{ m.id }}<div v-if="m.imap_uid" class="text-neutral-500">UID {{ m.imap_uid }}</div></td>
                <td class="px-3 py-2 text-xs">{{ m.imap_account_name || (m.imap_account_id ? `#${m.imap_account_id}` : '—') }}</td>
                <td class="px-3 py-2 max-w-sm">
                  <div class="font-mono text-xs truncate">{{ m.message_id || m.fallback_hash }}</div>
                  <div class="text-xs text-neutral-500 truncate">{{ m.sender }} · {{ m.subject }}</div>
                </td>
                <td class="px-3 py-2">{{ m.status }}<div v-if="m.error_message" class="text-xs text-danger-500">{{ m.error_message }}</div></td>
                <td class="px-3 py-2">{{ m.provider_code || '—' }}</td>
                <td class="px-3 py-2 font-mono text-xs">
                  {{ m.parsed_payload?.variable_symbol || '—' }}
                  <div v-if="m.parsed_payload?.amount">{{ m.parsed_payload.amount }} {{ m.parsed_payload.currency }}</div>
                </td>
                <td class="px-3 py-2">
                  <span v-if="m.bank_transaction_id">#{{ m.bank_transaction_id }}</span>
                  <span v-else>—</span>
                  <div v-if="m.matched_varsymbol" class="text-xs text-success-600">Faktura {{ m.matched_varsymbol }}</div>
                </td>
                <td class="px-3 py-2 text-right">
                  <button type="button" @click="deleteMessage(m)" class="cursor-pointer text-danger-600 hover:text-danger-700 text-xs">Smazat záznam</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <div v-if="currencyFormOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ editingCurrency === null ? 'Nový bankovní účet' : `Upravit ${editingCurrencyLabel}` }}</h3>
        <div class="space-y-3">
          <div v-if="editingCurrency === null">
            <label class="block text-sm font-medium text-neutral-700 mb-1">Měna</label>
            <select v-model="currencyDraft.code" @change="applyNewCurrencyCode"
              class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm">
              <option v-for="code in availableCurrencyCodes" :key="code" :value="code">{{ code }}</option>
            </select>
          </div>
          <div v-else>
            <label class="block text-sm font-medium text-neutral-700 mb-1">Měna</label>
            <div class="h-10 px-3 flex items-center bg-neutral-50 border border-neutral-200 rounded-md text-sm font-mono">
              {{ currencyDraft.code }}
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">Název účtu</label>
            <input v-model="currencyDraft.label" type="text"
              class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">Číslo účtu</label>
            <input v-model="currencyDraft.account_number" type="text"
              class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">Kód banky</label>
            <input v-model="currencyDraft.bank_code" type="text"
              class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">Název banky</label>
            <input v-model="currencyDraft.bank_name" type="text"
              class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">IBAN</label>
            <input v-model="currencyDraft.iban" type="text"
              class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">BIC</label>
            <input v-model="currencyDraft.bic" type="text"
              class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="currencyDraft.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            Aktivní účet
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="currencyDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            Výchozí pro danou měnu
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="closeCurrencyForm"
              class="cursor-pointer px-3 h-9 text-sm bg-surface border border-neutral-300 rounded-md hover:bg-neutral-50">Zrušit</button>
            <button type="button" @click="saveCurrency"
              class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">Uložit</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="providerFormOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-5xl w-full max-h-[90vh] overflow-y-auto p-5">
        <h3 class="text-lg font-semibold mb-4">{{ providerDraft.id === null ? 'Nový regex provider' : `Upravit ${providerDraft.name}` }}</h3>

        <div class="space-y-5">
          <section>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">Základní nastavení</h4>
            <div class="grid md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">Název</label>
                <input v-model="providerDraft.name" @input="syncProviderCode" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm" />
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">Kód</label>
                <input v-model="providerDraft.code" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
              </div>
              <label class="flex items-center gap-2 text-sm mt-7">
                <input v-model="providerDraft.enabled" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                Aktivní provider
              </label>
            </div>
          </section>

          <section>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">Pravidla e-mailu</h4>
            <div class="grid md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">Odesílatel</label>
                <input v-model="providerDraft.sender_whitelist" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm"
                  placeholder="info@banka.cz" />
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">Regex předmětu</label>
                <input v-model="providerDraft.subject_pattern" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono"
                  placeholder="Pohyb\\s+na\\s+účtě" />
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">Regex těla</label>
                <input v-model="providerDraft.body_pattern" type="text"
                  class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono"
                  placeholder="Variabilní\\s+symbol" />
              </div>
            </div>
          </section>

          <section>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">Vytěžená pole</h4>
            <div class="overflow-x-auto border border-neutral-200 rounded-lg">
              <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium w-56">Pole</th>
                    <th class="px-3 py-2 text-left font-medium">Regex</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="field in regexFieldDefinitions" :key="field.key">
                    <td class="px-3 py-2">
                      <div class="font-medium">{{ field.label }}</div>
                      <div class="text-xs" :class="field.required ? 'text-warning-700' : 'text-neutral-500'">
                        {{ field.required ? 'povinné' : 'volitelné' }}
                      </div>
                    </td>
                    <td class="px-3 py-2">
                      <input v-model="providerDraft.field_patterns[field.key]" type="text"
                        class="w-full h-9 px-2 bg-surface border border-neutral-300 rounded-md text-sm font-mono" />
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">Normalizer config</h4>
            <textarea v-model="providerDraft.normalizer_config_json" rows="4"
              class="w-full p-3 bg-surface border border-neutral-300 rounded-md text-sm font-mono"></textarea>
          </section>

          <div class="flex justify-end gap-2 pt-1">
            <button type="button" @click="closeProviderForm"
              class="cursor-pointer px-3 h-9 text-sm bg-surface border border-neutral-300 rounded-md hover:bg-neutral-50">Zrušit</button>
            <button type="button" @click="saveProvider"
              class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">Uložit provider</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
