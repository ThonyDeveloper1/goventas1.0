<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'

/* ── State ──────────────────────────────────────────────────── */
const loading      = ref(true)
const saving       = ref(false)
const running      = ref(false)
const loadingLogs  = ref(false)

const saveMsg      = ref('')
const saveError    = ref('')
const runMsg       = ref('')
const runError     = ref('')

// Valores actuales (solo lectura, mostrados como resumen)
const currentFrom  = ref('')
const currentTo    = ref('')

// Formulario de edición (solo correo destino)
const mailTo           = ref('')
const mailPasswordSet  = ref(false)
const editingConfig    = ref(false)

const logs = ref([])

/* ── Load config ────────────────────────────────────────────── */
async function loadConfig() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/backup/config')
    currentFrom.value     = data.mail_from        ?? ''
    currentTo.value       = data.mail_to          ?? ''
    mailTo.value          = data.mail_to          ?? ''
    mailPasswordSet.value = data.mail_password_set ?? false
  } catch {
    saveError.value = 'No se pudo cargar la configuración.'
  } finally {
    loading.value = false
  }
}

function startEditing() {
  mailTo.value        = currentTo.value
  editingConfig.value = true
}

function cancelEditing() {
  editingConfig.value = false
  saveError.value = ''
}

/* ── Save config ─────────────────────────────────────────────── */
async function saveConfig() {
  saveMsg.value   = ''
  saveError.value = ''
  saving.value    = true
  try {
    await api.post('/admin/backup/config', { mail_to: mailTo.value })
    currentTo.value     = mailTo.value
    editingConfig.value = false
    saveMsg.value        = 'Configuración guardada correctamente.'
  } catch (e) {
    saveError.value = e.response?.data?.errors
      ? Object.values(e.response.data.errors).flat().join(' ')
      : e.response?.data?.message ?? 'Error al guardar.'
  } finally {
    saving.value = false
    setTimeout(() => { saveMsg.value = '' }, 3000)
  }
}

/* ── Run backup now ─────────────────────────────────────────── */
async function runBackup() {
  runMsg.value   = ''
  runError.value = ''
  running.value  = true
  try {
    const { data } = await api.post('/admin/backup/run')
    runMsg.value = data.message
    await loadLogs()
  } catch (e) {
    runError.value = e.response?.data?.error ?? 'Error al ejecutar el backup.'
  } finally {
    running.value = false
    setTimeout(() => { runMsg.value = '' }, 5000)
  }
}

/* ── Load logs ──────────────────────────────────────────────── */
async function loadLogs() {
  loadingLogs.value = true
  try {
    const { data } = await api.get('/admin/backup/logs')
    logs.value = data.lines ?? []
  } catch {
    logs.value = []
  } finally {
    loadingLogs.value = false
  }
}

onMounted(async () => {
  await loadConfig()
  await loadLogs()
})
</script>

<template>
  <div class="p-4 sm:p-6 max-w-3xl mx-auto space-y-6">

    <!-- ── Header ──────────────────────────────────────────── -->
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Backup de Base de Datos</h1>
      <p class="text-sm text-gray-500 mt-1">Configura el correo de envío y ejecuta backups manuales o automáticos cada 3 días.</p>
    </div>

    <!-- ── Config card ─────────────────────────────────────── -->
    <div class="card">
      <h2 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
        <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">1</span>
        Configuración de correo
      </h2>

      <div v-if="loading" class="flex justify-center py-8">
        <svg class="w-6 h-6 animate-spin text-primary" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
      </div>

      <div v-else>
        <!-- ── Resumen actual ── -->
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 space-y-3 mb-4">
          <div class="flex items-start gap-3">
            <svg class="w-4 h-4 text-gray-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
            <div>
              <p class="text-xs text-gray-500 uppercase tracking-wide leading-none mb-1">Correo que envía</p>
              <p class="text-sm font-medium text-gray-800">{{ currentFrom || '—' }}</p>
            </div>
          </div>
          <div class="flex items-start gap-3">
            <svg class="w-4 h-4 text-gray-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0H4"/>
            </svg>
            <div>
              <p class="text-xs text-gray-500 uppercase tracking-wide leading-none mb-1">Correo que recibe</p>
              <p class="text-sm font-medium text-gray-800">{{ currentTo || '—' }}</p>
            </div>
          </div>
          <div class="flex items-center gap-2 pl-7">
            <span class="text-xs text-gray-500">Contraseña:</span>
            <span v-if="mailPasswordSet" class="inline-flex items-center gap-1 text-xs text-green-600 font-medium">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
              </svg>
              Configurada
            </span>
            <span v-else class="text-xs text-red-500 font-medium">⚠ No configurada</span>
          </div>
        </div>

        <button v-if="!editingConfig" @click="startEditing"
          class="btn-secondary text-sm px-4 flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
          </svg>
          Editar configuración
        </button>

        <!-- ── Formulario de edición ── -->
        <form v-if="editingConfig" @submit.prevent="saveConfig" class="space-y-4 mt-4 border-t border-gray-200 pt-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Nuevo correo que recibe el backup *</label>
            <input v-model="mailTo" type="email" required class="input" placeholder="destino@gmail.com" />
            <p class="text-xs text-gray-500 mt-1">Actualmente: <span class="font-medium text-gray-700">{{ currentTo }}</span></p>
          </div>

          <!-- Mensajes -->
          <p v-if="saveMsg" class="text-sm text-green-600 font-medium">{{ saveMsg }}</p>
          <p v-if="saveError" class="text-sm text-red-500">{{ saveError }}</p>

          <div class="flex items-center justify-end gap-3">
            <button type="button" @click="cancelEditing" class="btn-secondary px-4 text-sm">
              Cancelar
            </button>
            <button type="submit" :disabled="saving" class="btn-primary px-6 disabled:opacity-50">
              <svg v-if="saving" class="w-4 h-4 animate-spin mr-2" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>
              {{ saving ? 'Guardando...' : 'Guardar' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Manual run card ─────────────────────────────────── -->
    <div class="card">
      <h2 class="font-semibold text-gray-800 mb-3 flex items-center gap-2">
        <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">2</span>
        Ejecutar backup ahora
      </h2>
      <p class="text-sm text-gray-500 mb-4">Genera el backup de PostgreSQL y lo envía por correo inmediatamente. El backup automático corre cada 3 días a medianoche.</p>

      <p v-if="runMsg" class="text-sm text-green-600 font-medium mb-3">✓ {{ runMsg }}</p>
      <p v-if="runError" class="text-sm text-red-500 mb-3">{{ runError }}</p>

      <button @click="runBackup" :disabled="running" class="btn-primary px-6 disabled:opacity-50">
        <svg v-if="running" class="w-4 h-4 animate-spin mr-2 inline" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
        <svg v-else class="w-4 h-4 mr-2 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
        </svg>
        {{ running ? 'Ejecutando...' : 'Ejecutar backup ahora' }}
      </button>
    </div>

    <!-- ── Logs card ───────────────────────────────────────── -->
    <div class="card">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-semibold text-gray-800 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">3</span>
          Historial de backups
        </h2>
        <button @click="loadLogs" :disabled="loadingLogs"
          class="text-xs text-primary hover:underline disabled:opacity-50 flex items-center gap-1">
          <svg v-if="loadingLogs" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
          Actualizar
        </button>
      </div>

      <div v-if="logs.length === 0" class="text-sm text-gray-400 py-4 text-center">
        No hay registros aún.
      </div>
      <div v-else class="bg-gray-900 rounded-xl p-4 font-mono text-xs text-green-400 space-y-1 max-h-64 overflow-y-auto">
        <div v-for="(line, i) in logs" :key="i" :class="line.includes('ERROR') || line.includes('error') ? 'text-red-400' : ''">
          {{ line }}
        </div>
      </div>
    </div>

  </div>
</template>
