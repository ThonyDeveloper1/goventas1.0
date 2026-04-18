<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useClientsStore } from '@/store/clients'
import { useAuthStore } from '@/store/auth'
import { useInstallationsStore } from '@/store/installations'
import { usePlansStore } from '@/store/plans'
import { resolvePhotoUrl } from '@/utils/photoUrl'
import api from '@/services/api'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

const GOOGLE_MAPS_KEY = import.meta.env.VITE_GOOGLE_MAPS_KEY || ''

const router = useRouter()
const route  = useRoute()
const store  = useClientsStore()
const auth   = useAuthStore()
const installStore = useInstallationsStore()
const plansStore   = usePlansStore()

/* ── Mode ───────────────────────────────────────────── */
const isEdit  = computed(() => !!route.params.id)
const isViewOnly = computed(() => !!route.params.id && !route.path.endsWith('/editar'))
const isAdminUpload = computed(() => route.path === '/clientes/subir-venta')
const title   = computed(() => isAdminUpload.value ? 'Subir Venta' : isViewOnly.value ? 'Ver Cliente' : isEdit.value ? 'Editar Cliente' : 'Nuevo Cliente')

/* ── Admin upload state ─────────────────────────────── */
const adminUploadVendedora = ref('')
const adminUploadFecha     = ref('')
const vendedoraOptions     = ref([])

/* ── Form state ─────────────────────────────────────── */
const saving  = ref(false)
const errors  = ref({})
const success  = ref('')
const showSavedToast = ref(false)
const photosSectionRef = ref(null)
const uploadProgress = ref(0)
const DEFAULT_LAT = '-13.160482'
const DEFAULT_LNG = '-74.225823'

const form = reactive({
  dni:          '',
  nombres:      '',
  apellidos:    '',
  telefono_1:   '',
  telefono_2:   '',
  direccion:    '',
  referencia:   '',
  departamento: 'Ayacucho',
  provincia:    'Huamanga',
  distrito:     '',
  latitud:      DEFAULT_LAT,
  longitud:     DEFAULT_LNG,
  estado:       'pre_registro',
  plan_id:      '',
})

const DEPARTAMENTOS = ['Ayacucho']
const PROVINCIAS = ['Huamanga']

/* ── DNI lookup ─────────────────────────────────────── */
const dniLoading = ref(false)
const dniError   = ref('')

async function searchDni() {
  if (form.dni.length !== 8 || !/^\d{8}$/.test(form.dni)) {
    dniError.value = 'El DNI debe tener 8 dígitos numéricos.'
    return
  }
  dniError.value  = ''
  dniLoading.value = true
  try {
    const person = await store.lookupDni(form.dni)
    form.nombres   = person.nombres
    form.apellidos = person.apellidos
  } catch (e) {
    dniError.value = e.response?.data?.message ?? 'DNI no encontrado.'
  } finally {
    dniLoading.value = false
  }
}

watch(() => form.dni, () => { dniError.value = '' })

/* ── Geolocation + Leaflet map picker ───────────────── */
const geoLoading = ref(false)
const geoError   = ref('')
const geoPermissionState = ref('unknown') // unknown | granted | denied | prompt
const mapLayerMode = ref('street')
const mapLayerError = ref('')
const geoSectionRef = ref(null)
const mapPickerContainer = ref(null)
let   leafletMap    = null
let   leafletMarker = null
let   leafletStreetLayer = null
let   leafletSatelliteLayer = null
let   googleMap     = null
let   googleMarker  = null
let   geoPermissionStatus = null
let   satelliteSourceIndex = 0
let   satelliteTileErrorCount = 0

const STREET_LAYER = {
  url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
  options: {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 19,
  },
}

const SATELLITE_LAYER_SOURCES = [
  {
    type: 'tile',
    url: 'https://services.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    options: {
      attribution: 'Tiles © Esri — Source: Esri, Maxar, Earthstar Geographics',
      maxZoom: 17,
      maxNativeZoom: 17,
    },
  },
  {
    type: 'tile',
    url: 'https://tiles.maps.eox.at/wmts/1.0.0/s2cloudless-2024_3857/default/g/{z}/{y}/{x}.jpg',
    options: {
      attribution: 'Imagery © Sentinel-2 via EOX',
      maxZoom: 14,
      maxNativeZoom: 14,
    },
  },
]

function hasValidCoordinates() {
  const lat = Number(form.latitud)
  const lng = Number(form.longitud)
  return Number.isFinite(lat) && Number.isFinite(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180
}

async function syncGeolocationPermission() {
  if (!navigator.permissions?.query) {
    geoPermissionState.value = 'unknown'
    return
  }

  try {
    geoPermissionStatus = await navigator.permissions.query({ name: 'geolocation' })
    geoPermissionState.value = geoPermissionStatus.state
    geoPermissionStatus.onchange = () => {
      geoPermissionState.value = geoPermissionStatus.state
    }
  } catch {
    geoPermissionState.value = 'unknown'
  }
}

function initLeafletMap() {
  if (!mapPickerContainer.value) return
  const HUAMANGA_CENTER = [Number(DEFAULT_LAT), Number(DEFAULT_LNG)]
  const center = (form.latitud && form.longitud)
    ? [parseFloat(form.latitud), parseFloat(form.longitud)]
    : HUAMANGA_CENTER
  leafletMap = L.map(mapPickerContainer.value).setView(center, form.latitud ? 15 : 12)
  leafletStreetLayer = L.tileLayer(STREET_LAYER.url, STREET_LAYER.options)
  leafletSatelliteLayer = createSatelliteLayerByIndex(satelliteSourceIndex)
  setMapLayer(mapLayerMode.value)
  if (form.latitud && form.longitud) placeLeafletMarker(center)
  leafletMap.on('click', (e) => {
    form.latitud  = e.latlng.lat.toFixed(7)
    form.longitud = e.latlng.lng.toFixed(7)
    placeLeafletMarker([e.latlng.lat, e.latlng.lng])
  })
}

function initGoogleMap() {
  if (!mapPickerContainer.value || !window.google?.maps) return

  const HUAMANGA_CENTER = { lat: Number(DEFAULT_LAT), lng: Number(DEFAULT_LNG) }
  const center = (form.latitud && form.longitud)
    ? { lat: parseFloat(form.latitud), lng: parseFloat(form.longitud) }
    : HUAMANGA_CENTER

  googleMap = new window.google.maps.Map(mapPickerContainer.value, {
    center,
    zoom: form.latitud ? 18 : 15,
    mapTypeId: mapLayerMode.value === 'satellite' ? 'hybrid' : 'roadmap',
    mapTypeControl: false,
    streetViewControl: false,
    fullscreenControl: true,
    gestureHandling: 'greedy',
  })

  googleMap.addListener('click', (e) => {
    const lat = Number(e.latLng.lat()).toFixed(7)
    const lng = Number(e.latLng.lng()).toFixed(7)
    form.latitud = lat
    form.longitud = lng
    placeGoogleMarker({ lat: Number(lat), lng: Number(lng) })
  })

  if (form.latitud && form.longitud) {
    placeGoogleMarker({ lat: parseFloat(form.latitud), lng: parseFloat(form.longitud) })
  }
}

function loadGoogleMaps() {
  return new Promise((resolve) => {
    if (window.google?.maps) {
      resolve()
      return
    }

    if (!GOOGLE_MAPS_KEY) {
      resolve(false)
      return
    }

    const existing = document.getElementById('google-maps-js-client-form')
    if (existing) {
      existing.addEventListener('load', () => resolve(), { once: true })
      existing.addEventListener('error', () => resolve(false), { once: true })
      return
    }

    const script = document.createElement('script')
    script.id = 'google-maps-js-client-form'
    script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(GOOGLE_MAPS_KEY)}&libraries=marker`
    script.async = true
    script.defer = true
    script.onload = () => resolve()
    script.onerror = () => resolve(false)
    document.head.appendChild(script)
  })
}

function createSatelliteLayerByIndex(index) {
  const source = SATELLITE_LAYER_SOURCES[index] ?? SATELLITE_LAYER_SOURCES[0]
  const layer = source.type === 'wms'
    ? L.tileLayer.wms(source.url, source.options)
    : L.tileLayer(source.url, source.options)
  layer.on('tileerror', () => {
    if (mapLayerMode.value !== 'satellite') return

    satelliteTileErrorCount += 1
    if (satelliteTileErrorCount < 4) return

    if (satelliteSourceIndex < SATELLITE_LAYER_SOURCES.length - 1) {
      satelliteSourceIndex += 1
      satelliteTileErrorCount = 0
      leafletSatelliteLayer = createSatelliteLayerByIndex(satelliteSourceIndex)
      setMapLayer('satellite')
      mapLayerError.value = 'Satelite HD no disponible en esta red. Cambiamos a Sentinel global (menor nitidez).'
      return
    }

    mapLayerError.value = 'No se pudo cargar el modo satelite en esta red. Mostrando modo calle.'
    setMapLayer('street')
  })
  return layer
}

function refreshMapSize() {
  requestAnimationFrame(() => {
    if (googleMap && window.google?.maps) {
      window.google.maps.event.trigger(googleMap, 'resize')
      return
    }

    if (leafletMap) {
      leafletMap.invalidateSize()
    }
  })
}

function syncMapZoomForLayer() {
  if (!leafletMap) return

  if (mapLayerMode.value === 'satellite') {
    const source = SATELLITE_LAYER_SOURCES[satelliteSourceIndex] ?? SATELLITE_LAYER_SOURCES[0]
    const sourceMaxZoom = Number(source?.options?.maxNativeZoom ?? source?.options?.maxZoom ?? 17)
    leafletMap.setMaxZoom(sourceMaxZoom)
    if (leafletMap.getZoom() > sourceMaxZoom) {
      leafletMap.setZoom(sourceMaxZoom)
    }
    return
  }

  leafletMap.setMaxZoom(19)
}

function activateSatelliteLayer() {
  if (!leafletMap && !googleMap) return

  // Start with HD imagery every time user selects satellite.
  if (satelliteSourceIndex !== 0) {
    satelliteSourceIndex = 0
    satelliteTileErrorCount = 0
    leafletSatelliteLayer = createSatelliteLayerByIndex(satelliteSourceIndex)
  }

  setMapLayer('satellite')
}

function setMapLayer(mode) {
  mapLayerMode.value = mode === 'satellite' ? 'satellite' : 'street'
  satelliteTileErrorCount = 0

  if (googleMap && window.google?.maps) {
    googleMap.setMapTypeId(mapLayerMode.value === 'satellite' ? 'hybrid' : 'roadmap')
    mapLayerError.value = ''
    refreshMapSize()
    return
  }

  if (!leafletMap || !leafletStreetLayer || !leafletSatelliteLayer) return

  if (leafletMap.hasLayer(leafletStreetLayer)) leafletMap.removeLayer(leafletStreetLayer)
  if (leafletMap.hasLayer(leafletSatelliteLayer)) leafletMap.removeLayer(leafletSatelliteLayer)

  if (mapLayerMode.value === 'satellite') {
    mapLayerError.value = ''
    leafletSatelliteLayer.addTo(leafletMap)
    syncMapZoomForLayer()
    refreshMapSize()
    return
  }

  mapLayerError.value = ''
  leafletStreetLayer.addTo(leafletMap)
  syncMapZoomForLayer()
  refreshMapSize()
}

function placeGoogleMarker(latlng) {
  if (!googleMap || !window.google?.maps) return

  if (googleMarker) {
    googleMarker.setMap(null)
  }

  googleMarker = new window.google.maps.Marker({
    position: latlng,
    map: googleMap,
    draggable: true,
  })

  googleMarker.addListener('dragend', (e) => {
    const lat = Number(e.latLng.lat()).toFixed(7)
    const lng = Number(e.latLng.lng()).toFixed(7)
    form.latitud = lat
    form.longitud = lng
  })
}

function placeMarker(latlng) {
  if (googleMap && window.google?.maps) {
    placeGoogleMarker({ lat: latlng[0], lng: latlng[1] })
    return
  }

  placeLeafletMarker(latlng)
}

function updateMapPosition(pos, zoom = 16) {
  if (googleMap && window.google?.maps) {
    googleMap.setCenter({ lat: pos[0], lng: pos[1] })
    googleMap.setZoom(zoom)
    refreshMapSize()
    return
  }

  if (leafletMap) {
    leafletMap.invalidateSize()
    leafletMap.setView(pos, zoom)
  }
}

function placeLeafletMarker(latlng) {
  if (leafletMarker) leafletMap.removeLayer(leafletMarker)
  const icon = L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 36" width="24" height="36"><path d="M12 0C5.373 0 0 5.373 0 12c0 9 12 24 12 24s12-15 12-24C24 5.373 18.627 0 12 0z" fill="#ec4899"/><circle cx="12" cy="12" r="5" fill="white"/></svg>`,
    iconSize: [24, 36],
    iconAnchor: [12, 36],
    className: '',
  })
  leafletMarker = L.marker(latlng, { draggable: true, icon }).addTo(leafletMap)
  leafletMarker.on('dragend', (e) => {
    form.latitud  = e.target.getLatLng().lat.toFixed(7)
    form.longitud = e.target.getLatLng().lng.toFixed(7)
  })
}

function useDefaultCoordinates() {
  form.latitud = DEFAULT_LAT
  form.longitud = DEFAULT_LNG
  delete errors.value.latitud
  delete errors.value.longitud

  if (!leafletMap && !googleMap) return

  const pos = [Number(DEFAULT_LAT), Number(DEFAULT_LNG)]
  updateMapPosition(pos, 16)
  placeMarker(pos)
}

async function getCurrentLocation() {
  const host = window.location.hostname
  const isLocalHost = host === 'localhost' || host === '127.0.0.1'
  const canUseBrowserGeo = window.isSecureContext || isLocalHost

  geoLoading.value = true
  geoError.value = ''

  const applyCoords = (coords, zoom = 16) => {
    form.latitud = Number(coords.latitude).toFixed(7)
    form.longitud = Number(coords.longitude).toFixed(7)
    delete errors.value.latitud
    delete errors.value.longitud
    geoLoading.value = false

    const pos = [parseFloat(form.latitud), parseFloat(form.longitud)]
    updateMapPosition(pos, zoom)
    placeMarker(pos)
  }

  const fetchIpCoords = async () => {
    const controllers = []
    const withTimeout = (url, mapResponse) => {
      const controller = new AbortController()
      controllers.push(controller)
      const timer = setTimeout(() => controller.abort(), 8000)
      return fetch(url, { signal: controller.signal })
        .then((res) => (res.ok ? res.json() : null))
        .then((data) => {
          clearTimeout(timer)
          return data ? mapResponse(data) : null
        })
        .catch(() => {
          clearTimeout(timer)
          return null
        })
    }

    let coords = await withTimeout('https://ipapi.co/json/', (data) => {
      const lat = Number(data?.latitude)
      const lng = Number(data?.longitude)
      return Number.isFinite(lat) && Number.isFinite(lng)
        ? { latitude: lat, longitude: lng }
        : null
    })

    if (!coords) {
      coords = await withTimeout('https://ipwho.is/', (data) => {
        const lat = Number(data?.latitude)
        const lng = Number(data?.longitude)
        return Number.isFinite(lat) && Number.isFinite(lng)
          ? { latitude: lat, longitude: lng }
          : null
      })
    }

    controllers.forEach((c) => c.abort())
    return coords
  }

  if (!canUseBrowserGeo) {
    const ipCoords = await fetchIpCoords()
    if (ipCoords) {
      applyCoords(ipCoords, 13)
      return
    }

    geoLoading.value = false
    geoError.value = 'No se pudo obtener ubicación por GPS ni por IP. Ingresa coordenadas manualmente o marca en el mapa.'
    return
  }

  const handleGeoError = async (err) => {
    if (err?.code === 1) {
      geoPermissionState.value = 'denied'
      geoError.value = 'Permiso de ubicación denegado. Actívalo en el navegador.'
    } else if (err?.code === 2) {
      geoError.value = 'No se pudo determinar tu ubicación. Enciende GPS/Alta precisión e intenta de nuevo.'
    } else if (err?.code === 3) {
      geoError.value = 'Se agotó el tiempo al obtener la ubicación. Intenta otra vez.'
    } else {
      geoError.value = 'No se pudo obtener la ubicación.'
    }

    const ipCoords = await fetchIpCoords()
    if (ipCoords) {
      applyCoords(ipCoords, 13)
      return
    }

    geoLoading.value = false
  }

  const retryWithCoarseLocation = () => {
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => applyCoords(coords),
      (err) => handleGeoError(err),
      { enableHighAccuracy: false, timeout: 25000, maximumAge: 120000 }
    )
  }

  navigator.geolocation.getCurrentPosition(
    ({ coords }) => applyCoords(coords),
    (err) => {
      if (err?.code === 2) {
        retryWithCoarseLocation()
        return
      }
      handleGeoError(err)
    },
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
  )
}
/* ── Photo upload ────────────────────────────────────── */
const PHOTO_SECTIONS = {
  fachada: 'fachada',
  dni: 'dni',
}

const fotosNuevas = reactive({
  [PHOTO_SECTIONS.fachada]: [],
  [PHOTO_SECTIONS.dni]: [],
})

const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']
const MAX_UPLOAD_BYTES = 4 * 1024 * 1024
const TARGET_COMPRESSED_BYTES = 1200 * 1024
const CAPTURE_MAX_SIDE = 1280
const CAPTURE_VALIDATION_MIN_MS = 3000
const MAX_CAPTURE_INPUT_BYTES = 12 * 1024 * 1024
const MIN_CAPTURE_WIDTH = 320
const MIN_CAPTURE_HEIGHT = 240
const photoProcessing = ref(false)
const photoError = ref('')
const preparingPhotos = ref(false)
const photoPrepProgress = ref(0)
const captureValidationActive = ref(false)
const captureValidationProgress = ref(0)
const captureValidationLabel = ref('')
const nativePickerActive = ref(false)

const preview = reactive({
  [PHOTO_SECTIONS.fachada]: [],
  [PHOTO_SECTIONS.dni]: [],
})

const existingFotos = ref([])   // Photos already saved (edit mode)

function photoTypeOf(photo) {
  return photo?.photo_type === PHOTO_SECTIONS.dni ? PHOTO_SECTIONS.dni : PHOTO_SECTIONS.fachada
}

function existingPhotosByType(section) {
  return existingFotos.value.filter((photo) => photoTypeOf(photo) === section)
}

function totalPhotosCount() {
  return existingFotos.value.length + fotosNuevas[PHOTO_SECTIONS.fachada].length + fotosNuevas[PHOTO_SECTIONS.dni].length
}

function remainingPhotoSlots() {
  return Math.max(0, 5 - totalPhotosCount())
}

const hasPendingNewPhotos = computed(() => {
  return fotosNuevas[PHOTO_SECTIONS.fachada].length > 0 || fotosNuevas[PHOTO_SECTIONS.dni].length > 0
})

const hasCriticalPhotoProcess = computed(() => {
  return saving.value || preparingPhotos.value || photoProcessing.value || captureValidationActive.value || nativePickerActive.value
})

function clearNewPhotos() {
  Object.values(preview).flat().forEach((url) => URL.revokeObjectURL(url))
  fotosNuevas[PHOTO_SECTIONS.fachada] = []
  fotosNuevas[PHOTO_SECTIONS.dni] = []
  preview[PHOTO_SECTIONS.fachada] = []
  preview[PHOTO_SECTIONS.dni] = []
}

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function validateCapturedPhotoFile(file) {
  if (!file) {
    throw new Error('No se recibió la foto capturada.')
  }

  if (!ALLOWED_IMAGE_TYPES.includes(file.type)) {
    throw new Error('Formato no permitido para foto capturada.')
  }

  if (!file.size) {
    throw new Error('La foto capturada está vacía. Toma la foto nuevamente.')
  }

  if (file.size > MAX_CAPTURE_INPUT_BYTES) {
    throw new Error('La foto capturada es muy pesada. Vuelve a tomarla con menor resolución.')
  }

  const img = await loadImageFromFile(file)
  if (!img.width || !img.height) {
    throw new Error('No se pudo validar la foto capturada.')
  }

  if (img.width < MIN_CAPTURE_WIDTH || img.height < MIN_CAPTURE_HEIGHT) {
    throw new Error('La foto capturada es muy pequeña. Acércate más y repite la captura.')
  }

  const ratio = img.width / img.height
  if (!Number.isFinite(ratio) || ratio < 0.2 || ratio > 5) {
    throw new Error('La proporción de la foto no es válida. Toma otra foto.')
  }
}

async function runCapturedPhotoValidationProcess(label, task) {
  photoProcessing.value = true
  captureValidationActive.value = true
  captureValidationProgress.value = 0
  captureValidationLabel.value = label

  const duration = CAPTURE_VALIDATION_MIN_MS
  const startedAt = Date.now()
  const timer = setInterval(() => {
    const elapsed = Date.now() - startedAt
    captureValidationProgress.value = Math.min(99, Math.round((elapsed / duration) * 100))
  }, 50)

  try {
    const [result] = await Promise.all([task(), wait(duration)])
    captureValidationProgress.value = 100
    await wait(120)
    return result
  } finally {
    clearInterval(timer)
    captureValidationActive.value = false
    captureValidationProgress.value = 0
    captureValidationLabel.value = ''
    photoProcessing.value = false
  }
}

async function addCapturedPhotoWithValidation(section, file) {
  const validatedFile = await runCapturedPhotoValidationProcess('Validando foto capturada (3s)...', async () => {
    await validateCapturedPhotoFile(file)
    return file
  })

  fotosNuevas[section] = [...fotosNuevas[section], validatedFile]
  preview[section] = [...preview[section], URL.createObjectURL(validatedFile)]
}

async function preparePhotosForUpload() {
  const queue = [
    ...fotosNuevas[PHOTO_SECTIONS.fachada].map((file, index) => ({ section: PHOTO_SECTIONS.fachada, file, index })),
    ...fotosNuevas[PHOTO_SECTIONS.dni].map((file, index) => ({ section: PHOTO_SECTIONS.dni, file, index })),
  ]

  if (!queue.length) {
    photoPrepProgress.value = 100
    return
  }

  const preparedBySection = {
    [PHOTO_SECTIONS.fachada]: [],
    [PHOTO_SECTIONS.dni]: [],
  }

  let done = 0
  for (const item of queue) {
    const optimized = await optimizeImageFile(item.file)
    preparedBySection[item.section].push(optimized)
    done += 1
    photoPrepProgress.value = Math.round((done * 100) / queue.length)
  }

  fotosNuevas[PHOTO_SECTIONS.fachada] = preparedBySection[PHOTO_SECTIONS.fachada]
  fotosNuevas[PHOTO_SECTIONS.dni] = preparedBySection[PHOTO_SECTIONS.dni]
}

function loadImageFromFile(file) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const img = new Image()
    img.onload = () => {
      URL.revokeObjectURL(url)
      resolve(img)
    }
    img.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error('No se pudo leer la imagen.'))
    }
    img.src = url
  })
}

function canvasToBlob(canvas, quality = 0.82) {
  return new Promise((resolve) => {
    canvas.toBlob((blob) => resolve(blob), 'image/jpeg', quality)
  })
}

async function optimizeImageFile(file) {
  if (!ALLOWED_IMAGE_TYPES.includes(file.type)) {
    throw new Error('Formato no permitido. Usa JPG, PNG o WEBP.')
  }

  if (file.size <= TARGET_COMPRESSED_BYTES && ['image/jpeg', 'image/jpg', 'image/webp'].includes(file.type)) {
    return file
  }

  const img = await loadImageFromFile(file)
  const maxSide = 1280
  const scale = Math.min(1, maxSide / Math.max(img.width, img.height))
  let width = Math.max(1, Math.round(img.width * scale))
  let height = Math.max(1, Math.round(img.height * scale))

  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')
  if (!ctx) return file

  canvas.width = width
  canvas.height = height
  ctx.drawImage(img, 0, 0, width, height)

  let quality = 0.72
  let blob = await canvasToBlob(canvas, quality)

  while (blob && blob.size > TARGET_COMPRESSED_BYTES && quality > 0.45) {
    quality -= 0.08
    blob = await canvasToBlob(canvas, quality)
  }

  while (blob && blob.size > MAX_UPLOAD_BYTES && width > 900 && height > 900) {
    width = Math.round(width * 0.85)
    height = Math.round(height * 0.85)
    canvas.width = width
    canvas.height = height
    ctx.drawImage(img, 0, 0, width, height)
    blob = await canvasToBlob(canvas, Math.max(0.45, quality))
  }

  if (!blob) return file
  if (blob.size > MAX_UPLOAD_BYTES) {
    throw new Error('La foto supera el tamaño permitido (4MB).')
  }

  const safeName = file.name.replace(/\.[^/.]+$/, '')
  return new File([blob], `${safeName}.jpg`, { type: 'image/jpeg' })
}

async function onFileChange(section, e) {
  if (photoProcessing.value || saving.value) return

  const files = Array.from(e.target.files ?? [])
  e.target.value = ''
  photoError.value = ''

  if (!files.length) return

  const slice = files.slice(0, remainingPhotoSlots())
  if (!slice.length) {
    photoError.value = 'Ya alcanzaste el máximo de 5 fotos.'
    return
  }

  const accepted = []
  const rejectedMessages = []

  for (const file of slice) {
    if (!ALLOWED_IMAGE_TYPES.includes(file.type)) {
      rejectedMessages.push(`${file.name}: formato no permitido`)
      continue
    }
    accepted.push(file)
  }

  if (accepted.length) {
    fotosNuevas[section] = [...fotosNuevas[section], ...accepted]
    preview[section] = [...preview[section], ...accepted.map((f) => URL.createObjectURL(f))]
  }

  if (rejectedMessages.length) {
    photoError.value = rejectedMessages.join(' | ')
  }
}

function removeNewPhoto(section, index) {
  if (totalPhotosCount() <= 1) {
    photoError.value = 'Debes conservar al menos una foto de evidencia.'
    return
  }

  URL.revokeObjectURL(preview[section][index])
  fotosNuevas[section] = fotosNuevas[section].filter((_, i) => i !== index)
  preview[section] = preview[section].filter((_, i) => i !== index)
}

async function removeExistingPhoto(photo) {
  if (totalPhotosCount() <= 1) {
    photoError.value = 'Debes conservar al menos una foto de evidencia.'
    return
  }

  if (!confirm('¿Eliminar esta foto?')) return
  try {
    await store.removePhoto(route.params.id, photo.id)
    existingFotos.value = existingFotos.value.filter((p) => p.id !== photo.id)
    photoError.value = ''
  } catch (err) {
    photoError.value = err?.response?.data?.message ?? 'Error al eliminar la foto.'
  }
}

/* ── Load edit data ─────────────────────────────────── */
onMounted(async () => {
  plansStore.fetchPlans({ activo: true })

  if (isAdminUpload.value) {
    try {
      const res = await api.get('/admin/users', { params: { role: 'vendedora', active: true, per_page: 200 } })
      vendedoraOptions.value = res.data?.data ?? []
    } catch (_) { /* non-critical */ }
  }

  if (isEdit.value) {
    const client = await store.fetchClient(route.params.id)
    Object.keys(form).forEach((k) => {
      if (client[k] !== undefined && client[k] !== null) {
        form[k] = client[k]
      }
    })
    existingInstallation.value = client.latest_installation ?? null
    if (existingInstallation.value) {
      // Mantener la instalación anterior solo como referencia visual.
      // No precargamos fecha/hora para evitar sobrescribir un histórico de 2h al guardar.
      installDate.value = ''
      installSlot.value = ''
      installDuration.value = 1
    }
    existingFotos.value = client.photos ?? []
  }

  await syncGeolocationPermission()

  const googleLoaded = await loadGoogleMaps()

  if (googleLoaded && window.google?.maps) {
    initGoogleMap()
  } else {
    // Fallback seguro: Leaflet para no romper tu localhost si no hay key.
    setTimeout(() => initLeafletMap(), 50)
  }

  // Auto-request location when opening the form for a new client.
  if (!isEdit.value && !hasValidCoordinates()) {
    getCurrentLocation()
  }

  window.addEventListener('resize', refreshMapSize)
  window.addEventListener('beforeunload', handleBeforeUnload)
})

onUnmounted(() => {
  window.removeEventListener('resize', refreshMapSize)
  window.removeEventListener('beforeunload', handleBeforeUnload)
  if (googleMarker) {
    googleMarker.setMap(null)
    googleMarker = null
  }
  if (googleMap && window.google?.maps) {
    window.google.maps.event.clearInstanceListeners(googleMap)
    googleMap = null
  }
  if (leafletMap) { leafletMap.remove(); leafletMap = null }
  leafletStreetLayer = null
  leafletSatelliteLayer = null
  closeCamera()
  clearNewPhotos()
})

function canLeaveFormSafely() {
  return !hasCriticalPhotoProcess.value && !hasPendingNewPhotos.value
}

function handleBeforeUnload(event) {
  if (canLeaveFormSafely()) return
  event.preventDefault()
  event.returnValue = ''
}

function handleBackNavigation() {
  if (canLeaveFormSafely()) {
    router.back()
    return
  }

  const confirmLeave = window.confirm('Hay procesos de foto en curso o fotos pendientes en cola. Si sales ahora, se perderán. ¿Deseas salir?')
  if (confirmLeave) {
    router.back()
  }
}

/* ── Submit ─────────────────────────────────────────── */
async function handleSubmit() {
  errors.value  = {}
  success.value = ''
  photoError.value = ''

  if (!validateClientForm()) {
    return
  }

  // Admin upload: vendedora required
  if (isAdminUpload.value && !adminUploadVendedora.value) {
    errors.value = { _global: ['Debes seleccionar una vendedora.'] }
    return
  }

  if (totalPhotosCount() < 1) {
    photoError.value = 'Debes subir al menos una foto de evidencia.'
    photosSectionRef.value?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    return
  }

  // Admin and vendedora must pick installation date/slot on new client
  if ((auth.isVendedora || auth.isAdmin) && !isEdit.value) {
    if (!installDate.value || !installSlot.value || !installDuration.value) {
      errors.value = { _global: ['Debes seleccionar fecha, horario y duración de instalación.'] }
      return
    }
    if (scheduleError.value) {
      errors.value = { _global: [scheduleError.value] }
      return
    }
  }

  saving.value  = true
  uploadProgress.value = 0
  photoPrepProgress.value = 0
  preparingPhotos.value = false

  try {
    if (fotosNuevas[PHOTO_SECTIONS.fachada].length || fotosNuevas[PHOTO_SECTIONS.dni].length) {
      preparingPhotos.value = true
      await preparePhotosForUpload()
      preparingPhotos.value = false
    }

    // Strip estado — it's controlled by MikroTik, not user-editable
    const { estado, ...payload } = form
    payload.departamento = (payload.departamento || 'Ayacucho').trim()
    payload.provincia = (payload.provincia || 'Huamanga').trim()
    payload.distrito = (payload.distrito || '').trim()
    payload.direccion = (payload.direccion || '').trim()
    payload.referencia = (payload.referencia || '').trim()
    let client
    const requestOptions = {
      retries: 1,
      timeout: 180000,
      onUploadProgress: (event) => {
        if (!event?.total) return
        uploadProgress.value = Math.max(uploadProgress.value, Math.round((event.loaded * 100) / event.total))
      },
    }

    if (isEdit.value) {
      if ((auth.isVendedora || auth.isAdmin) && installDate.value && installSlot.value) {
        payload.installacion_fecha = installDate.value
        payload.installacion_hora_inicio = installSlot.value
        payload.installacion_duracion = installDuration.value
      }

      client = await store.updateClient(route.params.id, { ...payload }, {
        fachada: fotosNuevas[PHOTO_SECTIONS.fachada],
        dni: fotosNuevas[PHOTO_SECTIONS.dni],
      }, requestOptions)
      existingFotos.value = client.photos ?? []
      clearNewPhotos()
      uploadProgress.value = 100
      success.value = 'Cambios guardados correctamente.'
      await store.fetchClients(store.pagination.current_page)
      setTimeout(() => {
        router.push('/clientes')
      }, 500)
    } else {
      if ((auth.isVendedora || auth.isAdmin) && installDate.value && installSlot.value) {
        payload.installacion_fecha = installDate.value
        payload.installacion_hora_inicio = installSlot.value
        payload.installacion_duracion = installDuration.value
      }

      if (isAdminUpload.value) {
        payload.target_user_id = adminUploadVendedora.value
        if (adminUploadFecha.value) payload.fecha_registro = adminUploadFecha.value
      }

      client = await store.createClient({ ...payload }, {
        fachada: fotosNuevas[PHOTO_SECTIONS.fachada],
        dni: fotosNuevas[PHOTO_SECTIONS.dni],
      }, requestOptions)

      success.value = 'Cliente registrado correctamente.'
      uploadProgress.value = 100
      showSavedToast.value = true
      setTimeout(() => {
        showSavedToast.value = false
        router.push('/clientes')
      }, 900)
    }
  } catch (e) {
    const status = e.response?.status
    if (status === 422) {
      errors.value = e.response.data.errors ?? {}
      nextTick(() => scrollToFirstInvalidField())
    } else {
      const code = e?.code
      const networkMsg =
        code === 'ECONNABORTED'
          ? 'La subida tardó demasiado. Intenta con mejor señal o menos fotos.'
          : code === 'ERR_NETWORK'
            ? 'Error de red durante la subida. Verifica tu conexión e intenta nuevamente.'
            : null

      errors.value = { _global: [networkMsg ?? e.response?.data?.message ?? 'Error al guardar.'] }
    }

    if (!Object.keys(errors.value).length) {
      errors.value = { _global: ['No se pudo guardar el cliente. Intenta nuevamente.'] }
    }
  } finally {
    preparingPhotos.value = false
    saving.value = false
    if (!success.value) {
      uploadProgress.value = 0
      photoPrepProgress.value = 0
    }
  }
}

/* ── Helpers ────────────────────────────────────────── */
function commercialStateLabel(rawEstado, serviceStatus) {
  if (rawEstado === 'baja') return 'baja'
  if (rawEstado === 'suspendido') return 'suspendido'
  if (rawEstado === 'pre_registro') return 'pre-registro'
  if (rawEstado === 'finalizada' || rawEstado === 'activo' || serviceStatus === 'activo') return 'finalizada'
  return 'pre-registro'
}

/* ── Installation scheduling (vendedora only) ────── */
const installDate     = ref('')
const installSlot     = ref('')
const installDuration = ref(1)
const slotsLoading    = ref(false)
const existingInstallation = ref(null)
const slotData        = computed(() => installStore.availableSlots?.slots ?? [])
const occupiedSlots   = computed(() => installStore.availableSlots?.ocupados ?? [])

const todayStr = computed(() => {
  const d = new Date()
  return d.toISOString().slice(0, 10)
})

// Computed: is the currently selected slot + duration valid?
const selectedSlotInfo = computed(() => {
  if (!installSlot.value) return null
  const slot = slotData.value.find(s => s.hora_inicio === installSlot.value)
  if (!slot) return null
  const durInfo = slot.duraciones[installDuration.value]
  return durInfo ?? null
})

const scheduleError = computed(() => {
  if (!installSlot.value) return ''
  const info = selectedSlotInfo.value
  if (!info) return 'Horario no disponible.'
  if (!info.disponible) {
    if (info.motivo === 'horario_almuerzo') return 'Este rango cruza el horario de almuerzo (13:00–15:00).'
    if (info.motivo === 'fuera_de_horario') return 'El horario termina después de las 18:00.'
    if (info.conflicto_con) return `Conflicto con instalación existente: ${info.conflicto_con}.`
    return 'Horario no disponible.'
  }
  return ''
})

const computedRange = computed(() => {
  if (!installSlot.value || !selectedSlotInfo.value) return ''
  return `${installSlot.value} – ${selectedSlotInfo.value.hora_fin}`
})

async function loadSlots() {
  if (!installDate.value) return
  slotsLoading.value = true
  try {
    await installStore.fetchAvailableSlots(installDate.value)
  } finally {
    slotsLoading.value = false
  }
  installSlot.value = ''
}

watch(installDate, loadSlots)
// Re-validate when duration changes (availability per slot depends on duration)
watch(installDuration, () => {
  // If current slot is invalid for new duration, clear it
  if (installSlot.value && selectedSlotInfo.value && !selectedSlotInfo.value.disponible) {
    installSlot.value = ''
  }
})

/* ── Camera capture ──────────────────────────────── */
const cameraActive    = ref(false)
const cameraSection   = ref(PHOTO_SECTIONS.fachada)
const videoRef        = ref(null)
const canvasRef       = ref(null)
const fachadaCameraInputRef = ref(null)
const dniCameraInputRef = ref(null)
let   mediaStream     = null

function openNativeCamera(section) {
  if (photoProcessing.value || saving.value) return
  nativePickerActive.value = true
  setTimeout(() => {
    if (nativePickerActive.value) nativePickerActive.value = false
  }, 15000)

  if (section === PHOTO_SECTIONS.fachada) {
    if (fachadaCameraInputRef.value) {
      fachadaCameraInputRef.value.value = ''
      requestAnimationFrame(() => fachadaCameraInputRef.value?.click())
    }
    return
  }

  if (dniCameraInputRef.value) {
    dniCameraInputRef.value.value = ''
    requestAnimationFrame(() => dniCameraInputRef.value?.click())
  }
}

async function onNativeCameraCapture(section, e) {
  nativePickerActive.value = false
  if (photoProcessing.value || saving.value) return

  const files = Array.from(e.target.files ?? [])
  e.target.value = ''
  photoError.value = ''

  if (!files.length) return

  const remaining = remainingPhotoSlots()
  if (remaining <= 0) {
    photoError.value = 'Ya alcanzaste el máximo de 5 fotos.'
    return
  }

  const file = files[0]

  try {
    await addCapturedPhotoWithValidation(section, file)
  } catch (err) {
    photoError.value = err?.message ?? 'No se pudo validar la foto capturada.'
  }
}

async function openCamera(section) {
  try {
    if (photoProcessing.value || saving.value) return

    if (!navigator.mediaDevices?.getUserMedia) {
      openNativeCamera(section)
      return
    }

    if (!window.isSecureContext) {
      // In mobile HTTP contexts, getUserMedia is usually blocked.
      openNativeCamera(section)
      return
    }

    cameraSection.value = section
    mediaStream = await navigator.mediaDevices.getUserMedia({
      video: {
        facingMode: { ideal: 'environment' },
        width: { ideal: 960, max: 1280 },
        height: { ideal: 720, max: 960 },
      },
    })
    cameraActive.value = true
    await nextTick()
    if (videoRef.value) {
      videoRef.value.srcObject = mediaStream
    }
  } catch {
    // Fallback to native capture input when direct camera stream fails.
    openNativeCamera(section)
  }
}

async function capturePhoto() {
  if (photoProcessing.value || saving.value) return
  if (!videoRef.value || !canvasRef.value) return

  const remaining = remainingPhotoSlots()
  if (remaining <= 0) {
    alert('Maximo 5 fotos alcanzado.')
    return
  }

  const video  = videoRef.value
  const canvas = canvasRef.value
  const sourceWidth = video.videoWidth || 1280
  const sourceHeight = video.videoHeight || 720
  const captureScale = Math.min(1, CAPTURE_MAX_SIDE / Math.max(sourceWidth, sourceHeight))
  canvas.width = Math.max(1, Math.round(sourceWidth * captureScale))
  canvas.height = Math.max(1, Math.round(sourceHeight * captureScale))
  canvas.getContext('2d').drawImage(video, 0, 0)

  try {
    const blob = await canvasToBlob(canvas, 0.9)

    if (!blob) {
      throw new Error('No se pudo generar la foto capturada.')
    }

    const file = new File([blob], `captura_${Date.now()}.jpg`, { type: 'image/jpeg' })
    await addCapturedPhotoWithValidation(cameraSection.value, file)
    closeCamera()
  } catch (err) {
    alert(err?.message ?? 'No se pudo procesar la foto capturada.')
  }
}

function closeCamera() {
  if (mediaStream) {
    mediaStream.getTracks().forEach(t => t.stop())
    mediaStream = null
  }
  cameraActive.value = false
}

function fieldError(field) {
  return errors.value[field]?.[0]
}

function scrollToFirstInvalidField() {
  const orderedSelectors = [
    '#field-dni',
    '#field-nombres',
    '#field-apellidos',
    '#field-telefono_1',
    '#field-direccion',
    '#field-distrito',
  ]

  for (const selector of orderedSelectors) {
    const fieldName = selector.replace('#field-', '')
    if (!errors.value[fieldName]) continue

    const el = document.querySelector(selector)
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' })
      el.focus()
      return
    }
  }

  if (errors.value.fotos && photosSectionRef.value) {
    photosSectionRef.value.scrollIntoView({ behavior: 'smooth', block: 'center' })
    return
  }

  if ((errors.value.latitud || errors.value.longitud) && geoSectionRef.value) {
    geoSectionRef.value.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }
}

function validateClientForm() {
  const nextErrors = {}

  if (photoProcessing.value) {
    nextErrors._global = ['Espera a que termine el procesamiento de fotos.']
  }

  if (!String(form.dni || '').trim()) nextErrors.dni = ['El DNI es obligatorio.']
  if (!String(form.nombres || '').trim()) nextErrors.nombres = ['Los nombres son obligatorios.']
  if (!String(form.apellidos || '').trim()) nextErrors.apellidos = ['Los apellidos son obligatorios.']
  if (!String(form.telefono_1 || '').trim()) nextErrors.telefono_1 = ['El telefono principal es obligatorio.']
  if (!String(form.direccion || '').trim()) nextErrors.direccion = ['La direccion es obligatoria.']
  if (!String(form.distrito || '').trim()) nextErrors.distrito = ['El distrito es obligatorio.']

  if (!isEdit.value && totalPhotosCount() < 1) {
    nextErrors.fotos = ['Debes subir al menos una foto de evidencia.']
  }

  if (!hasValidCoordinates()) {
    nextErrors.latitud = ['Debes activar la geolocalización o marcar el punto en el mapa.']
    nextErrors.longitud = ['Debes activar la geolocalización o marcar el punto en el mapa.']
  }

  if (Object.keys(nextErrors).length > 0) {
    errors.value = nextErrors
    nextTick(() => scrollToFirstInvalidField())
    return false
  }

  return true
}
</script>

<template>
  <div class="max-w-3xl mx-auto">

    <!-- Header -->
    <div class="flex items-center gap-3 mb-6">
      <button
        @click="handleBackNavigation"
        class="p-2 rounded-xl text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
      >
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
      </button>
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ title }}</h1>
        <p class="text-gray-500 text-sm mt-0.5">Completa todos los campos requeridos.</p>
      </div>
    </div>

    <!-- Global error -->
    <Transition name="fade">
      <div
        v-if="errors._global"
        class="flex gap-2 bg-red-50 border border-red-200 text-red-600 text-sm rounded-xl px-4 py-3 mb-4"
      >
        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        {{ errors._global[0] }}
      </div>
    </Transition>

    <Transition name="fade">
      <div v-if="saving && uploadProgress > 0" class="mb-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3">
        <div class="flex items-center justify-between text-xs text-blue-700 mb-1.5">
          <span>Subiendo datos del cliente...</span>
          <span>{{ uploadProgress }}%</span>
        </div>
        <div class="h-2 w-full rounded-full bg-blue-100 overflow-hidden">
          <div
            class="h-full bg-blue-500 transition-all duration-200"
            :style="{ width: `${uploadProgress}%` }"
          ></div>
        </div>
      </div>
    </Transition>

    <Transition name="fade">
      <div v-if="saving && preparingPhotos" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
        <div class="flex items-center justify-between text-xs text-amber-700 mb-1.5">
          <span>Preparando fotos para una subida estable...</span>
          <span>{{ photoPrepProgress }}%</span>
        </div>
        <div class="h-2 w-full rounded-full bg-amber-100 overflow-hidden">
          <div
            class="h-full bg-amber-500 transition-all duration-200"
            :style="{ width: `${photoPrepProgress}%` }"
          ></div>
        </div>
      </div>
    </Transition>

    <!-- Success -->
    <Transition name="fade">
      <div
        v-if="success"
        class="flex gap-2 bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3 mb-4"
      >
        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        {{ success }}
      </div>
    </Transition>

    <Transition name="fade">
      <div
        v-if="showSavedToast"
        class="fixed right-4 top-4 z-50 bg-emerald-600 text-white shadow-lg rounded-xl px-4 py-3 text-sm flex items-center gap-2"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        Cambios guardados
      </div>
    </Transition>

    <form @submit.prevent="handleSubmit" novalidate class="space-y-5">

      <!-- ── Admin Upload Block ─────────────────────────── -->
      <div v-if="isAdminUpload" class="card border-amber-200 bg-amber-50">
        <h3 class="font-semibold text-amber-800 mb-4 flex items-center gap-2">
          <span class="w-6 h-6 bg-amber-200 text-amber-800 rounded-lg flex items-center justify-center text-xs font-bold">★</span>
          Datos de la venta (Admin)
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <!-- Vendedora selector -->
          <div>
            <label class="block text-sm font-medium text-amber-800 mb-1.5">Vendedora *</label>
            <select v-model="adminUploadVendedora" class="input bg-white">
              <option value="">Selecciona una vendedora</option>
              <option v-for="v in vendedoraOptions" :key="v.id" :value="v.id">
                {{ v.name }}
              </option>
            </select>
            <p v-if="!adminUploadVendedora && errors._global" class="text-red-500 text-xs mt-1">
              Selecciona una vendedora.
            </p>
          </div>
          <!-- Fecha de registro -->
          <div>
            <label class="block text-sm font-medium text-amber-800 mb-1.5">Fecha de registro</label>
            <input
              v-model="adminUploadFecha"
              type="date"
              class="input bg-white"
              placeholder="Dejar vacío = hoy"
            />
            <p class="text-amber-700 text-xs mt-1">Sin fecha = se usa la fecha actual.</p>
          </div>
        </div>
      </div>

      <!-- ── DNI ──────────────────────────────────────────── -->
      <div class="card">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">1</span>
          Identificación
        </h3>

        <div class="flex gap-3">
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1.5">DNI *</label>
            <input
              id="field-dni"
              v-model="form.dni"
              type="text"
              inputmode="numeric"
              maxlength="8"
              placeholder="12345678"
              :class="['input font-mono tracking-widest', fieldError('dni') || dniError ? 'border-red-400 focus:border-red-400' : '']"
              @keyup.enter.prevent="searchDni"
            />
            <p v-if="fieldError('dni') || dniError" class="text-red-500 text-xs mt-1">
              {{ fieldError('dni') || dniError }}
            </p>
          </div>

          <div class="flex flex-col justify-end">
            <button
              type="button"
              @click="searchDni"
              :disabled="dniLoading || form.dni.length !== 8"
              class="btn-primary h-[42px] px-4 disabled:opacity-50"
            >
              <svg v-if="dniLoading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
              <span class="hidden sm:block">{{ dniLoading ? 'Buscando...' : 'Buscar' }}</span>
            </button>
          </div>
        </div>

        <!-- Names (autocompleted) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Nombres *</label>
            <input
              id="field-nombres"
              v-model="form.nombres"
              type="text"
              placeholder="Carlos Alberto"
              :class="['input', fieldError('nombres') ? 'border-red-400' : '']"
            />
            <p v-if="fieldError('nombres')" class="text-red-500 text-xs mt-1">{{ fieldError('nombres') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Apellidos *</label>
            <input
              id="field-apellidos"
              v-model="form.apellidos"
              type="text"
              placeholder="García López"
              :class="['input', fieldError('apellidos') ? 'border-red-400' : '']"
            />
            <p v-if="fieldError('apellidos')" class="text-red-500 text-xs mt-1">{{ fieldError('apellidos') }}</p>
          </div>
        </div>
      </div>

      <!-- ── Contact ────────────────────────────────────────── -->
      <div class="card">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">2</span>
          Contacto
        </h3>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Teléfono principal *</label>
            <input
              id="field-telefono_1"
              v-model="form.telefono_1"
              type="tel"
              placeholder="999 888 777"
              :class="['input', fieldError('telefono_1') ? 'border-red-400' : '']"
            />
            <p v-if="fieldError('telefono_1')" class="text-red-500 text-xs mt-1">{{ fieldError('telefono_1') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Teléfono secundario</label>
            <input
              v-model="form.telefono_2"
              type="tel"
              placeholder="998 887 776 (opcional)"
              class="input"
            />
          </div>
        </div>
      </div>

      <!-- ── Address ───────────────────────────────────────── -->
      <div class="card">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">3</span>
          Dirección
        </h3>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Departamento *</label>
            <select
              v-model="form.departamento"
              :class="['input', fieldError('departamento') ? 'border-red-400' : '']"
            >
              <option v-for="dep in DEPARTAMENTOS" :key="dep" :value="dep">{{ dep }}</option>
            </select>
            <p v-if="fieldError('departamento')" class="text-red-500 text-xs mt-1">{{ fieldError('departamento') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Provincia *</label>
            <select
              v-model="form.provincia"
              :class="['input', fieldError('provincia') ? 'border-red-400' : '']"
            >
              <option v-for="prov in PROVINCIAS" :key="prov" :value="prov">{{ prov }}</option>
            </select>
            <p v-if="fieldError('provincia')" class="text-red-500 text-xs mt-1">{{ fieldError('provincia') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Distrito *</label>
            <input
              id="field-distrito"
              v-model="form.distrito"
              type="text"
              placeholder="Miraflores"
              :class="['input', fieldError('distrito') ? 'border-red-400' : '']"
            />
            <p v-if="fieldError('distrito')" class="text-red-500 text-xs mt-1">{{ fieldError('distrito') }}</p>
          </div>
        </div>

        <div class="mb-3">
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Dirección *</label>
          <input
            id="field-direccion"
            v-model="form.direccion"
            type="text"
            placeholder="Av. Los Olivos 123"
            :class="['input', fieldError('direccion') ? 'border-red-400' : '']"
          />
          <p v-if="fieldError('direccion')" class="text-red-500 text-xs mt-1">{{ fieldError('direccion') }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Referencia</label>
          <input
            v-model="form.referencia"
            type="text"
            placeholder="Frente al parque, casa rosada..."
            class="input"
          />
        </div>
      </div>

      <!-- ── Geolocation ───────────────────────────────────── -->
      <div ref="geoSectionRef" class="card">
        <h3 class="font-semibold text-gray-800 mb-1 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">4</span>
          Geolocalización
        </h3>
        <p class="text-xs text-gray-400 mb-4 ml-8">Coordenadas exactas del punto de instalación</p>

        <div class="flex flex-col sm:flex-row gap-3 mb-3">
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Latitud *</label>
            <input
              v-model="form.latitud"
              type="number"
              step="any"
              placeholder="-13.160482"
              required
              :class="['input font-mono text-sm', fieldError('latitud') ? 'border-red-400' : '']"
            />
          </div>
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Longitud *</label>
            <input
              v-model="form.longitud"
              type="number"
              step="any"
              placeholder="-74.225823"
              required
              :class="['input font-mono text-sm', fieldError('longitud') ? 'border-red-400' : '']"
            />
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
          <button
            type="button"
            @click="useDefaultCoordinates"
            :disabled="geoLoading"
            class="inline-flex items-center gap-2 text-sm font-medium px-3 py-1.5 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 transition-colors"
          >
            Ir a coordenadas por defecto
          </button>
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-3">
          <button
            type="button"
            @click="getCurrentLocation"
            :disabled="geoLoading"
            class="inline-flex items-center gap-2 text-sm font-medium px-3 py-1.5 rounded-lg border border-primary/30 text-primary hover:bg-primary/5 disabled:opacity-50 transition-colors"
          >
            <svg v-if="geoLoading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.5 10c0 5.25-7.5 11-7.5 11S4.5 15.25 4.5 10a7.5 7.5 0 1115 0z" />
            </svg>
            {{ geoLoading ? 'Obteniendo ubicación...' : 'Usar mi ubicación actual' }}
          </button>
        </div>
        <p v-if="geoError" class="text-red-500 text-xs mt-2">{{ geoError }}</p>
        <p v-else-if="mapLayerError" class="text-amber-600 text-xs mt-2">{{ mapLayerError }}</p>
        <p v-else-if="fieldError('latitud') || fieldError('longitud')" class="text-red-500 text-xs mt-2">
          {{ fieldError('latitud') ?? fieldError('longitud') }}
        </p>

        <!-- Leaflet map picker (OpenStreetMap + Esri Satellite — no API key needed) -->
        <div class="mt-4">
          <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 mb-2 shadow-sm">
            <button
              type="button"
              @click="setMapLayer('street')"
              :class="[
                'px-3 py-1.5 rounded-md text-xs font-medium transition-colors',
                mapLayerMode === 'street'
                  ? 'bg-primary text-white'
                  : 'text-gray-600 hover:bg-gray-100',
              ]"
            >
              Calle
            </button>
            <button
              type="button"
              @click="activateSatelliteLayer"
              :class="[
                'px-3 py-1.5 rounded-md text-xs font-medium transition-colors',
                mapLayerMode === 'satellite'
                  ? 'bg-primary text-white'
                  : 'text-gray-600 hover:bg-gray-100',
              ]"
            >
              Satélite
            </button>
          </div>
          <p class="text-xs text-gray-500 mb-2 flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
            </svg>
            Haz clic en el mapa para marcar la ubicación exacta, o arrastra el pin.
          </p>
          <div ref="mapPickerContainer" class="map-picker-container w-full h-72 sm:h-60 rounded-xl border border-gray-200 overflow-hidden shadow-sm"></div>
        </div>
      </div>

      <!-- ── Plan de Internet ──────────────────────────── -->
      <div class="card">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">5</span>
          Plan de Internet
        </h3>

        <div v-if="!plansStore.activePlans.length" class="text-sm text-gray-400 py-2">
          No hay planes disponibles. Un administrador debe crear planes primero.
        </div>

        <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <label
            v-for="plan in plansStore.activePlans"
            :key="plan.id"
            :class="[
              'flex flex-col p-3 rounded-xl border cursor-pointer transition-all',
              form.plan_id == plan.id
                ? 'border-primary bg-primary/5 ring-2 ring-primary/20'
                : 'border-gray-200 hover:border-gray-300',
            ]"
          >
            <input v-model="form.plan_id" :value="plan.id" type="radio" class="hidden" />
            <span class="font-semibold text-gray-900">{{ plan.nombre }}</span>
            <span class="text-lg font-bold text-primary mt-1">S/ {{ plan.precio }}<span class="text-xs text-gray-400 font-normal">/mes</span></span>
            <span class="text-xs text-gray-500 mt-1">⬇ {{ plan.velocidad_bajada }} Mbps · ⬆ {{ plan.velocidad_subida }} Mbps</span>
          </label>
        </div>
      </div>

      <!-- ── Estado (solo lectura — controlado por MikroTik) ── -->
      <div v-if="isEdit && (auth.isAdmin || auth.isSupervisor)" class="card">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">6</span>
          Estado del cliente
        </h3>

        <div class="flex items-center gap-3">
          <span
            class="inline-flex items-center px-3 py-1.5 rounded-xl text-sm font-semibold ring-1 capitalize"
            :class="{
              'bg-green-50  text-green-700  ring-green-200': commercialStateLabel(form.estado, form.service_status) === 'finalizada',
              'bg-sky-50    text-sky-700    ring-sky-200':   commercialStateLabel(form.estado, form.service_status) === 'pre-registro',
              'bg-orange-50 text-orange-600 ring-orange-200': form.estado === 'suspendido',
              'bg-red-50    text-red-600    ring-red-200':   form.estado === 'baja',
            }"
          >
            {{ commercialStateLabel(form.estado, form.service_status) }}
          </span>
          <span class="text-xs text-gray-400">
            <svg class="inline w-3.5 h-3.5 mr-0.5 -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Estado comercial. La morosidad técnica se muestra en Estado MikroTik.
          </span>
        </div>
      </div>

      <!-- ── Programar Instalación (vendedora/admin) ───── -->
      <div v-if="auth.isVendedora || auth.isAdmin" class="card">
        <h3 class="font-semibold text-gray-800 mb-1 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">7</span>
          Programar Instalación
        </h3>
        <p class="text-xs text-gray-400 mb-4 ml-8">Horario laboral: 08:00 – 18:00 · Almuerzo: 13:00 – 15:00</p>

        <div v-if="isEdit && existingInstallation" class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3">
          <p class="text-xs font-semibold text-indigo-700 uppercase tracking-wide">Instalación registrada</p>
          <p class="text-sm text-indigo-900 mt-1">
            {{ existingInstallation.fecha }} · {{ String(existingInstallation.hora_inicio || '').slice(0, 5) }} - {{ String(existingInstallation.hora_fin || '').slice(0, 5) }}
            ({{ existingInstallation.duracion || 1 }}h)
          </p>
          <p class="text-xs text-indigo-700 mt-0.5 capitalize">Estado: {{ existingInstallation.estado || 'pendiente' }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
          <!-- Fecha -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Fecha de instalación *</label>
            <input
              v-model="installDate"
              type="date"
              :min="isAdminUpload ? undefined : todayStr"
              class="input"
            />
          </div>

          <!-- Duración -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Duración *</label>
            <div class="flex gap-2">
              <button
                v-for="dur in [1]"
                :key="dur"
                type="button"
                @click="installDuration = dur"
                :class="[
                  'flex-1 py-2.5 rounded-xl border text-sm font-medium transition-all',
                  installDuration === dur
                    ? 'border-primary bg-primary/10 text-primary ring-1 ring-primary/30'
                    : 'border-gray-200 text-gray-500 hover:border-gray-300',
                ]"
              >
                {{ dur }}h
              </button>
            </div>
          </div>
        </div>

        <!-- Horarios -->
        <div v-if="installDate">
          <label class="block text-sm font-medium text-gray-700 mb-2">Hora de inicio *</label>

          <!-- Loading -->
          <div v-if="slotsLoading" class="flex items-center gap-2 text-sm text-gray-400 py-4">
            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            Cargando horarios...
          </div>

          <!-- Slots grid -->
          <div v-else-if="slotData.length" class="space-y-3">
            <!-- Occupied summary -->
            <div v-if="occupiedSlots.length" class="bg-amber-50 border border-amber-200 rounded-xl px-3 py-2">
              <p class="text-xs font-medium text-amber-700 flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.268 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                Instalaciones existentes:
                <span v-for="(s, i) in occupiedSlots" :key="s.id">
                  {{ s.hora_inicio }}–{{ s.hora_fin }}<span v-if="i < occupiedSlots.length - 1">, </span>
                </span>
              </p>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
              <button
                v-for="slot in slotData"
                :key="slot.hora_inicio"
                type="button"
                :disabled="!slot.duraciones[installDuration]?.disponible"
                @click="installSlot = slot.hora_inicio"
                :class="[
                  'relative flex flex-col items-center px-2 py-3 rounded-xl border text-sm font-medium transition-all',
                  !slot.duraciones[installDuration]?.disponible
                    ? 'border-red-200 bg-red-50/50 text-red-300 cursor-not-allowed'
                    : installSlot === slot.hora_inicio
                      ? 'border-primary bg-primary/5 text-primary ring-2 ring-primary/20'
                      : 'border-gray-200 text-gray-600 hover:border-primary/40 hover:bg-primary/5 cursor-pointer',
                ]"
              >
                <span class="flex items-center gap-1">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  {{ slot.hora_inicio }}
                </span>
                <span class="text-[10px] mt-0.5" :class="!slot.duraciones[installDuration]?.disponible ? 'text-red-400' : 'text-gray-400'">
                  {{ !slot.duraciones[installDuration]?.disponible
                    ? (slot.duraciones[installDuration]?.motivo === 'horario_almuerzo' ? 'Almuerzo' : slot.duraciones[installDuration]?.motivo === 'fuera_de_horario' ? 'Fuera rango' : 'Ocupado')
                    : (installSlot === slot.hora_inicio ? 'Seleccionado' : 'Disponible')
                  }}
                </span>
              </button>
            </div>
          </div>

          <p v-else-if="!slotsLoading" class="text-sm text-gray-400 py-4 text-center">
            No hay horarios configurados para esta fecha.
          </p>
        </div>

        <!-- Rango calculado -->
        <div v-if="computedRange && !scheduleError" class="mt-4 flex items-center gap-3 bg-green-50 border border-green-200 rounded-xl px-4 py-3">
          <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div>
            <p class="text-sm font-medium text-green-800">Instalación programada</p>
            <p class="text-xs text-green-600">{{ computedRange }} (1h)</p>
          </div>
        </div>

        <!-- Error -->
        <div v-if="scheduleError" class="mt-4 flex items-center gap-3 bg-red-50 border border-red-200 rounded-xl px-4 py-3">
          <svg class="w-5 h-5 text-red-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p class="text-sm text-red-600">{{ scheduleError }}</p>
        </div>
      </div>

      <!-- ── Photos ────────────────────────────────────────── -->
      <div class="card">
        <div ref="photosSectionRef"></div>
        <h3 class="font-semibold text-gray-800 mb-1 flex items-center gap-2">
          <span class="w-6 h-6 bg-primary/10 text-primary rounded-lg flex items-center justify-center text-xs font-bold">8</span>
          Documentos del Cliente
        </h3>
        <p class="text-xs text-gray-400 mb-2 ml-8">Máximo 5 fotos · JPG, PNG o WEBP · 4 MB por foto</p>
        <p class="text-xs text-rose-600 mb-3 ml-8">Obligatorio al registrar un cliente nuevo.</p>
        <p v-if="photoProcessing" class="text-xs text-blue-600 mb-3 ml-8">Procesando fotos...</p>
        <div
          v-if="captureValidationActive"
          class="mb-3 ml-8 mr-2 rounded-xl border border-cyan-200 bg-cyan-50 px-3 py-2"
        >
          <div class="flex items-center justify-between text-xs text-cyan-700 mb-1.5">
            <span>{{ captureValidationLabel }}</span>
            <span>{{ captureValidationProgress }}%</span>
          </div>
          <div class="h-2 w-full rounded-full bg-cyan-100 overflow-hidden">
            <div
              class="h-full bg-cyan-500 transition-all duration-75"
              :style="{ width: `${captureValidationProgress}%` }"
            ></div>
          </div>
        </div>
        <p v-if="!saving && (fotosNuevas[PHOTO_SECTIONS.fachada].length || fotosNuevas[PHOTO_SECTIONS.dni].length)" class="text-xs text-amber-600 mb-3 ml-8">Las fotos quedan en cola local y se preparan al presionar Guardar.</p>
        <p v-if="photoError" class="text-xs text-red-500 mb-3 ml-8">{{ photoError }}</p>
        <p v-if="fieldError('fotos')" class="text-red-500 text-xs mb-3 ml-8">{{ fieldError('fotos') }}</p>

        <!-- Camera viewfinder -->
        <div v-if="cameraActive" class="mb-3">
          <p class="text-sm font-semibold text-gray-700 mb-2">
            Capturando para: {{ cameraSection === PHOTO_SECTIONS.fachada ? 'Foto de la fachada' : 'Cara y reverso DNI' }}
          </p>
          <div class="relative rounded-xl overflow-hidden border-2 border-primary/30">
            <video ref="videoRef" autoplay playsinline class="w-full rounded-xl" />
            <canvas ref="canvasRef" class="hidden" />
            <div class="absolute bottom-3 left-0 right-0 flex justify-center gap-3">
              <button
                type="button"
                @click.prevent="capturePhoto"
                class="w-14 h-14 bg-white rounded-full shadow-lg border-4 border-primary flex items-center justify-center hover:scale-105 transition-transform"
              >
                <svg class="w-6 h-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              </button>
              <button
                type="button"
                @click.prevent="closeCamera"
                class="w-10 h-10 bg-gray-700/80 text-white rounded-full flex items-center justify-center hover:bg-gray-800 transition-colors"
              >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>
        </div>

        <!-- Upload & Camera buttons -->
        <div
          v-if="!cameraActive"
          class="grid grid-cols-1 lg:grid-cols-2 gap-4"
        >
          <div class="rounded-2xl border border-gray-200 p-3">
            <p class="text-sm font-semibold text-gray-800 mb-3">Foto de la fachada</p>
            <div v-if="existingPhotosByType(PHOTO_SECTIONS.fachada).length" class="flex flex-wrap gap-2 mb-3">
              <div
                v-for="photo in existingPhotosByType(PHOTO_SECTIONS.fachada)"
                :key="`existing-fachada-${photo.id}`"
                class="relative group"
              >
                <img
                  :src="resolvePhotoUrl(photo)"
                  :alt="`Foto fachada ${photo.id}`"
                  class="w-20 h-20 rounded-xl object-cover border border-gray-200"
                />
                <button
                  type="button"
                  @click="removeExistingPhoto(photo)"
                  class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 text-white rounded-full opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"
                >
                  <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>
            <div v-if="preview[PHOTO_SECTIONS.fachada].length" class="flex flex-wrap gap-2 mb-3">
              <div v-for="(src, i) in preview[PHOTO_SECTIONS.fachada]" :key="`fachada-${i}`" class="relative group">
                <img
                  :src="src"
                  :alt="`Fachada ${i + 1}`"
                  class="w-20 h-20 rounded-xl object-cover border-2 border-primary/30"
                />
                <button
                  type="button"
                  @click="removeNewPhoto(PHOTO_SECTIONS.fachada, i)"
                  class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 text-white rounded-full opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"
                >
                  <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>
            <div v-if="totalPhotosCount() < 5" class="flex gap-3">
              <label
                class="flex-1 flex flex-col items-center justify-center h-28 border-2 border-dashed border-gray-200 rounded-xl cursor-pointer hover:border-primary hover:bg-primary/5 transition-all"
              >
                <svg class="w-7 h-7 text-gray-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <p class="text-sm text-gray-500 text-center px-2">
                  <span class="text-primary font-medium">Subir fotos</span> o arrastra aquí
                </p>
                <p class="text-xs text-gray-400 mt-0.5">
                  Quedan {{ remainingPhotoSlots() }} espacios
                </p>
                <input
                  type="file"
                  accept="image/jpeg,image/jpg,image/png,image/webp"
                  multiple
                  class="hidden"
                  @change="onFileChange(PHOTO_SECTIONS.fachada, $event)"
                />

                <input
                  ref="fachadaCameraInputRef"
                  type="file"
                  accept="image/*"
                  capture="environment"
                  class="hidden"
                  @change="onNativeCameraCapture(PHOTO_SECTIONS.fachada, $event)"
                />
              </label>

              <button
                type="button"
                @click.prevent="openCamera(PHOTO_SECTIONS.fachada)"
                class="flex flex-col items-center justify-center w-28 h-28 border-2 border-dashed border-gray-200 rounded-xl hover:border-primary hover:bg-primary/5 transition-all"
              >
                <svg class="w-7 h-7 text-gray-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <p class="text-sm text-primary font-medium">Tomar foto</p>
              </button>
            </div>
            <p v-else class="text-xs text-gray-500 rounded-xl bg-gray-50 px-3 py-2">
              Ya alcanzaste el máximo de 5 fotos. Elimina una si necesitas reemplazarla.
            </p>
          </div>

          <div class="rounded-2xl border border-gray-200 p-3">
            <p class="text-sm font-semibold text-gray-800 mb-3">
              Cara y reverso DNI
              <span class="ml-2 text-xs font-normal text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full">Opcional</span>
            </p>
            <div v-if="existingPhotosByType(PHOTO_SECTIONS.dni).length" class="flex flex-wrap gap-2 mb-3">
              <div
                v-for="photo in existingPhotosByType(PHOTO_SECTIONS.dni)"
                :key="`existing-dni-${photo.id}`"
                class="relative group"
              >
                <img
                  :src="resolvePhotoUrl(photo)"
                  :alt="`Foto DNI ${photo.id}`"
                  class="w-20 h-20 rounded-xl object-cover border border-gray-200"
                />
                <button
                  type="button"
                  @click="removeExistingPhoto(photo)"
                  class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 text-white rounded-full opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"
                >
                  <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>
            <div v-if="preview[PHOTO_SECTIONS.dni].length" class="flex flex-wrap gap-2 mb-3">
              <div v-for="(src, i) in preview[PHOTO_SECTIONS.dni]" :key="`dni-${i}`" class="relative group">
                <img
                  :src="src"
                  :alt="`DNI ${i + 1}`"
                  class="w-20 h-20 rounded-xl object-cover border-2 border-primary/30"
                />
                <button
                  type="button"
                  @click="removeNewPhoto(PHOTO_SECTIONS.dni, i)"
                  class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 text-white rounded-full opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"
                >
                  <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>
            <div v-if="totalPhotosCount() < 5" class="flex gap-3">
              <label
                class="flex-1 flex flex-col items-center justify-center h-28 border-2 border-dashed border-gray-200 rounded-xl cursor-pointer hover:border-primary hover:bg-primary/5 transition-all"
              >
                <svg class="w-7 h-7 text-gray-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <p class="text-sm text-gray-500 text-center px-2">
                  <span class="text-primary font-medium">Subir fotos</span> o arrastra aquí
                </p>
                <p class="text-xs text-gray-400 mt-0.5">
                  Quedan {{ remainingPhotoSlots() }} espacios
                </p>
                <input
                  type="file"
                  accept="image/jpeg,image/jpg,image/png,image/webp"
                  multiple
                  class="hidden"
                  @change="onFileChange(PHOTO_SECTIONS.dni, $event)"
                />

                <input
                  ref="dniCameraInputRef"
                  type="file"
                  accept="image/*"
                  capture="environment"
                  class="hidden"
                  @change="onNativeCameraCapture(PHOTO_SECTIONS.dni, $event)"
                />
              </label>

              <button
                type="button"
                @click.prevent="openCamera(PHOTO_SECTIONS.dni)"
                class="flex flex-col items-center justify-center w-28 h-28 border-2 border-dashed border-gray-200 rounded-xl hover:border-primary hover:bg-primary/5 transition-all"
              >
                <svg class="w-7 h-7 text-gray-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <p class="text-sm text-primary font-medium">Tomar foto</p>
              </button>
            </div>
            <p v-else class="text-xs text-gray-500 rounded-xl bg-gray-50 px-3 py-2">
              Ya alcanzaste el máximo de 5 fotos. Elimina una si necesitas reemplazarla.
            </p>
          </div>
        </div>

      </div>

      <!-- ── Submit ─────────────────────────────────────────── -->
      <div v-if="!isViewOnly" class="flex gap-3 justify-end">
        <button
          type="button"
          @click="handleBackNavigation"
          class="px-5 py-2.5 rounded-xl border border-gray-200 text-sm font-medium text-gray-600 hover:border-gray-300 transition-colors"
        >
          Cancelar
        </button>
        <button
          type="submit"
          :disabled="saving || photoProcessing"
          class="btn-primary px-8"
        >
          <svg v-if="saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          {{ saving ? 'Guardando...' : isAdminUpload ? 'Subir Venta' : isEdit ? 'Actualizar Cliente' : 'Registrar Cliente' }}
        </button>
      </div>

    </form>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to        { opacity: 0; }

.map-picker-container {
  position: relative;
  z-index: 10;
}

/* Confine Leaflet controls within map container */
:deep(.map-picker-container .leaflet-top),
:deep(.map-picker-container .leaflet-bottom) {
  z-index: 40;
}

:deep(.map-picker-container .leaflet-control) {
  z-index: 40 !important;
}

:deep(.map-picker-container .leaflet-pane) {
  z-index: 1 !important;
}

/* Prevent map from escaping in mobile */
:deep(.map-picker-container .leaflet-container) {
  background: white;
}
</style>
