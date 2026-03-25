import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import plansApi from '@/services/plans'

export const usePlansStore = defineStore('plans', () => {
  const items   = ref([])
  const loading = ref(false)

  const activePlans = computed(() => items.value.filter(p => p.activo))

  async function fetchPlans(params = {}) {
    loading.value = true
    try {
      const { data } = await plansApi.list(params)
      items.value = data
      return data
    } finally {
      loading.value = false
    }
  }

  async function createPlan(payload) {
    const { data } = await plansApi.create(payload)
    items.value.push(data.data)
    return data.data
  }

  async function updatePlan(id, payload) {
    const { data } = await plansApi.update(id, payload)
    const idx = items.value.findIndex(p => p.id === id)
    if (idx !== -1) items.value[idx] = data.data
    return data.data
  }

  async function removePlan(id) {
    await plansApi.remove(id)
    items.value = items.value.filter(p => p.id !== id)
  }

  return {
    items, loading, activePlans,
    fetchPlans, createPlan, updatePlan, removePlan,
  }
})
