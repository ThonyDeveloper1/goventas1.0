import { defineStore } from 'pinia'
import { ref } from 'vue'
import suspiciousApi from '@/services/suspicious'

export const useSuspiciousStore = defineStore('suspicious', () => {
  /* ── State ─────────────────────────────────────────────── */
  const sales      = ref([])
  const stats      = ref({ total: 0, pendientes: 0, aprobados: 0, rechazados: 0, alto: 0, medio: 0 })
  const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 15 })
  const loading    = ref(false)
  const filters    = ref({ search: '', status: '', risk_level: '', month: new Date().toISOString().slice(0, 7), all_months: false, user_id: '' })

  /* ── Actions ────────────────────────────────────────────── */

  async function fetchStats() {
    const statsParams = {
      month: filters.value.month,
      all_months: filters.value.all_months ? 1 : 0,
      ...(filters.value.user_id ? { user_id: filters.value.user_id } : {}),
    }
    const { data } = await suspiciousApi.stats(statsParams)
    stats.value = data
    return data
  }

  async function fetchSales(params = {}) {
    loading.value = true
    try {
      const activeFilters = Object.fromEntries(
        Object.entries(filters.value).filter(([k, v]) => {
          if (k === 'all_months') return true  // always send
          return v !== ''
        })
      )
      if (activeFilters.all_months === false || activeFilters.all_months === 0) {
        // Don't send empty all_months=false, send as 0
        activeFilters.all_months = 0
      } else {
        activeFilters.all_months = 1
      }
      const query = {
        per_page: pagination.value.per_page,
        ...activeFilters,
        ...params,
      }
      const { data } = await suspiciousApi.list(query)
      sales.value = data.data
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

  async function analyzeSale(clientId) {
    const { data } = await suspiciousApi.analyze(clientId)
    await Promise.all([fetchStats(), fetchSales()])
    return data
  }

  async function approveSale(id) {
    const { data } = await suspiciousApi.approve(id)
    // Update local state
    const idx = sales.value.findIndex((s) => s.id === id)
    if (idx !== -1) sales.value[idx].status = 'aprobado'
    await fetchStats()
    return data
  }

  async function rejectSale(id) {
    const { data } = await suspiciousApi.reject(id)
    const idx = sales.value.findIndex((s) => s.id === id)
    if (idx !== -1) sales.value[idx].status = 'rechazado'
    await fetchStats()
    return data
  }

  async function unapproveSale(id) {
    const { data } = await suspiciousApi.unapprove(id)
    const idx = sales.value.findIndex((s) => s.id === id)
    if (idx !== -1) {
      sales.value[idx].status = 'pendiente'
      sales.value[idx].reviewed_by = null
      sales.value[idx].reviewed_at = null
    }
    await fetchStats()
    return data
  }

  function setFilter(key, value) {
    filters.value[key] = value
  }

  function resetFilters() {
    filters.value = { search: '', status: '', risk_level: '', month: new Date().toISOString().slice(0, 7), all_months: false, user_id: '' }
  }

  return {
    sales, stats, pagination, loading, filters,
    fetchStats, fetchSales, analyzeSale, approveSale, rejectSale, unapproveSale,
    setFilter, resetFilters,
  }
})
