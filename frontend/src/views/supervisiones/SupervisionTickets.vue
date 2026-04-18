<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import supervisionsApi from '@/services/supervisions'
import { useSupervisionsStore } from '@/store/supervisions'
import { useAuthStore } from '@/store/auth'

const router = useRouter()
const store  = useSupervisionsStore()
const auth   = useAuthStore()

/* ── State ─────────────────────────────────────────────── */
const items      = ref([])
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
const loading    = ref(false)

const filterMes     = ref(currentMonth())
const filterEstado  = ref('')
const showHistory   = ref(false)

/* Modal cambio de estado */
const stateModal   = ref(false)
const stateTarget  = ref(null)  // { installationId, supervisionId, currentEstadoId }
const stateSeleccionado = ref(null)
const stateComentario   = ref('')
const stateLoading  = ref(false)
const stateError    = ref('')

function currentMonth() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

/* ── Computed ──────────────────────────────────────────── */
const meses = computed(() => {
  const list = []
  const now  = new Date()
  for (let i = 0; i < 12; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1)
    const val = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
    const label = d.toLocaleDateString('es-PE', { month: 'long', year: 'numeric' })
    list.push({ val, label: label.charAt(0).toUpperCase() + label.slice(1) })
  }
  return list
})

/* ── Load ──────────────────────────────────────────────── */
async function load(page = 1) {
  loading.value = true
  try {
    const params = { page }
    if (filterMes.value)    params.mes       = filterMes.value
    if (filterEstado.value) params.estado_id  = filterEstado.value
    if (showHistory.value)  params.history    = 1

    const { data } = await supervisionsApi.tickets(params)
    items.value      = data.data
    pagination.value = { current_page: data.current_page, last_page: data.last_page, total: data.total }
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await store.fetchEstados()
  load()
})

watch([filterMes, filterEstado, showHistory], () => load(1))

/* ── Estado badge helper ───────────────────────────────── */
function estadoBadge(sup) {
  if (!sup) return { label: 'Sin supervisión', color: '#9CA3AF' }
  if (sup.estado_supervision) return { label: sup.estado_supervision.nombre, color: sup.estado_supervision.color }
  return { label: 'Sin estado', color: '#9CA3AF' }
}

function hexToRgb(hex) {
  const r = parseInt(hex.slice(1, 3), 16)
  const g = parseInt(hex.slice(3, 5), 16)
  const b = parseInt(hex.slice(5, 7), 16)
  return `${r}, ${g}, ${b}`
}

/* ── Open state change modal ───────────────────────────── */
function openStateModal(item) {
  if (!auth.isAdmin && !auth.isSupervisor) return
  stateTarget.value = item
  stateSeleccionado.value = item.supervision?.estado_id ?? null
  stateComentario.value   = item.supervision?.comentario ?? ''
  stateError.value = ''
  stateModal.value = true
}

async function saveState() {
  if (!stateSeleccionado.value || !stateTarget.value?.supervision) return
  stateLoading.value = true
  stateError.value   = ''
  try {
    await store.setEstado(
      stateTarget.value.supervision.id,
      stateSeleccionado.value,
      stateComentario.value || null,
    )
    stateModal.value = false
    load(pagination.value.current_page)
  } catch (e) {
    stateError.value = e.response?.data?.message || 'Error al cambiar estado.'
  } finally {
    stateLoading.value = false
  }
}

function goToDetail(item) {
  if (item.supervision) {
    router.push(`/supervisiones/${item.supervision.id}`)
  }
}

function goToPage(page) {
  load(page)
}
</script>

<template>
  <div>
    <!-- Filters -->
    <div class="card mb-5">
      <div class="flex flex-col sm:flex-row gap-3 flex-wrap">
        <div class="flex-1 min-w-[140px]">
          <label class="block text-xs font-medium text-gray-500 mb-1">Mes</label>
          <select v-model="filterMes" class="input text-sm">
            <option v-for="m in meses" :key="m.val" :value="m.val">{{ m.label }}</option>
          </select>
        </div>
        <div class="flex-1 min-w-[140px]">
          <label class="block text-xs font-medium text-gray-500 mb-1">Estado supervisión</label>
          <select v-model="filterEstado" class="input text-sm">
            <option value="">Todos los estados</option>
            <option v-for="e in store.estados" :key="e.id" :value="e.id">{{ e.nombre }}</option>
          </select>
        </div>
        <div class="flex items-end">
          <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-600 h-[38px]">
            <input type="checkbox" v-model="showHistory" class="rounded accent-primary"/>
            Ver historial (incluye Aprobados)
          </label>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-16 text-gray-400 gap-2">
      <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
      Cargando...
    </div>

    <!-- Empty -->
    <div v-else-if="!items.length" class="card text-center py-12">
      <p class="text-gray-500 text-sm">No hay instalaciones para este período.</p>
    </div>

    <!-- Table -->
    <div v-else class="card overflow-x-auto p-0">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-gray-100 text-xs text-gray-500 uppercase tracking-wide">
            <th class="px-4 py-3 text-left font-semibold">Cliente</th>
            <th class="px-4 py-3 text-left font-semibold">DNI</th>
            <th class="px-4 py-3 text-left font-semibold hidden md:table-cell">Teléfono</th>
            <th class="px-4 py-3 text-left font-semibold hidden lg:table-cell">Distrito</th>
            <th class="px-4 py-3 text-left font-semibold hidden md:table-cell">Fecha</th>
            <th class="px-4 py-3 text-left font-semibold hidden xl:table-cell">IP</th>
            <th class="px-4 py-3 text-left font-semibold hidden xl:table-cell">MikroTik</th>
            <th class="px-4 py-3 text-left font-semibold hidden lg:table-cell">Vendedora</th>
            <th class="px-4 py-3 text-left font-semibold">Supervisión</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <tr
            v-for="item in items"
            :key="item.id"
            class="hover:bg-gray-50 transition-colors"
          >
            <td class="px-4 py-3">
              <button
                v-if="item.supervision"
                @click="goToDetail(item)"
                class="font-medium text-gray-800 hover:text-primary text-left"
              >
                {{ item.client?.nombres }} {{ item.client?.apellidos }}
              </button>
              <span v-else class="font-medium text-gray-800">
                {{ item.client?.nombres }} {{ item.client?.apellidos }}
              </span>
            </td>
            <td class="px-4 py-3 text-gray-600">{{ item.client?.dni }}</td>
            <td class="px-4 py-3 text-gray-600 hidden md:table-cell">{{ item.client?.telefono_1 || '—' }}</td>
            <td class="px-4 py-3 text-gray-600 hidden lg:table-cell">{{ item.client?.distrito || '—' }}</td>
            <td class="px-4 py-3 text-gray-500 tabular-nums hidden md:table-cell">{{ item.fecha }}</td>
            <td class="px-4 py-3 text-gray-500 font-mono text-xs hidden xl:table-cell">{{ item.client?.ip_address || '—' }}</td>
            <td class="px-4 py-3 hidden xl:table-cell">
              <span :class="[
                'px-2 py-0.5 rounded-full text-xs font-medium',
                item.client?.service_status === 'active'
                  ? 'bg-green-100 text-green-700'
                  : item.client?.service_status
                    ? 'bg-red-100 text-red-700'
                    : 'bg-gray-100 text-gray-500'
              ]">
                {{ item.client?.service_status || '—' }}
              </span>
            </td>
            <td class="px-4 py-3 text-gray-600 hidden lg:table-cell">{{ item.vendedora?.name || '—' }}</td>
            <td class="px-4 py-3">
              <!-- Badge coloreado — click to change estado -->
              <button
                @click="openStateModal(item)"
                :disabled="!item.supervision || (!auth.isAdmin && !auth.isSupervisor)"
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold transition-opacity"
                :style="item.supervision?.estado_supervision
                  ? { backgroundColor: item.supervision.estado_supervision.color + '22', color: item.supervision.estado_supervision.color, borderColor: item.supervision.estado_supervision.color + '44', border: '1px solid' }
                  : { backgroundColor: '#F3F4F6', color: '#6B7280' }"
                :class="item.supervision ? 'cursor-pointer hover:opacity-80' : 'cursor-default'"
              >
                <span
                  v-if="item.supervision?.estado_supervision"
                  class="w-1.5 h-1.5 rounded-full flex-shrink-0"
                  :style="{ backgroundColor: item.supervision.estado_supervision.color }"
                />
                <span>
                  {{ item.supervision?.estado_supervision?.nombre ?? 'Sin supervisión' }}
                </span>
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Pagination -->
      <div v-if="pagination.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t border-gray-100">
        <span class="text-xs text-gray-500">{{ pagination.total }} instalaciones</span>
        <div class="flex gap-1">
          <button
            v-for="page in pagination.last_page"
            :key="page"
            @click="goToPage(page)"
            :class="[
              'w-8 h-8 rounded-lg text-xs font-medium transition-colors',
              page === pagination.current_page
                ? 'bg-primary text-white'
                : 'text-gray-600 hover:bg-gray-100'
            ]"
          >{{ page }}</button>
        </div>
      </div>
    </div>

    <!-- ── Modal: cambiar estado ─────────────────────────── -->
    <Transition name="fade">
      <div
        v-if="stateModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        @click.self="stateModal = false"
      >
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">
          <h3 class="font-semibold text-gray-900 mb-1">Cambiar estado</h3>
          <p class="text-xs text-gray-500 mb-4">
            {{ stateTarget?.client?.nombres }} {{ stateTarget?.client?.apellidos }}
          </p>

          <!-- Error -->
          <div v-if="stateError" class="bg-red-50 text-red-600 text-xs rounded-xl px-3 py-2 mb-3">{{ stateError }}</div>

          <!-- Estado pills -->
          <div class="flex flex-wrap gap-2 mb-4">
            <button
              v-for="e in store.estados"
              :key="e.id"
              @click="stateSeleccionado = e.id"
              class="px-3 py-1.5 rounded-full text-xs font-semibold border transition-all"
              :style="stateSeleccionado === e.id
                ? { backgroundColor: e.color, color: '#fff', borderColor: e.color }
                : { backgroundColor: e.color + '22', color: e.color, borderColor: e.color + '55' }"
            >
              {{ e.nombre }}
            </button>
          </div>

          <!-- Comentario -->
          <textarea
            v-model="stateComentario"
            rows="2"
            placeholder="Comentario opcional..."
            class="input text-sm w-full rounded-xl mb-4 resize-none"
          />

          <div class="flex gap-2 justify-end">
            <button @click="stateModal = false" class="btn-ghost text-sm px-4">Cancelar</button>
            <button
              @click="saveState"
              :disabled="!stateSeleccionado || stateLoading"
              class="btn-primary text-sm px-4"
            >
              <svg v-if="stateLoading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>
              <span v-else>Guardar</span>
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
