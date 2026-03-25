import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import installationsApi from '@/services/installations'

export const useInstallationsStore = defineStore('installations', () => {
  /* ── State ─────────────────────────────────────────────── */
  const items      = ref([])
  const current    = ref(null)
  const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 30 })
  const loading    = ref(false)
  const availability = ref({ fecha: null, ocupados: [], horarios: [] })
  const availableSlots = ref({ fecha: null, ocupados: [], slots: [] })

  /* ── Getters ────────────────────────────────────────────── */
  const byDate = computed(() => {
    return (date) => items.value.filter((i) => i.fecha === date || i.fecha?.startsWith(date))
  })

  /* ── Actions ────────────────────────────────────────────── */

  async function fetchInstallations(params = {}) {
    loading.value = true
    try {
      const { data } = await installationsApi.list(params)
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

  async function fetchInstallation(id) {
    loading.value = true
    try {
      const { data } = await installationsApi.get(id)
      current.value = data
      return data
    } finally {
      loading.value = false
    }
  }

  async function createInstallation(payload) {
    const { data } = await installationsApi.create(payload)
    items.value.unshift(data.data)
    pagination.value.total++
    return data.data
  }

  async function updateInstallation(id, payload) {
    const { data } = await installationsApi.update(id, payload)
    const idx = items.value.findIndex((i) => i.id === id)
    if (idx !== -1) items.value[idx] = data.data
    current.value = data.data
    return data.data
  }

  async function removeInstallation(id) {
    await installationsApi.remove(id)
    items.value = items.value.filter((i) => i.id !== id)
    pagination.value.total--
  }

  async function fetchAvailability(fecha, excludeId = null) {
    const { data } = await installationsApi.availability(fecha, excludeId)
    availability.value = data
    return data
  }

  async function fetchAvailableSlots(date, excludeId = null) {
    const { data } = await installationsApi.availableSlots(date, excludeId)
    availableSlots.value = data
    return data
  }

  function clearCurrent() {
    current.value = null
  }

  return {
    items,
    current,
    pagination,
    loading,
    availability,
    availableSlots,
    byDate,
    fetchInstallations,
    fetchInstallation,
    createInstallation,
    updateInstallation,
    removeInstallation,
    fetchAvailability,
    fetchAvailableSlots,
    clearCurrent,
  }
})
