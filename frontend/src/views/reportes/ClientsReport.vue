<script setup>
import { ref, onMounted, watch } from 'vue'
import { useReportsStore } from '@/store/reports'
import reportsApi from '@/services/reports'

const store = useReportsStore()

/* ── Filters ──────────────────────────────────────────────── */
const vendorId   = ref('')
const estado     = ref('')
const searchInput = ref('')
let searchTimer   = null

const vendors = ref([])

onMounted(async () => {
  const [, vendorData] = await Promise.all([
    store.fetchClients(),
    reportsApi.vendors(),
  ])
  vendors.value = vendorData.data?.vendors ?? []
})

watch(searchInput, (val) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => fetchFiltered(), 400)
})

function fetchFiltered(page = 1) {
  const params = { page }
  if (vendorId.value)   params.vendor_id = vendorId.value
  if (estado.value)     params.estado = estado.value
  if (searchInput.value) params.search = searchInput.value
  store.fetchClients(params)
}

function goToPage(page) {
  fetchFiltered(page)
}

function applyFilter() {
  fetchFiltered(1)
}

/* ── PDF Export ───────────────────────────────────────────── */
function handleExport() {
  const params = {}
  if (vendorId.value)   params.vendor_id = vendorId.value
  if (estado.value)     params.estado = estado.value
  if (searchInput.value) params.search = searchInput.value
  store.exportPDF(params)
}

/* ── Status helpers ───────────────────────────────────────── */
const ESTADO = {
  pre_registro: { label: 'Pre-registro', bg: 'bg-sky-50', text: 'text-sky-700' },
  finalizada:   { label: 'Finalizada',   bg: 'bg-green-50', text: 'text-green-700' },
  suspendido: { label: 'Suspendido', bg: 'bg-red-50',    text: 'text-red-600' },
  baja:       { label: 'Baja',       bg: 'bg-gray-100',  text: 'text-gray-600' },
}

function commercialState(client) {
  if (client.estado_comercial) return client.estado_comercial
  if (client.estado === 'baja') return 'baja'
  if (client.estado === 'suspendido') return 'suspendido'
  if (client.estado === 'pre_registro') return 'pre_registro'
  if (client.estado === 'finalizada' || client.estado === 'activo' || client.service_status === 'activo') return 'finalizada'
  return 'pre_registro'
}

function es(client) {
  return ESTADO[commercialState(client)] || ESTADO.pre_registro
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Reporte de Clientes</h1>
        <p class="text-gray-500 text-sm mt-0.5">Listado filtrable con exportación PDF</p>
      </div>
      <button
        @click="handleExport"
        :disabled="store.exporting"
        class="btn-primary flex items-center gap-2 px-5"
      >
        <svg v-if="store.exporting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
        <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        {{ store.exporting ? 'Generando...' : 'Exportar PDF' }}
      </button>
    </div>

    <!-- ── Filters ────────────────────────────────────────── -->
    <div class="card mb-5">
      <div class="grid sm:grid-cols-3 gap-3">
        <div class="relative">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
          <input
            v-model="searchInput"
            type="text"
            placeholder="Buscar nombre, DNI..."
            class="input pl-9"
          />
        </div>
        <select v-model="vendorId" @change="applyFilter" class="input">
          <option value="">Todas las vendedoras</option>
          <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }}</option>
        </select>
        <select v-model="estado" @change="applyFilter" class="input">
          <option value="">Todos los estados</option>
          <option value="pre_registro">Pre-registro</option>
          <option value="finalizada">Finalizada (servicio activo)</option>
          <option value="suspendido">Suspendido</option>
          <option value="baja">Baja</option>
        </select>
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
    <div v-else-if="store.clients.length === 0" class="card text-center py-16">
      <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
      </svg>
      <p class="text-gray-500 text-sm">No se encontraron clientes.</p>
    </div>

    <!-- ── Table ──────────────────────────────────────────── -->
    <div v-else class="card overflow-x-auto">
      <div class="mb-3 text-xs text-gray-500">
        {{ store.pagination.total }} cliente(s) encontrado(s)
      </div>
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100">
            <th class="pb-2 font-medium">DNI</th>
            <th class="pb-2 font-medium">Nombre</th>
            <th class="pb-2 font-medium">Teléfono</th>
            <th class="pb-2 font-medium hidden md:table-cell">Dirección</th>
            <th class="pb-2 font-medium hidden lg:table-cell">Distrito</th>
            <th class="pb-2 font-medium">Estado</th>
            <th class="pb-2 font-medium hidden lg:table-cell">Plan</th>
            <th class="pb-2 font-medium hidden sm:table-cell">Vendedora</th>
            <th class="pb-2 font-medium hidden xl:table-cell">Registro</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="client in store.clients" :key="client.id" class="border-t border-gray-50 hover:bg-gray-50/50">
            <td class="py-2.5 font-mono text-xs">{{ client.dni }}</td>
            <td class="py-2.5">
              <div class="flex items-center gap-2">
                <span class="font-medium text-gray-800">{{ client.nombres }} {{ client.apellidos }}</span>
                <svg v-if="client.is_suspicious" class="w-4 h-4 text-red-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" title="Sospechoso">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
              </div>
            </td>
            <td class="py-2.5 text-gray-600">{{ client.telefono_1 }}</td>
            <td class="py-2.5 text-gray-600 hidden md:table-cell text-xs max-w-[200px] truncate">{{ client.direccion }}</td>
            <td class="py-2.5 text-gray-600 hidden lg:table-cell">{{ client.distrito }}</td>
            <td class="py-2.5">
              <span :class="['px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide', es(client).bg, es(client).text]">
                {{ es(client).label }}
              </span>
            </td>
            <td class="py-2.5 text-gray-600 hidden lg:table-cell text-xs">{{ client.mikrotik_profile ?? '—' }}</td>
            <td class="py-2.5 text-gray-600 hidden sm:table-cell text-xs">{{ client.vendedora?.name }}</td>
            <td class="py-2.5 text-gray-500 hidden xl:table-cell text-xs">{{ new Date(client.created_at).toLocaleDateString('es-PE') }}</td>
          </tr>
        </tbody>
      </table>
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
