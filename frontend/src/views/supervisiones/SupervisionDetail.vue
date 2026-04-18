<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useSupervisionsStore } from '@/store/supervisions'
import { useAuthStore } from '@/store/auth'
import { resolvePhotoUrl } from '@/utils/photoUrl'
import supervisionsApi from '@/services/supervisions'

const router = useRouter()
const route  = useRoute()
const store  = useSupervisionsStore()
const auth   = useAuthStore()

const sup        = computed(() => store.current)
const uploading  = ref(false)
const savingChecklist = ref(false)
const settingEstado   = ref(false)
const comentario = ref('')
const error      = ref('')
const success    = ref('')

/* ── Local checklist state ────────────────────────────── */
const checklist = ref({
  fachada_verificada:    false,
  conexiones_verificadas: false,
  ubicacion_confirmada:  false,
  servicio_verificado:   false,
  nivel_senal:           '',
  notas_supervisor:      '',
})

/* ── Photo preview state ──────────────────────────────── */
const pendingFiles   = ref([])   // File objects to upload
const pendingPreviews = ref([])  // { url, name }

/* ── Load ─────────────────────────────────────────────── */
onMounted(async () => {
  await Promise.all([
    store.fetchSupervision(route.params.id),
    store.fetchEstados(),
  ])
  if (sup.value) {
    comentario.value = sup.value.comentario ?? ''
    checklist.value = {
      fachada_verificada:    sup.value.fachada_verificada    ?? false,
      conexiones_verificadas: sup.value.conexiones_verificadas ?? false,
      ubicacion_confirmada:  sup.value.ubicacion_confirmada  ?? false,
      servicio_verificado:   sup.value.servicio_verificado   ?? false,
      nivel_senal:           sup.value.nivel_senal           ?? '',
      notas_supervisor:      sup.value.notas_supervisor      ?? '',
    }
  }
})

/* ── Estado helpers ───────────────────────────────────── */
function getEstadoBadge() {
  if (!sup.value) return { label: '—', color: '#9CA3AF' }
  const e = sup.value.estado_supervision
  if (e) return { label: e.nombre, color: e.color }
  const legacyMap = {
    pendiente:  { label: 'Pendiente',  color: '#EAB308' },
    en_proceso: { label: 'En proceso', color: '#3B82F6' },
    completado: { label: 'Completado', color: '#16A34A' },
  }
  return legacyMap[sup.value.estado] ?? { label: sup.value.estado, color: '#9CA3AF' }
}

const canAct = computed(() => {
  if (!sup.value) return false
  return auth.isAdmin || sup.value.supervisor_id === auth.user?.id
})

const canUpload = computed(() => canAct.value)

/* ── Set estado ───────────────────────────────────────── */
async function handleSetEstado(estadoId) {
  if (!canAct.value || settingEstado.value) return
  error.value = ''
  settingEstado.value = true
  try {
    await store.setEstado(sup.value.id, estadoId, comentario.value || null)
    success.value = 'Estado actualizado.'
    await store.fetchSupervision(sup.value.id)
  } catch (e) {
    error.value = e.response?.data?.message || 'Error al cambiar estado.'
  } finally {
    settingEstado.value = false
  }
}

/* ── Save checklist ───────────────────────────────────── */
async function handleSaveChecklist() {
  if (!canAct.value) return
  error.value = ''
  savingChecklist.value = true
  try {
    await supervisionsApi.updateDetail(sup.value.id, {
      ...checklist.value,
      comentario: comentario.value || null,
    })
    await store.fetchSupervision(sup.value.id)
    success.value = 'Datos guardados.'
  } catch (e) {
    error.value = e.response?.data?.message || 'Error al guardar.'
  } finally {
    savingChecklist.value = false
  }
}

/* ── Photo upload ─────────────────────────────────────── */
function onFileSelect(event) {
  const files = Array.from(event.target.files)
  files.forEach((file) => {
    pendingFiles.value.push(file)
    pendingPreviews.value.push({
      url:  URL.createObjectURL(file),
      name: file.name,
    })
  })
  event.target.value = ''
}

function removePending(index) {
  URL.revokeObjectURL(pendingPreviews.value[index].url)
  pendingFiles.value.splice(index, 1)
  pendingPreviews.value.splice(index, 1)
}

async function handleUpload() {
  if (!pendingFiles.value.length) return
  error.value   = ''
  uploading.value = true
  try {
    await store.uploadPhotos(sup.value.id, pendingFiles.value)
    success.value = 'Fotos subidas correctamente.'
    // Clear previews
    pendingPreviews.value.forEach((p) => URL.revokeObjectURL(p.url))
    pendingFiles.value   = []
    pendingPreviews.value = []
  } catch (e) {
    const errs = e.response?.data?.errors
    error.value = errs
      ? Object.values(errs).flat().join(' ')
      : e.response?.data?.message || 'Error al subir fotos.'
  } finally {
    uploading.value = false
  }
}

async function handleDeletePhoto(photoId) {
  try {
    await store.removePhoto(sup.value.id, photoId)
  } catch (e) {
    error.value = e.response?.data?.message || 'Error al eliminar foto.'
  }
}

/* ── Lightbox ─────────────────────────────────────────── */
const lightboxUrl = ref(null)
</script>

<template>
  <div class="max-w-3xl mx-auto pb-8">

    <!-- Loading -->
    <div v-if="store.loading && !sup" class="flex items-center justify-center py-20 text-gray-400 gap-2">
      <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
      Cargando...
    </div>

    <template v-else-if="sup">

      <!-- Header -->
      <div class="flex items-center gap-3 mb-6">
        <button @click="router.push('/supervisiones')" class="p-2 rounded-xl text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
          </svg>
        </button>
        <div class="flex-1 min-w-0">
          <h1 class="text-xl sm:text-2xl font-bold text-gray-900 truncate">
            Supervisión #{{ sup.id }}
          </h1>
          <div class="flex items-center gap-2 mt-0.5">
            <span
              class="px-2 py-0.5 rounded-full text-xs font-semibold inline-flex items-center gap-1"
              :style="getEstadoBadge().color ? { backgroundColor: getEstadoBadge().color + '22', color: getEstadoBadge().color } : {}"
            >
              <span class="w-1.5 h-1.5 rounded-full inline-block" :style="{ backgroundColor: getEstadoBadge().color }" />
              {{ getEstadoBadge().label }}
            </span>
            <span class="text-xs text-gray-400">Supervisor: {{ sup.supervisor?.name }}</span>
          </div>
        </div>
      </div>

      <!-- Alerts -->
      <Transition name="fade">
        <div v-if="error" class="bg-red-50 border border-red-200 text-red-600 text-sm rounded-xl px-4 py-3 mb-4">{{ error }}</div>
      </Transition>
      <Transition name="fade">
        <div v-if="success" class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3 mb-4">{{ success }}</div>
      </Transition>

      <!-- ── Client info ───────────────────────────────── -->
      <div class="card mb-4">
        <h3 class="font-semibold text-gray-800 mb-3 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">C</span>
          Datos del Cliente
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
          <div>
            <p class="text-gray-400 text-xs">Nombre</p>
            <p class="font-medium text-gray-800">{{ sup.installation?.client?.nombres }} {{ sup.installation?.client?.apellidos }}</p>
          </div>
          <div>
            <p class="text-gray-400 text-xs">DNI</p>
            <p class="font-medium text-gray-800">{{ sup.installation?.client?.dni }}</p>
          </div>
          <div>
            <p class="text-gray-400 text-xs">Teléfono</p>
            <p class="font-medium text-gray-800">{{ sup.installation?.client?.telefono_1 || '—' }}</p>
          </div>
          <div>
            <p class="text-gray-400 text-xs">Distrito</p>
            <p class="font-medium text-gray-800">{{ sup.installation?.client?.distrito || '—' }}</p>
          </div>
          <div class="sm:col-span-2">
            <p class="text-gray-400 text-xs">Dirección</p>
            <p class="font-medium text-gray-800">{{ sup.installation?.client?.direccion || '—' }}</p>
          </div>
        </div>

        <!-- Map link -->
        <a
          v-if="sup.installation?.client?.latitud && sup.installation?.client?.longitud"
          :href="`https://www.google.com/maps?q=${sup.installation.client.latitud},${sup.installation.client.longitud}`"
          target="_blank"
          rel="noopener"
          class="mt-3 inline-flex items-center gap-2 text-primary text-sm font-medium hover:underline"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          Ver ubicación en mapa
        </a>
      </div>

      <!-- ── Installation info ─────────────────────────── -->
      <div class="card mb-4">
        <h3 class="font-semibold text-gray-800 mb-3 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">I</span>
          Datos de la Instalación
        </h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
          <div>
            <p class="text-gray-400 text-xs">Fecha</p>
            <p class="font-medium text-gray-800">{{ sup.installation?.fecha }}</p>
          </div>
          <div>
            <p class="text-gray-400 text-xs">Horario</p>
            <p class="font-medium text-gray-800">{{ sup.installation?.hora_inicio?.slice(0,5) }} – {{ sup.installation?.hora_fin?.slice(0,5) }}</p>
          </div>
          <div>
            <p class="text-gray-400 text-xs">Vendedora</p>
            <p class="font-medium text-gray-800">{{ sup.installation?.vendedora?.name || '—' }}</p>
          </div>
        </div>
      </div>

      <!-- ── Fotos de la vendedora (client photos) ─────── -->
      <div v-if="sup.installation?.client?.photos?.length" class="card mb-4">
        <h3 class="font-semibold text-gray-800 mb-3 flex items-center gap-2">
          <span class="w-6 h-6 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center text-xs font-bold">V</span>
          Fotos de la Vendedora
        </h3>
        <div class="grid grid-cols-3 sm:grid-cols-4 gap-2">
          <button
            v-for="photo in sup.installation.client.photos"
            :key="photo.id"
            @click="lightboxUrl = resolvePhotoUrl(photo)"
            class="aspect-square rounded-xl overflow-hidden border border-gray-100 hover:border-primary/40 transition-colors"
          >
            <img :src="resolvePhotoUrl(photo)" :alt="`Foto ${photo.id}`" class="w-full h-full object-cover"/>
          </button>
        </div>
      </div>

      <!-- ── Checklist de verificación ───────────────── -->
      <div v-if="canAct" class="card mb-4">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-6 h-6 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center text-xs font-bold">✓</span>
          Lista de verificación
        </h3>
        <div class="space-y-3">
          <label class="flex items-center gap-3 cursor-pointer group">
            <input type="checkbox" v-model="checklist.fachada_verificada" class="w-4 h-4 rounded accent-primary"/>
            <span class="text-sm text-gray-700 group-hover:text-gray-900">Fachada verificada</span>
          </label>
          <label class="flex items-center gap-3 cursor-pointer group">
            <input type="checkbox" v-model="checklist.conexiones_verificadas" class="w-4 h-4 rounded accent-primary"/>
            <span class="text-sm text-gray-700 group-hover:text-gray-900">Conexiones verificadas</span>
          </label>
          <label class="flex items-center gap-3 cursor-pointer group">
            <input type="checkbox" v-model="checklist.ubicacion_confirmada" class="w-4 h-4 rounded accent-primary"/>
            <span class="text-sm text-gray-700 group-hover:text-gray-900">Ubicación confirmada</span>
          </label>
          <label class="flex items-center gap-3 cursor-pointer group">
            <input type="checkbox" v-model="checklist.servicio_verificado" class="w-4 h-4 rounded accent-primary"/>
            <span class="text-sm text-gray-700 group-hover:text-gray-900">Servicio activo en MikroTik</span>
          </label>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Nivel de señal (dBm)</label>
            <input v-model="checklist.nivel_senal" type="text" class="input text-sm" placeholder="-18 dBm" maxlength="50"/>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Notas del supervisor</label>
            <textarea v-model="checklist.notas_supervisor" rows="3" class="input text-sm resize-none" placeholder="Observaciones, detalles adicionales..." maxlength="2000"/>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Comentario final</label>
            <textarea v-model="comentario" rows="2" class="input text-sm resize-none" placeholder="Comentario de cierre (opcional)..." maxlength="1000"/>
          </div>
        </div>
        <div class="flex justify-end mt-4">
          <button
            @click="handleSaveChecklist"
            :disabled="savingChecklist"
            class="btn-primary text-sm px-5 flex items-center gap-1.5"
          >
            <svg v-if="savingChecklist" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <span v-else>Guardar verificación</span>
          </button>
        </div>
      </div>

      <!-- Checklist read-only when no permission -->
      <div v-else class="card mb-4">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-6 h-6 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center text-xs font-bold">✓</span>
          Verificación
        </h3>
        <div class="space-y-2 text-sm">
          <div class="flex items-center gap-2">
            <span :class="sup.fachada_verificada ? 'text-green-500' : 'text-gray-300'">{{ sup.fachada_verificada ? '✓' : '○' }}</span>
            <span :class="sup.fachada_verificada ? 'text-gray-800' : 'text-gray-400'">Fachada verificada</span>
          </div>
          <div class="flex items-center gap-2">
            <span :class="sup.conexiones_verificadas ? 'text-green-500' : 'text-gray-300'">{{ sup.conexiones_verificadas ? '✓' : '○' }}</span>
            <span :class="sup.conexiones_verificadas ? 'text-gray-800' : 'text-gray-400'">Conexiones verificadas</span>
          </div>
          <div class="flex items-center gap-2">
            <span :class="sup.ubicacion_confirmada ? 'text-green-500' : 'text-gray-300'">{{ sup.ubicacion_confirmada ? '✓' : '○' }}</span>
            <span :class="sup.ubicacion_confirmada ? 'text-gray-800' : 'text-gray-400'">Ubicación confirmada</span>
          </div>
          <div class="flex items-center gap-2">
            <span :class="sup.servicio_verificado ? 'text-green-500' : 'text-gray-300'">{{ sup.servicio_verificado ? '✓' : '○' }}</span>
            <span :class="sup.servicio_verificado ? 'text-gray-800' : 'text-gray-400'">Servicio verificado</span>
          </div>
          <div v-if="sup.nivel_senal" class="text-gray-600">
            <span class="font-medium">Señal:</span> {{ sup.nivel_senal }}
          </div>
        </div>
        <div v-if="sup.notas_supervisor" class="mt-3 text-sm text-gray-600">
          <p class="font-medium text-gray-700 mb-1">Notas:</p>
          <p>{{ sup.notas_supervisor }}</p>
        </div>
        <div v-if="sup.comentario" class="mt-3 text-sm text-gray-600">
          <p class="font-medium text-gray-700 mb-1">Comentario:</p>
          <p>{{ sup.comentario }}</p>
        </div>
      </div>

      <!-- ── Fotos de evidencia (supervision) ──────────── -->
      <div class="card mb-4">
        <h3 class="font-semibold text-gray-800 mb-3 flex items-center gap-2">
          <span class="w-6 h-6 bg-green-100 text-green-600 rounded-lg flex items-center justify-center text-xs font-bold">E</span>
          Evidencia Fotográfica
          <span class="text-xs text-gray-400 font-normal ml-auto">{{ sup.photos?.length || 0 }} foto(s)</span>
        </h3>

        <!-- Existing photos -->
        <div v-if="sup.photos?.length" class="grid grid-cols-3 sm:grid-cols-4 gap-2 mb-3">
          <div v-for="photo in sup.photos" :key="photo.id" class="relative group aspect-square">
            <button @click="lightboxUrl = resolvePhotoUrl(photo)" class="w-full h-full rounded-xl overflow-hidden border border-gray-100 hover:border-primary/40 transition-colors">
              <img :src="resolvePhotoUrl(photo)" :alt="`Evidencia ${photo.id}`" class="w-full h-full object-cover"/>
            </button>
            <button
              v-if="canUpload"
              @click.stop="handleDeletePhoto(photo.id)"
              class="absolute -top-1.5 -right-1.5 w-6 h-6 bg-red-500 text-white rounded-full flex items-center justify-center text-xs opacity-0 group-hover:opacity-100 transition-opacity shadow"
            >
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
        </div>

        <!-- Upload section -->
        <div v-if="canUpload">
          <!-- Pending previews -->
          <div v-if="pendingPreviews.length" class="grid grid-cols-3 sm:grid-cols-4 gap-2 mb-3">
            <div v-for="(p, i) in pendingPreviews" :key="i" class="relative aspect-square">
              <img :src="p.url" :alt="p.name" class="w-full h-full object-cover rounded-xl border-2 border-dashed border-primary/40"/>
              <button
                @click="removePending(i)"
                class="absolute -top-1.5 -right-1.5 w-6 h-6 bg-gray-600 text-white rounded-full flex items-center justify-center text-xs shadow"
              >
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
              </button>
            </div>
          </div>

          <div class="flex flex-col sm:flex-row gap-2">
            <label class="flex-1 flex items-center justify-center gap-2 border-2 border-dashed border-gray-200 hover:border-primary/40 rounded-xl py-4 cursor-pointer transition-colors">
              <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
              </svg>
              <span class="text-sm text-gray-500 font-medium">Seleccionar fotos</span>
              <input type="file" multiple accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFileSelect"/>
            </label>

            <button
              v-if="pendingFiles.length"
              @click="handleUpload"
              :disabled="uploading"
              class="btn-primary py-4 px-6 flex items-center justify-center gap-2 text-base"
            >
              <svg v-if="uploading" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>
              <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
              </svg>
              {{ uploading ? 'Subiendo...' : `Subir ${pendingFiles.length} foto(s)` }}
            </button>
          </div>
        </div>

        <!-- hint: no photos yet -->
        <div v-if="!sup.photos?.length && canAct" class="mt-3 flex gap-2 bg-blue-50 border border-blue-200 text-blue-700 text-xs rounded-xl px-3 py-2">
          <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          Agrega fotos de evidencia de la instalación.
        </div>
      </div>

      <!-- ── Estado pills — sticky footer ─────────────── -->
      <div v-if="canAct && store.estados.length" class="fixed bottom-0 left-0 right-0 z-30 bg-white border-t border-gray-100 shadow-lg px-4 py-3">
        <p class="text-xs text-gray-500 mb-2 font-medium">Cambiar estado:</p>
        <div class="flex gap-2 flex-wrap">
          <button
            v-for="e in store.estados"
            :key="e.id"
            @click="handleSetEstado(e.id)"
            :disabled="settingEstado"
            class="px-3 py-1.5 rounded-full text-xs font-semibold border transition-all disabled:opacity-60"
            :style="(sup.estado_id === e.id || sup.estado_supervision?.id === e.id)
              ? { backgroundColor: e.color, color: '#fff', borderColor: e.color }
              : { backgroundColor: e.color + '22', color: e.color, borderColor: e.color + '55' }"
          >
            <svg v-if="settingEstado" class="w-3 h-3 animate-spin inline mr-1" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            {{ e.nombre }}
          </button>
        </div>
      </div>

    </template>

    <!-- ── Lightbox ──────────────────────────────────────── -->
    <Teleport to="body">
      <Transition name="fade">
        <div
          v-if="lightboxUrl"
          class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4"
          @click="lightboxUrl = null"
        >
          <button class="absolute top-4 right-4 w-10 h-10 bg-white/10 rounded-full flex items-center justify-center text-white hover:bg-white/20 transition-colors">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
          <img :src="lightboxUrl" class="max-w-full max-h-[85vh] object-contain rounded-xl" @click.stop/>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to        { opacity: 0; }
</style>
