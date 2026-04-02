import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import usersApi from '@/services/users'

export const useUsersStore = defineStore('users', () => {
  const items      = ref([])
  const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 })
  const loading    = ref(false)
  const filters    = ref({ search: '', role: '', active: '' })

  const vendedoras = computed(() =>
    items.value.filter(u => u.role === 'vendedora' && u.active)
  )

  async function fetchUsers(page = 1) {
    loading.value = true
    try {
      const params = { page, ...filters.value }
      // clean empty params
      Object.keys(params).forEach(k => { if (params[k] === '') delete params[k] })
      const { data } = await usersApi.list(params)
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

  async function createUser(payload) {
    const { data } = await usersApi.create(payload)
    items.value.unshift(data.data)
    pagination.value.total++
    return data.data
  }

  async function updateUser(id, payload) {
    const { data } = await usersApi.update(id, payload)
    const idx = items.value.findIndex(u => u.id === id)
    if (idx !== -1) items.value[idx] = data.data
    return data.data
  }

  async function removeUser(id, force = false) {
    await usersApi.remove(id, force)
    const idx = items.value.findIndex(u => u.id === id)
    if (idx !== -1) {
      items.value.splice(idx, 1)
    }
    pagination.value.total--
  }

  function setFilter(key, value) {
    filters.value[key] = value
  }

  function resetFilters() {
    filters.value = { search: '', role: '', active: '' }
  }

  return {
    items,
    pagination,
    loading,
    filters,
    vendedoras,
    fetchUsers,
    createUser,
    updateUser,
    removeUser,
    setFilter,
    resetFilters,
  }
})
