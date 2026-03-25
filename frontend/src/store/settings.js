import { defineStore } from 'pinia'
import { ref } from 'vue'
import settingsApi from '@/services/settings'

export const useSettingsStore = defineStore('settings', () => {
  /**
   * reniecEnabled — loaded from the backend once per session.
   * Reflects the DB toggle (does NOT contain any token).
   *
   * Google Maps key is a frontend env var (VITE_GOOGLE_MAPS_KEY).
   * It is never fetched from the backend to avoid exposing it via API.
   */
  const reniecEnabled = ref(false)
  const loaded        = ref(false)

  async function loadConfig() {
    if (loaded.value) return
    try {
      const { data } = await settingsApi.getPublicConfig()
      reniecEnabled.value = !!data.reniec_enabled
      loaded.value        = true
    } catch {
      // fail silently — features degrade gracefully
    }
  }

  return { reniecEnabled, loaded, loadConfig }
})

