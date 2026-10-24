import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api',
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
})

interface ApiError {
  message?: string
  errors?: Record<string, string[]>
}

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('exchange.token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

export function errorMessage(error: unknown): string {
  if (!axios.isAxiosError<ApiError>(error)) {
    return error instanceof Error ? error.message : 'The request could not be completed.'
  }
  const response = error.response
  const firstFieldError = response?.data?.errors
    ? Object.values(response.data.errors).flat()[0]
    : undefined

  return firstFieldError || response?.data?.message || 'The request could not be completed. Check your connection and try again.'
}

export function statusCode(error: unknown): number | undefined {
  return axios.isAxiosError(error) ? error.response?.status : undefined
}

export default api
