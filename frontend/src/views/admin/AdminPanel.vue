<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import { useUsersStore } from '@/store/users'
import { useAuthStore } from '@/store/auth'

const store = useUsersStore()
const auth  = useAuthStore()

/* ── Modal state ──────────────────────────────────── */
const showModal   = ref(false)
const modalMode   = ref('create') // 'create' | 'edit'
const modalErrors = ref({})
const modalSaving = ref(false)
const modalSuccess = ref('')

const form = reactive({
  id:       null,
  name:     '',
  email:    '',
  dni:      '',
  password: '',
  role:     'vendedora',
  active:   true,
})

const ROLES = [
  { value: 'admin',      label: 'Administrador', color: 'bg-purple-100 text-purple-700', icon: '🛡️' },
  { value: 'vendedora',  label: 'Vendedora',     color: 'bg-pink-100 text-pink-700',     icon: '💼' },
  { value: 'supervisor', label: 'Supervisor',    color: 'bg-blue-100 text-blue-700',     icon: '👁️' },
]

function roleInfo(role) {
  return ROLES.find(r => r.value === role) || ROLES[1]
}

/* ── Filters ──────────────────────────────────────── */
const searchTimeout = ref(null)
function onSearchInput(val) {
  clearTimeout(searchTimeout.value)
  searchTimeout.value = setTimeout(() => {
    store.setFilter('search', val)
    store.fetchUsers(1)
  }, 400)
}

function onFilterRole(val) {
  store.setFilter('role', val)
  store.fetchUsers(1)
}

function onFilterActive(val) {
  store.setFilter('active', val)
  store.fetchUsers(1)
}

/* ── CRUD actions ─────────────────────────────────── */
function openCreate() {
  modalMode.value = 'create'
  Object.assign(form, { id: null, name: '', email: '', dni: '', password: '', role: 'vendedora', active: true })
  modalErrors.value = {}
  modalSuccess.value = ''
  showModal.value = true
}

function openEdit(user) {
  modalMode.value = 'edit'
  Object.assign(form, { id: user.id, name: user.name, email: user.email, dni: user.dni ?? '', password: '', role: user.role, active: user.active })
  modalErrors.value = {}
  modalSuccess.value = ''
  showModal.value = true
}

async function saveUser() {
  modalErrors.value = {}
  modalSuccess.value = ''
  modalSaving.value = true

  try {
    const payload = { ...form }
    if (modalMode.value === 'edit' && !payload.password) {
      delete payload.password
    }
    delete payload.id

    if (modalMode.value === 'create') {
      await store.createUser(payload)
      modalSuccess.value = 'Usuario creado correctamente.'
    } else {
      await store.updateUser(form.id, payload)
      modalSuccess.value = 'Usuario actualizado correctamente.'
    }
    setTimeout(() => { showModal.value = false }, 800)
  } catch (e) {
    if (e.response?.status === 422) {
      modalErrors.value = e.response.data.errors ?? {}
      if (e.response.data.message && !e.response.data.errors) {
        modalErrors.value = { _global: [e.response.data.message] }
      }
    } else {
      modalErrors.value = { _global: [e.response?.data?.message ?? 'Error al guardar.'] }
    }
  } finally {
    modalSaving.value = false
  }
}

async function toggleActive(user) {
  if (!auth.isAdmin) {
    alert('Solo un administrador puede activar o desactivar usuarios.')
    return
  }

  if (user.id === auth.user?.id) {
    alert('No puedes desactivar tu propia cuenta.')
    return
  }
  const action = user.active ? 'desactivar' : 'activar'
  if (!confirm(`¿${action.charAt(0).toUpperCase() + action.slice(1)} a ${user.name}?`)) return

  try {
    await store.updateUser(user.id, { active: !user.active })
  } catch (e) {
    alert(e.response?.data?.message ?? 'Error al actualizar.')
  }
}

async function deleteUser(user) {
  if (!auth.isAdmin) {
    alert('Solo un administrador puede eliminar usuarios.')
    return
  }

  if (user.id === auth.user?.id) {
    alert('No puedes eliminar tu propia cuenta.')
    return
  }

  if (!confirm(`¿Eliminar permanentemente a "${user.name}"?\nEsta acción no se puede deshacer.`)) return

  try {
    await store.removeUser(user.id, false)
  } catch (e) {
    if (e.response?.status === 409 && e.response?.data?.requires_force) {
      const count = e.response.data.clients_count
      if (!confirm(
        `⚠️ ATENCIÓN: "${user.name}" tiene ${count} cliente(s) asignado(s).\n\n` +
        `Si lo eliminas, esos clientes quedarán sin vendedora asignada.\n\n` +
        `¿Confirmas la eliminación permanente?`
      )) return
      try {
        await store.removeUser(user.id, true)
      } catch (e2) {
        alert(e2.response?.data?.message ?? 'Error al eliminar.')
      }
    } else {
      alert(e.response?.data?.message ?? 'Error al eliminar.')
    }
  }
}

function fieldError(field) {
  return modalErrors.value[field]?.[0]
}

/* ── Pagination ───────────────────────────────────── */
function changePage(page) {
  if (page < 1 || page > store.pagination.last_page) return
  store.fetchUsers(page)
}

/* ── Init ─────────────────────────────────────────── */
onMounted(() => {
  store.resetFilters()
  store.fetchUsers(1)
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Gestión de Usuarios</h1>
        <p class="text-gray-500 text-sm mt-0.5">Administra las cuentas del sistema</p>
      </div>
      <button @click="openCreate" class="btn-primary flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Nuevo Usuario
      </button>
    </div>

    <!-- Filters -->
    <div class="card mb-5">
      <div class="flex flex-col sm:flex-row gap-3">
        <!-- Search -->
        <div class="flex-1 relative">
          <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <input
            type="text"
            placeholder="Buscar por nombre o correo..."
            class="input pl-10"
            :value="store.filters.search"
            @input="onSearchInput($event.target.value)"
          />
        </div>

        <!-- Role -->
        <select
          class="input w-full sm:w-40"
          :value="store.filters.role"
          @change="onFilterRole($event.target.value)"
        >
          <option value="">Todos los roles</option>
          <option value="admin">Administrador</option>
          <option value="vendedora">Vendedora</option>
          <option value="supervisor">Supervisor</option>
        </select>

        <!-- Active -->
        <select
          class="input w-full sm:w-36"
          :value="store.filters.active"
          @change="onFilterActive($event.target.value)"
        >
          <option value="">Todos</option>
          <option value="true">Activos</option>
          <option value="false">Inactivos</option>
        </select>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="store.loading" class="flex justify-center py-12">
      <svg class="w-8 h-8 animate-spin text-primary" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
      </svg>
    </div>

    <!-- Users table -->
    <div v-else-if="store.items.length" class="card overflow-hidden !p-0">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-gray-50/80 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              <th class="px-5 py-3">Usuario</th>
              <th class="px-5 py-3">Rol</th>
              <th class="px-5 py-3 hidden sm:table-cell">Clientes</th>
              <th class="px-5 py-3">Estado</th>
              <th class="px-5 py-3 text-right">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr
              v-for="user in store.items"
              :key="user.id"
              class="hover:bg-gray-50/50 transition-colors"
            >
              <!-- User info -->
              <td class="px-5 py-3.5">
                <div class="flex items-center gap-3">
                  <div
                    :class="[
                      'w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold text-white',
                      user.role === 'admin' ? 'bg-purple-500' : user.role === 'supervisor' ? 'bg-blue-500' : 'bg-pink-500',
                    ]"
                  >
                    {{ user.name.charAt(0).toUpperCase() }}
                  </div>
                  <div>
                    <p class="font-medium text-gray-800">{{ user.name }}</p>
                    <p class="text-xs text-gray-400">{{ user.email }}</p>
                    <p v-if="user.dni" class="text-xs text-gray-400">DNI: {{ user.dni }}</p>
                  </div>
                </div>
              </td>

              <!-- Role badge -->
              <td class="px-5 py-3.5">
                <span :class="['text-xs font-medium px-2.5 py-1 rounded-full', roleInfo(user.role).color]">
                  {{ roleInfo(user.role).icon }} {{ roleInfo(user.role).label }}
                </span>
              </td>

              <!-- Clients count -->
              <td class="px-5 py-3.5 hidden sm:table-cell">
                <span class="text-gray-600">{{ user.clients_count ?? 0 }}</span>
              </td>

              <!-- Active status -->
              <td class="px-5 py-3.5">
                <button
                  @click="toggleActive(user)"
                  :disabled="!auth.isAdmin || user.id === auth.user?.id"
                  :class="[
                    'inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-full transition-colors',
                    user.active
                      ? 'bg-green-100 text-green-700 hover:bg-green-200'
                      : 'bg-gray-100 text-gray-500 hover:bg-gray-200',
                    !auth.isAdmin || user.id === auth.user?.id ? 'cursor-default opacity-60' : 'cursor-pointer',
                  ]"
                >
                  <span :class="['w-1.5 h-1.5 rounded-full', user.active ? 'bg-green-500' : 'bg-gray-400']" />
                  {{ user.active ? 'Activo' : 'Inactivo' }}
                </button>
              </td>

              <!-- Actions -->
              <td class="px-5 py-3.5 text-right">
                <div class="flex items-center justify-end gap-1">
                  <button
                    @click="openEdit(user)"
                    class="p-2 rounded-lg text-gray-400 hover:text-primary hover:bg-primary/5 transition-colors"
                    title="Editar"
                  >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                  </button>
                  <button
                    v-if="auth.isAdmin && user.id !== auth.user?.id"
                    @click="deleteUser(user)"
                    class="p-2 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 transition-colors"
                    title="Eliminar usuario"
                  >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="store.pagination.last_page > 1" class="flex items-center justify-between px-5 py-3 border-t border-gray-100 bg-gray-50/40">
        <p class="text-xs text-gray-500">
          {{ store.pagination.total }} usuario{{ store.pagination.total !== 1 ? 's' : '' }}
        </p>
        <div class="flex gap-1">
          <button
            @click="changePage(store.pagination.current_page - 1)"
            :disabled="store.pagination.current_page <= 1"
            class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-100 disabled:opacity-40 transition-colors"
          >
            Anterior
          </button>
          <span class="px-3 py-1.5 text-xs text-gray-500">
            {{ store.pagination.current_page }} / {{ store.pagination.last_page }}
          </span>
          <button
            @click="changePage(store.pagination.current_page + 1)"
            :disabled="store.pagination.current_page >= store.pagination.last_page"
            class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-100 disabled:opacity-40 transition-colors"
          >
            Siguiente
          </button>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else class="card text-center py-12">
      <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
      </svg>
      <p class="text-gray-500 text-sm">No se encontraron usuarios.</p>
      <button @click="openCreate" class="text-primary text-sm font-medium mt-2 hover:underline">
        Crear primer usuario
      </button>
    </div>

    <!-- Stats cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-5">
      <div class="card flex items-center gap-3">
        <div class="w-10 h-10 bg-pink-100 rounded-xl flex items-center justify-center">
          <span class="text-lg">💼</span>
        </div>
        <div>
          <p class="text-2xl font-bold text-gray-800">{{ store.items.filter(u => u.role === 'vendedora').length }}</p>
          <p class="text-xs text-gray-500">Vendedoras</p>
        </div>
      </div>
      <div class="card flex items-center gap-3">
        <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center">
          <span class="text-lg">👁️</span>
        </div>
        <div>
          <p class="text-2xl font-bold text-gray-800">{{ store.items.filter(u => u.role === 'supervisor').length }}</p>
          <p class="text-xs text-gray-500">Supervisores</p>
        </div>
      </div>
      <div class="card flex items-center gap-3">
        <div class="w-10 h-10 bg-purple-100 rounded-xl flex items-center justify-center">
          <span class="text-lg">🛡️</span>
        </div>
        <div>
          <p class="text-2xl font-bold text-gray-800">{{ store.items.filter(u => u.role === 'admin').length }}</p>
          <p class="text-xs text-gray-500">Administradores</p>
        </div>
      </div>
    </div>

    <!-- ═══════════ Modal: Create/Edit User ═══════════ -->
    <Teleport to="body">
      <Transition name="modal">
        <div
          v-if="showModal"
          class="fixed inset-0 z-50 flex items-center justify-center p-4"
        >
          <!-- Backdrop -->
          <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="showModal = false" />

          <!-- Dialog -->
          <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
              <h2 class="text-lg font-semibold text-gray-800">
                {{ modalMode === 'create' ? 'Nuevo Usuario' : 'Editar Usuario' }}
              </h2>
              <button
                @click="showModal = false"
                class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
              >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <!-- Body -->
            <form @submit.prevent="saveUser" class="px-6 py-5 space-y-4">
              <!-- Global error -->
              <div v-if="modalErrors._global" class="bg-red-50 border border-red-200 text-red-600 text-sm rounded-xl px-4 py-2.5">
                {{ modalErrors._global[0] }}
              </div>

              <!-- Success -->
              <div v-if="modalSuccess" class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-2.5 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                {{ modalSuccess }}
              </div>

              <!-- Name -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Nombre completo *</label>
                <input
                  v-model="form.name"
                  type="text"
                  placeholder="María López"
                  :class="['input', fieldError('name') ? 'border-red-400' : '']"
                />
                <p v-if="fieldError('name')" class="text-red-500 text-xs mt-1">{{ fieldError('name') }}</p>
              </div>

              <!-- Email -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Correo electrónico *</label>
                <input
                  v-model="form.email"
                  type="email"
                  placeholder="usuario@gosystems.com"
                  :class="['input', fieldError('email') ? 'border-red-400' : '']"
                />
                <p v-if="fieldError('email')" class="text-red-500 text-xs mt-1">{{ fieldError('email') }}</p>
              </div>

              <!-- DNI -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">DNI (8 dígitos)</label>
                <input
                  :value="form.dni"
                  @input="form.dni = ($event.target.value || '').replace(/\D/g, '').slice(0, 8)"
                  type="text"
                  inputmode="numeric"
                  placeholder="12345678"
                  :class="['input', fieldError('dni') ? 'border-red-400' : '']"
                />
                <p v-if="fieldError('dni')" class="text-red-500 text-xs mt-1">{{ fieldError('dni') }}</p>
                <p v-else class="text-gray-400 text-xs mt-1">Obligatorio para vendedora y supervisor.</p>
              </div>

              <!-- Password -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                  Contraseña {{ modalMode === 'edit' ? '(dejar vacío para no cambiar)' : '*' }}
                </label>
                <input
                  v-model="form.password"
                  type="password"
                  placeholder="Mínimo 5 caracteres"
                  :class="['input', fieldError('password') ? 'border-red-400' : '']"
                />
                <p v-if="fieldError('password')" class="text-red-500 text-xs mt-1">{{ fieldError('password') }}</p>
                <p v-else class="text-gray-400 text-xs mt-1">Mínimo 5 caracteres, con letras mayúsculas y minúsculas.</p>
              </div>

              <!-- Role -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Rol *</label>
                <div class="grid grid-cols-3 gap-2">
                  <label
                    v-for="r in ROLES"
                    :key="r.value"
                    :class="[
                      'flex flex-col items-center gap-1 px-3 py-3 rounded-xl border cursor-pointer text-center transition-all',
                      form.role === r.value
                        ? 'border-primary bg-primary/5 ring-1 ring-primary/30'
                        : 'border-gray-200 hover:border-gray-300',
                      // Prevent self role change
                      modalMode === 'edit' && form.id === auth.user?.id ? 'opacity-50 pointer-events-none' : '',
                    ]"
                  >
                    <input v-model="form.role" :value="r.value" type="radio" class="hidden" />
                    <span class="text-xl">{{ r.icon }}</span>
                    <span class="text-xs font-medium" :class="form.role === r.value ? 'text-primary' : 'text-gray-600'">
                      {{ r.label }}
                    </span>
                  </label>
                </div>
              </div>

              <!-- Active toggle -->
              <div class="flex items-center justify-between py-2">
                <div>
                  <p class="text-sm font-medium text-gray-700">Cuenta activa</p>
                  <p class="text-xs text-gray-400">Los usuarios inactivos no pueden iniciar sesión.</p>
                </div>
                <button
                  type="button"
                  @click="form.active = !form.active"
                  :disabled="modalMode === 'edit' && form.id === auth.user?.id"
                  :class="[
                    'relative w-11 h-6 rounded-full transition-colors',
                    form.active ? 'bg-primary' : 'bg-gray-300',
                    modalMode === 'edit' && form.id === auth.user?.id ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer',
                  ]"
                >
                  <span
                    :class="[
                      'absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform',
                      form.active ? 'translate-x-5' : 'translate-x-0',
                    ]"
                  />
                </button>
              </div>

              <!-- Actions -->
              <div class="flex gap-3 justify-end pt-2 border-t border-gray-100">
                <button
                  type="button"
                  @click="showModal = false"
                  class="px-5 py-2.5 rounded-xl border border-gray-200 text-sm font-medium text-gray-600 hover:border-gray-300 transition-colors"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  :disabled="modalSaving"
                  class="btn-primary px-6"
                >
                  <svg v-if="modalSaving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                  </svg>
                  {{ modalSaving ? 'Guardando...' : modalMode === 'create' ? 'Crear Usuario' : 'Guardar Cambios' }}
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
.modal-enter-active, .modal-leave-active { transition: opacity 0.2s ease; }
.modal-enter-from, .modal-leave-to       { opacity: 0; }
</style>
