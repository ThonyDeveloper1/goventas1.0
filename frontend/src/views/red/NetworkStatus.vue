<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useNetworkStore } from '@/store/network'
import { useAuthStore } from '@/store/auth'

const store = useNetworkStore()
const auth  = useAuthStore()

/* ── Polling ──────────────────────────────────────────── */
let pollTimer = null
const POLL_INTERVAL = 30000 // 30s

onMounted(async () => {
  await Promise.all([store.fetchOverview(), store.fetchClients()])
  pollTimer = setInterval(() => store.fetchOverview(), POLL_INTERVAL)
})

onUnmounted(() => {
  clearInterval(pollTimer)
})

/* ── Filters ──────────────────────────────────────────── */
const searchInput = ref('')
let searchTimer   = null

watch(searchInput, (val) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    store.setFilter('search', val)
    store.fetchClients({ page: 1 })
  }, 400)
})

function filterByStatus(status) {
  store.setFilter('service_status', status === store.filters.service_status ? '' : status)
  store.fetchClients({ page: 1 })
}

function goToPage(page) {
  store.fetchClients({ page })
}

/* ── Actions ──────────────────────────────────────────── */
const actionLoading = ref({})   // { [clientId]: 'activating'|'suspending' }
const actionMessage = ref('')

async function handleActivate(client) {
  actionLoading.value[client.id] = 'activating'
  actionMessage.value = ''
  try {
    const result = await store.activateClient(client.id)
    actionMessage.value = result.message
    store.fetchOverview()
  } catch (e) {
    actionMessage.value = e.response?.data?.message || 'Error al activar.'
  } finally {
    delete actionLoading.value[client.id]
  }
}

async function handleSuspend(client) {
  actionLoading.value[client.id] = 'suspending'
  actionMessage.value = ''
  try {
    const result = await store.suspendClient(client.id)
    actionMessage.value = result.message
    store.fetchOverview()
  } catch (e) {
    actionMessage.value = e.response?.data?.message || 'Error al suspender.'
  } finally {
    delete actionLoading.value[client.id]
  }
}

async function handleSync() {
  actionMessage.value = ''
  try {
    const result = await store.syncAll()
    actionMessage.value = result.message
  } catch (e) {
    actionMessage.value = e.response?.data?.message || 'Error en sincronización.'
  }
}

/* ── Provision modal ──────────────────────────────────── */
const showProvision  = ref(false)
const provisionClient = ref(null)
const provisionForm   = ref({ mikrotik_user: '', mikrotik_password: '', mikrotik_profile: 'default' })
const provisionError  = ref('')
const provisioning    = ref(false)

function openProvision(client) {
  provisionClient.value = client
  provisionForm.value = {
    mikrotik_user:     `pppoe_${client.dni}`,
    mikrotik_password: '',
    mikrotik_profile:  'default',
  }
  provisionError.value = ''
  showProvision.value  = true
}

async function handleProvision() {
  provisionError.value = ''
  provisioning.value   = true
  try {
    await store.provisionClient(provisionClient.value.id, provisionForm.value)
    showProvision.value = false
    actionMessage.value = `Usuario PPPoE creado: ${provisionForm.value.mikrotik_user}`
    store.fetchOverview()
  } catch (e) {
    const errs = e.response?.data?.errors
    provisionError.value = errs
      ? Object.values(errs).flat().join(' ')
      : e.response?.data?.message || 'Error al crear usuario.'
  } finally {
    provisioning.value = false
  }
}

/* ── Status helpers ───────────────────────────────────── */
const STATUS = {
  activo:     { label: 'Activo',     bg: 'bg-green-50',  text: 'text-green-700', dot: 'bg-green-400',  ring: 'ring-green-200'  },
  suspendido: { label: 'Suspendido', bg: 'bg-red-50',    text: 'text-red-600',   dot: 'bg-red-400',    ring: 'ring-red-200'    },
  cortado:    { label: 'Cortado',    bg: 'bg-gray-100',  text: 'text-gray-600',  dot: 'bg-gray-400',   ring: 'ring-gray-200'   },
}

function sb(status) { return STATUS[status] || STATUS.suspendido }
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Estado de Red</h1>
        <p class="text-gray-500 text-sm mt-0.5">Monitoreo en tiempo real · MikroTik</p>
      </div>
      <div v-if="auth.isAdmin" class="flex gap-2">
        <button
          @click="handleSync"
          :disabled="store.syncing"
          class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-medium text-gray-600 hover:border-gray-300 transition-colors"
        >
          <svg :class="['w-4 h-4', store.syncing && 'animate-spin']" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          {{ store.syncing ? 'Sincronizando...' : 'Sincronizar MikroTik' }}
        </button>
      </div>
    </div>

    <!-- Alert -->
    <Transition name="fade">
      <div v-if="actionMessage" class="bg-blue-50 border border-blue-200 text-blue-700 text-sm rounded-xl px-4 py-3 mb-4 flex items-center gap-2">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        {{ actionMessage }}
        <button @click="actionMessage = ''" class="ml-auto text-blue-400 hover:text-blue-600">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </Transition>

    <!-- ── Overview cards ─────────────────────────────────── -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
      <button
        @click="filterByStatus('')"
        :class="['card text-center py-4 transition-all', !store.filters.service_status ? 'ring-2 ring-primary/30' : '']"
      >
        <p class="text-2xl font-bold text-gray-800">{{ store.overview.total }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Total configurados</p>
      </button>
      <button
        @click="filterByStatus('activo')"
        :class="['card text-center py-4 transition-all', store.filters.service_status === 'activo' ? 'ring-2 ring-green-300' : '']"
      >
        <p class="text-2xl font-bold text-green-600">{{ store.overview.activos }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Activos</p>
      </button>
      <button
        @click="filterByStatus('suspendido')"
        :class="['card text-center py-4 transition-all', store.filters.service_status === 'suspendido' ? 'ring-2 ring-red-300' : '']"
      >
        <p class="text-2xl font-bold text-red-600">{{ store.overview.suspendidos }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Suspendidos</p>
      </button>
      <button
        @click="filterByStatus('cortado')"
        :class="['card text-center py-4 transition-all', store.filters.service_status === 'cortado' ? 'ring-2 ring-gray-300' : '']"
      >
        <p class="text-2xl font-bold text-gray-500">{{ store.overview.cortados }}</p>
        <p class="text-xs text-gray-500 mt-0.5">Cortados</p>
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
          placeholder="Buscar por nombre, DNI o usuario PPPoE..."
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
    <div v-else-if="store.clients.length === 0" class="card text-center py-16">
      <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
      </svg>
      <p class="text-gray-500 text-sm">No se encontraron clientes.</p>
    </div>

    <!-- ── Client list ────────────────────────────────────── -->
    <div v-else class="space-y-2">
      <div
        v-for="client in store.clients"
        :key="client.id"
        class="card hover:shadow-md transition-all"
      >
        <div class="flex items-center gap-3 sm:gap-4">
          <!-- Status indicator -->
          <div :class="['w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 ring-1', sb(client.service_status).bg, sb(client.service_status).ring]">
            <!-- WiFi icon for active -->
            <svg v-if="client.service_status === 'activo'" class="w-5 h-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0"/>
            </svg>
            <!-- X for suspended -->
            <svg v-else-if="client.service_status === 'suspendido'" class="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
            </svg>
            <!-- Minus for cortado -->
            <svg v-else class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
            </svg>
          </div>

          <!-- Client info -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <h3 class="font-semibold text-gray-800 text-sm sm:text-base truncate">
                {{ client.nombres }} {{ client.apellidos }}
              </h3>
              <span :class="['px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide', sb(client.service_status).bg, sb(client.service_status).text]">
                {{ sb(client.service_status).label }}
              </span>
            </div>
            <div class="mt-0.5 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-gray-500">
              <span>DNI {{ client.dni }}</span>
              <span v-if="client.mikrotik_user" class="font-mono">{{ client.mikrotik_user }}</span>
              <span v-else class="italic text-gray-400">Sin PPPoE</span>
              <span v-if="client.distrito">{{ client.distrito }}</span>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex gap-1.5 flex-shrink-0" v-if="auth.isAdmin">
            <!-- Provision button (no mikrotik user) -->
            <button
              v-if="!client.mikrotik_user"
              @click.stop="openProvision(client)"
              class="p-2.5 rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors"
              title="Configurar PPPoE"
            >
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
              </svg>
            </button>

            <!-- Activate -->
            <button
              v-if="client.mikrotik_user && client.service_status !== 'activo'"
              @click.stop="handleActivate(client)"
              :disabled="!!actionLoading[client.id]"
              class="p-2.5 rounded-xl bg-green-50 text-green-600 hover:bg-green-100 transition-colors"
              title="Activar servicio"
            >
              <svg v-if="actionLoading[client.id] === 'activating'" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>
              <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
              </svg>
            </button>

            <!-- Suspend -->
            <button
              v-if="client.mikrotik_user && client.service_status === 'activo'"
              @click.stop="handleSuspend(client)"
              :disabled="!!actionLoading[client.id]"
              class="p-2.5 rounded-xl bg-red-50 text-red-600 hover:bg-red-100 transition-colors"
              title="Suspender servicio"
            >
              <svg v-if="actionLoading[client.id] === 'suspending'" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>
              <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
              </svg>
            </button>
          </div>
        </div>
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

    <!-- ── Provision Modal ────────────────────────────────── -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showProvision" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" @click.self="showProvision = false">
          <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6" @click.stop>
            <h2 class="text-lg font-bold text-gray-900 mb-1">Configurar PPPoE</h2>
            <p class="text-sm text-gray-500 mb-4">
              {{ provisionClient?.nombres }} {{ provisionClient?.apellidos }} — DNI {{ provisionClient?.dni }}
            </p>

            <div v-if="provisionError" class="bg-red-50 border border-red-200 text-red-600 text-sm rounded-xl px-4 py-2.5 mb-4">
              {{ provisionError }}
            </div>

            <form @submit.prevent="handleProvision" class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Usuario PPPoE *</label>
                <input v-model="provisionForm.mikrotik_user" type="text" class="input text-sm font-mono" placeholder="pppoe_12345678" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña *</label>
                <input v-model="provisionForm.mikrotik_password" type="text" class="input text-sm font-mono" placeholder="Contraseña PPPoE" autocomplete="off" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Perfil</label>
                <input v-model="provisionForm.mikrotik_profile" type="text" class="input text-sm" placeholder="default" />
              </div>

              <div class="flex gap-3 justify-end pt-2">
                <button type="button" @click="showProvision = false" class="px-4 py-2 rounded-xl border border-gray-200 text-sm font-medium text-gray-600 hover:border-gray-300 transition-colors">
                  Cancelar
                </button>
                <button
                  type="submit"
                  :disabled="provisioning || !provisionForm.mikrotik_user || !provisionForm.mikrotik_password"
                  class="btn-primary px-6"
                >
                  <svg v-if="provisioning" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                  </svg>
                  {{ provisioning ? 'Creando...' : 'Crear PPPoE' }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to        { opacity: 0; }
</style>
