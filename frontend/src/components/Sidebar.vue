<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { useAuthStore } from '@/store/auth'

defineProps({ open: Boolean })
defineEmits(['close'])

const route = useRoute()
const auth  = useAuthStore()

// Track which group menus are expanded
const expanded = ref({ clientes: false })

// Auto-expand group when navigating to a child route
watch(
  () => route.path,
  (path) => {
    if (path.startsWith('/clientes')) expanded.value.clientes = true
  },
  { immediate: true }
)

const navItems = computed(() => {
  const all = [
    { label: 'Dashboard',       to: '/dashboard',          icon: 'grid',     roles: ['admin', 'vendedora', 'supervisor'] },
    { label: 'Usuarios',        to: '/admin/usuarios',     icon: 'users',    roles: ['admin'] },
    { label: 'Planes',          to: '/admin/planes',       icon: 'package',  roles: ['admin'] },
    {
      label: 'Clientes',
      icon: 'person',
      roles: ['admin', 'vendedora'],
      group: 'clientes',
      basePath: '/clientes',
      children: [
        { label: 'Registrar Cliente', to: '/clientes/nuevo', icon: 'plus-circle' },
        { label: 'Lista de Clientes', to: '/clientes',       icon: 'list' },
      ],
    },
    { label: 'Instalaciones',   to: '/instalaciones',      icon: 'tool',     roles: ['admin', 'supervisor', 'vendedora'] },
    { label: 'Credenciales',    to: '/credenciales',       icon: 'key',      roles: ['admin', 'supervisor', 'vendedora'] },
    { label: 'Supervisiones',   to: '/supervisiones',      icon: 'clipboard',roles: ['admin', 'supervisor'] },
    { label: 'Red',             to: '/red',                icon: 'wifi',     roles: ['admin'] },
    { label: 'Morosos ISP',      to: '/red/morosos',        icon: 'ban',      roles: ['admin'] },
    { label: 'Ventas Sospechosas', to: '/ventas-sospechosas', icon: 'shield', roles: ['admin'] },
    { label: 'Reportes',        to: '/reportes',           icon: 'chart',    roles: ['admin'] },
    { label: 'Mapa',            to: '/reportes/mapa',      icon: 'map',      roles: ['admin'] },
    { label: 'Configuración',   to: '/configuracion',      icon: 'settings', roles: ['admin'] },
  ]
  return all.filter((item) => auth.hasRole(item.roles))
})

function isActive(path) {
  return route.path === path || (path !== '/' && route.path.startsWith(path + '/'))
}

function isGroupActive(basePath) {
  return route.path === basePath || route.path.startsWith(basePath + '/')
}

function toggleGroup(key) {
  expanded.value[key] = !expanded.value[key]
}
</script>

<template>
  <aside
    :class="[
      'fixed inset-y-0 left-0 z-30 w-64 bg-gray-900 flex flex-col',
      'transition-transform duration-300 ease-in-out',
      'lg:relative lg:translate-x-0 lg:z-auto',
      open ? 'translate-x-0' : '-translate-x-full',
    ]"
  >
    <!-- ── Logo ───────────────────────────────────────────── -->
    <div class="flex items-center gap-3 px-5 py-5 border-b border-white/5">
      <div class="w-9 h-9 bg-primary/10 rounded-xl flex items-center justify-center ring-1 ring-primary/30 flex-shrink-0">
        <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
      </div>
      <div class="min-w-0 flex-1">
        <p class="text-white font-bold text-sm leading-tight">GO Systems</p>
        <p class="text-gray-500 text-xs">& Technology</p>
      </div>
      <button @click="$emit('close')" class="lg:hidden text-gray-500 hover:text-white transition-colors ml-auto">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- ── User card ───────────────────────────────────────── -->
    <div class="px-4 py-3.5 border-b border-white/5">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-lg bg-primary/20 flex items-center justify-center text-primary font-bold text-sm flex-shrink-0">
          {{ auth.user?.name?.charAt(0)?.toUpperCase() }}
        </div>
        <div class="min-w-0 flex-1">
          <p class="text-white text-sm font-medium truncate">{{ auth.user?.name }}</p>
          <p class="text-gray-500 text-xs capitalize">{{ auth.user?.role }}</p>
        </div>
      </div>
    </div>

    <!-- ── Navigation ─────────────────────────────────────── -->
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-0.5">
      <template v-for="item in navItems" :key="item.to ?? item.group">

        <!-- ── Group item (has children) ── -->
        <template v-if="item.children">
          <button
            @click="toggleGroup(item.group)"
            :class="[
              'w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150',
              isGroupActive(item.basePath)
                ? 'bg-primary/20 text-white'
                : 'text-gray-400 hover:text-white hover:bg-gray-800',
            ]"
          >
            <!-- Person icon -->
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
            <span class="flex-1 text-left">{{ item.label }}</span>
            <!-- Chevron -->
            <svg
              :class="['w-4 h-4 flex-shrink-0 transition-transform duration-200', expanded[item.group] ? 'rotate-180' : '']"
              fill="none" viewBox="0 0 24 24" stroke="currentColor"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
          </button>

          <!-- Children submenu -->
          <div v-show="expanded[item.group]" class="ml-4 mt-0.5 space-y-0.5 border-l border-white/10 pl-3">
            <RouterLink
              v-for="child in item.children"
              :key="child.to"
              :to="child.to"
              @click="$emit('close')"
              :class="[
                'flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-medium transition-all duration-150',
                route.path === child.to
                  ? 'bg-primary text-white shadow-primary'
                  : 'text-gray-400 hover:text-white hover:bg-gray-800',
              ]"
            >
              <!-- plus-circle -->
              <svg v-if="child.icon === 'plus-circle'" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <!-- list -->
              <svg v-else-if="child.icon === 'list'" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
              </svg>
              {{ child.label }}
            </RouterLink>
          </div>
        </template>

        <!-- ── Flat link ── -->
        <RouterLink
          v-else
          :to="item.to"
          @click="$emit('close')"
          :class="[
            'flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150',
            isActive(item.to)
              ? 'bg-primary text-white shadow-primary'
              : 'text-gray-400 hover:text-white hover:bg-gray-800',
          ]"
        >
        <!-- Grid / Dashboard -->
        <svg v-if="item.icon === 'grid'" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
        </svg>
        <!-- Users -->
        <svg v-else-if="item.icon === 'users'" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
        </svg>
        <!-- Package / Plans -->
        <svg v-else-if="item.icon === 'package'" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
        </svg>
        <!-- Tool / Installations -->
        <svg v-else-if="item.icon === 'tool'" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
        <!-- Chart / Reports -->
        <svg v-else-if="item.icon === 'chart'" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
        </svg>
        <!-- Clipboard / Supervisions -->
        <svg v-else-if="item.icon === 'clipboard'" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
        </svg>
        <!-- Wifi / Red -->
        <svg v-else-if="item.icon === 'wifi'" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
        </svg>
        <!-- Ban / Morosos -->
        <svg v-else-if="item.icon === 'ban'" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
        </svg>
        <!-- Shield / Sospechosas -->
        <svg v-else-if="item.icon === 'shield'" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
        </svg>
        <!-- Map / Mapa -->
        <svg v-else-if="item.icon === 'map'" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
        </svg>
        <!-- Settings -->
        <svg v-else-if="item.icon === 'settings'" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
        </svg>
        <!-- Key / Credenciales -->
        <svg v-else-if="item.icon === 'key'" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a5 5 0 11-9.9 1H3v3h2v2h2v2h3l2.6-2.6A5 5 0 0115 7z" />
        </svg>

        {{ item.label }}
        </RouterLink>

      </template>
    </nav>

    <!-- ── Footer ─────────────────────────────────────────── -->
    <div class="px-4 py-3 border-t border-white/5">
      <p class="text-center text-gray-700 text-xs">v1.0.0 — Fase 7</p>
    </div>
  </aside>
</template>
