import { defineStore } from 'pinia'
import { ref } from 'vue'
import notificationsApi from '@/services/notifications'

export const useNotificationsStore = defineStore('notifications', () => {
  const items       = ref([])
  const unreadCount = ref(0)
  const loading     = ref(false)
  const pagination  = ref({ current_page: 1, last_page: 1, total: 0 })

  async function fetchNotifications(params = {}) {
    loading.value = true
    try {
      const { data } = await notificationsApi.list(params)
      items.value = data.data
      pagination.value = {
        current_page: data.current_page,
        last_page:    data.last_page,
        total:        data.total,
      }
      return data
    } finally {
      loading.value = false
    }
  }

  async function fetchUnreadCount() {
    const { data } = await notificationsApi.unreadCount()
    unreadCount.value = data.count
    return data.count
  }

  async function markRead(id) {
    await notificationsApi.markRead(id)
    const item = items.value.find((n) => n.id === id)
    if (item) item.read_at = new Date().toISOString()
    unreadCount.value = Math.max(0, unreadCount.value - 1)
  }

  async function markAllRead() {
    await notificationsApi.markAllRead()
    items.value.forEach((n) => { n.read_at = n.read_at || new Date().toISOString() })
    unreadCount.value = 0
  }

  return {
    items, unreadCount, loading, pagination,
    fetchNotifications, fetchUnreadCount, markRead, markAllRead,
  }
})
