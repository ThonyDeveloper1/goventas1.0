<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useSupervisionsStore } from '@/store/supervisions'
import { useAuthStore } from '@/store/auth'
import { resolvePhotoUrl } from '@/utils/photoUrl'

const router = useRouter()
const route  = useRoute()
const store  = useSupervisionsStore()
const auth   = useAuthStore()

const sup        = computed(() => store.current)
const uploading  = ref(false)
const completing = ref(false)
const starting   = ref(false)
const comentario = ref('')
const error      = ref('')
const success    = ref('')

/* ── Photo preview state ──────────────────────────────── */
const pendingFiles   = ref([])   // File objects to upload
const pendingPreviews = ref([])  // { url, name }

/* ── Load ─────────────────────────────────────────────── */
onMounted(async () => {
  await store.fetchSupervision(route.params.id)
  if (sup.value) {
    comentario.value = sup.value.comentario ?? ''
  }
})

/* ── Estado helpers ───────────────────────────────────── */
const ESTADOS = {
  pendiente:  { label: 'Pendiente',  bg: 'bg-gray-100',   text: 'text-gray-600',  dot: 'bg-gray-400'  },
  en_proceso: { label: 'En proceso', bg: 'bg-yellow-50',  text: 'text-yellow-700',dot: 'bg-yellow-400'},
  completado: { label: 'Completado', bg: 'bg-green-50',   text: 'text-green-700', dot: 'bg-green-400' },
}

function eb(estado) { return ESTADOS[estado] || ESTADOS.pendiente }

const canStart    = computed(() => sup.value?.estado === 'pendiente' && canAct.value)
const canUpload   = computed(() => sup.value?.estado !== 'completado' && canAct.value)
const canComplete = computed(() =>
  sup.value?.estado !== 'completado'
  && sup.value?.photos?.length > 0
  && canAct.value
)
const canAct = computed(() => {
  if (!sup.value) return false
  return auth.isAdmin || sup.value.supervisor_id === auth.user?.id
})

/* ── Actions ──────────────────────────────────────────── */
async function handleStart() {
  error.value   = ''
  starting.value = true
  try {
    await store.startSupervision(sup.value.id)
    success.value = 'Supervisión iniciada.'
    await store.fetchSupervision(sup.value.id)
  } catch (e) {
    error.value = e.response?.data?.errors?.estado?.[0] || e.response?.data?.message || 'Error al iniciar.'
  } finally {
    starting.value = false
  }
}

async function handleComplete() {
  error.value     = ''
  completing.value = true
  try {
    await store.completeSupervision(sup.value.id, comentario.value || null)
    success.value = 'Supervisión completada correctamente.'
    await store.fetchSupervision(sup.value.id)
  } catch (e) {
    const errs = e.response?.data?.errors
    error.value = errs
      ? Object.values(errs).flat().join(' ')
      : e.response?.data?.message || 'Error al completar.'
  } finally {
    completing.value = false
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
            <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', eb(sup.estado).bg, eb(sup.estado).text]">
              <span :class="['inline-block w-1.5 h-1.5 rounded-full mr-1', eb(sup.estado).dot]" />
              {{ eb(sup.estado).label }}
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

      <!-- ── ACTION: Iniciar supervisión ───────────────── -->
      <div v-if="canStart" class="card mb-4">
        <button
          @click="handleStart"
          :disabled="starting"
          class="w-full py-4 rounded-xl bg-yellow-400 hover:bg-yellow-500 text-yellow-900 font-bold text-lg transition-colors flex items-center justify-center gap-3"
        >
          <svg v-if="starting" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
          <svg v-else class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          {{ starting ? 'Iniciando...' : 'Iniciar Supervisión' }}
        </button>
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
              v-if="canUpload && sup.estado !== 'completado'"
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

        <!-- Warning: no photos for completion -->
        <div v-if="sup.estado !== 'completado' && !sup.photos?.length && canAct" class="mt-3 flex gap-2 bg-yellow-50 border border-yellow-200 text-yellow-700 text-xs rounded-xl px-3 py-2">
          <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.832c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/>
          </svg>
          Debe subir al menos una foto de evidencia antes de completar.
        </div>
      </div>

      <!-- ── ACTION: Completar supervisión ─────────────── -->
      <div v-if="canComplete" class="card mb-4">
        <h3 class="font-semibold text-gray-800 mb-3 flex items-center gap-2">
          <span class="w-6 h-6 bg-green-100 text-green-600 rounded-lg flex items-center justify-center text-xs font-bold">✓</span>
          Completar Supervisión
        </h3>
        <textarea
          v-model="comentario"
          rows="3"
          placeholder="Comentario final (opcional)..."
          class="input resize-none mb-3"
          maxlength="1000"
        />
        <button
          @click="handleComplete"
          :disabled="completing"
          class="w-full py-4 rounded-xl bg-green-500 hover:bg-green-600 text-white font-bold text-lg transition-colors flex items-center justify-center gap-3"
        >
          <svg v-if="completing" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
          <svg v-else class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
          </svg>
          {{ completing ? 'Completando...' : 'Marcar como Completado' }}
        </button>
      </div>

      <!-- ── Comentario (if completed) ─────────────────── -->
      <div v-if="sup.estado === 'completado' && sup.comentario" class="card mb-4">
        <h3 class="font-semibold text-gray-800 mb-2 text-sm">Comentario del supervisor</h3>
        <p class="text-gray-600 text-sm">{{ sup.comentario }}</p>
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
