<script setup>
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/store/auth'
import api from '@/services/api'

const auth = useAuthStore()
const activeTab = ref('credenciales')

// ─── Tab: Credenciales ────────────────────
const dniForm = ref({ dni: '' })
const dniSaving = ref(false)
const dniSuccess = ref('')
const dniError = ref('')

const passwordForm = ref({
  current_password: '',
  password: '',
  password_confirmation: '',
})
const passwordSaving = ref(false)
const passwordSuccess = ref('')
const passwordError = ref('')
const showCurrentPassword = ref(false)
const showNewPassword = ref(false)
const showConfirmPassword = ref(false)

// ─── Tab: Estados de Clientes ─────────────
const estados = ref([])
const estadosLoading = ref(false)
const showEstadoForm = ref(false)
const editingEstado = ref(null)
const estadoForm = ref({ nombre: '', color: '#3B82F6', descripcion: '' })
const estadoSaving = ref(false)
const estadoError = ref('')
const estadoSuccess = ref('')
const confirmDelete = ref(null)

onMounted(() => {
  dniForm.value.dni = auth.user?.dni ?? ''
  if (activeTab.value === 'estados') {
    loadEstados()
  }
})

// ─── Credenciales Functions ───────────────
async function updateDni() {
  dniSaving.value = true
  dniSuccess.value = ''
  dniError.value = ''
  try {
    const payload = {
      dni: (dniForm.value.dni || '').replace(/\D/g, '').slice(0, 8),
    }
    const { data } = await api.put('/me/dni', payload)
    auth.user = data.user
    dniSuccess.value = data.message ?? 'DNI actualizado correctamente.'
  } catch (e) {
    dniError.value =
      e.response?.data?.errors?.dni?.[0] ??
      e.response?.data?.message ??
      'No se pudo actualizar el DNI.'
  } finally {
    dniSaving.value = false
  }
}

async function updatePassword() {
  passwordSaving.value = true
  passwordSuccess.value = ''
  passwordError.value = ''
  try {
    const { data } = await api.put('/me/password', passwordForm.value)
    passwordSuccess.value = data.message ?? 'Contraseña actualizada correctamente.'
    passwordForm.value = {
      current_password: '',
      password: '',
      password_confirmation: '',
    }
  } catch (e) {
    passwordError.value =
      e.response?.data?.errors?.current_password?.[0] ??
      e.response?.data?.errors?.password?.[0] ??
      e.response?.data?.message ??
      'No se pudo actualizar la contraseña.'
  } finally {
    passwordSaving.value = false
  }
}

// ─── Estados Functions ────────────────────
async function loadEstados() {
  estadosLoading.value = true
  try {
    const { data } = await api.get('/admin/client-estados')
    estados.value = data.data
  } catch (e) {
    estadoError.value = 'No se pudieron cargar los estados de cliente.'
  } finally {
    estadosLoading.value = false
  }
}

function openEstadoForm(estado = null) {
  if (estado) {
    editingEstado.value = estado.id
    estadoForm.value = {
      nombre: estado.nombre,
      color: estado.color,
      descripcion: estado.descripcion ?? '',
    }
  } else {
    editingEstado.value = null
    estadoForm.value = { nombre: '', color: '#3B82F6', descripcion: '' }
  }
  showEstadoForm.value = true
  estadoError.value = ''
  estadoSuccess.value = ''
}

function closeEstadoForm() {
  showEstadoForm.value = false
  editingEstado.value = null
}

async function saveEstado() {
  estadoSaving.value = true
  estadoError.value = ''
  estadoSuccess.value = ''
  try {
    const payload = { ...estadoForm.value }
    if (editingEstado.value) {
      await api.put(`/admin/client-estados/${editingEstado.value}`, payload)
      estadoSuccess.value = 'Estado actualizado correctamente.'
    } else {
      await api.post('/admin/client-estados', payload)
      estadoSuccess.value = 'Estado creado correctamente.'
    }
    closeEstadoForm()
    await loadEstados()
  } catch (e) {
    estadoError.value =
      e.response?.data?.message ??
      'No se pudo guardar el estado.'
  } finally {
    estadoSaving.value = false
  }
}

async function deleteEstado(estado) {
  if (!confirm(`¿Estás seguro de que deseas eliminar el estado "${estado.nombre}"?`)) {
    return
  }
  try {
    await api.delete(`/admin/client-estados/${estado.id}`)
    estadoSuccess.value = 'Estado eliminado correctamente.'
    await loadEstados()
  } catch (e) {
    estadoError.value =
      e.response?.data?.message ??
      'No se pudo eliminar el estado.'
  }
}

function handleTabChange(tab) {
  activeTab.value = tab
  if (tab === 'estados' && estados.value.length === 0) {
    loadEstados()
  }
}
</script>

<template>
  <div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div>
      <h1 class="text-2xl font-bold text-gray-900">UpdateHub</h1>
      <p class="text-sm text-gray-500 mt-1">Centro de configuración de tu cuenta y estados de cliente.</p>
    </div>

    <!-- Tabs Navigation -->
    <div class="flex gap-2 border-b border-gray-200">
      <button
        @click="handleTabChange('credenciales')"
        :class="[
          'px-4 py-2 font-medium text-sm border-b-2 -mb-px transition-colors',
          activeTab === 'credenciales'
            ? 'border-blue-500 text-blue-600'
            : 'border-transparent text-gray-600 hover:text-gray-800',
        ]"
      >
        Credenciales
      </button>
      <button
        v-if="auth.user?.role === 'admin'"
        @click="handleTabChange('estados')"
        :class="[
          'px-4 py-2 font-medium text-sm border-b-2 -mb-px transition-colors',
          activeTab === 'estados'
            ? 'border-blue-500 text-blue-600'
            : 'border-transparent text-gray-600 hover:text-gray-800',
        ]"
      >
        Estados de Clientes
      </button>
    </div>

    <!-- Tab: Credenciales -->
    <div v-if="activeTab === 'credenciales'" class="space-y-6">
      <div class="card">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Actualizar DNI</h2>

        <form @submit.prevent="updateDni" class="space-y-3">
          <div class="max-w-sm">
            <label class="block text-sm font-medium text-gray-700 mb-1.5">DNI (8 dígitos)</label>
            <input
              :value="dniForm.dni"
              @input="dniForm.dni = ($event.target.value || '').replace(/\D/g, '').slice(0, 8)"
              type="text"
              inputmode="numeric"
              class="input"
              placeholder="12345678"
            />
          </div>

          <p v-if="dniError" class="text-sm text-red-600">{{ dniError }}</p>
          <p v-if="dniSuccess" class="text-sm text-green-600">{{ dniSuccess }}</p>

          <button type="submit" class="btn-primary" :disabled="dniSaving">
            {{ dniSaving ? 'Guardando...' : 'Guardar DNI' }}
          </button>
        </form>
      </div>

      <div class="card">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Cambiar Contraseña</h2>

        <form @submit.prevent="updatePassword" class="space-y-3">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="relative">
              <input
                v-model="passwordForm.current_password"
                :type="showCurrentPassword ? 'text' : 'password'"
                class="input pr-10"
                placeholder="Contraseña actual"
              />
              <button
                type="button"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                @click="showCurrentPassword = !showCurrentPassword"
                :aria-label="showCurrentPassword ? 'Ocultar contraseña actual' : 'Mostrar contraseña actual'"
              >
                <svg
                  v-if="!showCurrentPassword"
                  class="w-4 h-4"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                  />
                </svg>
                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M3 3l18 18"
                  />
                </svg>
              </button>
            </div>

            <div class="relative">
              <input
                v-model="passwordForm.password"
                :type="showNewPassword ? 'text' : 'password'"
                class="input pr-10"
                placeholder="Nueva contraseña"
              />
              <button
                type="button"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                @click="showNewPassword = !showNewPassword"
                :aria-label="showNewPassword ? 'Ocultar nueva contraseña' : 'Mostrar nueva contraseña'"
              >
                <svg v-if="!showNewPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                  />
                </svg>
                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M3 3l18 18"
                  />
                </svg>
              </button>
            </div>

            <div class="relative">
              <input
                v-model="passwordForm.password_confirmation"
                :type="showConfirmPassword ? 'text' : 'password'"
                class="input pr-10"
                placeholder="Confirmar nueva"
              />
              <button
                type="button"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                @click="showConfirmPassword = !showConfirmPassword"
                :aria-label="showConfirmPassword ? 'Ocultar confirmación' : 'Mostrar confirmación'"
              >
                <svg v-if="!showConfirmPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                  />
                </svg>
                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M3 3l18 18"
                  />
                </svg>
              </button>
            </div>
          </div>

          <p v-if="passwordError" class="text-sm text-red-600">{{ passwordError }}</p>
          <p v-if="passwordSuccess" class="text-sm text-green-600">{{ passwordSuccess }}</p>

          <button type="submit" class="btn-primary" :disabled="passwordSaving">
            {{ passwordSaving ? 'Actualizando...' : 'Actualizar Contraseña' }}
          </button>
        </form>
      </div>
    </div>

    <!-- Tab: Estados de Clientes -->
    <div v-if="activeTab === 'estados' && auth.user?.role === 'admin'" class="space-y-6">
      <div class="card">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-lg font-semibold text-gray-800">Estados de Cliente</h2>
          <button
            @click="openEstadoForm()"
            class="btn-primary"
            :disabled="estadoSaving || estadosLoading"
          >
            + Nuevo Estado
          </button>
        </div>

        <!-- Messages -->
        <p v-if="estadoError" class="text-sm text-red-600 mb-4">{{ estadoError }}</p>
        <p v-if="estadoSuccess" class="text-sm text-green-600 mb-4">{{ estadoSuccess }}</p>

        <!-- Loading -->
        <div v-if="estadosLoading" class="text-center py-8 text-gray-500">
          Cargando estados...
        </div>

        <!-- Estados Table -->
        <table v-else class="w-full">
          <thead>
            <tr class="border-b border-gray-200">
              <th class="text-left py-2 px-2 font-semibold text-sm text-gray-700">Nombre</th>
              <th class="text-left py-2 px-2 font-semibold text-sm text-gray-700">Color</th>
              <th class="text-left py-2 px-2 font-semibold text-sm text-gray-700">Orden</th>
              <th class="text-left py-2 px-2 font-semibold text-sm text-gray-700">Protegido</th>
              <th class="text-left py-2 px-2 font-semibold text-sm text-gray-700">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="estado in estados" :key="estado.id" class="border-b border-gray-100 hover:bg-gray-50">
              <td class="py-2 px-2 text-sm text-gray-900">{{ estado.nombre }}</td>
              <td class="py-2 px-2">
                <div class="flex items-center gap-2">
                  <div class="w-6 h-6 rounded" :style="{ backgroundColor: estado.color }"></div>
                  <span class="text-xs text-gray-500">{{ estado.color }}</span>
                </div>
              </td>
              <td class="py-2 px-2 text-sm text-gray-600">{{ estado.orden }}</td>
              <td class="py-2 px-2">
                <span
                  v-if="estado.sistema_protegido"
                  class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-yellow-100 text-yellow-800"
                >
                  PROTEGIDO
                </span>
                <span v-else class="text-xs text-gray-500">-</span>
              </td>
              <td class="py-2 px-2 text-sm">
                <button
                  v-if="!estado.sistema_protegido"
                  @click="openEstadoForm(estado)"
                  class="text-blue-600 hover:text-blue-800 mr-2"
                  title="Editar"
                >
                  Editar
                </button>
                <button
                  v-if="!estado.sistema_protegido"
                  @click="deleteEstado(estado)"
                  class="text-red-600 hover:text-red-800"
                  title="Eliminar"
                >
                  Eliminar
                </button>
                <span v-if="estado.sistema_protegido" class="text-xs text-gray-400">Sin acciones</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal: Agregar/Editar Estado -->
    <div v-if="showEstadoForm" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="p-6 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">
            {{ editingEstado ? 'Editar Estado' : 'Nuevo Estado' }}
          </h3>
        </div>

        <form @submit.prevent="saveEstado" class="p-6 space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
            <input
              v-model="estadoForm.nombre"
              type="text"
              class="input"
              placeholder="ej: En revisión"
              required
            />
          </div>

          <div class="flex gap-4">
            <div class="flex-1">
              <label class="block text-sm font-medium text-gray-700 mb-1">Color (Hex)</label>
              <div class="flex gap-2">
                <input
                  v-model="estadoForm.color"
                  type="color"
                  class="w-12 h-10 rounded border border-gray-300 cursor-pointer"
                />
                <input
                  v-model="estadoForm.color"
                  type="text"
                  class="input flex-1"
                  placeholder="#3B82F6"
                  required
                />
              </div>
            </div>
          </div>

          <p v-if="!editingEstado" class="text-xs text-gray-500">
            El orden se asigna automaticamente usando el primer numero libre.
          </p>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
            <textarea
              v-model="estadoForm.descripcion"
              class="input"
              placeholder="Descripción opcional"
              rows="3"
            ></textarea>
          </div>

          <p v-if="estadoError" class="text-sm text-red-600">{{ estadoError }}</p>

          <div class="flex gap-2 pt-4 border-t border-gray-200">
            <button
              type="button"
              @click="closeEstadoForm()"
              class="flex-1 px-4 py-2 text-sm font-medium text-gray-700 border border-gray-300 rounded hover:bg-gray-50"
            >
              Cancelar
            </button>
            <button type="submit" class="flex-1 btn-primary" :disabled="estadoSaving">
              {{ estadoSaving ? 'Guardando...' : 'Guardar' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
