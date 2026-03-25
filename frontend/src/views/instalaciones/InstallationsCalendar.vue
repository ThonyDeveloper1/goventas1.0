<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useInstallationsStore } from '@/store/installations'
import { useAuthStore } from '@/store/auth'

const router = useRouter()
const store  = useInstallationsStore()
const auth   = useAuthStore()

/* ── Calendar navigation ────────────────────────────────── */
const today        = new Date()
const currentYear  = ref(today.getFullYear())
const currentMonth = ref(today.getMonth()) // 0-based
const selectedDate = ref(null)

const MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']
const DAYS   = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb']

const monthLabel = computed(() => `${MONTHS[currentMonth.value]} ${currentYear.value}`)

function prevMonth() {
  if (currentMonth.value === 0) { currentMonth.value = 11; currentYear.value-- }
  else currentMonth.value--
}
function nextMonth() {
  if (currentMonth.value === 11) { currentMonth.value = 0; currentYear.value++ }
  else currentMonth.value++
}
function goToday() {
  currentYear.value  = today.getFullYear()
  currentMonth.value = today.getMonth()
  selectedDate.value = toISO(today)
}

/* ── Calendar grid ──────────────────────────────────────── */
const calendarDays = computed(() => {
  const year  = currentYear.value
  const month = currentMonth.value
  const first = new Date(year, month, 1).getDay()
  const days  = new Date(year, month + 1, 0).getDate()

  const grid = []
  for (let i = 0; i < first; i++) grid.push(null)
  for (let d = 1; d <= days; d++) {
    const date = toISO(new Date(year, month, d))
    grid.push({ date, day: d })
  }
  return grid
})

function toISO(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`
}
function isToday(date) { return date === toISO(today) }
function isPast(date)  { return date < toISO(today) }
function isSelected(date) { return date === selectedDate.value }

/* ── Load installations for current month ───────────────── */
const installationsByDate = computed(() => {
  const map = {}
  for (const item of store.items) {
    const d = item.fecha?.split('T')[0] ?? item.fecha
    if (!map[d]) map[d] = []
    map[d].push(item)
  }
  return map
})

function countForDate(date) {
  return installationsByDate.value[date]?.length ?? 0
}

watch([currentYear, currentMonth], loadMonth, { immediate: false })
onMounted(loadMonth)

async function loadMonth() {
  const y = currentYear.value
  const m = currentMonth.value
  const from = `${y}-${String(m + 1).padStart(2,'0')}-01`
  const last  = new Date(y, m + 1, 0).getDate()
  const to    = `${y}-${String(m + 1).padStart(2,'0')}-${last}`
  await store.fetchInstallations({ from, to, per_page: 200 })
}

/* ── Day panel ──────────────────────────────────────────── */
const dayInstallations = computed(() =>
  selectedDate.value ? (installationsByDate.value[selectedDate.value] ?? []) : []
)

function selectDate(date) {
  if (!date || isPast(date)) return
  selectedDate.value = selectedDate.value === date ? null : date
}

/* ── Status styles ──────────────────────────────────────── */
const estadoStyle = {
  pendiente:  { dot: 'bg-yellow-400', badge: 'bg-yellow-50 text-yellow-700 ring-yellow-200' },
  en_proceso: { dot: 'bg-blue-400',   badge: 'bg-blue-50   text-blue-700   ring-blue-200'   },
  completado: { dot: 'bg-green-400',  badge: 'bg-green-50  text-green-700  ring-green-200'  },
}

function dotColor(estado) {
  return estadoStyle[estado]?.dot ?? 'bg-gray-300'
}

async function deleteInstallation(id) {
  if (!confirm('¿Eliminar esta instalación?')) return
  try {
    await store.removeInstallation(id)
  } catch {
    alert('Error al eliminar.')
  }
}
</script>

<template>
  <div>

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Instalaciones</h1>
        <p class="text-gray-500 text-sm mt-0.5">Agenda de instalaciones · {{ store.pagination.total }} total</p>
      </div>
      <router-link to="/instalaciones/nueva" class="btn-primary inline-flex items-center gap-2 self-start sm:self-auto">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
        </svg>
        Nueva Instalación
      </router-link>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-[1fr_340px] gap-5">

      <!-- ── Calendar ──────────────────────────────────────── -->
      <div class="card p-0 overflow-hidden">

        <!-- Month nav -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <button @click="prevMonth" class="p-2 rounded-lg hover:bg-gray-100 text-gray-500 transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
          </button>
          <div class="flex items-center gap-3">
            <h2 class="text-base font-bold text-gray-900">{{ monthLabel }}</h2>
            <button
              @click="goToday"
              class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-500 hover:border-primary hover:text-primary transition-colors"
            >
              Hoy
            </button>
          </div>
          <button @click="nextMonth" class="p-2 rounded-lg hover:bg-gray-100 text-gray-500 transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
          </button>
        </div>

        <!-- Day labels -->
        <div class="grid grid-cols-7 border-b border-gray-100">
          <div
            v-for="d in DAYS"
            :key="d"
            class="py-2.5 text-center text-xs font-semibold text-gray-400"
          >
            {{ d }}
          </div>
        </div>

        <!-- Day cells -->
        <div class="grid grid-cols-7">
          <div
            v-for="(cell, idx) in calendarDays"
            :key="idx"
            :class="[
              'min-h-[72px] border-b border-r border-gray-50 p-1.5 transition-colors',
              cell && !isPast(cell.date) ? 'cursor-pointer hover:bg-primary/5' : '',
              cell && isSelected(cell.date) ? 'bg-primary/8 ring-1 ring-inset ring-primary/30' : '',
              cell && isPast(cell.date) ? 'opacity-40' : '',
              !cell ? '' : '',
            ]"
            @click="cell && selectDate(cell.date)"
          >
            <template v-if="cell">
              <!-- Day number -->
              <div class="flex items-center justify-between mb-1">
                <span
                  :class="[
                    'w-6 h-6 rounded-full flex items-center justify-center text-xs font-semibold',
                    isToday(cell.date)    ? 'bg-primary text-white' :
                    isSelected(cell.date) ? 'text-primary font-bold' :
                    'text-gray-600',
                  ]"
                >
                  {{ cell.day }}
                </span>
                <span
                  v-if="countForDate(cell.date)"
                  class="text-xs bg-primary/10 text-primary font-semibold px-1.5 py-0.5 rounded-md"
                >
                  {{ countForDate(cell.date) }}
                </span>
              </div>

              <!-- Slot dots -->
              <div class="flex flex-wrap gap-0.5">
                <div
                  v-for="inst in (installationsByDate[cell.date] ?? []).slice(0, 4)"
                  :key="inst.id"
                  :class="['w-2 h-2 rounded-full', dotColor(inst.estado)]"
                  :title="`${inst.hora_inicio?.slice(0,5)} ${inst.client?.nombres ?? ''}`"
                />
                <span
                  v-if="(installationsByDate[cell.date]?.length ?? 0) > 4"
                  class="text-xs text-gray-400 leading-none"
                >
                  +{{ installationsByDate[cell.date].length - 4 }}
                </span>
              </div>
            </template>
          </div>
        </div>

      </div>

      <!-- ── Day panel ─────────────────────────────────────── -->
      <div class="space-y-4">

        <!-- Legend -->
        <div class="card py-3">
          <p class="text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Estados</p>
          <div class="flex flex-wrap gap-3">
            <div v-for="(s, key) in estadoStyle" :key="key" class="flex items-center gap-1.5 text-xs text-gray-600">
              <div :class="['w-2.5 h-2.5 rounded-full', s.dot]" />
              {{ { pendiente: 'Pendiente', en_proceso: 'En proceso', completado: 'Completado' }[key] }}
            </div>
          </div>
        </div>

        <!-- Selected day detail -->
        <div class="card p-0 overflow-hidden">
          <div class="px-4 py-3.5 border-b border-gray-100 flex items-center justify-between">
            <div>
              <p class="font-semibold text-gray-800 text-sm">
                {{ selectedDate
                    ? new Date(selectedDate + 'T00:00:00').toLocaleDateString('es-PE', { weekday:'long', day:'numeric', month:'long' })
                    : 'Selecciona un día' }}
              </p>
              <p v-if="selectedDate" class="text-xs text-gray-400 mt-0.5">
                {{ dayInstallations.length }} instalación{{ dayInstallations.length !== 1 ? 'es' : '' }}
              </p>
            </div>
            <router-link
              v-if="selectedDate"
              :to="`/instalaciones/nueva?fecha=${selectedDate}`"
              class="p-2 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 transition-colors"
              title="Agendar en este día"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
              </svg>
            </router-link>
          </div>

          <!-- Empty -->
          <div v-if="!selectedDate" class="flex flex-col items-center justify-center py-10 text-center px-4">
            <svg class="w-8 h-8 text-gray-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <p class="text-sm text-gray-400">Haz clic en un día del calendario</p>
          </div>

          <div v-else-if="dayInstallations.length === 0" class="flex flex-col items-center justify-center py-10 text-center px-4">
            <svg class="w-8 h-8 text-gray-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-sm text-gray-500 font-medium">Día libre</p>
            <p class="text-xs text-gray-400 mt-0.5">Sin instalaciones agendadas</p>
          </div>

          <!-- Installations list for the day -->
          <div v-else class="divide-y divide-gray-50">
            <div
              v-for="inst in dayInstallations"
              :key="inst.id"
              class="px-4 py-3 group hover:bg-gray-50/60 transition-colors"
            >
              <div class="flex items-start justify-between gap-2">
                <div class="flex-1 min-w-0">
                  <!-- Time -->
                  <div class="flex items-center gap-2 mb-1">
                    <span class="text-xs font-mono font-bold text-gray-900">
                      {{ inst.hora_inicio?.slice(0,5) }} – {{ inst.hora_fin?.slice(0,5) }}
                    </span>
                    <span
                      class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold ring-1 capitalize"
                      :class="estadoStyle[inst.estado]?.badge ?? ''"
                    >
                      {{ inst.estado?.replace('_',' ') }}
                    </span>
                  </div>
                  <!-- Client -->
                  <p class="text-sm font-medium text-gray-800 truncate">
                    {{ inst.client?.nombres }} {{ inst.client?.apellidos }}
                  </p>
                  <p class="text-xs text-gray-400">{{ inst.client?.distrito }} · DNI {{ inst.client?.dni }}</p>
                  <p v-if="auth.isAdmin" class="text-xs text-gray-400 mt-0.5">
                    <span class="text-gray-300">·</span> {{ inst.vendedora?.name }}
                  </p>
                </div>

                <!-- Actions -->
                <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                  <router-link
                    :to="`/instalaciones/${inst.id}/editar`"
                    class="p-1.5 rounded-lg text-gray-400 hover:text-blue-500 hover:bg-blue-50 transition-colors"
                  >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </router-link>
                  <button
                    @click="deleteInstallation(inst.id)"
                    class="p-1.5 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 transition-colors"
                  >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Loading indicator -->
        <div v-if="store.loading" class="flex items-center justify-center gap-2 text-sm text-gray-400 py-3">
          <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
          Actualizando...
        </div>

      </div>
    </div>
  </div>
</template>
