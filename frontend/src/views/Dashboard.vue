<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/store/auth'
import api from '@/services/api'

const router = useRouter()
const auth = useAuthStore()
const data = ref(null)
const loading = ref(true)
const vendors = ref([])
const loadingVendors = ref(false)
const filters = ref({
  month: new Date().toISOString().slice(0, 7),
  all_months: false,
  user_id: '',
})

async function loadDashboard() {
  loading.value = true
  try {
    const params = {
      month: filters.value.month,
      all_months: filters.value.all_months ? 1 : 0,
      ...(auth.isAdmin && filters.value.user_id ? { user_id: filters.value.user_id } : {}),
    }
    const res = await api.get('/dashboard', { params })
    data.value = res.data
  } catch (e) {
    console.error('Dashboard load error:', e)
  } finally {
    loading.value = false
  }
}

async function loadVendors() {
  if (!auth.isAdmin) return
  loadingVendors.value = true
  try {
    const res = await api.get('/admin/users', {
      params: {
        role: 'vendedora',
        active: true,
        per_page: 200,
      },
    })
    vendors.value = Array.isArray(res.data?.data) ? res.data.data : []
  } catch (e) {
    console.error('Vendors load error:', e)
    vendors.value = []
  } finally {
    loadingVendors.value = false
  }
}

onMounted(async () => {
  await Promise.all([
    loadDashboard(),
    loadVendors(),
  ])
})

watch(
  () => [filters.value.month, filters.value.all_months, filters.value.user_id],
  () => {
    loadDashboard()
  }
)

const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 12) return 'Buenos días'
  if (h < 18) return 'Buenas tardes'
  return 'Buenas noches'
})

const estadoColor = {
  activo:     'bg-green-100 text-green-700',
  moroso:     'bg-yellow-100 text-yellow-700',
  suspendido: 'bg-red-100 text-red-700',
  baja:       'bg-gray-100 text-gray-600',
  pendiente:  'bg-yellow-100 text-yellow-700',
  en_proceso: 'bg-blue-100 text-blue-700',
  completado: 'bg-green-100 text-green-700',
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">
        {{ greeting }}, {{ auth.user?.name?.split(' ')[0] }} 👋
      </h1>
      <p class="text-gray-500 text-sm mt-0.5">Panel de control — GOFIBRA</p>
    </div>

    <!-- Nuevo Cliente button (vendedora only) -->
    <div v-if="auth.isVendedora" class="mb-4 flex justify-end">
      <button
        type="button"
        class="btn-primary flex items-center gap-2"
        @click="router.push('/clientes/nuevo')"
      >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
        Nuevo Cliente
      </button>
    </div>

    <!-- Filters -->
    <div class="card mb-6">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
        <div>
          <label class="block text-xs font-semibold text-gray-500 mb-1">Mes</label>
          <input
            v-model="filters.month"
            type="month"
            class="input"
            :disabled="filters.all_months"
          />
        </div>

        <div v-if="auth.isAdmin">
          <label class="block text-xs font-semibold text-gray-500 mb-1">Vendedora</label>
          <select
            v-model="filters.user_id"
            class="input"
            :disabled="loadingVendors"
          >
            <option value="">Todas las vendedoras (general)</option>
            <option v-for="vendor in vendors" :key="vendor.id" :value="String(vendor.id)">
              {{ vendor.name }}
            </option>
          </select>
        </div>

        <div class="flex items-center gap-2">
          <button
            type="button"
            class="px-3 py-2 rounded-lg border text-sm font-medium transition-colors"
            :class="filters.all_months ? 'border-primary bg-primary/10 text-primary' : 'border-gray-200 text-gray-700 hover:border-primary/50'"
            @click="filters.all_months = !filters.all_months"
          >
            {{ filters.all_months ? 'Viendo todos los meses' : 'Ver todos los meses' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <svg class="animate-spin h-8 w-8 text-primary" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>

    <!-- ═══════════════ ADMIN DASHBOARD ═══════════════ -->
    <template v-else-if="auth.isAdmin && data">
      <!-- Stats Cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="card hover:shadow-md transition-shadow cursor-pointer" @click="router.push('/clientes')">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </div>
            <div>
              <p class="text-2xl font-bold text-gray-900">{{ data.clients?.total ?? 0 }}</p>
              <p class="text-xs text-gray-500">Total Clientes</p>
            </div>
          </div>
        </div>

        <div class="card hover:shadow-md transition-shadow cursor-pointer" @click="router.push('/clientes')">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-green-50 text-green-600 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
              <p class="text-2xl font-bold text-green-600">{{ data.clients?.activos ?? 0 }}</p>
              <p class="text-xs text-gray-500">Activos</p>
            </div>
          </div>
        </div>

        <div class="card hover:shadow-md transition-shadow cursor-pointer" @click="router.push('/clientes')">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-yellow-50 text-yellow-600 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            </div>
            <div>
              <p class="text-2xl font-bold text-yellow-600">{{ data.clients?.morosos ?? 0 }}</p>
              <p class="text-xs text-gray-500">Morosos</p>
            </div>
          </div>
        </div>

        <div class="card hover:shadow-md transition-shadow cursor-pointer" @click="router.push('/instalaciones')">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <div>
              <p class="text-2xl font-bold text-purple-600">{{ data.installations?.pendientes ?? 0 }}</p>
              <p class="text-xs text-gray-500">Inst. Pendientes</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Secondary Stats Row -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="card bg-gradient-to-br from-blue-500 to-blue-600 text-white">
          <p class="text-xs opacity-80">Este mes</p>
          <p class="text-xl font-bold">{{ data.this_month ?? 0 }}</p>
          <p class="text-xs mt-1 opacity-80">
            <span v-if="data.growth > 0">📈 +{{ data.growth }}%</span>
            <span v-else-if="data.growth < 0">📉 {{ data.growth }}%</span>
            <span v-else>— Sin cambio</span>
          </p>
        </div>
        <div class="card bg-gradient-to-br from-red-500 to-red-600 text-white cursor-pointer" @click="router.push('/clientes')">
          <p class="text-xs opacity-80">Suspendidos</p>
          <p class="text-xl font-bold">{{ data.clients?.suspendidos ?? 0 }}</p>
          <p class="text-xs mt-1 opacity-80">+ {{ data.clients?.bajas ?? 0 }} bajas</p>
        </div>
        <div class="card bg-gradient-to-br from-orange-500 to-orange-600 text-white cursor-pointer" @click="router.push('/ventas-sospechosas')">
          <p class="text-xs opacity-80">🚨 Sospechosas</p>
          <p class="text-xl font-bold">{{ data.suspicious_pending ?? 0 }}</p>
          <p class="text-xs mt-1 opacity-80">Pendientes de revisión</p>
        </div>
        <div class="card">
          <p class="text-xs text-gray-500">Equipo</p>
          <p class="text-xl font-bold text-gray-900">{{ (data.vendor_count ?? 0) + (data.supervisor_count ?? 0) }}</p>
          <p class="text-xs text-gray-500 mt-1">{{ data.vendor_count ?? 0 }} vendedoras · {{ data.supervisor_count ?? 0 }} supervisores</p>
        </div>
      </div>

      <!-- Two-column: Recent clients + Pending installations -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Recent Clients -->
        <div class="card">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800">Últimos clientes registrados</h3>
            <button class="text-xs text-primary hover:underline" @click="router.push('/clientes')">Ver todos →</button>
          </div>
          <div v-if="!data.recent_clients?.length" class="text-sm text-gray-400 py-4 text-center">Sin clientes registrados</div>
          <div v-else class="space-y-3">
            <div
              v-for="c in data.recent_clients"
              :key="c.id"
              class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0 cursor-pointer hover:bg-gray-50 rounded px-2 -mx-2"
              @click="router.push(`/clientes/${c.id}`)"
            >
              <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">{{ c.nombre }}</p>
                <p class="text-xs text-gray-500">{{ c.dni }} · {{ c.vendedora }}</p>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', estadoColor[c.estado]]">{{ c.estado }}</span>
                <span class="text-xs text-gray-400">{{ c.fecha }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Pending Installations -->
        <div class="card">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800">Instalaciones pendientes</h3>
            <button class="text-xs text-primary hover:underline" @click="router.push('/instalaciones')">Ver calendario →</button>
          </div>
          <div v-if="!data.recent_installations?.length" class="text-sm text-gray-400 py-4 text-center">Sin instalaciones pendientes</div>
          <div v-else class="space-y-3">
            <div
              v-for="i in data.recent_installations"
              :key="i.id"
              class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0"
            >
              <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">{{ i.cliente }}</p>
                <p class="text-xs text-gray-500">{{ i.vendedora }}</p>
              </div>
              <div class="text-right shrink-0">
                <p class="text-sm font-medium text-primary">{{ i.fecha }}</p>
                <p class="text-xs text-gray-500">{{ i.hora }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="mt-6 card">
        <h3 class="font-semibold text-gray-800 mb-3">Accesos rápidos</h3>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <button @click="router.push('/clientes/nuevo')" class="flex flex-col items-center gap-2 p-3 rounded-xl border border-gray-200 hover:bg-primary/5 hover:border-primary/30 transition-colors">
            <span class="text-xl">➕</span>
            <span class="text-xs text-gray-600">Nuevo Cliente</span>
          </button>
          <button @click="router.push('/admin/usuarios')" class="flex flex-col items-center gap-2 p-3 rounded-xl border border-gray-200 hover:bg-primary/5 hover:border-primary/30 transition-colors">
            <span class="text-xl">👥</span>
            <span class="text-xs text-gray-600">Usuarios</span>
          </button>
          <button @click="router.push('/reportes')" class="flex flex-col items-center gap-2 p-3 rounded-xl border border-gray-200 hover:bg-primary/5 hover:border-primary/30 transition-colors">
            <span class="text-xl">📊</span>
            <span class="text-xs text-gray-600">Reportes</span>
          </button>
          <button @click="router.push('/red')" class="flex flex-col items-center gap-2 p-3 rounded-xl border border-gray-200 hover:bg-primary/5 hover:border-primary/30 transition-colors">
            <span class="text-xl">🌐</span>
            <span class="text-xs text-gray-600">Estado de Red</span>
          </button>
        </div>
      </div>
    </template>

    <!-- ═══════════════ VENDEDORA DASHBOARD ═══════════════ -->
    <template v-else-if="auth.isVendedora && data">
      <!-- Stats Cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="card hover:shadow-md transition-shadow cursor-pointer" @click="router.push('/clientes')">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </div>
            <div>
              <p class="text-2xl font-bold text-gray-900">{{ data.clients?.total ?? 0 }}</p>
              <p class="text-xs text-gray-500">Mis Clientes</p>
            </div>
          </div>
        </div>

        <div class="card hover:shadow-md transition-shadow">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-green-50 text-green-600 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
              <p class="text-2xl font-bold text-green-600">{{ data.clients?.activos ?? 0 }}</p>
              <p class="text-xs text-gray-500">Activos</p>
            </div>
          </div>
        </div>

        <div class="card hover:shadow-md transition-shadow">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <div>
              <p class="text-2xl font-bold text-indigo-600">{{ data.this_month ?? 0 }}</p>
              <p class="text-xs text-gray-500">Este Mes</p>
            </div>
          </div>
        </div>

        <div class="card hover:shadow-md transition-shadow cursor-pointer" @click="router.push('/instalaciones')">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-yellow-50 text-yellow-600 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
              <p class="text-2xl font-bold text-yellow-600">{{ data.pending_installations ?? 0 }}</p>
              <p class="text-xs text-gray-500">Inst. Pendientes</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Status Summary -->
      <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="card text-center py-3">
          <p class="text-lg font-bold text-yellow-600">{{ data.clients?.morosos ?? 0 }}</p>
          <p class="text-xs text-gray-500">Morosos</p>
        </div>
        <div class="card text-center py-3">
          <p class="text-lg font-bold text-red-600">{{ data.clients?.suspendidos ?? 0 }}</p>
          <p class="text-xs text-gray-500">Suspendidos</p>
        </div>
        <div class="card text-center py-3">
          <p class="text-lg font-bold text-gray-500">{{ data.clients?.bajas ?? 0 }}</p>
          <p class="text-xs text-gray-500">Bajas</p>
        </div>
      </div>

      <!-- Two-column: Recent clients + Installations -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="card">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800">Mis últimos clientes</h3>
            <button class="text-xs text-primary hover:underline" @click="router.push('/clientes')">Ver todos →</button>
          </div>
          <div v-if="!data.recent_clients?.length" class="text-sm text-gray-400 py-4 text-center">Aún no has registrado clientes</div>
          <div v-else class="space-y-3">
            <div
              v-for="c in data.recent_clients"
              :key="c.id"
              class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0 cursor-pointer hover:bg-gray-50 rounded px-2 -mx-2"
              @click="router.push(`/clientes/${c.id}`)"
            >
              <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">{{ c.nombre }}</p>
                <p class="text-xs text-gray-500">DNI: {{ c.dni }}</p>
              </div>
              <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', estadoColor[c.estado]]">{{ c.estado }}</span>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800">Mis instalaciones pendientes</h3>
            <button class="text-xs text-primary hover:underline" @click="router.push('/instalaciones')">Ver calendario →</button>
          </div>
          <div v-if="!data.my_installations?.length" class="text-sm text-gray-400 py-4 text-center">Sin instalaciones pendientes</div>
          <div v-else class="space-y-3">
            <div
              v-for="i in data.my_installations"
              :key="i.id"
              class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0"
            >
              <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">{{ i.cliente }}</p>
              </div>
              <div class="text-right shrink-0">
                <p class="text-sm font-medium text-primary">{{ i.fecha }}</p>
                <p class="text-xs text-gray-500">{{ i.hora }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Action -->
      <div class="mt-6 card">
        <h3 class="font-semibold text-gray-800 mb-3">Accesos rápidos</h3>
        <div class="grid grid-cols-2 gap-3">
          <button @click="router.push('/clientes/nuevo')" class="flex flex-col items-center gap-2 p-4 rounded-xl border border-gray-200 hover:bg-primary/5 hover:border-primary/30 transition-colors">
            <span class="text-2xl">➕</span>
            <span class="text-sm text-gray-600">Registrar Cliente</span>
          </button>
          <button @click="router.push('/instalaciones')" class="flex flex-col items-center gap-2 p-4 rounded-xl border border-gray-200 hover:bg-primary/5 hover:border-primary/30 transition-colors">
            <span class="text-2xl">📅</span>
            <span class="text-sm text-gray-600">Ver Instalaciones</span>
          </button>
        </div>
      </div>
    </template>

    <!-- ═══════════════ SUPERVISOR DASHBOARD ═══════════════ -->
    <template v-else-if="auth.isSupervisor && data">
      <!-- Stats Cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="card hover:shadow-md transition-shadow">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <div>
              <p class="text-2xl font-bold text-gray-900">{{ data.supervisions?.total ?? 0 }}</p>
              <p class="text-xs text-gray-500">Total Supervisiones</p>
            </div>
          </div>
        </div>

        <div class="card hover:shadow-md transition-shadow">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-yellow-50 text-yellow-600 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
              <p class="text-2xl font-bold text-yellow-600">{{ data.supervisions?.pendientes ?? 0 }}</p>
              <p class="text-xs text-gray-500">Pendientes</p>
            </div>
          </div>
        </div>

        <div class="card hover:shadow-md transition-shadow">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div>
              <p class="text-2xl font-bold text-indigo-600">{{ data.supervisions?.en_proceso ?? 0 }}</p>
              <p class="text-xs text-gray-500">En Proceso</p>
            </div>
          </div>
        </div>

        <div class="card hover:shadow-md transition-shadow">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-green-50 text-green-600 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
              <p class="text-2xl font-bold text-green-600">{{ data.supervisions?.completadas ?? 0 }}</p>
              <p class="text-xs text-gray-500">Completadas</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Today -->
      <div class="card bg-gradient-to-r from-primary to-blue-600 text-white mb-6">
        <div class="flex items-center gap-3">
          <span class="text-3xl">📍</span>
          <div>
            <p class="text-lg font-bold">{{ data.today_installations ?? 0 }} instalaciones hoy</p>
            <p class="text-sm opacity-80">Supervisiones asignadas para hoy</p>
          </div>
        </div>
      </div>

      <!-- Pending Supervisions -->
      <div class="card">
        <div class="flex items-center justify-between mb-4">
          <h3 class="font-semibold text-gray-800">Supervisiones pendientes</h3>
          <button class="text-xs text-primary hover:underline" @click="router.push('/supervisiones')">Ver todas →</button>
        </div>
        <div v-if="!data.pending_supervisions?.length" class="text-sm text-gray-400 py-4 text-center">No tienes supervisiones pendientes</div>
        <div v-else class="space-y-3">
          <div
            v-for="s in data.pending_supervisions"
            :key="s.id"
            class="p-3 rounded-xl border border-gray-100 hover:bg-gray-50 cursor-pointer transition-colors"
            @click="router.push(`/supervisiones/${s.id}`)"
          >
            <div class="flex items-center justify-between">
              <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900">{{ s.cliente }}</p>
                <p class="text-xs text-gray-500">{{ s.direccion }}</p>
              </div>
              <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', estadoColor[s.estado]]">{{ s.estado }}</span>
            </div>
            <div class="flex items-center gap-3 mt-2 text-xs text-gray-500">
              <span>📅 {{ s.fecha }}</span>
              <span>🕐 {{ s.hora }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Action -->
      <div class="mt-6 card">
        <div class="grid grid-cols-2 gap-3">
          <button @click="router.push('/supervisiones')" class="flex flex-col items-center gap-2 p-4 rounded-xl border border-gray-200 hover:bg-primary/5 hover:border-primary/30 transition-colors">
            <span class="text-2xl">📋</span>
            <span class="text-sm text-gray-600">Mis Supervisiones</span>
          </button>
          <button @click="router.push('/instalaciones')" class="flex flex-col items-center gap-2 p-4 rounded-xl border border-gray-200 hover:bg-primary/5 hover:border-primary/30 transition-colors">
            <span class="text-2xl">📅</span>
            <span class="text-sm text-gray-600">Ver Instalaciones</span>
          </button>
        </div>
      </div>
    </template>

    <!-- No data fallback -->
    <div v-else-if="!loading && !data" class="card text-center py-8">
      <p class="text-gray-400">No se pudo cargar el panel. Intenta recargar la página.</p>
    </div>
  </div>
</template>
