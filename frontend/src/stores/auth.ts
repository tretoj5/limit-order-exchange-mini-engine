import { defineStore } from 'pinia'
import { ref } from 'vue'
import api, { statusCode } from '../lib/api'
import type { User } from '../types'

interface AuthResponse {
  token: string
  user: User
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem('exchange.token') ?? '')
  const user = ref<User | null>(null)
  const initialized = ref(false)
  let initialization: Promise<void> | null = null

  function saveSession(response: AuthResponse): void {
    token.value = response.token
    user.value = response.user
    localStorage.setItem('exchange.token', response.token)
  }

  function clearSession(): void {
    token.value = ''
    user.value = null
    localStorage.removeItem('exchange.token')
  }

  async function authenticate(path: '/login' | '/register', credentials: Record<string, string>): Promise<void> {
    const { data } = await api.post<AuthResponse>(path, credentials)
    saveSession(data)
    try {
      const profile = await api.get<User>('/profile')
      user.value = profile.data
    } catch (error) {
      clearSession()
      throw error
    }
  }

  async function login(email: string, password: string): Promise<void> {
    await authenticate('/login', { email, password })
  }

  async function register(name: string, email: string, password: string, passwordConfirmation: string): Promise<void> {
    await authenticate('/register', {
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
    })
  }

  async function initialize(): Promise<void> {
    if (initialized.value) return
    if (initialization) return initialization

    initialization = (async () => {
      let completed = false
      try {
        if (!token.value) {
          completed = true
          return
        }

        const { data } = await api.get<User>('/profile')
        user.value = data
        completed = true
      } catch (error) {
        if (statusCode(error) === 401) {
          clearSession()
          completed = true
        } else {
          throw error
        }
      } finally {
        initialized.value = completed
        initialization = null
      }
    })()

    return initialization
  }

  async function logout(): Promise<void> {
    let requestError: unknown
    try {
      await api.post('/logout')
    } catch (error) {
      requestError = error
    } finally {
      clearSession()
      initialized.value = true
    }

    if (requestError) throw requestError
  }

  return {
    token,
    user,
    initialized,
    isAuthenticated: () => Boolean(token.value),
    authenticate,
    initialize,
    login,
    logout,
    register,
  }
})
