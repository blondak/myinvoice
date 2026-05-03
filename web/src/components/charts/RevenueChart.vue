<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  Chart,
  BarController,
  BarElement,
  LineController,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend,
} from 'chart.js'

Chart.register(BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend)

const props = defineProps<{
  thisYear: number[]
  prevYear: number[]
  currency: string
  yearLabel: number
  prevYearLabel: number
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null

const { t, locale } = useI18n()
const monthLabels = computed(() => (t('common.months_short') as unknown as string[]))

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels: monthLabels.value,
      datasets: [
        {
          label: `${props.yearLabel}`,
          data: props.thisYear,
          backgroundColor: '#5C45A0',
          borderRadius: 4,
        },
        {
          label: `${props.prevYearLabel}`,
          data: props.prevYear,
          type: 'line',
          borderColor: '#A99CD8',
          backgroundColor: 'transparent',
          borderWidth: 2,
          tension: 0.3,
          pointRadius: 3,
          pointBackgroundColor: '#A99CD8',
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          position: 'bottom',
          labels: { font: { size: 11 }, boxWidth: 12, color: '#5A5470' },
        },
        tooltip: {
          backgroundColor: '#15131D',
          callbacks: {
            label: (ctx) => `${ctx.dataset.label}: ${formatVal(ctx.parsed.y ?? 0)} ${props.currency}`,
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { color: '#7A748C', font: { size: 11 }, callback: (v) => formatTick(Number(v)) },
          grid: { color: '#E7E3EE' },
        },
        x: {
          ticks: { color: '#7A748C', font: { size: 11 } },
          grid: { display: false },
        },
      },
    },
  })
}

function formatVal(n: number): string {
  return new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 }).format(n)
}

function formatTick(n: number): string {
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M'
  if (n >= 1_000) return (n / 1_000).toFixed(0) + 'k'
  return n.toString()
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => [props.thisYear, props.prevYear, props.currency, locale.value], build, { deep: true })
</script>

<template>
  <div class="relative h-64">
    <canvas ref="canvas"></canvas>
  </div>
</template>
