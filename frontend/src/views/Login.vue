<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/store/auth'

const router   = useRouter()
const auth     = useAuthStore()

const form = reactive({ login: '', password: '' })
const loading      = ref(false)
const errorMsg     = ref('')
const showPassword = ref(false)
const rememberMe   = ref(false)

function normalizeLoginInput(raw) {
  const value = String(raw ?? '').replace(/\s+/g, '')

  // If input is strictly numeric, treat it as DNI and cap to 8 digits.
  if (/^\d*$/.test(value)) {
    return value.slice(0, 8)
  }

  // Mobile keyboards often auto-capitalize emails; normalize to avoid false credential errors.
  return value.toLowerCase()
}

async function handleLogin() {
  loading.value  = true
  errorMsg.value = ''
  try {
    await auth.login(form.login, form.password, rememberMe.value)
    router.push('/dashboard')
  } catch (err) {
    errorMsg.value =
      err.response?.data?.errors?.login?.[0] ??
      err.response?.data?.message ??
      'Error al iniciar sesión. Verifica tus credenciales.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-gradient-to-br from-gray-950 via-gray-900 to-gray-950 flex items-center justify-center p-4 relative overflow-hidden">

    <!-- Ambient blobs -->
    <div class="pointer-events-none absolute inset-0">
      <div class="absolute -top-48 -right-48 w-96 h-96 bg-primary/10 rounded-full blur-3xl" />
      <div class="absolute -bottom-48 -left-48 w-96 h-96 bg-primary/8 rounded-full blur-3xl" />
      <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-64 h-64 bg-primary/5 rounded-full blur-2xl" />
    </div>

    <div class="relative w-full max-w-md">

      <!-- Card -->
      <div class="bg-gray-900/80 backdrop-blur-xl rounded-3xl shadow-2xl border border-gray-700/40 p-8">

        <!-- Logo -->
        <div class="flex flex-col items-center mb-8">
          <div class="w-16 h-16 bg-primary/10 rounded-2xl flex items-center justify-center ring-1 ring-primary/30 mb-4 shadow-primary">
            <svg class="w-8 h-8 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
          </div>
          <h1 class="text-2xl font-bold text-white tracking-tight">GO Systems</h1>
          <p class="text-gray-400 text-sm">& Technology</p>
        </div>

        <h2 class="text-lg font-semibold text-gray-100 text-center mb-6">Iniciar Sesión</h2>

        <!-- Error -->
        <Transition name="fade">
          <div
            v-if="errorMsg"
            class="flex items-start gap-3 bg-red-500/10 border border-red-500/25 text-red-400 text-sm rounded-xl px-4 py-3 mb-4"
          >
            <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{{ errorMsg }}</span>
          </div>
        </Transition>

        <form @submit.prevent="handleLogin" class="space-y-4" novalidate>

          <!-- Login -->
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1.5">DNI o correo</label>
            <input
              :value="form.login"
              @input="form.login = normalizeLoginInput($event.target.value)"
              type="text"
              autocomplete="username"
              inputmode="text"
              autocapitalize="off"
              autocorrect="off"
              spellcheck="false"
              placeholder="12345678 o usuario@correo.com"
              required
              class="w-full bg-gray-800/60 border border-gray-600/60 rounded-xl px-4 py-3 text-white placeholder-gray-500 text-sm
                     focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all duration-150"
            />
          </div>

          <!-- Password -->
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1.5">Contraseña</label>
            <div class="relative">
              <input
                v-model="form.password"
                :type="showPassword ? 'text' : 'password'"
                autocomplete="current-password"
                placeholder="••••••••"
                required
                class="w-full bg-gray-800/60 border border-gray-600/60 rounded-xl px-4 py-3 pr-12 text-white placeholder-gray-500 text-sm
                       focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all duration-150"
              />
              <button
                type="button"
                @click="showPassword = !showPassword"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-300 transition-colors"
                :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
              >
                <svg v-if="!showPassword" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
                <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                </svg>
              </button>
            </div>
          </div>

          <!-- Submit -->
                    <!-- Mantener sesión -->
                    <label class="flex items-center gap-2.5 cursor-pointer select-none w-fit">
                      <input
                        v-model="rememberMe"
                        type="checkbox"
                        class="w-4 h-4 rounded border-gray-600 bg-gray-800 text-primary accent-primary cursor-pointer"
                      />
                      <span class="text-sm text-gray-400">Mantener sesión iniciada</span>
                    </label>

          <button
            type="submit"
            :disabled="loading || !form.login || !form.password"
            class="btn-primary w-full py-3 mt-2"
          >
            <svg v-if="loading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            <span>{{ loading ? 'Iniciando sesión...' : 'Iniciar Sesión' }}</span>
          </button>

        </form>

        <!-- Footer -->
        <p class="text-center text-gray-600 text-xs mt-8">
          GO Systems &amp; Technology &copy; {{ new Date().getFullYear() }}
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to        { opacity: 0; }
</style>
