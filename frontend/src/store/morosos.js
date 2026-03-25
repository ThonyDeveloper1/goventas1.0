import { defineStore } from 'pinia'
import { ref } from 'vue'
import mikrotikApi from '@/services/mikrotik'

export const useMorososStore = defineStore('morosos', () => {
  const routers        = ref([])
  const selectedRouter = ref(null)
  const entries        = ref([])
  const loading        = ref(false)
  const lastUpdated    = ref(null)

  const syncing       = ref(false)

  async function fetchRouters() {
    const { data } = await mikrotikApi.getRouters()
    routers.value = Array.isArray(data) ? data : data.data ?? []
    return routers.value
  }

  async function fetchMorosos() {
    if (!selectedRouter.value) return
    loading.value = true
    try {
      const { data } = await mikrotikApi.getCortesMorosos(selectedRouter.value)
      entries.value = Array.isArray(data) ? data : []
      lastUpdated.value = new Date()
    } finally {
      loading.value = false
    }
  }

  async function syncWithDb() {
    if (!selectedRouter.value) return null
    syncing.value = true
    try {
      const { data } = await mikrotikApi.syncMorosos(selectedRouter.value)
      await fetchMorosos()
      return data
    } finally {
      syncing.value = false
    }
  }

  function selectRouter(routerId) {
    selectedRouter.value = routerId
    entries.value = []
    lastUpdated.value = null
  }

  return {
    routers, selectedRouter, entries, loading, lastUpdated, syncing,
    fetchRouters, fetchMorosos, syncWithDb, selectRouter,
  }
})
