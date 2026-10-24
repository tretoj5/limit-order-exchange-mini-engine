import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from './auth'

const { get, post, statusCode } = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  statusCode: vi.fn((error: unknown) => (
    typeof error === 'object' && error !== null && 'status' in error
      ? (error as { status: number }).status
      : undefined
  )),
}))

vi.mock('../lib/api', () => ({
  default: { get, post },
  statusCode,
}))

describe('auth initialization', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    get.mockReset()
    post.mockReset()
    statusCode.mockClear()
  })

  it('keeps the token and retries initialization after a transient profile failure', async () => {
    localStorage.setItem('exchange.token', 'valid-token')
    const auth = useAuthStore()
    get.mockRejectedValueOnce(new Error('Network unavailable'))

    await expect(auth.initialize()).rejects.toThrow('Network unavailable')

    expect(auth.token).toBe('valid-token')
    expect(auth.initialized).toBe(false)

    get.mockResolvedValueOnce({
      data: { id: 7, name: 'Trader', email: 'trader@example.test' },
    })
    await auth.initialize()

    expect(auth.initialized).toBe(true)
    expect(auth.user?.id).toBe(7)
    expect(auth.token).toBe('valid-token')
  })
})
