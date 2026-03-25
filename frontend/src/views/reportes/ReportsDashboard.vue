<script setup>
import { ref, onMounted, computed } from 'vue'
import { useReportsStore } from '@/store/reports'
import { useRouter } from 'vue-router'

const store  = useReportsStore()
const router = useRouter()

const now = new Date()
const salesMonth = ref(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`)
const salesFrom = ref('')
const salesTo   = ref('')

function setRangeFromMonth(monthValue) {
  const [year, month] = monthValue.split('-').map(Number)
  const firstDay = new Date(year, month - 1, 1)
  const lastDay = new Date(year, month, 0)
  salesFrom.value = firstDay.toISOString().slice(0, 10)
  salesTo.value = lastDay.toISOString().slice(0, 10)
}

setRangeFromMonth(salesMonth.value)

onMounted(async () => {
  await Promise.all([
    store.fetchSummary(),
    store.fetchSales({ from: salesFrom.value, to: salesTo.value }),
    store.fetchVendors(),
  ])
})

async function refreshSales() {
  await store.fetchSales({ from: salesFrom.value, to: salesTo.value })
}

async function applyMonthFilter() {
  setRangeFromMonth(salesMonth.value)
  await refreshSales()
}

async function applyMarchFilter() {
  const year = salesMonth.value?.split('-')?.[0] || String(new Date().getFullYear())
  salesMonth.value = `${year}-03`
  await applyMonthFilter()
}

/* ── Computed helpers ─────────────────────────────────────── */
const retentionAvg = computed(() => {
  if (!store.vendors?.vendors?.length) return 0
  const sum = store.vendors.vendors.reduce((a, v) => a + v.retention_rate, 0)
  return (sum / store.vendors.vendors.length).toFixed(1)
})

const maxBarValue = computed(() => {
  if (!store.sales?.by_month?.length) return 1
  return Math.max(...store.sales.by_month.map((m) => m.total), 1)
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Reportes</h1>
        <p class="text-gray-500 text-sm mt-0.5">Panel de análisis · Fase 7</p>
      </div>
      <div class="flex gap-2">
        <button @click="router.push('/reportes/clientes')" class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-medium text-gray-600 hover:border-gray-300 transition-colors">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          Reporte Clientes
        </button>
        <button @click="router.push('/reportes/mapa')" class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-medium text-gray-600 hover:border-gray-300 transition-colors">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          Mapa
        </button>
      </div>
    </div>

    <!-- ── Summary Cards ──────────────────────────────────── -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
      <div class="card text-center py-4">
        <p class="text-2xl font-bold text-gray-800">{{ store.summary?.clients?.total ?? 0 }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Total Clientes</p>
      </div>
      <div class="card text-center py-4">
        <p class="text-2xl font-bold text-sky-600">{{ store.summary?.clients?.pre_registro ?? 0 }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Pre-registro</p>
      </div>
      <div class="card text-center py-4">
        <p class="text-2xl font-bold text-green-600">{{ store.summary?.clients?.finalizadas ?? 0 }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Finalizadas</p>
      </div>
      <div class="card text-center py-4">
        <p class="text-2xl font-bold text-red-600">{{ store.summary?.clients?.suspendidos ?? 0 }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Suspendidos</p>
      </div>
      <div class="card text-center py-4">
        <p class="text-2xl font-bold text-gray-500">{{ store.summary?.clients?.bajas ?? 0 }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Bajas</p>
      </div>
      <div class="card text-center py-4">
        <p :class="['text-2xl font-bold', (store.summary?.growth ?? 0) >= 0 ? 'text-green-600' : 'text-red-600']">
          {{ (store.summary?.growth ?? 0) >= 0 ? '+' : '' }}{{ store.summary?.growth ?? 0 }}%
        </p>
        <p class="text-xs text-gray-500 mt-0.5">Crecimiento</p>
      </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-5 mb-6">
      <!-- ── Sales by Month Chart ─────────────────────────── -->
      <div class="card">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-sm font-semibold text-gray-700">Ventas por Mes</h2>
          <div class="flex gap-2">
            <input v-model="salesMonth" type="month" class="input text-xs !py-1.5 !px-2 w-auto" @change="applyMonthFilter" />
            <button @click="applyMarchFilter" class="px-2.5 py-1.5 rounded-lg border border-gray-200 text-xs font-medium text-gray-600 hover:border-gray-300 transition-colors">
              Marzo
            </button>
            <input v-model="salesFrom" type="date" class="input text-xs !py-1.5 !px-2 w-auto" @change="refreshSales" />
            <input v-model="salesTo"   type="date" class="input text-xs !py-1.5 !px-2 w-auto" @change="refreshSales" />
          </div>
        </div>

        <div v-if="store.sales?.by_month?.length" class="space-y-2">
          <div v-for="month in store.sales.by_month" :key="month.month" class="flex items-center gap-3">
            <span class="text-xs text-gray-500 w-16 flex-shrink-0 font-mono">{{ month.month }}</span>
            <div class="flex-1 h-6 bg-gray-100 rounded-full overflow-hidden flex">
              <div
                class="h-full bg-green-400 transition-all"
                :style="{ width: (month.finalizadas / maxBarValue * 100) + '%' }"
                :title="`Finalizadas: ${month.finalizadas}`"
              ></div>
              <div
                class="h-full bg-sky-300 transition-all"
                :style="{ width: (month.pre_registro / maxBarValue * 100) + '%' }"
                :title="`Pre-registro: ${month.pre_registro}`"
              ></div>
              <div
                class="h-full bg-red-300 transition-all"
                :style="{ width: (month.bajas / maxBarValue * 100) + '%' }"
                :title="`Bajas: ${month.bajas}`"
              ></div>
            </div>
            <span class="text-xs font-semibold text-gray-700 w-8 text-right">{{ month.total }}</span>
          </div>
        </div>
        <div v-else class="text-center py-8 text-gray-400 text-sm">Sin datos en el período.</div>

        <div class="flex gap-4 mt-3 text-xs text-gray-500">
          <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-green-400 inline-block"></span> Finalizadas</span>
          <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-sky-300 inline-block"></span> Pre-registro</span>
          <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-red-300 inline-block"></span> Bajas</span>
        </div>
      </div>

      <!-- ── Sales by Vendor ──────────────────────────────── -->
      <div class="card">
        <h2 class="text-sm font-semibold text-gray-700 mb-4">Ventas por Vendedora</h2>

        <div v-if="store.sales?.by_vendor?.length" class="space-y-2">
          <div v-for="vendor in store.sales.by_vendor" :key="vendor.user_id" class="flex items-center gap-3 p-2.5 rounded-xl bg-gray-50">
            <div class="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center text-primary text-xs font-bold flex-shrink-0">
              {{ vendor.vendedora?.charAt(0)?.toUpperCase() }}
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-800 truncate">{{ vendor.vendedora }}</p>
              <div class="flex gap-3 text-xs text-gray-500">
                <span>{{ vendor.total }} total</span>
                <span class="text-green-600">{{ vendor.finalizadas }} finalizadas</span>
                <span class="text-sky-600">{{ vendor.pre_registro }} pre-registro</span>
                <span class="text-red-500">{{ vendor.bajas }} bajas</span>
              </div>
            </div>
            <span class="text-lg font-bold text-gray-700">{{ vendor.total }}</span>
          </div>
        </div>
        <div v-else class="text-center py-8 text-gray-400 text-sm">Sin datos.</div>
      </div>
    </div>

    <!-- ── Vendor Performance ─────────────────────────────── -->
    <div class="card">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-sm font-semibold text-gray-700">Rendimiento de Vendedoras</h2>
        <span class="text-xs text-gray-500">Retención promedio: <strong class="text-gray-800">{{ retentionAvg }}%</strong></span>
      </div>

      <div v-if="store.vendors?.vendors?.length" class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs text-gray-500 uppercase tracking-wide">
              <th class="pb-2 font-medium">Vendedora</th>
              <th class="pb-2 font-medium text-center">Total</th>
              <th class="pb-2 font-medium text-center">Finalizadas</th>
              <th class="pb-2 font-medium text-center">Pre-registro</th>
              <th class="pb-2 font-medium text-center">Suspendidos</th>
              <th class="pb-2 font-medium text-center">Bajas</th>
              <th class="pb-2 font-medium text-center">Sospechosos</th>
              <th class="pb-2 font-medium text-center">Retención</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="v in store.vendors.vendors" :key="v.id" class="border-t border-gray-100">
              <td class="py-2.5">
                <div class="flex items-center gap-2">
                  <div class="w-7 h-7 rounded-lg bg-primary/10 flex items-center justify-center text-primary text-xs font-bold flex-shrink-0">
                    {{ v.name?.charAt(0)?.toUpperCase() }}
                  </div>
                  <span class="font-medium text-gray-800">{{ v.name }}</span>
                </div>
              </td>
              <td class="py-2.5 text-center font-semibold">{{ v.clients_count }}</td>
              <td class="py-2.5 text-center text-green-600">{{ v.finalizadas_count }}</td>
              <td class="py-2.5 text-center text-sky-600">{{ v.pre_registro_count }}</td>
              <td class="py-2.5 text-center text-red-500">{{ v.suspendidos_count }}</td>
              <td class="py-2.5 text-center text-gray-500">{{ v.bajas_count }}</td>
              <td class="py-2.5 text-center">
                <span v-if="v.suspicious_count > 0" class="text-red-600 font-semibold">{{ v.suspicious_count }}</span>
                <span v-else class="text-gray-400">0</span>
              </td>
              <td class="py-2.5 text-center">
                <span :class="['font-semibold', v.retention_rate >= 80 ? 'text-green-600' : v.retention_rate >= 50 ? 'text-yellow-600' : 'text-red-600']">
                  {{ v.retention_rate }}%
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="text-center py-8 text-gray-400 text-sm">No hay vendedoras registradas.</div>
    </div>
  </div>
</template>
