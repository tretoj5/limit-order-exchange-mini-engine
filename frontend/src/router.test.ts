import { afterEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const { get, post, statusCode } = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  statusCode: vi.fn(() => undefined),
}))

vi.mock('./lib/api', () => ({
  default: { get, post },
  errorMessage: (error: unknown) => error instanceof Error ? error.message : 'Request failed',
  statusCode,
}))

import router from './router'
import { useAuthStore } from './stores/auth'
import { useToastStore } from './stores/toast'

describe('router auth recovery', () => {
  afterEach(() => {
    localStorage.clear()
    get.mockReset()
    post.mockReset()
    statusCode.mockClear()
  })

  it('allows an authenticated route after a transient profile lookup failure', async () => {
    setActivePinia(createPinia())
    localStorage.setItem('exchange.token', 'valid-token')
    get.mockRejectedValueOnce(new Error('Network unavailable'))

    await router.push('/trade')

    expect(router.currentRoute.value.path).toBe('/trade')
    expect(useAuthStore().token).toBe('valid-token')
    expect(useToastStore().messages[0]?.message).toBe('Network unavailable')
  })
})
