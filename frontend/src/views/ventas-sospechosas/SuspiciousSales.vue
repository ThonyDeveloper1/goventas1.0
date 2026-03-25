<script setup>
import { ref, onMounted, watch } from 'vue'
import { useSuspiciousStore } from '@/store/suspicious'
import api from '@/services/api'
import { useAuthStore } from '@/store/auth'

const store = useSuspiciousStore()
const auth  = useAuthStore()

onMounted(async () => {
  await Promise.all([store.fetchStats(), store.fetchSales()])
  if (auth.isAdmin) loadVendors()
})

/* ── Search ──────────────────────────────────────────────── */
/* ── Vendor filter ───────────────────────────────────────── */
const vendors = ref([])

async function loadVendors() {
  try {
    const { data } = await api.get('/admin/users', { params: { role: 'vendedora', active: true, per_page: 200 } })
    vendors.value = data.data ?? data
  } catch { /* silent */ }
}

/* ── Month / all-months ──────────────────────────────────── */
function onMonthChange() {
  store.setFilter('all_months', false)
  store.fetchStats()
  store.fetchSales({ page: 1 })
}

function toggleAllMonths() {
  store.setFilter('all_months', !store.filters.all_months)
  store.fetchStats()
  store.fetchSales({ page: 1 })
}

function onVendorChange(val) {
  store.setFilter('user_id', val)
  store.fetchStats()
  store.fetchSales({ page: 1 })
}

/* ── Search ──────────────────────────────────────────────── */
const searchInput = ref('')
let searchTimer   = null

watch(searchInput, (val) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    store.setFilter('search', val)
    store.fetchSales({ page: 1 })
  }, 400)
})

function filterByStatus(status) {
  store.setFilter('status', status === store.filters.status ? '' : status)
  store.fetchSales({ page: 1 })
}

function filterByLevel(level) {
  store.setFilter('risk_level', level === store.filters.risk_level ? '' : level)
  store.fetchSales({ page: 1 })
}

function goToPage(page) {
  store.fetchSales({ page })
}

/* ── Actions ─────────────────────────────────────────────── */
const actionLoading = ref({})
const message       = ref('')
const messageType   = ref('info') // info | success | error

async function handleApprove(sale) {
  actionLoading.value[sale.id] = 'approving'
  try {
    const result = await store.approveSale(sale.id)
    message.value     = result.message
    messageType.value = 'success'
  } catch (e) {
    message.value     = e.response?.data?.message || 'Error al aprobar.'
    messageType.value = 'error'
  } finally {
    delete actionLoading.value[sale.id]
  }
}

async function handleReject(sale) {
  actionLoading.value[sale.id] = 'rejecting'
  try {
    const result = await store.rejectSale(sale.id)
    message.value     = result.message
    messageType.value = 'success'
  } catch (e) {
    message.value     = e.response?.data?.message || 'Error al rechazar.'
    messageType.value = 'error'
  } finally {
    delete actionLoading.value[sale.id]
  }
}

async function handleUnapprove(sale) {
  actionLoading.value[sale.id] = 'unapproving'
  try {
    const result = await store.unapproveSale(sale.id)
    message.value     = result.message
    messageType.value = 'success'
  } catch (e) {
    message.value     = e.response?.data?.message || 'Error al anular revisión.'
    messageType.value = 'error'
  } finally {
    delete actionLoading.value[sale.id]
  }
}

/* ── Detail expand ───────────────────────────────────────── */
const expandedId = ref(null)

function toggleExpand(id) {
  expandedId.value = expandedId.value === id ? null : id
}

/* ── Helpers ─────────────────────────────────────────────── */
const RISK = {
  bajo:  { label: 'Bajo',  bg: 'bg-green-50',  text: 'text-green-700', border: 'border-green-200', dot: 'bg-green-400' },
  medio: { label: 'Medio', bg: 'bg-yellow-50', text: 'text-yellow-700',border: 'border-yellow-200',dot: 'bg-yellow-400' },
  alto:  { label: 'Alto',  bg: 'bg-red-50',    text: 'text-red-600',   border: 'border-red-200',   dot: 'bg-red-400' },
}

const STATUS_STYLE = {
  pendiente:  { label: 'Pendiente',  bg: 'bg-amber-50',  text: 'text-amber-700' },
  aprobado:   { label: 'Aprobado',   bg: 'bg-green-50',  text: 'text-green-700' },
  rechazado:  { label: 'Rechazado',  bg: 'bg-red-50',    text: 'text-red-600'   },
}

function rk(level) { return RISK[level] || RISK.bajo }
function ss(status) { return STATUS_STYLE[status] || STATUS_STYLE.pendiente }

function scoreBarWidth(score) {
  return Math.min(score, 200) / 2 + '%'
}

function scoreBarColor(score) {
  if (score <= 30) return 'bg-green-400'
  if (score <= 70) return 'bg-yellow-400'
  return 'bg-red-500'
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Ventas Sospechosas</h1>
        <p class="text-gray-500 text-sm mt-0.5">Detección de fraude · Fase 6</p>
      </div>
    </div>

    <!-- Alert -->
        <!-- Filters -->
        <div class="card mb-5">
          <div class="flex flex-wrap items-end gap-3">
            <!-- Month -->
            <div class="flex flex-col gap-1">
              <label class="text-xs font-semibold text-gray-500">Mes</label>
              <input
                :value="store.filters.month"
                type="month"
                class="input"
                :disabled="store.filters.all_months"
                @change="store.setFilter('month', $event.target.value); onMonthChange()"
              />
            </div>
            <!-- Vendor (admin only) -->
            <div v-if="auth.isAdmin" class="flex flex-col gap-1">
              <label class="text-xs font-semibold text-gray-500">Vendedora</label>
              <select
                :value="store.filters.user_id"
                class="input"
                @change="onVendorChange($event.target.value)"
              >
                <option value="">Todas las vendedoras</option>
                <option v-for="v in vendors" :key="v.id" :value="String(v.id)">{{ v.name }}</option>
              </select>
            </div>
            <!-- All months toggle -->
            <button
              type="button"
              class="px-3 py-2 rounded-lg border text-sm font-medium transition-colors self-end"
              :class="store.filters.all_months ? 'border-primary bg-primary/10 text-primary' : 'border-gray-200 text-gray-700 hover:border-primary/50'"
              @click="toggleAllMonths"
            >
              {{ store.filters.all_months ? 'Viendo todos los meses' : 'Ver todos los meses' }}
            </button>
          </div>
        </div>

        <!-- Alert -->
    <Transition name="fade">
      <div
        v-if="message"
        :class="[
          'text-sm rounded-xl px-4 py-3 mb-4 flex items-center gap-2 border',
          messageType === 'success' ? 'bg-green-50 border-green-200 text-green-700' :
          messageType === 'error'   ? 'bg-red-50 border-red-200 text-red-600' :
                                      'bg-blue-50 border-blue-200 text-blue-700',
        ]"
      >
        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        {{ message }}
        <button @click="message = ''" class="ml-auto opacity-60 hover:opacity-100">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </Transition>

    <!-- ── Stats cards ────────────────────────────────────── -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
      <button @click="filterByStatus('')" :class="['card text-center py-4 transition-all', !store.filters.status ? 'ring-2 ring-primary/30' : '']">
        <p class="text-2xl font-bold text-gray-800">{{ store.stats.total }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Total</p>
      </button>
      <button @click="filterByStatus('pendiente')" :class="['card text-center py-4 transition-all', store.filters.status === 'pendiente' ? 'ring-2 ring-amber-300' : '']">
        <p class="text-2xl font-bold text-amber-600">{{ store.stats.pendientes }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Pendientes</p>
      </button>
      <button @click="filterByLevel('alto')" :class="['card text-center py-4 transition-all', store.filters.risk_level === 'alto' ? 'ring-2 ring-red-300' : '']">
        <p class="text-2xl font-bold text-red-600">{{ store.stats.alto }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Riesgo Alto</p>
      </button>
      <button @click="filterByLevel('medio')" :class="['card text-center py-4 transition-all', store.filters.risk_level === 'medio' ? 'ring-2 ring-yellow-300' : '']">
        <p class="text-2xl font-bold text-yellow-600">{{ store.stats.medio }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Riesgo Medio</p>
      </button>
      <button @click="filterByStatus('aprobado')" :class="['card text-center py-4 transition-all', store.filters.status === 'aprobado' ? 'ring-2 ring-green-300' : '']">
        <p class="text-2xl font-bold text-green-600">{{ store.stats.aprobados }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Aprobados</p>
      </button>
      <button @click="filterByStatus('rechazado')" :class="['card text-center py-4 transition-all', store.filters.status === 'rechazado' ? 'ring-2 ring-red-300' : '']">
        <p class="text-2xl font-bold text-gray-600">{{ store.stats.rechazados }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Rechazados</p>
      </button>
    </div>

    <!-- ── Search ─────────────────────────────────────────── -->
    <div class="card mb-5">
      <div class="relative">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
        <input
          v-model="searchInput"
          type="text"
          placeholder="Buscar por nombre o DNI del cliente..."
          class="input pl-9"
        />
      </div>
    </div>

    <!-- ── Loading ────────────────────────────────────────── -->
    <div v-if="store.loading" class="flex items-center justify-center py-16 text-gray-400 gap-2">
      <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
      Cargando...
    </div>

    <!-- ── Empty ──────────────────────────────────────────── -->
    <div v-else-if="store.sales.length === 0" class="card text-center py-16">
      <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
      <p class="text-gray-500 text-sm">No se encontraron ventas sospechosas.</p>
    </div>

    <!-- ── Sales list ─────────────────────────────────────── -->
    <div v-else class="space-y-3">
      <div
        v-for="sale in store.sales"
        :key="sale.id"
        :class="['card transition-all hover:shadow-md', sale.risk_level === 'alto' ? 'border-l-4 border-l-red-400' : sale.risk_level === 'medio' ? 'border-l-4 border-l-yellow-400' : '']"
      >
        <!-- Main row -->
        <div class="flex items-center gap-3 sm:gap-4 cursor-pointer" @click="toggleExpand(sale.id)">
          <!-- Risk icon -->
          <div :class="['w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 ring-1', rk(sale.risk_level).bg, sale.risk_level === 'alto' ? 'ring-red-200' : sale.risk_level === 'medio' ? 'ring-yellow-200' : 'ring-green-200']">
            <svg v-if="sale.risk_level === 'alto'" class="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
            <svg v-else-if="sale.risk_level === 'medio'" class="w-5 h-5 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <svg v-else class="w-5 h-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>

          <!-- Client info -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <h3 class="font-semibold text-gray-800 text-sm sm:text-base truncate">
                {{ sale.client?.nombres }} {{ sale.client?.apellidos }}
              </h3>
              <span :class="['px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide', rk(sale.risk_level).bg, rk(sale.risk_level).text]">
                {{ rk(sale.risk_level).label }}
              </span>
              <span :class="['px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide', ss(sale.status).bg, ss(sale.status).text]">
                {{ ss(sale.status).label }}
              </span>
            </div>
            <div class="mt-0.5 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-gray-500">
              <span>DNI {{ sale.client?.dni }}</span>
              <span v-if="sale.vendedora">Vendedora: {{ sale.vendedora.name }}</span>
              <span>Score: <strong :class="sale.risk_score > 70 ? 'text-red-600' : sale.risk_score > 30 ? 'text-yellow-600' : 'text-green-600'">{{ sale.risk_score }}</strong></span>
            </div>
            <!-- Score bar -->
            <div class="mt-1.5 h-1.5 bg-gray-100 rounded-full overflow-hidden max-w-xs">
              <div
                :class="['h-full rounded-full transition-all', scoreBarColor(sale.risk_score)]"
                :style="{ width: scoreBarWidth(sale.risk_score) }"
              ></div>
            </div>
          </div>

          <!-- Action buttons -->
          <div class="flex gap-1.5 flex-shrink-0">
            <!-- Pending: approve + reject -->
            <template v-if="sale.status === 'pendiente'">
              <button
                @click.stop="handleApprove(sale)"
                :disabled="!!actionLoading[sale.id]"
                class="p-2.5 rounded-xl bg-green-50 text-green-600 hover:bg-green-100 transition-colors"
                title="Aprobar venta"
              >
                <svg v-if="actionLoading[sale.id] === 'approving'" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
              </button>

              <button
                @click.stop="handleReject(sale)"
                :disabled="!!actionLoading[sale.id]"
                class="p-2.5 rounded-xl bg-red-50 text-red-600 hover:bg-red-100 transition-colors"
                title="Rechazar venta"
              >
                <svg v-if="actionLoading[sale.id] === 'rejecting'" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </template>

            <!-- Approved/Rejected: unapprove -->
            <template v-else>
              <button
                @click.stop="handleUnapprove(sale)"
                :disabled="!!actionLoading[sale.id]"
                class="p-2.5 rounded-xl bg-orange-50 text-orange-600 hover:bg-orange-100 transition-colors"
                title="Deshacer revisión (volver a pendiente)"
              >
                <svg v-if="actionLoading[sale.id] === 'unapproving'" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12.066 11.2a1 1 0 000 1.6l5.334 4A1 1 0 0019 16V8a1 1 0 00-1.6-.8l-5.334 4zM4.066 11.2a1 1 0 000 1.6l5.334 4A1 1 0 0011 16V8a1 1 0 00-1.6-.8l-5.334 4z"/>
                </svg>
              </button>
            </template>
          </div>

          <!-- Expand / collapse chevron -->
          <div class="flex-shrink-0 text-gray-400">
            <svg :class="['w-5 h-5 transition-transform', expandedId === sale.id && 'rotate-180']" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
          </div>
        </div>

        <!-- Expanded detail: reasons -->
        <Transition name="slide">
          <div v-if="expandedId === sale.id" class="mt-4 pt-4 border-t border-gray-100">
            <h4 class="text-sm font-semibold text-gray-700 mb-3">Motivos detectados</h4>
            <div class="space-y-2">
              <div
                v-for="(reason, idx) in (sale.reasons || [])"
                :key="idx"
                class="flex items-start gap-3 p-3 rounded-xl bg-gray-50"
              >
                <div :class="['w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 text-xs font-bold', reason.points >= 70 ? 'bg-red-100 text-red-600' : reason.points >= 30 ? 'bg-yellow-100 text-yellow-700' : 'bg-green-100 text-green-700']">
                  +{{ reason.points }}
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm text-gray-700">{{ reason.label }}</p>
                  <p class="text-xs text-gray-400 mt-0.5 font-mono">{{ reason.rule }}</p>
                </div>
              </div>
            </div>

            <!-- Client details -->
            <div class="mt-4 grid grid-cols-2 gap-3 text-sm" v-if="sale.client">
              <div class="bg-gray-50 rounded-xl p-3">
                <p class="text-gray-500 text-xs mb-0.5">Teléfono</p>
                <p class="text-gray-800 font-medium">{{ sale.client.telefono_1 || '—' }}</p>
              </div>
              <div class="bg-gray-50 rounded-xl p-3">
                <p class="text-gray-500 text-xs mb-0.5">Estado cliente</p>
                <p class="text-gray-800 font-medium capitalize">{{ sale.client.estado }}</p>
              </div>
              <div class="bg-gray-50 rounded-xl p-3 col-span-2">
                <p class="text-gray-500 text-xs mb-0.5">Dirección</p>
                <p class="text-gray-800 font-medium">{{ sale.client.direccion || '—' }}, {{ sale.client.distrito || '' }}</p>
              </div>
            </div>

            <!-- Reviewer info -->
            <div v-if="sale.reviewed_at" class="mt-3 flex items-center gap-2 text-xs text-gray-500">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
              </svg>
              Revisado por {{ sale.reviewer?.name }} el {{ new Date(sale.reviewed_at).toLocaleDateString('es-PE') }}
            </div>
          </div>
        </Transition>
      </div>
    </div>

    <!-- ── Pagination ─────────────────────────────────────── -->
    <div v-if="store.pagination.last_page > 1" class="flex items-center justify-center gap-2 mt-6">
      <button
        v-for="p in store.pagination.last_page"
        :key="p"
        @click="goToPage(p)"
        :class="[
          'w-9 h-9 rounded-lg text-sm font-medium transition-colors',
          p === store.pagination.current_page
            ? 'bg-primary text-white'
            : 'bg-gray-100 text-gray-600 hover:bg-gray-200',
        ]"
      >
        {{ p }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to        { opacity: 0; }
.slide-enter-active, .slide-leave-active { transition: all 0.25s ease; }
.slide-enter-from, .slide-leave-to       { opacity: 0; max-height: 0; overflow: hidden; }
.slide-enter-to, .slide-leave-from       { max-height: 600px; }
</style>
