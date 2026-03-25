<script setup>
import { ref, onMounted, watch, onUnmounted } from 'vue'
import { useReportsStore } from '@/store/reports'
import reportsApi from '@/services/reports'

const store = useReportsStore()

/* ── Filters ──────────────────────────────────────────────── */
const vendorId = ref('')
const estado   = ref('')
const vendors  = ref([])

/* ── Map refs ─────────────────────────────────────────────── */
const mapContainer = ref(null)
let map     = null
let markers = []

const mapReady  = ref(false)
const mapError  = ref('')

/* ── Status colors for markers ────────────────────────────── */
const MARKER_COLORS = {
  activo:     '#22c55e',
  moroso:     '#eab308',
  suspendido: '#ef4444',
  baja:       '#9ca3af',
}

onMounted(async () => {
  const vendorData = await reportsApi.vendors()
  vendors.value = vendorData.data?.vendors ?? []

  await loadGoogleMaps()
  await fetchAndRender()
})

onUnmounted(() => {
  clearMarkers()
})

/* ── Load Google Maps script ──────────────────────────────── */
function loadGoogleMaps() {
  return new Promise((resolve) => {
    if (window.google?.maps) {
      initMap()
      resolve()
      return
    }

    const apiKey = import.meta.env.VITE_GOOGLE_MAPS_KEY || ''

    if (!apiKey) {
      mapError.value = 'Configura VITE_GOOGLE_MAPS_KEY en frontend/.env (ver Configuración → Google Maps)'
      mapReady.value = true
      resolve()
      return
    }

    const script = document.createElement('script')
    script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&libraries=marker`
    script.async = true
    script.defer = true
    script.onload = () => {
      initMap()
      resolve()
    }
    script.onerror = () => {
      mapError.value = 'Error al cargar Google Maps API.'
      mapReady.value = true
      resolve()
    }
    document.head.appendChild(script)
  })
}

function initMap() {
  if (!mapContainer.value || !window.google?.maps) return

  map = new window.google.maps.Map(mapContainer.value, {
    center: { lat: -12.0464, lng: -77.0428 }, // Lima, Peru default
    zoom: 12,
    styles: [
      { featureType: 'poi', stylers: [{ visibility: 'off' }] },
    ],
    mapTypeControl: false,
    streetViewControl: false,
    fullscreenControl: true,
  })

  mapReady.value = true
}

/* ── Fetch and render markers ─────────────────────────────── */
async function fetchAndRender() {
  const params = {}
  if (vendorId.value) params.vendor_id = vendorId.value
  if (estado.value)   params.estado = estado.value

  await store.fetchMapClients(params)
  renderMarkers()
}

function renderMarkers() {
  clearMarkers()
  if (!map || !window.google?.maps) return

  const bounds = new window.google.maps.LatLngBounds()
  let hasPoints = false

  store.mapClients.forEach((client) => {
    if (!client.latitud || !client.longitud) return

    const position = { lat: client.latitud, lng: client.longitud }
    const color = MARKER_COLORS[client.estado] || MARKER_COLORS.activo

    const marker = new window.google.maps.Marker({
      position,
      map,
      icon: {
        path: window.google.maps.SymbolPath.CIRCLE,
        fillColor: color,
        fillOpacity: 0.9,
        strokeColor: '#fff',
        strokeWeight: 2,
        scale: 8,
      },
      title: `${client.nombres} ${client.apellidos}`,
    })

    const infoWindow = new window.google.maps.InfoWindow({
      content: `
        <div style="font-family: system-ui; font-size: 13px; max-width: 220px;">
          <p style="font-weight: 600; margin: 0 0 4px;">${client.nombres} ${client.apellidos}</p>
          <p style="color: #666; margin: 0 0 2px; font-size: 12px;">DNI: ${client.dni}</p>
          <p style="color: #666; margin: 0 0 2px; font-size: 12px;">Tel: ${client.telefono_1 || '—'}</p>
          <p style="color: #666; margin: 0 0 2px; font-size: 12px;">${client.direccion || ''}</p>
          <p style="margin: 6px 0 0;">
            <span style="display:inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; background: ${color}22; color: ${color};">
              ${client.estado}
            </span>
          </p>
        </div>
      `,
    })

    marker.addListener('click', () => {
      infoWindow.open(map, marker)
    })

    markers.push(marker)
    bounds.extend(position)
    hasPoints = true
  })

  if (hasPoints) {
    map.fitBounds(bounds)
    if (store.mapClients.length === 1) {
      map.setZoom(15)
    }
  }
}

function clearMarkers() {
  markers.forEach((m) => m.setMap(null))
  markers = []
}

watch([vendorId, estado], () => fetchAndRender())

/* ── Stats ────────────────────────────────────────────────── */
function countByEstado(est) {
  return store.mapClients.filter((c) => c.estado === est).length
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Mapa de Clientes</h1>
        <p class="text-gray-500 text-sm mt-0.5">Geolocalización en tiempo real</p>
      </div>
      <div class="flex gap-3 text-xs">
        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-green-500 inline-block"></span> Activo ({{ countByEstado('activo') }})</span>
        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-yellow-500 inline-block"></span> Moroso ({{ countByEstado('moroso') }})</span>
        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-red-500 inline-block"></span> Suspendido ({{ countByEstado('suspendido') }})</span>
      </div>
    </div>

    <!-- ── Filters ────────────────────────────────────────── -->
    <div class="card mb-5">
      <div class="grid sm:grid-cols-3 gap-3">
        <select v-model="vendorId" class="input">
          <option value="">Todas las vendedoras</option>
          <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }}</option>
        </select>
        <select v-model="estado" class="input">
          <option value="">Todos los estados</option>
          <option value="activo">Activo</option>
          <option value="moroso">Moroso</option>
          <option value="suspendido">Suspendido</option>
          <option value="baja">Baja</option>
        </select>
        <div class="flex items-center text-sm text-gray-500">
          <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
          </svg>
          {{ store.mapClients.length }} cliente(s) con ubicación
        </div>
      </div>
    </div>

    <!-- ── Map Container ─────────────────────────────────────────── -->
    <div class="card !p-0 overflow-hidden">
      <div ref="mapContainer" class="w-full" style="height: 600px;"></div>
    </div>
  </div>
</template>
