<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useSupervisionsStore } from '@/store/supervisions'
import { useAuthStore } from '@/store/auth'
import SupervisionTickets from './SupervisionTickets.vue'
import EstadosManager from './components/EstadosManager.vue'

const router = useRouter()
const store  = useSupervisionsStore()
const auth   = useAuthStore()

/* ── Tabs ─────────────────────────────────────────────── */
const activeTab = ref('tickets')  // 'tickets' | 'gestion' | 'estados'

/* ── Gestión tab — filters ────────────────────────────── */
const filterEstado     = ref('')
const filterSupervisor = ref('')
const showAssignModal  = ref(false)

/* ── Assign modal state ──────────────────────────────── */
const assignForm = ref({ installation_id: '', supervisor_id: '' })
const assignError = ref('')
const assigning   = ref(false)

/* ── Installation search for assign ──────────────────── */
const installationSearch   = ref('')
const installationResults  = ref([])
const selectedInstallation = ref(null)
const showInstDropdown     = ref(false)
let instTimer = null

import installationsApi from '@/services/installations'

watch(installationSearch, (val) => {
  clearTimeout(instTimer)
  selectedInstallation.value = null
  assignForm.value.installation_id = ''
  if (val.length < 2) { installationResults.value = []; return }
  instTimer = setTimeout(async () => {
    const { data } = await installationsApi.list({ search: val, per_page: 8 })
    installationResults.value = data.data
    showInstDropdown.value = installationResults.value.length > 0
  }, 300)
})

function selectInstallation(inst) {
  selectedInstallation.value = inst
  assignForm.value.installation_id = inst.id
  installationSearch.value = `#${inst.id} — ${inst.client?.nombres} ${inst.client?.apellidos}`
  showInstDropdown.value = false
  installationResults.value = []
}

/* ── Load ─────────────────────────────────────────────── */
onMounted(async () => {
  await Promise.all([
    store.fetchSupervisions(),
    store.fetchEstados(),
    auth.isAdmin ? store.fetchSupervisors() : Promise.resolve(),
  ])
})

watch([filterEstado, filterSupervisor], () => {
  const params = {}
  if (filterEstado.value)     params.estado_id     = filterEstado.value
  if (filterSupervisor.value) params.supervisor_id  = filterSupervisor.value
  store.fetchSupervisions(params)
})

/* ── Pagination ───────────────────────────────────────── */
function goToPage(page) {
  const params = { page }
  if (filterEstado.value)     params.estado_id     = filterEstado.value
  if (filterSupervisor.value) params.supervisor_id  = filterSupervisor.value
  store.fetchSupervisions(params)
}

/* ── Assign ───────────────────────────────────────────── */
async function handleAssign() {
  assignError.value = ''
  assigning.value   = true
  try {
    await store.assignSupervision(assignForm.value)
    showAssignModal.value = false
    assignForm.value = { installation_id: '', supervisor_id: '' }
    installationSearch.value = ''
    selectedInstallation.value = null
  } catch (e) {
    const errs = e.response?.data?.errors
    assignError.value = errs
      ? Object.values(errs).flat().join(' ')
      : e.response?.data?.message || 'Error al asignar.'
  } finally {
    assigning.value = false
  }
}

function openAssignModal() {
  assignForm.value = { installation_id: '', supervisor_id: '' }
  assignError.value = ''
  installationSearch.value = ''
  selectedInstallation.value = null
  showAssignModal.value = true
}
</script>

<template>
  <div>
    <!-- ── Page header ─────────────────────────────────── -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Supervisiones</h1>
      </div>
      <button
        v-if="auth.isAdmin"
        @click="openAssignModal"
        class="btn-primary flex items-center gap-2 self-start sm:self-auto"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Asignar
      </button>
    </div>

    <!-- ── Tabs ────────────────────────────────────────── -->
    <div class="flex gap-1 bg-gray-100 rounded-xl p-1 mb-6 w-fit">
      <button
        @click="activeTab = 'tickets'"
        :class="['px-4 py-2 rounded-lg text-sm font-medium transition-all', activeTab === 'tickets' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700']"
      >
        Tickets
      </button>
      <button
        @click="activeTab = 'gestion'"
        :class="['px-4 py-2 rounded-lg text-sm font-medium transition-all', activeTab === 'gestion' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700']"
      >
        Gestión
      </button>
      <button
        v-if="auth.isAdmin"
        @click="activeTab = 'estados'"
        :class="['px-4 py-2 rounded-lg text-sm font-medium transition-all', activeTab === 'estados' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700']"
      >
        Estados
      </button>
    </div>

    <!-- ── Tab: Tickets ────────────────────────────────── -->
    <SupervisionTickets v-if="activeTab === 'tickets'" />

    <!-- ── Tab: Gestión ────────────────────────────────── -->
    <template v-else-if="activeTab === 'gestion'">
      <!-- Filters -->
      <div class="card mb-5">
        <div class="flex flex-col sm:flex-row gap-3">
          <div class="flex-1">
            <label class="block text-xs font-medium text-gray-500 mb-1">Estado</label>
            <select v-model="filterEstado" class="input text-sm">
              <option value="">Todos los estados</option>
              <option v-for="e in store.estados" :key="e.id" :value="e.id">{{ e.nombre }}</option>
            </select>
          </div>
          <div v-if="auth.isAdmin" class="flex-1">
            <label class="block text-xs font-medium text-gray-500 mb-1">Supervisor</label>
            <select v-model="filterSupervisor" class="input text-sm">
              <option value="">Todos los supervisores</option>
              <option v-for="s in store.supervisors" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="store.loading" class="flex items-center justify-center py-16 text-gray-400 gap-2">
        <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
        Cargando...
      </div>

      <!-- Empty -->
      <div v-else-if="store.items.length === 0" class="card text-center py-16">
        <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
        </svg>
        <p class="text-gray-500 text-sm">No hay supervisiones registradas.</p>
      </div>

      <!-- List -->
      <div v-else class="space-y-3">
        <div
          v-for="sup in store.items"
          :key="sup.id"
          @click="router.push(`/supervisiones/${sup.id}`)"
          class="card hover:shadow-md cursor-pointer transition-all group"
        >
          <div class="flex items-start gap-4">
            <!-- Status indicator -->
            <div
              class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
              :style="sup.estado_supervision ? { backgroundColor: sup.estado_supervision.color + '22' } : { backgroundColor: '#F3F4F6' }"
            >
              <span
                class="w-2.5 h-2.5 rounded-full"
                :style="sup.estado_supervision ? { backgroundColor: sup.estado_supervision.color } : { backgroundColor: '#9CA3AF' }"
              />
            </div>

            <!-- Content -->
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <h3 class="font-semibold text-gray-800 text-sm sm:text-base">
                  {{ sup.installation?.client?.nombres }} {{ sup.installation?.client?.apellidos }}
                </h3>
                <span
                  v-if="sup.estado_supervision"
                  class="px-2 py-0.5 rounded-full text-xs font-medium"
                  :style="{ backgroundColor: sup.estado_supervision.color + '22', color: sup.estado_supervision.color }"
                >
                  {{ sup.estado_supervision.nombre }}
                </span>
                <span v-else class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                  Sin estado
                </span>
              </div>
              <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                <span>DNI {{ sup.installation?.client?.dni }}</span>
                <span>{{ sup.installation?.client?.distrito }}</span>
                <span>Supervisor: {{ sup.supervisor?.name }}</span>
                <span v-if="sup.photos?.length">{{ sup.photos.length }} foto(s)</span>
              </div>
            </div>

            <!-- Arrow -->
            <svg class="w-5 h-5 text-gray-300 group-hover:text-primary transition-colors flex-shrink-0 mt-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="store.pagination.last_page > 1" class="flex items-center justify-center gap-2 mt-6">
        <button
          v-for="p in store.pagination.last_page"
          :key="p"
          @click="goToPage(p)"
          :class="['w-9 h-9 rounded-lg text-sm font-medium transition-colors', p === store.pagination.current_page ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']"
        >{{ p }}</button>
      </div>
    </template>

    <!-- ── Tab: Estados (admin only) ───────────────────── -->
    <EstadosManager v-else-if="activeTab === 'estados' && auth.isAdmin" />

    <!-- ── Assign Modal ─────────────────────────────────── -->
    <Teleport to="body">
      <Transition name="fade">
        <div
          v-if="showAssignModal"
          class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40"
          @click.self="showAssignModal = false"
        >
          <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6" @click.stop>
            <h2 class="text-lg font-bold text-gray-900 mb-4">Asignar Supervisión</h2>

            <div v-if="assignError" class="bg-red-50 border border-red-200 text-red-600 text-sm rounded-xl px-4 py-2.5 mb-4">
              {{ assignError }}
            </div>

            <form @submit.prevent="handleAssign" class="space-y-4">
              <div class="relative">
                <label class="block text-sm font-medium text-gray-700 mb-1">Instalación *</label>
                <input
                  v-model="installationSearch"
                  type="text"
                  placeholder="Busca por cliente o #ID..."
                  autocomplete="off"
                  class="input text-sm"
                  @focus="showInstDropdown = installationResults.length > 0"
                  @blur="setTimeout(() => showInstDropdown = false, 200)"
                />
                <Transition name="fade">
                  <div
                    v-if="showInstDropdown"
                    class="absolute z-20 top-full mt-1 left-0 right-0 bg-white rounded-xl border border-gray-200 shadow-lg overflow-hidden max-h-48 overflow-y-auto"
                  >
                    <button
                      v-for="inst in installationResults"
                      :key="inst.id"
                      type="button"
                      @mousedown.prevent="selectInstallation(inst)"
                      class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-primary/5 transition-colors text-left"
                    >
                      <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-800">#{{ inst.id }} — {{ inst.client?.nombres }} {{ inst.client?.apellidos }}</p>
                        <p class="text-xs text-gray-400">{{ inst.fecha }} · {{ inst.hora_inicio }}</p>
                      </div>
                    </button>
                  </div>
                </Transition>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Supervisor *</label>
                <select v-model="assignForm.supervisor_id" class="input text-sm">
                  <option value="" disabled>Seleccionar supervisor</option>
                  <option v-for="s in store.supervisors" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
              </div>

              <div class="flex gap-3 justify-end pt-2">
                <button type="button" @click="showAssignModal = false" class="px-4 py-2 rounded-xl border border-gray-200 text-sm font-medium text-gray-600 hover:border-gray-300 transition-colors">
                  Cancelar
                </button>
                <button
                  type="submit"
                  :disabled="assigning || !assignForm.installation_id || !assignForm.supervisor_id"
                  class="btn-primary px-6"
                >
                  <svg v-if="assigning" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                  </svg>
                  {{ assigning ? 'Asignando...' : 'Asignar' }}
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
