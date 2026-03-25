<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useInstallationsStore } from '@/store/installations'
import clientsApi from '@/services/clients'

const router = useRouter()
const route  = useRoute()
const store  = useInstallationsStore()

/* ── Mode ───────────────────────────────────────────── */
const isEdit = computed(() => !!route.params.id)
const title  = computed(() => isEdit.value ? 'Editar Instalación' : 'Nueva Instalación')

/* ── Form ───────────────────────────────────────────── */
const saving  = ref(false)
const errors  = ref({})
const success = ref('')

const form = reactive({
  client_id:   '',
  fecha:       route.query.fecha ?? todayISO(),
  hora_inicio: '',
  duracion:    1,           // 1 OR 2 hours
  estado:      'pendiente',
  notas:       '',
})

function todayISO() {
  return new Date().toISOString().split('T')[0]
}

/* ── Client search ──────────────────────────────────── */
const clientSearch    = ref('')
const clientResults   = ref([])
const selectedClient  = ref(null)
const showDropdown    = ref(false)
let   clientTimer     = null

watch(clientSearch, (val) => {
  clearTimeout(clientTimer)
  if (val.length < 2) { clientResults.value = []; showDropdown.value = false; return }
  clientTimer = setTimeout(async () => {
    try {
      const { data } = await clientsApi.list({ search: val, per_page: 8 })
      clientResults.value = data.data
      showDropdown.value  = clientResults.value.length > 0
    } catch { clientResults.value = [] }
  }, 300)
})

onUnmounted(() => clearTimeout(clientTimer))

function selectClient(client) {
  selectedClient.value = client
  form.client_id       = client.id
  clientSearch.value   = `${client.nombres} ${client.apellidos} — DNI ${client.dni}`
  showDropdown.value   = false
  clientResults.value  = []
}

/* ── Availability ───────────────────────────────────── */
const loadingSlots = ref(false)
const slots        = ref([])   // [{ hora, hora_fin, disponible, conflicto_con, motivo }]

watch(() => form.fecha, fetchSlots)

let preserveHoraInicio = false

async function fetchSlots() {
  if (!form.fecha) return
  loadingSlots.value = true
  if (!preserveHoraInicio) form.hora_inicio = ''
  preserveHoraInicio = false
  try {
    const excludeId = isEdit.value ? Number(route.params.id) : null
    const data = await store.fetchAvailableSlots(form.fecha, excludeId)
    slots.value = (data.slots ?? []).map((slot) => {
      const durInfo = slot?.duraciones?.[form.duracion] ?? {}
      return {
        hora: slot.hora_inicio,
        hora_fin: durInfo.hora_fin,
        disponible: Boolean(durInfo.disponible),
        conflicto_con: durInfo.conflicto_con ?? null,
        motivo: durInfo.motivo ?? null,
      }
    })
  } finally {
    loadingSlots.value = false
  }
}

function selectSlot(slot) {
  if (!slot.disponible) return
  form.hora_inicio = slot.hora
}

watch(() => form.duracion, fetchSlots)

const horaFin = computed(() => {
  if (!form.hora_inicio) return null
  const [h, m] = form.hora_inicio.split(':').map(Number)
  return `${String(h + form.duracion).padStart(2,'0')}:${String(m).padStart(2,'0')}`
})

/* ── Load edit data ─────────────────────────────────── */
onMounted(async () => {
  if (isEdit.value) {
    const inst = await store.fetchInstallation(route.params.id)
    form.client_id   = inst.client_id
    form.fecha       = inst.fecha?.split('T')[0] ?? inst.fecha
    form.hora_inicio = inst.hora_inicio?.slice(0, 5)
    form.duracion    = inst.duracion ?? 1
    form.estado      = inst.estado
    form.notas       = inst.notas ?? ''
    selectedClient.value = inst.client
    clientSearch.value   = inst.client
      ? `${inst.client.nombres} ${inst.client.apellidos} — DNI ${inst.client.dni}`
      : ''
    preserveHoraInicio = true
  }
  // Always load slots for the current date
  await fetchSlots()
})

/* ── Submit ─────────────────────────────────────────── */
async function handleSubmit() {
  errors.value  = {}
  success.value = ''
  saving.value  = true

  try {
    const payload = { ...form }

    if (isEdit.value) {
      await store.updateInstallation(route.params.id, payload)
      success.value = 'Instalación actualizada correctamente.'
    } else {
      await store.createInstallation(payload)
      success.value = 'Instalación agendada correctamente.'
      setTimeout(() => router.push('/instalaciones'), 1200)
    }
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors ?? {}
    } else {
      errors.value = { _global: [e.response?.data?.message ?? 'Error al guardar.'] }
    }
  } finally {
    saving.value = false
  }
}

function fieldError(f) {
  return errors.value[f]?.[0]
}

const ESTADOS = [
  { value: 'pendiente',  label: 'Pendiente',  color: 'bg-yellow-400' },
  { value: 'en_proceso', label: 'En proceso', color: 'bg-blue-400'   },
  { value: 'completado', label: 'Completado', color: 'bg-green-400'  },
]
</script>

<template>
  <div class="max-w-2xl mx-auto">

    <!-- Header -->
    <div class="flex items-center gap-3 mb-6">
      <button
        @click="router.back()"
        class="p-2 rounded-xl text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
      >
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
      </button>
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ title }}</h1>
        <p class="text-gray-500 text-sm mt-0.5">
          Cada instalación ocupa un bloque de <strong>1 o 2 horas</strong>.
        </p>
      </div>
    </div>

    <!-- Alerts -->
    <Transition name="fade">
      <div v-if="errors._global" class="flex gap-2 bg-red-50 border border-red-200 text-red-600 text-sm rounded-xl px-4 py-3 mb-4">
        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        {{ errors._global[0] }}
      </div>
    </Transition>

    <Transition name="fade">
      <div v-if="success" class="flex gap-2 bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3 mb-4">
        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        {{ success }}
      </div>
    </Transition>

    <form @submit.prevent="handleSubmit" novalidate class="space-y-5">

      <!-- ── Client Search ─────────────────────────────── -->
      <div class="card">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">1</span>
          Cliente
        </h3>

        <div class="relative">
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Buscar cliente *</label>
          <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input
              v-model="clientSearch"
              type="text"
              placeholder="Escribe nombre, apellido o DNI..."
              autocomplete="off"
              :class="['input pl-9', fieldError('client_id') ? 'border-red-400' : '']"
              @focus="showDropdown = clientResults.length > 0"
              @blur="setTimeout(() => showDropdown = false, 200)"
            />
          </div>
          <p v-if="fieldError('client_id')" class="text-red-500 text-xs mt-1">{{ fieldError('client_id') }}</p>

          <!-- Dropdown -->
          <Transition name="fade">
            <div
              v-if="showDropdown"
              class="absolute z-20 top-full mt-1 left-0 right-0 bg-white rounded-xl border border-gray-200
                     shadow-lg overflow-hidden max-h-56 overflow-y-auto"
            >
              <button
                v-for="c in clientResults"
                :key="c.id"
                type="button"
                @mousedown.prevent="selectClient(c)"
                class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-primary/5 transition-colors text-left"
              >
                <div class="w-8 h-8 rounded-lg bg-primary/10 text-primary font-bold text-xs flex items-center justify-center flex-shrink-0">
                  {{ (c.nombres?.[0] ?? '') + (c.apellidos?.[0] ?? '') }}
                </div>
                <div class="min-w-0">
                  <p class="text-sm font-medium text-gray-800 truncate">{{ c.nombres }} {{ c.apellidos }}</p>
                  <p class="text-xs text-gray-400">DNI {{ c.dni }} · {{ c.distrito }}</p>
                </div>
              </button>
            </div>
          </Transition>
        </div>

        <!-- Selected client chip -->
        <div v-if="selectedClient" class="mt-3 flex items-center gap-3 bg-primary/5 border border-primary/20 rounded-xl px-3 py-2.5">
          <div class="w-8 h-8 rounded-lg bg-primary/10 text-primary font-bold text-xs flex items-center justify-center flex-shrink-0">
            {{ (selectedClient.nombres?.[0] ?? '') + (selectedClient.apellidos?.[0] ?? '') }}
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-gray-800">{{ selectedClient.nombres }} {{ selectedClient.apellidos }}</p>
            <p class="text-xs text-gray-500">DNI {{ selectedClient.dni }} · {{ selectedClient.distrito }}</p>
          </div>
          <svg class="w-4 h-4 text-primary flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
          </svg>
        </div>
      </div>

      <!-- ── Date ──────────────────────────────────────── -->
      <div class="card">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">2</span>
          Fecha
        </h3>
        <input
          v-model="form.fecha"
          type="date"
          :min="todayISO()"
          :class="['input', fieldError('fecha') ? 'border-red-400' : '']"
        />
        <p v-if="fieldError('fecha')" class="text-red-500 text-xs mt-1">{{ fieldError('fecha') }}</p>
      </div>

      <!-- ── Time slots ────────────────────────────────── -->
      <div class="card">
        <h3 class="font-semibold text-gray-800 mb-1 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">3</span>
          Horario
        </h3>

        <!-- Duration selector -->
        <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-xl">
          <label class="block text-sm font-medium text-gray-700 mb-2">Duración *</label>
          <div class="flex gap-3">
            <label class="flex items-center gap-2 cursor-pointer flex-1">
              <input
                type="radio"
                v-model.number="form.duracion"
                :value="1"
                class="rounded border-gray-300"
              />
              <span class="text-sm font-semibold text-gray-700">1 hora</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer flex-1">
              <input
                type="radio"
                v-model.number="form.duracion"
                :value="2"
                class="rounded border-gray-300"
              />
              <span class="text-sm font-semibold text-gray-700">2 horas</span>
            </label>
          </div>
        </div>

        <p class="text-xs text-gray-400 mb-4 ml-8">
          Selecciona el bloque de inicio. La instalación durará {{ form.duracion }} hora{{ form.duracion === 1 ? '' : 's' }}.
        </p>

        <!-- Slots grid -->
        <div v-if="loadingSlots" class="flex items-center justify-center gap-2 text-sm text-gray-400 py-6">
          <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
          Cargando horarios...
        </div>

        <div v-else class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
          <button
            v-for="slot in slots"
            :key="slot.hora"
            type="button"
            @click="selectSlot(slot)"
            :disabled="!slot.disponible"
            :title="slot.disponible ? `${slot.hora} – ${slot.hora_fin}` : `Ocupado: ${slot.conflicto_con}`"
            :class="[
              'relative flex flex-col items-center justify-center rounded-xl py-3 px-2 text-xs font-semibold transition-all border',
              form.hora_inicio === slot.hora
                ? 'bg-primary border-primary text-white shadow-primary scale-105'
                : slot.disponible
                  ? 'border-green-200 bg-green-50 text-green-700 hover:border-green-400 hover:bg-green-100 cursor-pointer'
                  : 'border-red-100 bg-red-50 text-red-400 cursor-not-allowed opacity-70',
            ]"
          >
            <!-- Status dot -->
            <span
              :class="[
                'absolute top-1.5 right-1.5 w-1.5 h-1.5 rounded-full',
                form.hora_inicio === slot.hora ? 'bg-white/70' :
                slot.disponible ? 'bg-green-400' : 'bg-red-400',
              ]"
            />
            <span class="text-sm font-mono">{{ slot.hora }}</span>
            <span class="opacity-70 mt-0.5">–{{ slot.hora_fin }}</span>
            <span v-if="!slot.disponible" class="text-xs opacity-60 mt-0.5">ocupado</span>
          </button>
        </div>

        <!-- Selected slot summary -->
        <Transition name="fade">
          <div
            v-if="form.hora_inicio && horaFin"
            class="mt-4 flex items-center gap-3 bg-primary/5 border border-primary/20 rounded-xl px-4 py-3"
          >
            <svg class="w-5 h-5 text-primary flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
              <p class="text-sm font-semibold text-gray-800">
                {{ form.hora_inicio }} – {{ horaFin }}
              </p>
              <p class="text-xs text-gray-500">Bloque de {{ form.duracion }} hora{{ form.duracion === 1 ? '' : 's' }} confirmado</p>
            </div>
          </div>
        </Transition>

        <p v-if="fieldError('hora_inicio')" class="text-red-500 text-xs mt-2">
          {{ fieldError('hora_inicio') }}
        </p>
      </div>

      <!-- ── Estado ────────────────────────────────────── -->
      <div class="card">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">4</span>
          Estado
        </h3>
        <div class="flex flex-wrap gap-2">
          <label
            v-for="opt in ESTADOS"
            :key="opt.value"
            :class="[
              'flex items-center gap-2 px-4 py-2.5 rounded-xl border cursor-pointer text-sm font-medium transition-all',
              form.estado === opt.value
                ? 'border-primary bg-primary/5 text-primary'
                : 'border-gray-200 text-gray-600 hover:border-gray-300',
            ]"
          >
            <input v-model="form.estado" :value="opt.value" type="radio" class="hidden" />
            <span :class="['w-2 h-2 rounded-full', opt.color]" />
            {{ opt.label }}
          </label>
        </div>
      </div>

      <!-- ── Notes ─────────────────────────────────────── -->
      <div class="card">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">5</span>
          Notas
          <span class="text-xs text-gray-400 font-normal">(opcional)</span>
        </h3>
        <textarea
          v-model="form.notas"
          rows="3"
          placeholder="Observaciones, equipos necesarios, acceso especial..."
          class="input resize-none"
          maxlength="500"
        />
        <p class="text-xs text-gray-400 text-right mt-1">{{ form.notas.length }}/500</p>
      </div>

      <!-- ── Submit ────────────────────────────────────── -->
      <div class="flex gap-3 justify-end">
        <button
          type="button"
          @click="router.back()"
          class="px-5 py-2.5 rounded-xl border border-gray-200 text-sm font-medium text-gray-600 hover:border-gray-300 transition-colors"
        >
          Cancelar
        </button>
        <button
          type="submit"
          :disabled="saving || !form.client_id || !form.fecha || !form.hora_inicio"
          class="btn-primary px-8"
        >
          <svg v-if="saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
          {{ saving ? 'Guardando...' : isEdit ? 'Actualizar' : 'Agendar Instalación' }}
        </button>
      </div>

    </form>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to        { opacity: 0; }
</style>
