<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useMorososStore } from '@/store/morosos'

const store = useMorososStore()

/* ── Polling ──────────────────────────────────────────── */
let pollTimer = null
const POLL_INTERVAL = 30000

onMounted(async () => {
  await store.fetchRouters()
  if (store.routers.length === 1) {
    store.selectRouter(store.routers[0].id)
    await store.fetchMorosos()
  }
})

onUnmounted(() => {
  clearInterval(pollTimer)
})

watch(() => store.selectedRouter, (val) => {
  clearInterval(pollTimer)
  if (val) {
    pollTimer = setInterval(() => store.fetchMorosos(), POLL_INTERVAL)
  }
})

async function handleRouterChange(e) {
  const id = Number(e.target.value)
  if (!id) {
    store.selectRouter(null)
    return
  }
  store.selectRouter(id)
  await store.fetchMorosos()
}

async function refresh() {
  await store.fetchMorosos()
}

const syncMessage = ref('')

async function handleSync() {
  syncMessage.value = ''
  try {
    const result = await store.syncWithDb()
    if (result) {
      syncMessage.value = result.message || 'Sincronización completada.'
    }
  } catch (e) {
    syncMessage.value = e.response?.data?.message || 'Error al sincronizar.'
  }
}

function formatDate(raw) {
  if (!raw) return '—'
  try {
    // MikroTik returns dates like "jan/01/2025 12:00:00" or ISO
    const d = new Date(raw)
    if (isNaN(d.getTime())) return raw
    return d.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' })
  } catch {
    return raw
  }
}

function formatTime(d) {
  if (!d) return ''
  return d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Cortes Morosos</h1>
        <p class="text-gray-500 text-sm mt-0.5">Address-list "CORTE MOROSO" · MikroTik</p>
      </div>
      <div class="flex items-center gap-2">
        <button
          @click="handleSync"
          :disabled="store.syncing || !store.selectedRouter"
          class="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-primary text-white text-sm font-medium hover:bg-primary/90 transition-colors disabled:opacity-40"
        >
          <svg :class="['w-4 h-4', store.syncing && 'animate-spin']" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          {{ store.syncing ? 'Sincronizando...' : 'Sincronizar con BD' }}
        </button>
        <button
          @click="refresh"
          :disabled="store.loading || !store.selectedRouter"
          class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-medium text-gray-600 hover:border-gray-300 transition-colors disabled:opacity-40"
        >
          <svg :class="['w-4 h-4', store.loading && 'animate-spin']" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          {{ store.loading ? 'Cargando...' : 'Actualizar' }}
        </button>
      </div>
    </div>

    <!-- Sync message -->
    <Transition name="fade">
      <div v-if="syncMessage" class="bg-blue-50 border border-blue-200 text-blue-700 text-sm rounded-xl px-4 py-3 mb-4 flex items-center gap-2">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        {{ syncMessage }}
        <button @click="syncMessage = ''" class="ml-auto text-blue-400 hover:text-blue-600">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </Transition>

    <!-- Router selector -->
    <div class="card mb-5">
      <div class="flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="flex items-center gap-2 flex-1">
          <svg class="w-5 h-5 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
          </svg>
          <select
            class="input text-sm flex-1"
            :value="store.selectedRouter || ''"
            @change="handleRouterChange"
          >
            <option value="">Seleccionar router...</option>
            <option v-for="r in store.routers" :key="r.id" :value="r.id">
              {{ r.name }} — {{ r.host }}
            </option>
          </select>
        </div>
        <div v-if="store.lastUpdated" class="text-xs text-gray-400">
          Última actualización: {{ formatTime(store.lastUpdated) }}
        </div>
      </div>
    </div>

    <!-- No router selected -->
    <div v-if="!store.selectedRouter" class="card text-center py-16">
      <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
      </svg>
      <p class="text-gray-500 text-sm">Selecciona un router para ver la lista de morosos.</p>
    </div>

    <!-- Loading -->
    <div v-else-if="store.loading && store.entries.length === 0" class="flex items-center justify-center py-16 text-gray-400 gap-2">
      <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
      Cargando morosos...
    </div>

    <template v-else>
      <!-- Stats card -->
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-6">
        <div class="card text-center py-4">
          <p class="text-2xl font-bold text-red-600">{{ store.entries.length }}</p>
          <p class="text-xs text-gray-500 mt-0.5">Total en lista</p>
        </div>
        <div class="card text-center py-4">
          <p class="text-2xl font-bold text-orange-600">{{ store.entries.filter(e => !e.disabled).length }}</p>
          <p class="text-xs text-gray-500 mt-0.5">Bloqueados</p>
        </div>
        <div class="card text-center py-4">
          <p class="text-2xl font-bold text-green-600">{{ store.entries.filter(e => e.disabled).length }}</p>
          <p class="text-xs text-gray-500 mt-0.5">Deshabilitados</p>
        </div>
      </div>

      <!-- Empty -->
      <div v-if="store.entries.length === 0" class="card text-center py-16">
        <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-gray-500 text-sm">No hay entradas en "CORTE MOROSO" en este router.</p>
      </div>

      <!-- Entries list -->
      <div v-else class="space-y-2">
        <div
          v-for="entry in store.entries"
          :key="entry.id"
          class="card hover:shadow-md transition-all"
        >
          <div class="flex items-center gap-3 sm:gap-4">
            <!-- Status indicator -->
            <div :class="[
              'w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 ring-1',
              entry.disabled
                ? 'bg-gray-100 ring-gray-200'
                : 'bg-red-50 ring-red-200'
            ]">
              <!-- Blocked icon (active in list = blocked) -->
              <svg v-if="!entry.disabled" class="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
              </svg>
              <!-- Disabled icon -->
              <svg v-else class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
              </svg>
            </div>

            <!-- Entry info -->
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <h3 class="font-semibold text-gray-800 text-sm sm:text-base font-mono">
                  {{ entry.address }}
                </h3>
                <span :class="[
                  'px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide',
                  entry.disabled
                    ? 'bg-gray-100 text-gray-600'
                    : 'bg-red-50 text-red-600'
                ]">
                  {{ entry.disabled ? 'Deshabilitado' : 'Bloqueado' }}
                </span>
              </div>
              <div class="mt-0.5 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-gray-500">
                <!-- Client name linked -->
                <router-link
                  v-if="entry.clientId && entry.clientNombre"
                  :to="`/clientes/${entry.clientId}`"
                  class="text-primary hover:underline font-medium"
                >
                  {{ entry.clientNombre }}
                </router-link>
                <!-- Comment fallback -->
                <span v-else-if="entry.comment" class="italic text-gray-400">{{ entry.comment }}</span>
                <span v-else class="italic text-gray-400">Sin identificar</span>
                <!-- Date -->
                <span v-if="entry.creationTime">{{ formatDate(entry.creationTime) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to        { opacity: 0; }
</style>
