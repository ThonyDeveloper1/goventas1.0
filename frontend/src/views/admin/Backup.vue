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

const mailFrom         = ref('')
const mailTo           = ref('')
const mailPassword     = ref('')
const mailPasswordSet  = ref(false)
const showPassword     = ref(false)
const changePassword   = ref(false)

const logs = ref([])

/* ── Load config ────────────────────────────────────────────── */
async function loadConfig() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/backup/config')
    mailFrom.value        = data.mail_from        ?? ''
    mailTo.value          = data.mail_to          ?? ''
    mailPasswordSet.value = data.mail_password_set ?? false
  } catch {
    saveError.value = 'No se pudo cargar la configuración.'
  } finally {
    loading.value = false
  }
}

/* ── Save config ─────────────────────────────────────────────── */
async function saveConfig() {
  saveMsg.value   = ''
  saveError.value = ''
  saving.value    = true
  try {
    const payload = { mail_from: mailFrom.value, mail_to: mailTo.value }
    if (changePassword.value && mailPassword.value) {
      payload.mail_password = mailPassword.value
    }
    await api.post('/admin/backup/config', payload)
    saveMsg.value        = 'Configuración guardada correctamente.'
    mailPasswordSet.value = true
    mailPassword.value   = ''
    changePassword.value  = false
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

      <form v-else @submit.prevent="saveConfig" class="space-y-4">
        <!-- Correo que envía -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Correo que envía (Gmail) *</label>
          <input v-model="mailFrom" type="email" required class="input" placeholder="ejemplo@gmail.com" />
        </div>

        <!-- Correo que recibe -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Correo que recibe el backup *</label>
          <input v-model="mailTo" type="email" required class="input" placeholder="destino@gmail.com" />
        </div>

        <!-- Contraseña de aplicación -->
        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="block text-sm font-medium text-gray-700">
              Contraseña de aplicación Gmail *
            </label>
            <span v-if="mailPasswordSet && !changePassword"
              class="text-xs text-green-600 font-medium flex items-center gap-1">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
              </svg>
              Configurada
            </span>
          </div>

          <div v-if="!mailPasswordSet || changePassword" class="relative">
            <input
              v-model="mailPassword"
              :type="showPassword ? 'text' : 'password'"
              class="input pr-10 font-mono"
              placeholder="xxxx xxxx xxxx xxxx"
              :required="!mailPasswordSet"
            />
            <button type="button" @click="showPassword = !showPassword"
              class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
              <svg v-if="!showPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
              </svg>
            </button>
          </div>
          <p v-if="!mailPasswordSet || changePassword" class="text-xs text-gray-500 mt-1">
            Ve a <a href="https://myaccount.google.com/apppasswords" target="_blank" class="text-primary underline">myaccount.google.com/apppasswords</a> para obtener la clave de 16 caracteres.
          </p>

          <button v-if="mailPasswordSet && !changePassword"
            type="button"
            @click="changePassword = true"
            class="text-xs text-amber-600 hover:text-amber-700 font-medium mt-1 underline">
            Cambiar contraseña
          </button>
          <button v-if="changePassword"
            type="button"
            @click="changePassword = false; mailPassword = ''"
            class="text-xs text-gray-500 hover:text-gray-700 font-medium mt-1 underline ml-2">
            Cancelar
          </button>
        </div>

        <!-- Mensajes -->
        <p v-if="saveMsg" class="text-sm text-green-600 font-medium">{{ saveMsg }}</p>
        <p v-if="saveError" class="text-sm text-red-500">{{ saveError }}</p>

        <div class="flex justify-end">
          <button type="submit" :disabled="saving" class="btn-primary px-6 disabled:opacity-50">
            <svg v-if="saving" class="w-4 h-4 animate-spin mr-2" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            {{ saving ? 'Guardando...' : 'Guardar configuración' }}
          </button>
        </div>
      </form>
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
