<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/store/auth'
import { useNotificationsStore } from '@/store/notifications'
import api from '@/services/api'

defineEmits(['toggle-sidebar'])
const props = defineProps({ sidebarOpen: { type: Boolean, default: false } })

const router        = useRouter()
const auth          = useAuthStore()
const notifications = useNotificationsStore()
const loggingOut    = ref(false)
const showNotifs    = ref(false)
const salesCount    = ref(0)
const showSales     = ref(false)
const salesItems    = ref([])
const loadingSales  = ref(false)

let pollInterval = null
let salesPollInterval = null

// Close dropdowns when mobile sidebar opens to avoid overlap
watch(() => props.sidebarOpen, (open) => {
  if (open) {
    showNotifs.value = false
    showSales.value  = false
  }
})

onMounted(() => {
  notifications.fetchUnreadCount()
  pollInterval = setInterval(() => notifications.fetchUnreadCount(), 60000)
  if (auth.isAdmin) {
    fetchSalesCount()
    salesPollInterval = setInterval(fetchSalesCount, 300000) // every 5 min
  }
})

onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval)
  if (salesPollInterval) clearInterval(salesPollInterval)
})

const roleLabel = {
  admin:      'Administrador',
  vendedora:  'Vendedora',
  supervisor: 'Supervisor',
}

const roleBadgeClass = {
  admin:      'bg-purple-50 text-purple-700 ring-purple-200',
  vendedora:  'bg-pink-50   text-pink-700   ring-pink-200',
  supervisor: 'bg-blue-50   text-blue-700   ring-blue-200',
}

async function fetchSalesCount() {
  try {
    const now   = new Date()
    const y     = now.getFullYear()
    const m     = String(now.getMonth() + 1).padStart(2, '0')
    const from  = `${y}-${m}-01`
    const days  = new Date(y, now.getMonth() + 1, 0).getDate()
    const to    = `${y}-${m}-${String(days).padStart(2, '0')}`
    const { data } = await api.get('/clients', {
      params: { per_page: 1, from, to, estado: 'pre_registro' },
    })
    salesCount.value = data.total ?? 0
  } catch {
    // silent
  }
}

async function fetchSalesList() {
  loadingSales.value = true
  try {
    const now   = new Date()
    const y     = now.getFullYear()
    const m     = String(now.getMonth() + 1).padStart(2, '0')
    const from  = `${y}-${m}-01`
    const days  = new Date(y, now.getMonth() + 1, 0).getDate()
    const to    = `${y}-${m}-${String(days).padStart(2, '0')}`
    const { data } = await api.get('/clients', {
      params: {
        per_page: 8,
        from,
        to,
        estado: 'pre_registro',
        sort_by: 'created_at',
        sort_dir: 'desc',
      },
    })
    salesItems.value = Array.isArray(data?.data) ? data.data : []
  } catch {
    salesItems.value = []
  } finally {
    loadingSales.value = false
  }
}

async function toggleSalesDropdown() {
  showSales.value = !showSales.value
  if (showSales.value) {
    showNotifs.value = false
    await fetchSalesCount()
    await fetchSalesList()
  }
}

function openClientReview(client) {
  showSales.value = false
  router.push({
    path: '/clientes',
    query: { review_client_id: String(client.id) },
  })
}

function formatSaleDate(value) {
  if (!value) return 'Sin fecha'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return 'Sin fecha'
  return d.toLocaleDateString('es-PE', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

async function handleLogout() {
  loggingOut.value = true
  try {
    await auth.logout()
    router.push('/login')
  } finally {
    loggingOut.value = false
  }
}
</script>

<template>
  <header class="bg-white border-b border-gray-100 px-4 md:px-6 h-14 flex items-center gap-4 flex-shrink-0 shadow-sm">

    <!-- Hamburger -->
    <button
      @click="$emit('toggle-sidebar')"
      class="lg:hidden text-gray-400 hover:text-gray-700 transition-colors"
      aria-label="Abrir menú"
    >
      <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
      </svg>
    </button>

    <!-- Spacer -->
    <div class="flex-1" />

    <!-- Right: notifications + role badge + user + logout -->
    <div class="flex items-center gap-1.5 sm:gap-3">

      <!-- Ventas icon (admin only) -->
      <div v-if="auth.isAdmin" class="relative">
        <button
          @click="toggleSalesDropdown"
          class="relative p-1.5 sm:p-2 rounded-lg text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 transition-colors"
          title="Ventas del mes"
        >
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
          </svg>
          <span
            v-if="salesCount > 0"
            class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] bg-emerald-500 text-white rounded-full text-[10px] font-bold flex items-center justify-center px-1"
          >
            {{ salesCount > 99 ? '99+' : salesCount }}
          </span>
        </button>

        <Transition name="fade">
          <div
            v-if="showSales"
            class="fixed top-14 right-2 lg:absolute lg:top-full lg:right-0 lg:mt-2 w-[calc(100vw-1rem)] max-w-[20rem] sm:w-80 bg-white rounded-xl border border-gray-200 shadow-xl z-50 overflow-hidden"
          >
            <div class="px-4 py-3 border-b border-gray-100">
              <h3 class="text-sm font-bold text-gray-800">Ventas del mes</h3>
            </div>
            <div class="max-h-[70vh] sm:max-h-72 overflow-y-auto">
              <div v-if="loadingSales" class="px-4 py-6 text-center text-gray-400 text-sm">
                Cargando ventas...
              </div>
              <div v-else-if="!salesItems.length" class="px-4 py-8 text-center text-gray-400 text-sm">
                Sin ventas registradas este mes
              </div>
              <button
                v-for="sale in salesItems"
                :key="sale.id"
                @click="openClientReview(sale)"
                class="w-full text-left px-4 py-3 border-b border-gray-50 hover:bg-emerald-50 transition-colors"
              >
                <p class="text-sm font-medium text-gray-800 truncate">
                  {{ sale.nombre_completo || `${sale.nombres || ''} ${sale.apellidos || ''}`.trim() || `Cliente #${sale.id}` }}
                </p>
                <p class="text-xs text-gray-500 mt-0.5 truncate">
                  Vendedora: {{ sale.vendedora?.name || 'Sin asignar' }}
                </p>
                <p class="text-xs text-gray-400 mt-0.5 truncate">
                  Fecha: {{ formatSaleDate(sale.created_at) }}
                </p>
              </button>
            </div>
          </div>
        </Transition>
      </div>

      <!-- Notification bell -->
      <div class="relative">
        <button
          @click="showNotifs = !showNotifs; if (showNotifs) notifications.fetchNotifications()"
          class="relative p-1.5 sm:p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
          title="Notificaciones"
        >
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
          </svg>
          <span
            v-if="notifications.unreadCount > 0"
            class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] bg-primary text-white rounded-full text-[10px] font-bold flex items-center justify-center px-1"
          >
            {{ notifications.unreadCount > 99 ? '99+' : notifications.unreadCount }}
          </span>
        </button>

        <!-- Dropdown -->
        <Transition name="fade">
          <div
            v-if="showNotifs"
            class="fixed top-14 right-2 lg:absolute lg:top-full lg:right-0 lg:mt-2 w-[calc(100vw-1rem)] max-w-[20rem] sm:w-80 bg-white rounded-xl border border-gray-200 shadow-xl z-50 overflow-hidden"
          >
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
              <h3 class="text-sm font-bold text-gray-800">Notificaciones</h3>
              <button
                v-if="notifications.unreadCount > 0"
                @click="notifications.markAllRead()"
                class="text-[11px] sm:text-xs text-primary hover:underline whitespace-nowrap"
              >
                Marcar todo leído
              </button>
            </div>
            <div class="max-h-[70vh] sm:max-h-72 overflow-y-auto">
              <div v-if="!notifications.items.length" class="px-4 py-8 text-center text-gray-400 text-sm">
                Sin notificaciones
              </div>
              <button
                v-for="n in notifications.items"
                :key="n.id"
                @click="notifications.markRead(n.id); showNotifs = false; n.tipo === 'alerta_fraude' ? router.push('/ventas-sospechosas') : (n.data?.supervision_id ? router.push(`/supervisiones/${n.data.supervision_id}`) : null)"
                :class="[
                  'w-full text-left px-4 py-3 border-b border-gray-50 hover:bg-gray-50 transition-colors',
                  !n.read_at ? 'bg-primary/5' : '',
                ]"
              >
                <p class="text-sm font-medium text-gray-800 break-words">{{ n.titulo }}</p>
                <p class="text-xs text-gray-500 mt-0.5 break-words">{{ n.mensaje }}</p>
              </button>
            </div>
          </div>
        </Transition>
      </div>

      <!-- Role badge -->
      <span
        class="hidden sm:inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold ring-1"
        :class="roleBadgeClass[auth.userRole]"
      >
        {{ roleLabel[auth.userRole] ?? auth.userRole }}
      </span>

      <!-- Avatar + name -->
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center text-primary font-bold text-sm select-none">
          {{ auth.user?.name?.charAt(0)?.toUpperCase() }}
        </div>
        <span class="hidden md:block text-sm font-medium text-gray-700 max-w-[8rem] truncate">
          {{ auth.user?.name }}
        </span>
      </div>

      <!-- Divider -->
      <div class="h-5 w-px bg-gray-200 hidden sm:block" />

      <!-- Logout -->
      <button
        @click="handleLogout"
        :disabled="loggingOut"
        class="flex items-center gap-1.5 text-gray-400 hover:text-red-500 disabled:opacity-50 transition-colors text-sm"
        title="Cerrar sesión"
      >
        <svg v-if="!loggingOut" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
        </svg>
        <svg v-else class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
        <span class="hidden sm:block">Salir</span>
      </button>

    </div>
  </header>
</template>
