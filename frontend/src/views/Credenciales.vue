<script setup>
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/store/auth'
import api from '@/services/api'

const auth = useAuthStore()

const dniForm = ref({ dni: '' })
const dniSaving = ref(false)
const dniSuccess = ref('')
const dniError = ref('')

const passwordForm = ref({
  current_password: '',
  password: '',
  password_confirmation: '',
})
const passwordSaving = ref(false)
const passwordSuccess = ref('')
const passwordError = ref('')
const showCurrentPassword = ref(false)
const showNewPassword = ref(false)
const showConfirmPassword = ref(false)

onMounted(() => {
  dniForm.value.dni = auth.user?.dni ?? ''
})

async function updateDni() {
  dniSaving.value = true
  dniSuccess.value = ''
  dniError.value = ''
  try {
    const payload = {
      dni: (dniForm.value.dni || '').replace(/\D/g, '').slice(0, 8),
    }
    const { data } = await api.put('/me/dni', payload)
    auth.user = data.user
    dniSuccess.value = data.message ?? 'DNI actualizado correctamente.'
  } catch (e) {
    dniError.value =
      e.response?.data?.errors?.dni?.[0] ??
      e.response?.data?.message ??
      'No se pudo actualizar el DNI.'
  } finally {
    dniSaving.value = false
  }
}

async function updatePassword() {
  passwordSaving.value = true
  passwordSuccess.value = ''
  passwordError.value = ''
  try {
    const { data } = await api.put('/me/password', passwordForm.value)
    passwordSuccess.value = data.message ?? 'Contraseña actualizada correctamente.'
    passwordForm.value = {
      current_password: '',
      password: '',
      password_confirmation: '',
    }
  } catch (e) {
    passwordError.value =
      e.response?.data?.errors?.current_password?.[0] ??
      e.response?.data?.errors?.password?.[0] ??
      e.response?.data?.message ??
      'No se pudo actualizar la contraseña.'
  } finally {
    passwordSaving.value = false
  }
}
</script>

<template>
  <div class="max-w-4xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">UpdateHub</h1>
      <p class="text-sm text-gray-500 mt-1">Actualiza tu DNI y tu contraseña de acceso.</p>
    </div>

    <div class="card">
      <h2 class="text-lg font-semibold text-gray-800 mb-4">Actualizar DNI</h2>

      <form @submit.prevent="updateDni" class="space-y-3">
        <div class="max-w-sm">
          <label class="block text-sm font-medium text-gray-700 mb-1.5">DNI (8 dígitos)</label>
          <input
            :value="dniForm.dni"
            @input="dniForm.dni = ($event.target.value || '').replace(/\D/g, '').slice(0, 8)"
            type="text"
            inputmode="numeric"
            class="input"
            placeholder="12345678"
          />
        </div>

        <p v-if="dniError" class="text-sm text-red-600">{{ dniError }}</p>
        <p v-if="dniSuccess" class="text-sm text-green-600">{{ dniSuccess }}</p>

        <button type="submit" class="btn-primary" :disabled="dniSaving">
          {{ dniSaving ? 'Guardando...' : 'Guardar DNI' }}
        </button>
      </form>
    </div>

    <div class="card">
      <h2 class="text-lg font-semibold text-gray-800 mb-4">Cambiar Contraseña</h2>

      <form @submit.prevent="updatePassword" class="space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div class="relative">
            <input
              v-model="passwordForm.current_password"
              :type="showCurrentPassword ? 'text' : 'password'"
              class="input pr-10"
              placeholder="Contraseña actual"
            />
            <button
              type="button"
              class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
              @click="showCurrentPassword = !showCurrentPassword"
              :aria-label="showCurrentPassword ? 'Ocultar contraseña actual' : 'Mostrar contraseña actual'"
            >
              <svg v-if="!showCurrentPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
              <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M3 3l18 18" />
              </svg>
            </button>
          </div>

          <div class="relative">
            <input
              v-model="passwordForm.password"
              :type="showNewPassword ? 'text' : 'password'"
              class="input pr-10"
              placeholder="Nueva contraseña"
            />
            <button
              type="button"
              class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
              @click="showNewPassword = !showNewPassword"
              :aria-label="showNewPassword ? 'Ocultar nueva contraseña' : 'Mostrar nueva contraseña'"
            >
              <svg v-if="!showNewPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
              <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M3 3l18 18" />
              </svg>
            </button>
          </div>

          <div class="relative">
            <input
              v-model="passwordForm.password_confirmation"
              :type="showConfirmPassword ? 'text' : 'password'"
              class="input pr-10"
              placeholder="Confirmar nueva"
            />
            <button
              type="button"
              class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
              @click="showConfirmPassword = !showConfirmPassword"
              :aria-label="showConfirmPassword ? 'Ocultar confirmación' : 'Mostrar confirmación'"
            >
              <svg v-if="!showConfirmPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
              <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M3 3l18 18" />
              </svg>
            </button>
          </div>
        </div>

        <p v-if="passwordError" class="text-sm text-red-600">{{ passwordError }}</p>
        <p v-if="passwordSuccess" class="text-sm text-green-600">{{ passwordSuccess }}</p>

        <button type="submit" class="btn-primary" :disabled="passwordSaving">
          {{ passwordSaving ? 'Actualizando...' : 'Actualizar Contraseña' }}
        </button>
      </form>
    </div>
  </div>
</template>
