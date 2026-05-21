<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { clientsApi, type Client } from '@/api/clients'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

const props = defineProps<{
  modelValue: number | null
  /** Open modal pro vytvoření nového vendoru — parent ho otevře a po vytvoření vrátí id přes update:modelValue */
  onCreateNew?: () => void
}>()

const emit = defineEmits<{
  'update:modelValue': [v: number | null]
  'selected': [vendor: Client | null]
}>()

const { t } = useI18n()
const clients = ref<Client[]>([])
const loading = ref(false)

onMounted(load)

async function load() {
  loading.value = true
  try {
    const res = await clientsApi.list({ per_page: 200 })
    // Filtr na frontendu: pouze klienti označení jako vendor.
    // Backendový filtr přidáme později (clients?role=vendor) — v fázi 1 takhle.
    clients.value = (res.data || []).filter((c: any) => c.is_vendor || c.is_vendor === undefined)
  } finally {
    loading.value = false
  }
}

const options = computed(() => clients.value.map(c => ({
  value: c.id,
  label: c.company_name,
  secondary: c.ic ? `IČO ${c.ic}` : (c.dic || ''),
})))

function onChange(id: number | string | null) {
  emit('update:modelValue', id === null ? null : Number(id))
  emit('selected', id === null ? null : (clients.value.find(c => c.id === Number(id)) ?? null))
}

// Re-load po vytvoření nového klienta (parent emit signal přes ref změny)
defineExpose({ reload: load })
</script>

<template>
  <div class="space-y-1">
    <label class="block text-sm text-neutral-700">{{ t('vendor.picker_label') }}</label>
    <div class="flex items-center gap-2">
      <div class="flex-1">
        <SearchableSelect
          :model-value="modelValue"
          :options="options"
          :placeholder="t('vendor.search_placeholder')"
          @update:model-value="onChange"
        />
      </div>
      <button
        v-if="onCreateNew"
        type="button"
        @click="onCreateNew"
        class="cursor-pointer px-3 py-1.5 text-xs border border-neutral-300 rounded-md hover:bg-neutral-50 whitespace-nowrap"
      >
        {{ t('vendor.create_new') }}
      </button>
    </div>
  </div>
</template>
