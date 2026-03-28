import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'

export const useAuthStore = defineStore('auth', () => {
  /* ── State ─────────────────────────────────────── */
  const user  = ref(null)
  const token = ref(localStorage.getItem('token') ?? sessionStorage.getItem('token') ?? null)

  /* ── Getters ────────────────────────────────────── */
  const isAuthenticated = computed(() => !!token.value && !!user.value)
  const userRole        = computed(() => user.value?.role ?? null)
  const isAdmin         = computed(() => user.value?.role === 'admin')
  const isVendedora     = computed(() => user.value?.role === 'vendedora')
  const isSupervisor    = computed(() => user.value?.role === 'supervisor')

  /* ── Actions ────────────────────────────────────── */
  async function login(login, password, remember = true) {
    const { data } = await api.post('/login', { login, password })
    token.value = data.token
    user.value  = data.user
    if (remember) {
      localStorage.setItem('token', data.token)
      sessionStorage.removeItem('token')
    } else {
      sessionStorage.setItem('token', data.token)
      localStorage.removeItem('token')
    }
    return data.user
  }

  async function logout() {
    try {
      await api.post('/logout')
    } finally {
      _clearSession()
    }
  }

  async function fetchUser() {
    if (!token.value) return null
    try {
      const { data } = await api.get('/me')
      user.value = data
      return data
    } catch {
      _clearSession()
      return null
    }
  }

  function hasRole(roles) {
    if (!user.value) return false
    return Array.isArray(roles)
      ? roles.includes(user.value.role)
      : user.value.role === roles
  }

  function _clearSession() {
    token.value = null
    user.value  = null
    localStorage.removeItem('token')
    sessionStorage.removeItem('token')
  }

  return {
    user,
    token,
    isAuthenticated,
    userRole,
    isAdmin,
    isVendedora,
    isSupervisor,
    login,
    logout,
    fetchUser,
    hasRole,
  }
})
