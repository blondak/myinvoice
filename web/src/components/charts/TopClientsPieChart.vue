<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Chart, DoughnutController, ArcElement, Tooltip, Legend } from 'chart.js'
import type { TopClient } from '@/api/dashboard'
import { formatMoney } from '@/composables/useFormat'

Chart.register(DoughnutController, ArcElement, Tooltip, Legend)

const props = defineProps<{ clients: TopClient[]; currency?: string }>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const { t, locale } = useI18n()

// Indigo gradient palette
const palette = [
  '#3B2D83', '#5C45A0', '#6753AE', '#8675C5', '#A99CD8',
  '#C9C0E9', '#E5E0F4', '#F4A261', '#E8A547', '#4CAF7A',
]

// Filter by currency if provided, sum & sort top 8 + "Ostatní"
const sliceData = computed(() => {
  const filtered = props.currency
    ? props.clients.filter(c => c.currency === props.currency)
    : props.clients
  if (filtered.length === 0) return { labels: [] as string[], values: [] as number[] }
  const sorted = [...filtered].sort((a, b) => b.total - a.total)
  const top = sorted.slice(0, 8)
  const rest = sorted.slice(8)
  const labels = top.map(c => c.company_name)
  const values = top.map(c => c.total)
  if (rest.length > 0) {
    labels.push(t('common.other'))
    values.push(rest.reduce((s, c) => s + c.total, 0))
  }
  return { labels, values }
})

function build() {
  if (!canvas.value) return
  if (chart) { chart.destroy(); chart = null }
  const { labels, values } = sliceData.value
  if (labels.length === 0) return
  const total = values.reduce((s, v) => s + v, 0)
  chart = new Chart(canvas.value, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: labels.map((_, i) => palette[i % palette.length]),
        borderWidth: 1,
        borderColor: '#FFFFFF',
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'right',
          labels: { boxWidth: 12, font: { size: 11 } },
        },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const v = ctx.parsed as number
              const pct = total > 0 ? ((v / total) * 100).toFixed(1) : '0'
              return ` ${ctx.label}: ${formatMoney(v, props.currency || 'CZK')} (${pct} %)`
            },
          },
        },
      },
      cutout: '55%',
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => [props.clients, props.currency], build, { deep: true })
watch(() => locale.value, build)
</script>

<template>
  <div v-if="sliceData.labels.length === 0" class="text-sm text-neutral-400 text-center py-12">
    {{ t('common.no_data') }}
  </div>
  <div v-else class="relative h-64">
    <canvas ref="canvas"></canvas>
  </div>
</template>
