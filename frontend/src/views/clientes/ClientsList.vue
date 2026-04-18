<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useClientsStore } from '@/store/clients'
import { useAuthStore } from '@/store/auth'
import api from '@/services/api'
import { resolvePhotoUrl } from '@/utils/photoUrl'

const router  = useRouter()
const route   = useRoute()
const store   = useClientsStore()
const auth    = useAuthStore()

// Local refs for debounced search
const searchInput = ref(store.filters.search)
const searchTimer = ref(null)
const reviewModalOpen = ref(false)
const reviewClient = ref(null)
const reviewStatus = ref('pre_registro')
const savingStatus = ref(false)
const reviewPage = ref(1)
const reviewTotalPages = 4
const reviewStepLabels = [
  'Datos personales',
  'Ubicacion',
  'Servicio',
  'Documentos del cliente',
]
const vendorOptions = ref([])
const selectedMonth = ref(new Date().toISOString().slice(0, 7))
const allMonths = ref(true)
let mikrotikAutoRefreshTimer = null
const dynamicEstados = ref([])

/* ── Row selection ──────────────────────────────────── */
const selectedClientId = ref(null)
const selectedClient = computed(() => store.items.find(c => c.id === selectedClientId.value) ?? null)

/* ── Asignar IP modal ───────────────────────────────── */
const showAssignIpModal = ref(false)
const assignIpForm = ref({ ip: '', notes: '' })
const assignIpError = ref('')
const assigningIp = ref(false)
const clearingIp = ref(false)
const ipHistoryList = ref([])
const ipHistoryLoading = ref(false)
const ipHistoryPage = ref(1)
const ipHistoryLastPage = ref(1)

async function openAssignIpModal() {
  if (!selectedClient.value) return
  assignIpForm.value = { ip: '', notes: '' }
  assignIpError.value = ''
  ipHistoryList.value = []
  ipHistoryPage.value = 1
  ipHistoryLastPage.value = 1
  showAssignIpModal.value = true
  await loadIpHistory(1)
}

function closeAssignIpModal() {
  if (assigningIp.value || clearingIp.value) return
  showAssignIpModal.value = false
}

async function loadIpHistory(page = 1) {
  if (!selectedClient.value) return
  ipHistoryLoading.value = true
  try {
    const res = await store.fetchIpHistory(selectedClient.value.id, page)
    ipHistoryList.value = res.data ?? []
    ipHistoryPage.value = res.current_page ?? 1
    ipHistoryLastPage.value = res.last_page ?? 1
  } catch (e) {
    console.error('Error loading IP history:', e)
  } finally {
    ipHistoryLoading.value = false
  }
}

async function submitClearIp() {
  if (!selectedClient.value || !selectedClient.value.ip_override) return
  if (!confirm(`¿Limpiar la IP asignada manualmente (${selectedClient.value.ip_address})?\n\nEl cliente volverá a usar la lógica automática de MikroTik.`)) return
  clearingIp.value = true
  try {
    const data = await store.clearIp(selectedClient.value.id)
    ipHistoryList.value = data.history ?? []
    ipHistoryPage.value = 1
  } catch (e) {
    alert(e?.response?.data?.message ?? 'No se pudo limpiar la IP.')
  } finally {
    clearingIp.value = false
  }
}

async function submitAssignIp() {
  assignIpError.value = ''
  const ipVal = assignIpForm.value.ip.trim()
  if (!/^(\d{1,3}\.){3}\d{1,3}$/.test(ipVal)) {
    assignIpError.value = 'Formato de IP inválido. Ejemplo: 192.168.1.100'
    return
  }
  if (!selectedClient.value) return
  assigningIp.value = true
  try {
    await store.assignIp(selectedClient.value.id, {
      ip: ipVal,
      notes: assignIpForm.value.notes.trim() || null,
    })
    assignIpForm.value = { ip: '', notes: '' }
    await loadIpHistory(1)
  } catch (e) {
    assignIpError.value = e?.response?.data?.message ?? 'No se pudo asignar la IP. Intenta nuevamente.'
  } finally {
    assigningIp.value = false
  }
}

function formatHistoryDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleString('es-PE', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

function toggleSelect(client) {
  selectedClientId.value = selectedClientId.value === client.id ? null : client.id
}

onMounted(async () => {
  // Start with no date filter — show all clients
  store.setFilter('from', '')
  store.setFilter('to', '')

  if (!allMonths.value) {
    applyMonthToFilters(selectedMonth.value)
  }

  if (auth.isAdmin) {
    loadVendedoras()
    await loadDynamicEstados()
  }

  await store.fetchClients(1)

  const hasActiveFilters = Boolean(
    store.filters.search || store.filters.estado || store.filters.from || store.filters.to || store.filters.user_id
  )

  if (!store.hasClients && hasActiveFilters) {
    store.resetFilters()
    searchInput.value = ''
    await store.fetchClients(1)
  }

  refreshVisibleMikrotikStatuses()

  const reviewClientId = route.query.review_client_id
  if (reviewClientId) {
    await openClientReviewFromQuery(reviewClientId)
  }

  mikrotikAutoRefreshTimer = setInterval(() => {
    if (reviewModalOpen.value || store.loading) return
    refreshVisibleMikrotikStatuses()
  }, 60000)
})

async function loadDynamicEstados() {
  try {
    const res = await api.get('/admin/client-estados')
    dynamicEstados.value = Array.isArray(res.data?.data) ? res.data.data : []
  } catch (e) {
    console.error('Error loading client estados:', e)
    dynamicEstados.value = []
  }
}

onUnmounted(() => {
  if (mikrotikAutoRefreshTimer) {
    clearInterval(mikrotikAutoRefreshTimer)
    mikrotikAutoRefreshTimer = null
  }
})

watch(
  () => route.query.review_client_id,
  async (reviewClientId) => {
    if (!reviewClientId) return
    await openClientReviewFromQuery(reviewClientId)
  }
)

async function openClientReviewFromQuery(rawId) {
  if (!auth.isAdmin) return

  const clientId = Number(rawId)
  if (!Number.isFinite(clientId) || clientId <= 0) return

  let target = store.items.find((c) => c.id === clientId)
  if (!target) {
    try {
      const fetched = await store.fetchClient(clientId)
      target = fetched?.data ?? fetched
    } catch {
      target = null
    }
  }

  if (target) {
    await openReviewModal(target)
  }

  const nextQuery = { ...route.query }
  delete nextQuery.review_client_id
  router.replace({ query: nextQuery })
}

async function loadVendedoras() {
  try {
    const res = await api.get('/admin/users', {
      params: {
        role: 'vendedora',
        active: true,
        per_page: 200,
      },
    })
    vendorOptions.value = Array.isArray(res.data?.data) ? res.data.data : []
  } catch (e) {
    console.error('Error loading vendedoras:', e)
    vendorOptions.value = []
  }
}

  async function loadEstados() {
    try {
      const res = await api.get('/admin/client-estados')
      dynamicEstados.value = Array.isArray(res.data?.data) ? res.data.data : []
    } catch (e) {
      console.error('Error loading client estados:', e)
      dynamicEstados.value = []
    }
  }

function applyMonthToFilters(monthValue) {
  if (!monthValue || !/^\d{4}-\d{2}$/.test(monthValue)) return
  const [year, month] = monthValue.split('-').map(Number)
  const lastDay = new Date(year, month, 0).getDate()
  store.setFilter('from', `${year}-${String(month).padStart(2, '0')}-01`)
  store.setFilter('to', `${year}-${String(month).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`)
}

function onMonthChange() {
  allMonths.value = false
  applyMonthToFilters(selectedMonth.value)
  store.fetchClients(1)
}

function toggleAllMonths() {
  allMonths.value = !allMonths.value
  if (allMonths.value) {
    store.setFilter('from', '')
    store.setFilter('to', '')
  } else {
    applyMonthToFilters(selectedMonth.value)
  }
  store.fetchClients(1)
}

const ESTADOS = [
  { value: '',              label: 'Todos los estados' },
  { value: 'pre_registro',  label: 'Venta pre registrada' },
  { value: 'en_proceso',    label: 'En proceso' },
  { value: 'finalizada',    label: 'Finalizada (servicio activo)' },
  { value: 'suspendido',    label: 'Suspendido' },
  { value: 'baja',          label: 'Baja' },
]

const ADMIN_STATUS_OPTIONS = [
  { value: 'pre_registro', label: 'Venta pre registrada' },
  { value: 'en_proceso', label: 'En proceso' },
  { value: 'finalizada', label: 'Finalizada (servicio activo)' },
  { value: 'suspendido', label: 'Suspendido' },
  { value: 'baja', label: 'Baja' },
]

const adminStatusOptions = computed(() => {
  if (!dynamicEstados.value.length) return ADMIN_STATUS_OPTIONS
  return dynamicEstados.value.map((estado) => ({
    value: estado.id,
    label: estado.nombre,
    color: estado.color,
  }))
})

/* ── Estado badge styles ────────────────────────────── */
const estadoBadge = {
  pre_registro: 'bg-sky-50 text-sky-700 ring-sky-200',
  en_proceso:  'bg-indigo-50 text-indigo-700 ring-indigo-200',
  finalizada:   'bg-green-50 text-green-700 ring-green-200',
  suspendido: 'bg-orange-50 text-orange-600 ring-orange-200',
  baja:       'bg-red-50    text-red-600    ring-red-200',
}

const estadoLabel = {
  pre_registro: 'venta pre registrada',
  en_proceso: 'en proceso',
  finalizada: 'finalizada',
  suspendido: 'suspendido',
  baja: 'baja',
}

const mikrotikBadge = {
  moroso:    { label: 'MOROSO', text: 'text-red-700', bg: 'bg-red-100', ring: 'ring-red-300', dot: 'bg-red-500' },
  no_moroso: { label: 'SERVICIO ACTIVO', text: 'text-green-700', bg: 'bg-green-100', ring: 'ring-green-300', dot: 'bg-green-500' },
  sin_datos: { label: 'SIN DATOS', text: 'text-gray-600', bg: 'bg-gray-100', ring: 'ring-gray-300', dot: 'bg-gray-400' },
}

function mikrotikState(client) {
  return mikrotikBadge[client.mikrotik_estado] || mikrotikBadge.sin_datos
}

function commercialState(client) {
  const dynamicStateName = client?.cliente_estado?.nombre || client?.clienteEstado?.nombre
  if (dynamicStateName) return dynamicStateName

  if (client.estado_comercial) return client.estado_comercial
  if (client.estado === 'baja') return 'baja'
  if (client.estado === 'suspendido') return 'suspendido'
  if (client.estado === 'pre_registro') return 'pre_registro'
  if (client.estado === 'en_proceso') return 'en_proceso'
  if (client.estado === 'finalizada' || client.estado === 'activo') return 'finalizada'
  return client.estado || 'pre_registro'
}

function resolveReviewStatusValue(client) {
  if (client?.cliente_estado_id) return client.cliente_estado_id

  const dynamicState = client?.cliente_estado || client?.clienteEstado
  if (dynamicState?.id) return dynamicState.id

  if (client?.estado && dynamicEstados.value.length) {
    const match = dynamicEstados.value.find((estado) => estado.nombre === client.estado)
    if (match) return match.id
  }

  return client?.estado || commercialState(client)
}

/* ── Lifecycle ──────────────────────────────────────── */
// (handled above with auto-refresh)

/* ── Watchers ───────────────────────────────────────── */
watch(searchInput, (val) => {
  clearTimeout(searchTimer.value)
  searchTimer.value = setTimeout(() => {
    store.setFilter('search', val)
    store.fetchClients(1)
  }, 400)
})

watch(() => store.pagination.current_page, () => {
  selectedClientId.value = null
})

/* ── Actions ────────────────────────────────────────── */
function applyFilter(key, val) {
  store.setFilter(key, val)
  store.fetchClients(1)
}

function changePage(page) {
  if (page < 1 || page > store.pagination.last_page) return
  store.fetchClients(page)
}

async function openReviewModal(client) {
  if (!auth.isAdmin) {
    alert('Solo un administrador puede revisar y cambiar estado.')
    return
  }

  let hydrated = client
  try {
    const fetched = await store.fetchClient(client.id)
    const details = fetched?.data ?? fetched
    if (details) {
      hydrated = {
        ...client,
        ...details,
        // Preserve current live state from list while enriching photos/details.
        mikrotik_estado: client.mikrotik_estado,
        mikrotik_ip: client.mikrotik_ip,
      }
    }
  } catch (e) {
    console.error('Error loading full client detail for review:', e)
  }

  reviewClient.value = hydrated
  reviewStatus.value = resolveReviewStatusValue(hydrated)
  reviewPage.value = 1
  reviewModalOpen.value = true
}

function closeReviewModal() {
  if (savingStatus.value) return
  reviewModalOpen.value = false
  reviewClient.value = null
  reviewPage.value = 1
}

function goReviewPage(page) {
  if (page < 1 || page > reviewTotalPages) return
  reviewPage.value = page
}

function nextReviewPage() {
  goReviewPage(reviewPage.value + 1)
}

function prevReviewPage() {
  goReviewPage(reviewPage.value - 1)
}

async function saveClientStatus() {
  if (!reviewClient.value?.id) return

  savingStatus.value = true
  try {
    // store.updateClientStatus() already patches store.items[idx] with the PATCH
    // response from the server — it includes the freshly computed estado_comercial.
    // We must NOT await fetchClients() here: that triggers maybeAutoReconcileMorosos()
    // on the backend which can race against the just-saved estado and overwrite it.
    const saved = await store.updateClientStatus(reviewClient.value.id, reviewStatus.value)
    const updatedClient = saved?.data

    if (updatedClient) {
      reviewClient.value = { ...reviewClient.value, ...updatedClient }
      reviewStatus.value = resolveReviewStatusValue(reviewClient.value)
    }

    alert('Estado actualizado y guardado en BD correctamente.')
    closeReviewModal()
  } catch (e) {
    alert(e?.response?.data?.message ?? 'No se pudo guardar el estado del cliente en BD.')
  } finally {
    savingStatus.value = false
  }
}

async function deleteClient(client) {
  if (!auth.isAdmin) {
    alert('Solo un administrador puede eliminar clientes.')
    return
  }

  const warningMessage = [
    `¿Estas seguro de eliminar al cliente ${client.nombre_completo}?`,
    '',
    'Esta accion es permanente y no se puede deshacer.',
    'Tambien se eliminaran registros relacionados (instalaciones, supervisiones y fotos).',
    '',
    'Presiona "Aceptar" solo si estas completamente seguro.',
  ].join('\n')

  if (!confirm(warningMessage)) return

  try {
    const result = await store.removeClient(client.id)
    const cleanup = result?.cleanup ?? {}
    const details = [
      `Instalaciones eliminadas: ${cleanup.installations ?? 0}`,
      `Supervisiones eliminadas: ${cleanup.supervisions ?? 0}`,
      `Fotos de supervision eliminadas: ${cleanup.supervision_photos ?? 0}`,
    ].join('\n')

    alert(`Cliente eliminado correctamente.\n\nMotivo/impacto por registros relacionados:\n${details}`)
  } catch (e) {
    alert(e?.response?.data?.message ?? 'Error al eliminar el cliente.')
  }
}

function toolbarView() {
  if (!selectedClient.value) return
  router.push(`/clientes/${selectedClient.value.id}`)
}

function toolbarEdit() {
  if (!selectedClient.value) return
  router.push(`/clientes/${selectedClient.value.id}/editar`)
}

function toolbarReview() {
  if (!selectedClient.value) return
  openReviewModal(selectedClient.value)
}

function toolbarDelete() {
  if (!selectedClient.value) return
  deleteClient(selectedClient.value)
}

function refreshMikrotikState() {
  refreshVisibleMikrotikStatuses(true)
}

async function refreshVisibleMikrotikStatuses(force = false) {
  const ids = store.items.map((client) => client.id).filter(Boolean)
  if (!ids.length) return

  try {
    const { data } = await api.get('/clients/mikrotik-statuses', {
      params: {
        ids: ids.join(','),
        ...(force ? { refresh_mikrotik: 1 } : {}),
      },
    })

    const statuses = Array.isArray(data?.data) ? data.data : []
    const byId = new Map(statuses.map((item) => [item.id, item]))

    store.items.forEach((client) => {
      const current = byId.get(client.id)
      if (!current) return
      client.mikrotik_estado = current.mikrotik_estado ?? 'sin_datos'
      client.mikrotik_ip = current.mikrotik_ip ?? null
      client.ip_address = current.ip_address ?? client.ip_address
      if (current.ip_override !== undefined) client.ip_override = current.ip_override
    })

    if (reviewClient.value?.id) {
      const current = byId.get(reviewClient.value.id)
      if (current) {
        reviewClient.value = {
          ...reviewClient.value,
          mikrotik_estado: current.mikrotik_estado ?? 'sin_datos',
          mikrotik_ip: current.mikrotik_ip ?? null,
          ip_address: current.ip_address ?? reviewClient.value.ip_address,
        }
      }
    }
  } catch (e) {
    console.error('Error refreshing MikroTik statuses:', e)
  }
}

function applyDateFilters() {
  store.fetchClients(1)
}

function formatRegistro(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('es-PE', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

function formatTime(value) {
  if (!value) return '—'
  return String(value).slice(0, 5)
}

function installationRange(installation) {
  if (!installation?.hora_inicio || !installation?.hora_fin) return '—'
  return `${formatTime(installation.hora_inicio)} - ${formatTime(installation.hora_fin)}`
}

function reviewPhotoSrc(photo) {
  return resolvePhotoUrl(photo)
}

function reviewPhotosByType(client, type) {
  const photos = Array.isArray(client?.photos) ? client.photos : []
  return photos.filter((photo) => (photo?.photo_type === 'dni' ? 'dni' : 'fachada') === type)
}

async function copyToClipboard(text) {
  if (text === null || text === undefined) return

  const value = String(text)
  if (!value.trim()) return

  try {
    // navigator.clipboard fails on many HTTP/IP contexts; keep a fallback path.
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(value)
      return
    }

    const textArea = document.createElement('textarea')
    textArea.value = value
    textArea.setAttribute('readonly', '')
    textArea.style.position = 'fixed'
    textArea.style.opacity = '0'
    textArea.style.pointerEvents = 'none'
    document.body.appendChild(textArea)
    textArea.focus()
    textArea.select()

    const copied = document.execCommand('copy')
    document.body.removeChild(textArea)

    if (!copied) {
      throw new Error('Clipboard fallback failed')
    }
  } catch (e) {
    console.error('Error copying to clipboard:', e)
  }
}
</script>

<template>
  <div>

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Clientes</h1>
        <p class="text-gray-500 text-sm mt-0.5">
          {{ store.pagination.total }} cliente{{ store.pagination.total !== 1 ? 's' : '' }} registrado{{ store.pagination.total !== 1 ? 's' : '' }}
          <button
            type="button"
            class="ml-2 inline-flex items-center gap-2 text-sm font-medium text-gray-500 hover:text-primary transition-colors"
            :disabled="store.loading"
            @click="refreshMikrotikState"
            title="Actualización automática cada 15 segundos. Haz clic si quieres forzarla ahora."
          >
            <span class="w-6 h-6 rounded-full border-2 border-current flex items-center justify-center">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0A8.003 8.003 0 016.582 15m12.837 0H15" />
              </svg>
            </span>
            MikroTik auto
          </button>
        </p>
      </div>
      <router-link
        to="/clientes/nuevo"
        class="btn-primary inline-flex items-center gap-2 self-start sm:self-auto"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
        </svg>
        Nuevo Cliente
      </router-link>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">

        <!-- Search -->
        <div class="relative lg:col-span-2">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <input
            v-model="searchInput"
            type="text"
            placeholder="Buscar por nombre, DNI o teléfono..."
            class="input pl-10"
          />
        </div>

        <!-- Estado filter -->
        <div class="relative">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6M7 7h10M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z" />
          </svg>
          <select
            :value="store.filters.estado"
            @change="applyFilter('estado', $event.target.value)"
            class="input pl-10"
          >
            <option v-for="e in ESTADOS" :key="e.value" :value="e.value">{{ e.label }}</option>
          </select>
        </div>

        <div class="relative">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10m-12 9h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v11a2 2 0 002 2z" />
          </svg>
          <input
            v-model="selectedMonth"
            type="month"
            class="input pl-10"
            title="Filtrar por mes"
            :disabled="allMonths"
            @change="onMonthChange"
          />
        </div>

        <div v-if="auth.isAdmin" class="relative">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857" />
          </svg>
          <select
            :value="store.filters.user_id"
            @change="applyFilter('user_id', $event.target.value)"
            class="input pl-10"
          >
            <option value="">Todas las vendedoras (general)</option>
            <option v-for="vendor in vendorOptions" :key="vendor.id" :value="String(vendor.id)">{{ vendor.name }}</option>
          </select>
        </div>

        <button
          type="button"
          @click="toggleAllMonths"
          class="px-4 py-2.5 text-sm font-medium rounded-xl border transition-colors"
          :class="allMonths ? 'border-primary bg-primary/10 text-primary' : 'border-gray-200 text-gray-600 hover:border-primary hover:text-primary'"
        >
          {{ allMonths ? 'Todos los meses (activo)' : 'Ver todos los meses' }}
        </button>

        <button
          v-if="store.filters.search || store.filters.estado || store.filters.from || store.filters.to || store.filters.user_id || allMonths"
          @click="store.resetFilters(); searchInput = ''; selectedMonth = new Date().toISOString().slice(0, 7); allMonths = false; applyMonthToFilters(selectedMonth); store.fetchClients(1)"
          class="px-4 py-2.5 text-sm font-medium text-gray-500 border border-gray-200 rounded-xl hover:border-primary hover:text-primary transition-colors lg:col-span-1"
        >
          Limpiar
        </button>

        <div class="text-xs text-gray-500 flex items-center lg:justify-end lg:col-span-1">
          <span>
            {{ allMonths ? 'Vista general de todos los meses' : `Mes seleccionado: ${selectedMonth}` }}
          </span>
        </div>

      </div>
    </div>

    <!-- Table card -->
    <div class="card p-0 overflow-hidden">

      <!-- ── Action toolbar ── -->
      <div class="flex flex-wrap items-center gap-2 px-4 py-2.5 border-b border-gray-100 bg-white">
        <!-- Selection info -->
        <div class="flex items-center gap-1.5 text-xs mr-2 min-w-0 max-w-xs">
          <svg class="w-4 h-4 flex-shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <span v-if="selectedClient" class="font-semibold text-gray-700 truncate">{{ selectedClient.nombre_completo }}</span>
          <span v-else class="text-gray-400">Selecciona un cliente de la tabla</span>
        </div>

        <!-- Ver -->
        <button
          @click="toolbarView"
          :disabled="!selectedClient"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all bg-primary text-white hover:brightness-110 disabled:opacity-40 disabled:cursor-not-allowed shadow-sm"
        >
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
          </svg>
          Ver
        </button>

        <!-- Editar -->
        <button
          @click="toolbarEdit"
          :disabled="!selectedClient"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all bg-blue-600 text-white hover:brightness-110 disabled:opacity-40 disabled:cursor-not-allowed shadow-sm"
        >
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
          </svg>
          Editar
        </button>

        <!-- Revisar Estado (admin) -->
        <button
          v-if="auth.isAdmin"
          @click="toolbarReview"
          :disabled="!selectedClient"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all bg-indigo-600 text-white hover:brightness-110 disabled:opacity-40 disabled:cursor-not-allowed shadow-sm"
        >
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          Revisar Estado
        </button>

        <!-- Asignar IP (admin) -->
        <button
          v-if="auth.isAdmin"
          @click="openAssignIpModal"
          :disabled="!selectedClient"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all bg-amber-500 text-white hover:brightness-110 disabled:opacity-40 disabled:cursor-not-allowed shadow-sm"
        >
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
          </svg>
          Asignar IP
        </button>

        <!-- Eliminar (admin) -->
        <button
          v-if="auth.isAdmin"
          @click="toolbarDelete"
          :disabled="!selectedClient"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all bg-red-600 text-white hover:brightness-110 disabled:opacity-40 disabled:cursor-not-allowed shadow-sm"
        >
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
          Eliminar
        </button>
      </div>

      <!-- Loading state -->
      <div v-if="store.loading" class="flex items-center justify-center py-16">
        <svg class="w-8 h-8 text-primary animate-spin" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
      </div>

      <!-- Empty state -->
      <div v-else-if="!store.hasClients" class="flex flex-col items-center justify-center py-16 text-center px-4">
        <div class="w-14 h-14 bg-gray-100 rounded-2xl flex items-center justify-center mb-4">
          <svg class="w-7 h-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0" />
          </svg>
        </div>
        <p class="font-semibold text-gray-700">No hay clientes</p>
        <p class="text-sm text-gray-400 mt-1">
          {{ store.filters.search || store.filters.estado ? 'Intenta cambiar los filtros.' : 'Registra el primer cliente.' }}
        </p>
      </div>

      <!-- Table -->
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[980px] text-sm">
          <thead>
            <tr class="border-b border-gray-100 bg-gray-50/60">
              <th class="w-10 px-3 py-3" />
              <th class="text-left px-5 py-3 font-semibold text-gray-500 text-xs uppercase tracking-wide">Cliente</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-500 text-xs uppercase tracking-wide">DNI</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-500 text-xs uppercase tracking-wide">Teléfono</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-500 text-xs uppercase tracking-wide">Distrito</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-500 text-xs uppercase tracking-wide">Registro</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-500 text-xs uppercase tracking-wide">IP Address</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-500 text-xs uppercase tracking-wide">Estado</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-500 text-xs uppercase tracking-wide">Estado MikroTik</th>
              <th v-if="auth.isAdmin" class="text-left px-5 py-3 font-semibold text-gray-500 text-xs uppercase tracking-wide hidden xl:table-cell">Vendedora</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr
              v-for="client in store.items"
              :key="client.id"
              class="transition-colors cursor-pointer select-none"
              :class="selectedClientId === client.id ? 'bg-primary/5 border-l-[3px] border-primary' : 'hover:bg-gray-50/60'"
              @click="toggleSelect(client)"
            >
              <!-- Checkbox -->
              <td class="px-3 py-3.5 w-10">
                <div :class="['w-5 h-5 rounded border-2 flex items-center justify-center flex-shrink-0 transition-all', selectedClientId === client.id ? 'bg-primary border-primary' : 'border-gray-300']">
                  <svg v-if="selectedClientId === client.id" class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                  </svg>
                </div>
              </td>

              <!-- Name + initials -->
              <td class="px-5 py-3.5">
                <div class="flex items-center gap-3">
                  <div class="w-8 h-8 rounded-lg bg-primary/10 text-primary font-bold text-xs flex items-center justify-center flex-shrink-0 select-none">
                    {{ (client.nombres?.[0] ?? '') + (client.apellidos?.[0] ?? '') }}
                  </div>
                  <div>
                    <p class="font-medium text-gray-900 leading-tight">{{ client.nombre_completo }}</p>
                  </div>
                </div>
              </td>

              <td class="px-5 py-3.5 text-gray-600 font-mono text-xs tracking-wider">{{ client.dni }}</td>
              <td class="px-5 py-3.5 text-gray-600">{{ client.telefono_1 }}</td>
              <td class="px-5 py-3.5 text-gray-600 text-xs">{{ client.distrito }}</td>
              <td class="px-5 py-3.5 text-gray-600 text-xs">{{ formatRegistro(client.created_at) }}</td>

              <!-- IP Address -->
              <td class="px-5 py-3.5">
                <span
                  v-if="client.mikrotik_estado !== 'sin_datos' && (client.mikrotik_ip || client.ip_address)"
                  class="inline-flex items-center gap-1.5 font-mono text-xs text-gray-600 bg-gray-100 px-2 py-0.5 rounded"
                >
                  {{ client.mikrotik_ip || client.ip_address }}
                  <span
                    v-if="client.ip_override"
                    class="w-2 h-2 rounded-full bg-pink-400 flex-shrink-0"
                    title="IP asignada manualmente"
                  />
                </span>
                <span v-else-if="client.ip_address" class="inline-flex items-center gap-1.5 font-mono text-xs text-gray-400 bg-gray-50 px-2 py-0.5 rounded">
                  {{ client.ip_address }}
                  <span
                    v-if="client.ip_override"
                    class="w-2 h-2 rounded-full bg-pink-400 flex-shrink-0"
                    title="IP asignada manualmente"
                  />
                </span>
                <span v-else class="text-xs text-gray-300">—</span>
              </td>

              <!-- Estado -->
              <td class="px-5 py-3.5">
                <span
                  class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold ring-1 capitalize"
                  :class="estadoBadge[commercialState(client)]"
                >
                  {{ estadoLabel[commercialState(client)] || commercialState(client) }}
                </span>
              </td>

              <!-- Estado MikroTik -->
              <td class="px-5 py-3.5">
                <span :class="['inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-bold ring-1 uppercase tracking-wide', mikrotikState(client).bg, mikrotikState(client).text, mikrotikState(client).ring]">
                  <span :class="['w-1.5 h-1.5 rounded-full', mikrotikState(client).dot]" />
                  {{ mikrotikState(client).label }}
                </span>
              </td>

              <!-- Vendedora (admin only) -->
              <td v-if="auth.isAdmin" class="px-5 py-3.5 text-gray-500 text-xs hidden xl:table-cell">
                {{ client.vendedora?.name ?? '—' }}
              </td>

            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div
        v-if="store.pagination.last_page > 1"
        class="flex items-center justify-between px-5 py-3.5 border-t border-gray-100 bg-gray-50/40"
      >
        <p class="text-xs text-gray-500">
          Página {{ store.pagination.current_page }} de {{ store.pagination.last_page }}
          &nbsp;·&nbsp; {{ store.pagination.total }} registros
        </p>

        <div class="flex items-center gap-1">
          <button
            @click="changePage(store.pagination.current_page - 1)"
            :disabled="store.pagination.current_page === 1"
            class="px-3 py-1.5 rounded-lg text-xs font-medium border border-gray-200 text-gray-600
                   hover:border-primary hover:text-primary disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
          >
            ← Anterior
          </button>

          <template v-for="page in store.pagination.last_page" :key="page">
            <button
              v-if="Math.abs(page - store.pagination.current_page) <= 2"
              @click="changePage(page)"
              :class="[
                'w-8 h-8 rounded-lg text-xs font-semibold transition-colors',
                page === store.pagination.current_page
                  ? 'bg-primary text-white shadow-primary'
                  : 'text-gray-600 hover:bg-gray-100',
              ]"
            >
              {{ page }}
            </button>
          </template>

          <button
            @click="changePage(store.pagination.current_page + 1)"
            :disabled="store.pagination.current_page === store.pagination.last_page"
            class="px-3 py-1.5 rounded-lg text-xs font-medium border border-gray-200 text-gray-600
                   hover:border-primary hover:text-primary disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
          >
            Siguiente →
          </button>
        </div>
      </div>

    </div>

    <div
      v-if="reviewModalOpen && reviewClient"
      class="fixed inset-0 z-50 flex items-center justify-center px-4"
    >
      <div class="absolute inset-0 bg-black/40" @click="closeReviewModal" />

      <div class="relative z-10 w-full max-w-4xl rounded-2xl bg-white shadow-2xl border border-gray-100 overflow-hidden max-h-[94vh] overflow-y-auto">
        <div class="px-5 py-3 border-b border-gray-100 flex items-start justify-between gap-3 sticky top-0 bg-white">
          <div>
            <h3 class="text-lg font-bold text-gray-900">Revisión de cliente</h3>
            <p class="text-sm text-gray-500">Verifica todos los datos registrados.</p>
            <p class="text-[11px] text-primary font-semibold mt-0.5 leading-tight">
              Pagina {{ reviewPage }} de {{ reviewTotalPages }} - {{ reviewStepLabels[reviewPage - 1] }}
            </p>
          </div>
          <button
            type="button"
            class="text-gray-400 hover:text-gray-700 transition-colors"
            :disabled="savingStatus"
            @click="closeReviewModal"
            title="Cerrar"
          >
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="p-4 space-y-3 flex flex-col">
          <!-- Datos Personales -->
          <div v-show="reviewPage === 1">
            <h4 class="text-sm font-bold text-gray-900 mb-2 pb-1 border-b border-gray-200">DATOS PERSONALES</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <!-- Nombre Completo -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors md:col-span-2">
                <div class="flex-1">
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Nombre Completo</p>
                  <p class="font-semibold text-gray-800">{{ reviewClient.nombre_completo }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.nombre_completo)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar nombre completo"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- DNI -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">DNI</p>
                  <p class="font-semibold text-gray-800 font-mono">{{ reviewClient.dni || '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.dni)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar DNI"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Teléfono 1 -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Teléfono 1</p>
                  <p class="font-semibold text-gray-800">{{ reviewClient.telefono_1 || '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.telefono_1)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar teléfono"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Teléfono 2 -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Teléfono 2</p>
                  <p class="font-semibold text-gray-800">{{ reviewClient.telefono_2 || '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.telefono_2)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar teléfono 2"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Plan (con sombreado rosado) -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-rose-50 hover:bg-rose-100 transition-colors border border-rose-200 md:col-span-2">
                <div class="flex-1">
                  <p class="text-xs text-rose-600 uppercase tracking-wide font-semibold">Plan</p>
                  <p class="font-semibold text-rose-900">
                    {{ reviewClient.plan ? `${reviewClient.plan.nombre} - S/ ${reviewClient.plan.precio || '0.00'}` : (reviewClient.plan_id || '—') }}
                  </p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.plan ? `${reviewClient.plan.nombre} - S/ ${reviewClient.plan.precio || '0.00'}` : (reviewClient.plan_id || ''))"
                  class="flex-shrink-0 p-1.5 text-rose-400 hover:text-rose-700 hover:bg-white rounded transition-colors"
                  title="Copiar plan"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>
            </div>
          </div>

          <!-- Ubicación -->
          <div v-show="reviewPage === 2">
            <h4 class="text-sm font-bold text-gray-900 mb-2 pb-1 border-b border-gray-200">UBICACIÓN</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <!-- Departamento -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Departamento</p>
                  <p class="font-semibold text-gray-800">{{ reviewClient.departamento || '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.departamento)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar departamento"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Provincia -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Provincia</p>
                  <p class="font-semibold text-gray-800">{{ reviewClient.provincia || '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.provincia)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar provincia"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Distrito -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Distrito</p>
                  <p class="font-semibold text-gray-800">{{ reviewClient.distrito || '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.distrito)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar distrito"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Dirección -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors md:col-span-2">
                <div class="flex-1">
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Dirección</p>
                  <p class="font-semibold text-gray-800">{{ reviewClient.direccion || '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.direccion)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar dirección"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Referencia -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors md:col-span-2">
                <div class="flex-1">
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Referencia</p>
                  <p class="font-semibold text-gray-800">{{ reviewClient.referencia || '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.referencia)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar referencia"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Latitud -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Latitud</p>
                  <p class="font-semibold text-gray-800 font-mono">{{ reviewClient.latitud ?? '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.latitud)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar latitud"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Longitud -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Longitud</p>
                  <p class="font-semibold text-gray-800 font-mono">{{ reviewClient.longitud ?? '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.longitud)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar longitud"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>
            </div>
          </div>

          <!-- Información del Servicio -->
          <div v-show="reviewPage === 3">
            <h4 class="text-sm font-bold text-gray-900 mb-2 pb-1 border-b border-gray-200">SERVICIO</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <!-- Estado Comercial -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Estado Comercial</p>
                  <span
                    class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold ring-1 capitalize mt-1"
                    :class="estadoBadge[commercialState(reviewClient)]"
                  >
                    {{ estadoLabel[commercialState(reviewClient)] || commercialState(reviewClient) }}
                  </span>
                </div>
              </div>

              <!-- IP Address -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">IP Address</p>
                  <p class="font-semibold text-gray-800 font-mono">
                    {{ reviewClient.mikrotik_estado !== 'sin_datos' ? (reviewClient.mikrotik_ip || reviewClient.ip_address || '—') : '—' }}
                  </p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.mikrotik_estado !== 'sin_datos' ? (reviewClient.mikrotik_ip || reviewClient.ip_address) : null)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar IP"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Estado MikroTik -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Estado MikroTik</p>
                  <span :class="['inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-bold ring-1 uppercase tracking-wide mt-1', mikrotikState(reviewClient).bg, mikrotikState(reviewClient).text, mikrotikState(reviewClient).ring]">
                    <span :class="['w-1.5 h-1.5 rounded-full', mikrotikState(reviewClient).dot]" />
                    {{ mikrotikState(reviewClient).label }}
                  </span>
                </div>
              </div>

              <!-- Usuario MikroTik -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Usuario MikroTik</p>
                  <p class="font-semibold text-gray-800">{{ reviewClient.mikrotik_user || '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.mikrotik_user)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar usuario"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Perfil MikroTik -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Perfil MikroTik</p>
                  <p class="font-semibold text-gray-800">{{ reviewClient.mikrotik_profile || '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.mikrotik_profile)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar perfil"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Fecha Vencimiento -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Vencimiento</p>
                  <p class="font-semibold text-gray-800">{{ reviewClient.fecha_vencimiento ? formatRegistro(reviewClient.fecha_vencimiento) : '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.fecha_vencimiento)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar fecha"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Fecha instalación -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-indigo-50 hover:bg-indigo-100 transition-colors border border-indigo-200">
                <div>
                  <p class="text-xs text-indigo-600 uppercase tracking-wide">Fecha instalación</p>
                  <p class="font-semibold text-indigo-900">
                    {{ reviewClient.latest_installation?.fecha ? formatRegistro(reviewClient.latest_installation.fecha) : '—' }}
                  </p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.latest_installation?.fecha)"
                  class="flex-shrink-0 p-1.5 text-indigo-400 hover:text-indigo-700 hover:bg-white rounded transition-colors"
                  title="Copiar fecha instalación"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Horario instalación -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-indigo-50 hover:bg-indigo-100 transition-colors border border-indigo-200">
                <div>
                  <p class="text-xs text-indigo-600 uppercase tracking-wide">Horario instalación</p>
                  <p class="font-semibold text-indigo-900">{{ installationRange(reviewClient.latest_installation) }}</p>
                </div>
                <button
                  @click="copyToClipboard(installationRange(reviewClient.latest_installation))"
                  class="flex-shrink-0 p-1.5 text-indigo-400 hover:text-indigo-700 hover:bg-white rounded transition-colors"
                  title="Copiar horario instalación"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Duración instalación -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-indigo-50 hover:bg-indigo-100 transition-colors border border-indigo-200">
                <div>
                  <p class="text-xs text-indigo-600 uppercase tracking-wide">Duración instalación</p>
                  <p class="font-semibold text-indigo-900">
                    {{ reviewClient.latest_installation?.duracion ? `${reviewClient.latest_installation.duracion}h` : '—' }}
                  </p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.latest_installation?.duracion ? `${reviewClient.latest_installation.duracion}h` : '')"
                  class="flex-shrink-0 p-1.5 text-indigo-400 hover:text-indigo-700 hover:bg-white rounded transition-colors"
                  title="Copiar duración instalación"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              <!-- Estado instalación -->
              <div class="flex items-start justify-between gap-2 p-3 rounded-lg bg-indigo-50 hover:bg-indigo-100 transition-colors border border-indigo-200">
                <div>
                  <p class="text-xs text-indigo-600 uppercase tracking-wide">Estado instalación</p>
                  <p class="font-semibold text-indigo-900 capitalize">{{ reviewClient.latest_installation?.estado || '—' }}</p>
                </div>
              </div>

              <!-- Vendedora -->
              <div v-if="auth.isAdmin" class="flex items-start justify-between gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <div>
                  <p class="text-xs text-gray-500 uppercase tracking-wide">Vendedora</p>
                  <p class="font-semibold text-gray-800">{{ reviewClient.vendedora?.name || '—' }}</p>
                </div>
                <button
                  @click="copyToClipboard(reviewClient.vendedora?.name)"
                  class="flex-shrink-0 p-1.5 text-gray-400 hover:text-primary hover:bg-white rounded transition-colors"
                  title="Copiar vendedora"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>
            </div>
          </div>

          <!-- Cambiar Estado -->
          <div v-show="reviewPage === 4" class="order-2">
            <h4 class="text-sm font-bold text-gray-900 mb-2 pb-1 border-b border-gray-200">CAMBIAR ESTADO</h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
              <button
                v-for="option in adminStatusOptions"
                :key="option.value"
                type="button"
                :disabled="savingStatus"
                @click="reviewStatus = option.value"
                :class="[
                  'px-3 py-2 rounded-lg border text-sm font-semibold transition-colors text-left',
                  reviewStatus === option.value
                    ? 'border-primary bg-primary/10 text-primary ring-1 ring-primary/30'
                    : 'border-gray-200 text-gray-700 hover:border-primary/50 hover:bg-primary/5',
                ]"
              >
                <span>{{ option.label }}</span>
              </button>
            </div>
          </div>

          <!-- Evidencias / Fotos (al final) -->
          <div v-show="reviewPage === 4" class="order-1">
            <h4 class="text-sm font-bold text-gray-900 mb-2 pb-1 border-b border-gray-200">DOCUMENTOS DEL CLIENTE</h4>

            <div v-if="Array.isArray(reviewClient.photos) && reviewClient.photos.length" class="space-y-3">
              <div>
                <p class="text-sm font-semibold text-gray-700 mb-2">Foto de la fachada</p>
                <div v-if="reviewPhotosByType(reviewClient, 'fachada').length" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                  <a
                    v-for="photo in reviewPhotosByType(reviewClient, 'fachada')"
                    :key="`fachada-${photo.id}`"
                    :href="reviewPhotoSrc(photo)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="block group"
                    title="Abrir imagen en nueva ventana"
                  >
                    <div class="relative rounded-lg overflow-hidden bg-gray-100 border border-gray-200 group-hover:border-primary transition-colors">
                      <img
                        :src="reviewPhotoSrc(photo)"
                        :alt="`Fachada ${photo.id}`"
                        class="w-full h-32 object-cover group-hover:opacity-80 transition-opacity"
                      />
                      <div class="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors flex items-center justify-center">
                        <svg class="w-5 h-5 text-white opacity-0 group-hover:opacity-100 transition-opacity" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                      </div>
                    </div>
                  </a>
                </div>
                <p v-else class="text-sm text-gray-500 p-3 bg-gray-50 rounded-lg">No hay foto de fachada registrada.</p>
              </div>

              <div>
                <p class="text-sm font-semibold text-gray-700 mb-2">Cara y reverso DNI</p>
                <div v-if="reviewPhotosByType(reviewClient, 'dni').length" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                  <a
                    v-for="photo in reviewPhotosByType(reviewClient, 'dni')"
                    :key="`dni-${photo.id}`"
                    :href="reviewPhotoSrc(photo)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="block group"
                    title="Abrir imagen en nueva ventana"
                  >
                    <div class="relative rounded-lg overflow-hidden bg-gray-100 border border-gray-200 group-hover:border-primary transition-colors">
                      <img
                        :src="reviewPhotoSrc(photo)"
                        :alt="`DNI ${photo.id}`"
                        class="w-full h-32 object-cover group-hover:opacity-80 transition-opacity"
                      />
                      <div class="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors flex items-center justify-center">
                        <svg class="w-5 h-5 text-white opacity-0 group-hover:opacity-100 transition-opacity" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                      </div>
                    </div>
                  </a>
                </div>
                <p v-else class="text-sm text-gray-500 p-3 bg-gray-50 rounded-lg">No hay foto de DNI registrada.</p>
              </div>
            </div>

            <p v-else class="text-sm text-gray-500 p-4 bg-gray-50 rounded-lg text-center">
              Este cliente no tiene fotos registradas.
            </p>
          </div>
        </div>

        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sticky bottom-0">
          <div class="flex items-center gap-2">
            <button
              type="button"
              class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-100 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="reviewPage === 1 || savingStatus"
              @click="prevReviewPage"
            >
              ← Anterior
            </button>
            <button
              type="button"
              class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-100 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="reviewPage === reviewTotalPages || savingStatus"
              @click="nextReviewPage"
            >
              Siguiente →
            </button>
            <span class="text-xs font-semibold text-primary px-2">
              Pagina {{ reviewPage }} de {{ reviewTotalPages }}
            </span>
          </div>

          <div class="flex items-center justify-end gap-2">
          <button
            type="button"
            class="px-4 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-100 transition-colors"
            :disabled="savingStatus"
            @click="closeReviewModal"
          >
            Cancelar
          </button>
          <button
            type="button"
            class="px-4 py-2 rounded-lg bg-primary text-white hover:opacity-90 transition-opacity disabled:opacity-60"
            :disabled="savingStatus"
            @click="saveClientStatus"
          >
            {{ savingStatus ? 'Guardando...' : 'Guardar estado' }}
          </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Asignar IP Modal ── -->
    <div
      v-if="showAssignIpModal && selectedClient"
      class="fixed inset-0 z-50 flex items-center justify-center px-4"
    >
      <div class="absolute inset-0 bg-black/40" @click="closeAssignIpModal" />

      <div class="relative z-10 w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-gray-100 overflow-hidden max-h-[90vh] flex flex-col">

        <!-- Modal header -->
        <div class="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3 flex-shrink-0">
          <div>
            <h3 class="text-base font-bold text-gray-900">Asignar IP</h3>
            <p class="text-sm text-gray-500 mt-0.5">{{ selectedClient.nombre_completo }}</p>
            <p v-if="selectedClient.ip_address" class="text-xs text-gray-400 mt-1 font-mono">
              IP actual:
              <span class="inline-flex items-center gap-1">
                {{ selectedClient.ip_address }}
                <span v-if="selectedClient.ip_override" class="w-2 h-2 rounded-full bg-pink-400" title="Asignada manualmente" />
              </span>
            </p>
            <p v-else class="text-xs text-gray-400 mt-1">Sin IP asignada</p>
          </div>
          <button
            type="button"
            class="text-gray-400 hover:text-gray-700 transition-colors"
            :disabled="assigningIp"
            @click="closeAssignIpModal"
          >
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Modal body -->
        <div class="overflow-y-auto flex-1 p-5 space-y-5">

          <!-- IP form -->
          <div class="space-y-3">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">Nueva dirección IP *</label>
              <input
                v-model="assignIpForm.ip"
                type="text"
                placeholder="Ej: 192.168.1.100"
                class="input font-mono"
                :disabled="assigningIp"
                @keyup.enter="submitAssignIp"
              />
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">
                Notas <span class="font-normal text-gray-400">(opcional)</span>
              </label>
              <textarea
                v-model="assignIpForm.notes"
                rows="2"
                placeholder="Motivo del cambio de IP..."
                class="input resize-none"
                :disabled="assigningIp"
              />
            </div>
            <p v-if="assignIpError" class="text-xs text-red-600 font-medium">{{ assignIpError }}</p>
          </div>

          <!-- Action buttons -->
          <div class="flex justify-between gap-2">
            <!-- Limpiar IP (solo si hay ip_override activo) -->
            <button
              v-if="selectedClient?.ip_override"
              type="button"
              class="px-3 py-2 rounded-lg border border-red-200 text-red-600 text-xs font-semibold hover:bg-red-50 transition-colors disabled:opacity-60 flex items-center gap-1.5"
              :disabled="clearingIp || assigningIp"
              @click="submitClearIp"
            >
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
              {{ clearingIp ? 'Limpiando...' : 'Limpiar IP asignada' }}
            </button>
            <div v-else />

            <div class="flex gap-2">
              <button
                type="button"
                class="px-4 py-2 rounded-lg border border-gray-200 text-gray-700 text-sm hover:bg-gray-50 transition-colors"
                :disabled="assigningIp || clearingIp"
                @click="closeAssignIpModal"
              >
                Cancelar
              </button>
              <button
                type="button"
                class="px-4 py-2 rounded-lg bg-amber-500 text-white text-sm font-semibold hover:bg-amber-600 transition-colors disabled:opacity-60"
                :disabled="assigningIp || clearingIp || !assignIpForm.ip.trim()"
                @click="submitAssignIp"
              >
                {{ assigningIp ? 'Asignando...' : 'Asignar IP' }}
              </button>
            </div>
          </div>

          <!-- History section -->
          <div class="border-t border-gray-100 pt-4">
            <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wide mb-3 flex items-center gap-2">
              <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              Historial de cambios de IP
            </h4>

            <div v-if="ipHistoryLoading" class="flex justify-center py-4">
              <svg class="w-5 h-5 text-primary animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
            </div>

            <div v-else-if="!ipHistoryList.length" class="text-xs text-gray-400 text-center py-4">
              Sin historial de cambios de IP.
            </div>

            <div v-else class="space-y-2">
              <div
                v-for="entry in ipHistoryList"
                :key="entry.id"
                class="rounded-xl border border-gray-100 bg-gray-50 px-3 py-2.5 text-xs"
              >
                <div class="flex items-center justify-between gap-2 mb-1">
                  <span class="text-gray-400">{{ formatHistoryDate(entry.created_at) }}</span>
                  <span class="text-gray-500 font-medium">{{ entry.assigned_by?.name ?? '—' }}</span>
                </div>
                <div class="flex items-center gap-2 font-mono">
                  <span class="text-gray-400">{{ entry.previous_ip ?? 'sin IP' }}</span>
                  <svg class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                  </svg>
                  <span :class="entry.new_ip ? 'font-semibold text-gray-800' : 'text-red-500 font-semibold'">
                    {{ entry.new_ip ?? '[limpiada]' }}
                  </span>
                </div>
                <p v-if="entry.notes" class="text-gray-500 mt-1 not-italic">{{ entry.notes }}</p>
              </div>

              <!-- Pagination for history -->
              <div v-if="ipHistoryLastPage > 1" class="flex justify-center gap-2 pt-2">
                <button
                  class="px-3 py-1 rounded-lg text-xs border border-gray-200 text-gray-600 hover:border-primary disabled:opacity-40 disabled:cursor-not-allowed"
                  :disabled="ipHistoryPage === 1 || ipHistoryLoading"
                  @click="loadIpHistory(ipHistoryPage - 1)"
                >
                  ← Anterior
                </button>
                <span class="px-2 py-1 text-xs text-gray-500">{{ ipHistoryPage }} / {{ ipHistoryLastPage }}</span>
                <button
                  class="px-3 py-1 rounded-lg text-xs border border-gray-200 text-gray-600 hover:border-primary disabled:opacity-40 disabled:cursor-not-allowed"
                  :disabled="ipHistoryPage === ipHistoryLastPage || ipHistoryLoading"
                  @click="loadIpHistory(ipHistoryPage + 1)"
                >
                  Siguiente →
                </button>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

  </div>
</template>
