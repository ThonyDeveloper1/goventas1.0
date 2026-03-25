import { defineStore } from 'pinia'
import { ref } from 'vue'
import supervisionsApi from '@/services/supervisions'

export const useSupervisionsStore = defineStore('supervisions', () => {
  /* ── State ─────────────────────────────────────────────── */
  const items        = ref([])
  const current      = ref(null)
  const supervisors  = ref([])
  const pagination   = ref({ current_page: 1, last_page: 1, total: 0, per_page: 15 })
  const loading      = ref(false)

  /* ── Actions ────────────────────────────────────────────── */

  async function fetchSupervisions(params = {}) {
    loading.value = true
    try {
      const { data } = await supervisionsApi.list(params)
      items.value = data.data
      pagination.value = {
        current_page: data.current_page,
        last_page:    data.last_page,
        total:        data.total,
        per_page:     data.per_page,
      }
      return data
    } finally {
      loading.value = false
    }
  }

  async function fetchSupervision(id) {
    loading.value = true
    try {
      const { data } = await supervisionsApi.get(id)
      current.value = data
      return data
    } finally {
      loading.value = false
    }
  }

  async function fetchSupervisors() {
    const { data } = await supervisionsApi.supervisors()
    supervisors.value = data
    return data
  }

  async function assignSupervision(payload) {
    const { data } = await supervisionsApi.assign(payload)
    items.value.unshift(data.data)
    pagination.value.total++
    return data.data
  }

  async function startSupervision(id) {
    const { data } = await supervisionsApi.start(id)
    updateLocal(id, data.data)
    return data.data
  }

  async function completeSupervision(id, comentario = null) {
    const { data } = await supervisionsApi.complete(id, { comentario })
    updateLocal(id, data.data)
    return data.data
  }

  async function uploadPhotos(id, files) {
    const { data } = await supervisionsApi.uploadPhotos(id, files)
    // Reload current to get fresh photo list
    if (current.value?.id === id) {
      await fetchSupervision(id)
    }
    return data.data
  }

  async function removePhoto(supervisionId, photoId) {
    await supervisionsApi.removePhoto(supervisionId, photoId)
    if (current.value?.id === supervisionId) {
      current.value.photos = current.value.photos.filter((p) => p.id !== photoId)
    }
  }

  function updateLocal(id, data) {
    const idx = items.value.findIndex((s) => s.id === id)
    if (idx !== -1) Object.assign(items.value[idx], data)
    if (current.value?.id === id) Object.assign(current.value, data)
  }

  return {
    items, current, supervisors, pagination, loading,
    fetchSupervisions, fetchSupervision, fetchSupervisors,
    assignSupervision, startSupervision, completeSupervision,
    uploadPhotos, removePhoto,
  }
})
