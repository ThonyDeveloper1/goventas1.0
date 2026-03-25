<script setup>
import { computed, onMounted, ref, reactive } from 'vue'
import settingsApi from '@/services/settings'

const activeTab = ref('reniec')

/* ── RENIEC state ─────────────────────────────── */
const loading = ref(true)
const saving = ref(false)
const savingToken = ref(false)
const clearingToken = ref(false)
const testing = ref(false)
const reniecEnabled = ref(false)
const reniecConfigured = ref(false)
const reniecUpdatedAt = ref(null)
const reniecToken = ref('')
const showToken = ref(false)
const saveMsg = ref('')
const saveError = ref('')
const tokenMsg = ref('')
const tokenError = ref('')
const testMsg = ref('')
const testError = ref('')

/* ── Routers state ────────────────────────────── */
const routers = ref([])
const loadingRouters = ref(false)
const savingRouter = ref(false)
const routerMsg = ref('')
const routerError = ref('')
const testingRouterId = ref(null)
const editingRouter = ref(null)
const showRouterPassword = ref(false)

const routerForm = reactive({
  name: '',
  host: '',
  port: 8728,
  username: '',
  password: '',
  use_tls: false,
})

function resetRouterForm() {
  routerForm.name = ''
  routerForm.host = ''
  routerForm.port = 8728
  routerForm.username = ''
  routerForm.password = ''
  routerForm.use_tls = false
  editingRouter.value = null
  showRouterPassword.value = false
}

function editRouter(router) {
  editingRouter.value = router.id
  routerForm.name = router.name
  routerForm.host = router.host
  routerForm.port = router.port
  routerForm.username = router.username
  routerForm.password = ''
  routerForm.use_tls = router.use_tls
  showRouterPassword.value = false
}

const formattedUpdatedAt = computed(() => {
  if (!reniecUpdatedAt.value) return null
  const d = new Date(reniecUpdatedAt.value)
  return d.toLocaleDateString('es-PE', { day: '2-digit', month: '2-digit', year: 'numeric' }) +
    ' ' + d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
})

onMounted(async () => {
  try {
    const { data } = await settingsApi.getSettings()
    reniecConfigured.value = data.reniec_configured ?? false
    reniecEnabled.value = data.reniec_enabled ?? false
    reniecUpdatedAt.value = data.reniec_updated_at ?? null
  } catch {
    saveError.value = 'No se pudieron cargar las configuraciones.'
  } finally {
    loading.value = false
  }
  fetchRouters()
})

async function fetchRouters() {
  loadingRouters.value = true
  try {
    const { data } = await settingsApi.getRouters()
    routers.value = data
  } catch {
    routerError.value = 'No se pudieron cargar los routers.'
  } finally {
    loadingRouters.value = false
  }
}

async function saveRouter() {
  savingRouter.value = true
  routerMsg.value = ''
  routerError.value = ''
  try {
    if (editingRouter.value) {
      const payload = { ...routerForm }
      if (!payload.password) delete payload.password
      await settingsApi.updateRouter(editingRouter.value, payload)
      routerMsg.value = 'Router actualizado.'
    } else {
      await settingsApi.createRouter({ ...routerForm })
      routerMsg.value = 'Router creado.'
    }
    resetRouterForm()
    await fetchRouters()
  } catch (e) {
    routerError.value = e.response?.data?.message ?? 'Error al guardar router.'
  } finally {
    savingRouter.value = false
  }
}

async function deleteRouter(id) {
  if (!confirm('¿Eliminar este router?')) return
  routerMsg.value = ''
  routerError.value = ''
  try {
    await settingsApi.deleteRouter(id)
    routerMsg.value = 'Router eliminado.'
    await fetchRouters()
  } catch (e) {
    routerError.value = e.response?.data?.message ?? 'Error al eliminar.'
  }
}

async function testRouterConnection(id) {
  testingRouterId.value = id
  routerMsg.value = ''
  routerError.value = ''
  try {
    const { data } = await settingsApi.testRouter(id)
    if (data.success) {
      routerMsg.value = data.message
    } else {
      routerError.value = data.message
    }
  } catch (e) {
    routerError.value = e.response?.data?.message ?? 'Error de conexión.'
  } finally {
    testingRouterId.value = null
  }
}

async function save() {
  saving.value = true
  saveMsg.value = ''
  saveError.value = ''

  try {
    await settingsApi.saveSettings({ reniec_enabled: reniecEnabled.value })
    saveMsg.value = 'Cambios guardados.'
  } catch (error) {
    saveError.value = error.response?.data?.message ?? 'Error al guardar.'
  } finally {
    saving.value = false
  }
}

async function saveToken() {
  if (!reniecToken.value.trim()) return
  savingToken.value = true
  tokenMsg.value = ''
  tokenError.value = ''

  try {
    await settingsApi.saveToken(reniecToken.value.trim())
    tokenMsg.value = 'Token guardado correctamente.'
    reniecConfigured.value = true
    reniecUpdatedAt.value = new Date().toISOString()
    reniecToken.value = ''
    showToken.value = false
  } catch (error) {
    tokenError.value = error.response?.data?.message ?? 'Error al guardar el token.'
  } finally {
    savingToken.value = false
  }
}

async function clearToken() {
  clearingToken.value = true
  tokenMsg.value = ''
  tokenError.value = ''

  try {
    await settingsApi.clearToken()
    tokenMsg.value = 'Token eliminado.'
    reniecConfigured.value = false
    reniecUpdatedAt.value = null
    reniecToken.value = ''
  } catch (error) {
    tokenError.value = error.response?.data?.message ?? 'Error al eliminar el token.'
  } finally {
    clearingToken.value = false
  }
}

async function testReniec() {
  testing.value = true
  testMsg.value = ''
  testError.value = ''

  try {
    const { data } = await settingsApi.testReniec()

    if (data.success) {
      testMsg.value = data.message
    } else {
      testError.value = data.message
    }
  } catch (error) {
    testError.value = error.response?.data?.message ?? 'Error de conexion.'
  } finally {
    testing.value = false
  }
}
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Configuración</h1>
      <p class="text-gray-500 text-sm mt-0.5">Estado de APIs externas, routers MikroTik y ajustes del sistema</p>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 bg-gray-100 rounded-xl p-1 mb-6">
      <button
        :class="['flex-1 py-2 px-4 rounded-lg text-sm font-medium transition-all', activeTab === 'reniec' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700']"
        @click="activeTab = 'reniec'"
      >Token RENIEC</button>
      <button
        :class="['flex-1 py-2 px-4 rounded-lg text-sm font-medium transition-all', activeTab === 'routers' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700']"
        @click="activeTab = 'routers'"
      >Routers MikroTik</button>
    </div>

    <div v-if="loading" class="card flex items-center gap-3 text-sm text-gray-500">
      <svg class="w-4 h-4 animate-spin flex-shrink-0" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
      </svg>
      Cargando...
    </div>

    <template v-else>

      <!-- ═══════════════ TAB: RENIEC ═══════════════ -->
      <div v-show="activeTab === 'reniec'" class="space-y-4">
        <!-- Token SUNAT / RENIEC card -->
        <div class="card space-y-5">
          <!-- Header row -->
          <div class="flex items-start justify-between">
            <div>
              <div class="flex items-center gap-2.5">
                <h2 class="text-lg font-bold text-gray-900">Token SUNAT / RENIEC</h2>
                <span
                  :class="[
                    'inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full',
                    reniecConfigured ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700',
                  ]"
                >
                  <span :class="['w-1.5 h-1.5 rounded-full', reniecConfigured ? 'bg-green-500' : 'bg-amber-500']" />
                  {{ reniecConfigured ? 'Configurado' : 'No configurado' }}
                </span>
              </div>
              <p class="text-xs text-gray-500 mt-1">
                Token usado para consultas DNI/RUC en apiperu.dev.
                <span v-if="formattedUpdatedAt" class="text-gray-400">
                  · Última actualización: {{ formattedUpdatedAt }}
                </span>
              </p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
              <a
                href="https://apiperu.dev/api-peru-tokens"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:text-primary/80 transition-colors"
              >
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                </svg>
                Generar token
              </a>
            </div>
          </div>

          <!-- Token input + actions -->
          <div class="flex items-center gap-3">
            <div class="relative flex-1">
              <input
                v-model="reniecToken"
                :type="showToken ? 'text' : 'password'"
                :placeholder="reniecConfigured ? '(ya configurado)' : 'Pega tu token aquí...'"
                class="w-full px-4 py-2.5 pr-10 rounded-xl border border-gray-200 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all"
                autocomplete="off"
              />
              <button
                type="button"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors"
                @click="showToken = !showToken"
              >
                <svg v-if="showToken" class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L6.59 6.59m7.532 7.532l3.29 3.29M3 3l18 18" />
                </svg>
                <svg v-else class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
              </button>
            </div>
            <button
              type="button"
              class="btn-primary flex items-center gap-2 px-5 whitespace-nowrap"
              :disabled="savingToken || !reniecToken.trim()"
              @click="saveToken"
            >
              {{ savingToken ? 'Guardando...' : 'Guardar' }}
            </button>
            <button
              v-if="reniecConfigured"
              type="button"
              class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium text-red-600 border border-red-200 hover:bg-red-50 disabled:opacity-50 transition-all whitespace-nowrap"
              :disabled="clearingToken"
              @click="clearToken"
            >
              Limpiar
            </button>
          </div>

          <p v-if="tokenMsg" class="text-sm text-green-600 font-medium">{{ tokenMsg }}</p>
          <p v-if="tokenError" class="text-sm text-red-500">{{ tokenError }}</p>
        </div>

        <!-- Toggle + test section -->
        <div class="card space-y-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-gray-800">Activar consulta en tiempo real</p>
              <p class="text-xs text-gray-500 mt-0.5">Desactivado: se usan datos de prueba.</p>
            </div>
            <button
              type="button"
              :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors', reniecEnabled ? 'bg-primary' : 'bg-gray-200']"
              @click="reniecEnabled = !reniecEnabled"
            >
              <span :class="['inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform', reniecEnabled ? 'translate-x-6' : 'translate-x-1']" />
            </button>
          </div>

          <div class="flex items-center gap-3 pt-1">
            <button
              type="button"
              class="flex items-center gap-2 px-4 py-2 rounded-xl border border-gray-200 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 transition-all"
              :disabled="testing || !reniecConfigured"
              @click="testReniec"
            >
              <svg v-if="testing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              {{ testing ? 'Probando...' : 'Probar conexión' }}
            </button>
            <p v-if="testMsg" class="text-sm text-green-600 font-medium">{{ testMsg }}</p>
            <p v-if="testError" class="text-sm text-red-500">{{ testError }}</p>
          </div>
        </div>

        <div class="flex items-center justify-between">
          <div>
            <p v-if="saveMsg" class="text-sm text-green-600 font-medium">{{ saveMsg }}</p>
            <p v-if="saveError" class="text-sm text-red-500">{{ saveError }}</p>
          </div>
          <button type="button" class="btn-primary flex items-center gap-2 px-6" :disabled="saving" @click="save">
            {{ saving ? 'Guardando...' : 'Guardar cambios' }}
          </button>
        </div>
      </div>

      <!-- ═══════════════ TAB: ROUTERS MIKROTIK ═══════════════ -->
      <div v-show="activeTab === 'routers'">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 bg-primary/10 rounded-xl flex items-center justify-center">
            <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
            </svg>
          </div>
          <div>
            <h2 class="text-lg font-bold text-gray-900">Routers MikroTik</h2>
            <p class="text-xs text-gray-500">Administra los routers a los que GO se puede conectar.</p>
          </div>
        </div>

        <!-- Feedback -->
        <div v-if="routerMsg" class="mb-4 p-3 bg-green-50 border border-green-200 rounded-xl text-sm text-green-700">{{ routerMsg }}</div>
        <div v-if="routerError" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-600">{{ routerError }}</div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Form: Crear/Editar router -->
          <div class="card">
            <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
              <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
              </svg>
              {{ editingRouter ? 'Editar router' : 'Crear nuevo router' }}
            </h3>

            <form @submit.prevent="saveRouter" class="space-y-3">
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Nombre del Router <span class="text-red-400">*</span></label>
                <input v-model="routerForm.name" type="text" required placeholder="Ej: Router Principal"
                  class="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none" />
              </div>

              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">IP / URL <span class="text-red-400">*</span></label>
                <input v-model="routerForm.host" type="text" required placeholder="Ej: 192.168.1.1 o router.example.com"
                  class="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none" />
              </div>

              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Puerto API <span class="text-red-400">*</span></label>
                  <input v-model.number="routerForm.port" type="number" required min="1" max="65535"
                    class="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none" />
                </div>
                <div class="flex items-end pb-1">
                  <label class="flex items-center gap-2 cursor-pointer">
                    <input v-model="routerForm.use_tls" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-primary focus:ring-primary/30" />
                    <span class="text-sm text-gray-700">Usar TLS (HTTPS)</span>
                  </label>
                </div>
              </div>

              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Usuario <span class="text-red-400">*</span></label>
                  <input v-model="routerForm.username" type="text" required placeholder="Ej: admin"
                    class="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Contraseña {{ editingRouter ? '' : '*' }}</label>
                  <div class="relative">
                    <input v-model="routerForm.password" :type="showRouterPassword ? 'text' : 'password'"
                      :required="!editingRouter" :placeholder="editingRouter ? 'Dejar vacío para no cambiar' : 'Contraseña de API'"
                      class="w-full px-3 py-2 pr-9 rounded-lg border border-gray-200 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none" />
                    <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                      @click="showRouterPassword = !showRouterPassword">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path v-if="showRouterPassword" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M3 3l18 18" />
                        <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                      </svg>
                    </button>
                  </div>
                </div>
              </div>

              <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="btn-primary flex items-center gap-2 px-5" :disabled="savingRouter">
                  <svg v-if="savingRouter" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                  </svg>
                  <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                  </svg>
                  {{ editingRouter ? 'Actualizar router' : 'Crear router' }}
                </button>
                <button v-if="editingRouter" type="button" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800" @click="resetRouterForm">
                  Cancelar
                </button>
              </div>
            </form>
          </div>

          <!-- Routers List -->
          <div class="card">
            <div class="flex items-center justify-between mb-4">
              <h3 class="font-semibold text-gray-800">Routers configurados</h3>
              <button class="text-xs text-primary hover:underline flex items-center gap-1" @click="fetchRouters" :disabled="loadingRouters">
                <svg class="w-3.5 h-3.5" :class="{ 'animate-spin': loadingRouters }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Actualizar
              </button>
            </div>

            <div v-if="loadingRouters" class="text-sm text-gray-400 py-8 text-center">Cargando routers...</div>
            <div v-else-if="!routers.length" class="text-sm text-gray-400 py-8 text-center">
              No hay routers configurados.<br>
              <span class="text-xs">Agrega uno usando el formulario.</span>
            </div>

            <div v-else class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="text-left text-xs text-gray-500 border-b border-gray-100">
                    <th class="pb-2 font-medium">Nombre</th>
                    <th class="pb-2 font-medium">IP / URL</th>
                    <th class="pb-2 font-medium">Puerto</th>
                    <th class="pb-2 font-medium">Usuario</th>
                    <th class="pb-2 font-medium">TLS</th>
                    <th class="pb-2 font-medium text-right">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in routers" :key="r.id" class="border-b border-gray-50 last:border-0">
                    <td class="py-2.5 font-medium text-gray-900">{{ r.name }}</td>
                    <td class="py-2.5 text-gray-600">{{ r.host }}</td>
                    <td class="py-2.5 text-gray-600">{{ r.port }}</td>
                    <td class="py-2.5 text-gray-600">{{ r.username }}</td>
                    <td class="py-2.5">
                      <span :class="r.use_tls ? 'text-green-600' : 'text-gray-400'">{{ r.use_tls ? 'Sí' : 'No' }}</span>
                    </td>
                    <td class="py-2.5 text-right">
                      <div class="flex items-center justify-end gap-1.5">
                        <button
                          class="px-2.5 py-1 text-xs font-medium text-blue-600 border border-blue-200 rounded-lg hover:bg-blue-50 transition-colors"
                          @click="editRouter(r)"
                        >Editar</button>
                        <button
                          class="px-2.5 py-1 text-xs font-medium text-gray-700 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors disabled:opacity-50"
                          :disabled="testingRouterId === r.id"
                          @click="testRouterConnection(r.id)"
                        >{{ testingRouterId === r.id ? '...' : 'Probar' }}</button>
                        <button
                          class="px-2.5 py-1 text-xs font-medium text-red-600 border border-red-200 rounded-lg hover:bg-red-50 transition-colors"
                          @click="deleteRouter(r.id)"
                        >Eliminar</button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

    </template>
  </div>
</template>
