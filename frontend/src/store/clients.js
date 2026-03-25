import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import clientsApi from '@/services/clients'

function currentMonthDateRange() {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')

  return {
    from: `${year}-${month}-01`,
    to: `${year}-${month}-${day}`,
  }
}

function defaultFilters() {
  return {
    search: '',
    estado: '',
    user_id: '',
    sort_by: 'created_at',
    sort_dir: 'desc',
    from: '',
    to: '',
  }
}

export const useClientsStore = defineStore('clients', () => {
  /* ── State ─────────────────────────────────────────────── */
  const items      = ref([])
  const current    = ref(null)
  const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 15 })
  const loading    = ref(false)
  const filters    = ref(defaultFilters())

  /* ── Getters ────────────────────────────────────────────── */
  const hasClients = computed(() => items.value.length > 0)

  /* ── Actions ────────────────────────────────────────────── */

  async function fetchClients(page = 1, extraParams = {}) {
    loading.value = true
    try {
      const { data } = await clientsApi.list({
        page,
        per_page: pagination.value.per_page,
        ...Object.fromEntries(
          Object.entries(filters.value).filter(([, v]) => v !== '')
        ),
        ...extraParams,
      })
      items.value = data.data
      pagination.value = {
        current_page: data.current_page,
        last_page:    data.last_page,
        total:        data.total,
        per_page:     data.per_page,
      }
    } finally {
      loading.value = false
    }
  }

  async function fetchClient(id) {
    loading.value = true
    try {
      const { data } = await clientsApi.get(id)
      current.value = data
      return data
    } finally {
      loading.value = false
    }
  }

  async function createClient(formData, fotos = [], options = {}) {
    const { data } = await clientsApi.create(formData, fotos, options)
    items.value.unshift(data.data)
    pagination.value.total++
    return data.data
  }

  async function updateClient(id, formData, fotos = [], options = {}) {
    const { data } = await clientsApi.update(id, formData, fotos, options)
    const idx = items.value.findIndex((c) => c.id === id)
    if (idx !== -1) items.value[idx] = data.data
    current.value = data.data
    return data.data
  }

  async function removeClient(id) {
    const { data } = await clientsApi.remove(id)
    items.value = items.value.filter((c) => c.id !== id)
    pagination.value.total--
    return data
  }

  async function updateClientStatus(id, estado) {
    const { data } = await clientsApi.updateStatus(id, estado)
    const updatedClient = data?.data

    if (updatedClient) {
      const idx = items.value.findIndex((c) => c.id === id)
      if (idx !== -1) {
        items.value[idx] = {
          ...items.value[idx],
          ...updatedClient,
        }
      }

      if (current.value?.id === id) {
        current.value = {
          ...current.value,
          ...updatedClient,
        }
      }
    }

    return data
  }

  async function removePhoto(clientId, photoId) {
    await clientsApi.removePhoto(clientId, photoId)
    if (current.value?.id === clientId) {
      current.value.photos = current.value.photos.filter((p) => p.id !== photoId)
    }
  }

  async function lookupDni(dni) {
    const { data } = await clientsApi.lookupDni(dni)
    return data
  }

  function setFilter(key, value) {
    filters.value[key] = value
  }

  function resetFilters() {
    filters.value = defaultFilters()
  }

  function clearCurrent() {
    current.value = null
  }

  return {
    items,
    current,
    pagination,
    loading,
    filters,
    hasClients,
    fetchClients,
    fetchClient,
    createClient,
    updateClient,
    updateClientStatus,
    removeClient,
    removePhoto,
    lookupDni,
    setFilter,
    resetFilters,
    clearCurrent,
  }
})
