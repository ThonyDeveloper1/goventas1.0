import { defineStore } from 'pinia'
import { ref } from 'vue'
import mikrotikApi from '@/services/mikrotik'
import clientsApi from '@/services/clients'

export const useNetworkStore = defineStore('network', () => {
  /* ── State ─────────────────────────────────────────────── */
  const clients    = ref([])
  const overview   = ref({ total: 0, activos: 0, suspendidos: 0, cortados: 0, sin_config: 0 })
  const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 })
  const loading    = ref(false)
  const syncing    = ref(false)
  const filters    = ref({ search: '', service_status: '' })

  /* ── Actions ────────────────────────────────────────────── */

  async function fetchOverview() {
    const { data } = await mikrotikApi.networkOverview()
    overview.value = data
    return data
  }

  async function fetchClients(params = {}) {
    loading.value = true
    try {
      const query = {
        per_page: pagination.value.per_page,
        ...Object.fromEntries(
          Object.entries(filters.value).filter(([, v]) => v !== '')
        ),
        ...params,
      }
      const { data } = await clientsApi.list(query)
      clients.value = data.data
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

  async function activateClient(clientId) {
    const { data } = await mikrotikApi.activate(clientId)
    updateLocalStatus(clientId, 'activo')
    return data
  }

  async function suspendClient(clientId) {
    const { data } = await mikrotikApi.suspend(clientId)
    updateLocalStatus(clientId, 'suspendido')
    return data
  }

  async function provisionClient(clientId, payload) {
    const { data } = await mikrotikApi.provision(clientId, payload)
    const idx = clients.value.findIndex((c) => c.id === clientId)
    if (idx !== -1) Object.assign(clients.value[idx], data.data)
    return data
  }

  async function getClientStatus(clientId) {
    const { data } = await mikrotikApi.clientStatus(clientId)
    return data
  }

  async function syncAll() {
    syncing.value = true
    try {
      const { data } = await mikrotikApi.syncAll()
      // Refresh data after sync
      await Promise.all([fetchOverview(), fetchClients()])
      return data
    } finally {
      syncing.value = false
    }
  }

  function updateLocalStatus(clientId, status) {
    const client = clients.value.find((c) => c.id === clientId)
    if (client) client.service_status = status
  }

  function setFilter(key, value) {
    filters.value[key] = value
  }

  function resetFilters() {
    filters.value = { search: '', service_status: '' }
  }

  return {
    clients, overview, pagination, loading, syncing, filters,
    fetchOverview, fetchClients, activateClient, suspendClient,
    provisionClient, getClientStatus, syncAll, setFilter, resetFilters,
  }
})
