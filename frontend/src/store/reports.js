import { defineStore } from 'pinia'
import { ref } from 'vue'
import reportsApi from '@/services/reports'

export const useReportsStore = defineStore('reports', () => {
  /* ── State ─────────────────────────────────────────────── */
  const summary    = ref(null)
  const sales      = ref(null)
  const vendors    = ref(null)
  const clients    = ref([])
  const mapClients = ref([])
  const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 })
  const loading    = ref(false)
  const exporting  = ref(false)

  /* ── Actions ────────────────────────────────────────────── */

  async function fetchSummary() {
    const { data } = await reportsApi.summary()
    summary.value = data
    return data
  }

  async function fetchSales(params = {}) {
    loading.value = true
    try {
      const { data } = await reportsApi.sales(params)
      sales.value = data
      return data
    } finally {
      loading.value = false
    }
  }

  async function fetchVendors() {
    loading.value = true
    try {
      const { data } = await reportsApi.vendors()
      vendors.value = data
      return data
    } finally {
      loading.value = false
    }
  }

  async function fetchClients(params = {}) {
    loading.value = true
    try {
      const { data } = await reportsApi.clients(params)
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

  async function fetchMapClients(params = {}) {
    const { data } = await reportsApi.map(params)
    mapClients.value = data.clients
    return data
  }

  async function exportPDF(params = {}) {
    exporting.value = true
    try {
      const { data } = await reportsApi.clientsPDF(params)
      const url  = window.URL.createObjectURL(new Blob([data], { type: 'application/pdf' }))
      const link = document.createElement('a')
      link.href  = url
      link.download = `clientes_${Date.now()}.pdf`
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
    } finally {
      exporting.value = false
    }
  }

  return {
    summary, sales, vendors, clients, mapClients, pagination, loading, exporting,
    fetchSummary, fetchSales, fetchVendors, fetchClients, fetchMapClients, exportPDF,
  }
})
