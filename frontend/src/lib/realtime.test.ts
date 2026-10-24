import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { OrderMatchedPayload } from '../types'

const mocks = vi.hoisted(() => {
  const listen = vi.fn()
  const privateChannel = vi.fn(() => ({ listen }))
  const bind = vi.fn()
  const leave = vi.fn()
  const disconnect = vi.fn()
  const echo = {
    private: privateChannel,
    leave,
    disconnect,
    connector: { pusher: { connection: { bind } } },
  }

  return {
    bind,
    disconnect,
    echo,
    listen,
    leave,
    privateChannel,
    Echo: vi.fn(function MockEcho() {
      return echo
    }),
  }
})

vi.mock('laravel-echo', () => ({ default: mocks.Echo }))
vi.mock('pusher-js', () => ({ default: class MockPusher {} }))

import { subscribeToMatches, unsubscribeFromMatches } from './realtime'

describe('private realtime listener lifecycle', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_PUSHER_APP_KEY', 'public-test-key')
    vi.stubEnv('VITE_PUSHER_APP_CLUSTER', 'mt1')
  })

  afterEach(() => {
    unsubscribeFromMatches()
    vi.unstubAllEnvs()
    vi.clearAllMocks()
  })

  it('keeps one subscription per user and resyncs after reconnect', () => {
    const firstHandler = vi.fn()
    const updatedHandler = vi.fn()
    const resync = vi.fn()
    const statusChange = vi.fn()

    subscribeToMatches(7, 'token-one', firstHandler, resync, statusChange)
    subscribeToMatches(7, 'token-one', updatedHandler, resync, statusChange)

    expect(mocks.Echo).toHaveBeenCalledTimes(1)
    expect(mocks.privateChannel).toHaveBeenCalledTimes(1)
    expect(mocks.privateChannel).toHaveBeenCalledWith('user.7')

    const stateChange = mocks.bind.mock.calls[0]?.[1] as (state: { current: string }) => void
    stateChange({ current: 'connected' })
    expect(statusChange).toHaveBeenCalledWith('Live updates connected')
    expect(resync).not.toHaveBeenCalled()
    stateChange({ current: 'disconnected' })
    expect(statusChange).toHaveBeenCalledWith('Reconnecting to live updates')
    stateChange({ current: 'connected' })
    expect(resync).toHaveBeenCalledTimes(1)

    const match: OrderMatchedPayload = {
      trade: {
        id: 1,
        symbol: 'BTC',
        price: '95000.00000000',
        amount: '0.01000000',
        usd_volume: '950.00000000',
        fee: '14.25000000',
        side: 'buy',
      },
      order: { id: 2, status: 2 },
      balance: '49035.75000000',
      assets: [],
    }
    const listener = mocks.listen.mock.calls[0]?.[1] as (payload: OrderMatchedPayload) => void
    listener(match)
    expect(firstHandler).not.toHaveBeenCalled()
    expect(updatedHandler).toHaveBeenCalledWith(match)
  })

  it('leaves the private channel and disconnects on unsubscribe', () => {
    subscribeToMatches(9, 'token', vi.fn(), vi.fn(), vi.fn())

    unsubscribeFromMatches()

    expect(mocks.leave).toHaveBeenCalledWith('user.9')
    expect(mocks.disconnect).toHaveBeenCalledTimes(1)
  })

  it('recreates the connection when the authenticated user token changes', () => {
    subscribeToMatches(9, 'old-token', vi.fn(), vi.fn(), vi.fn())
    subscribeToMatches(9, 'new-token', vi.fn(), vi.fn(), vi.fn())

    expect(mocks.Echo).toHaveBeenCalledTimes(2)
    expect(mocks.leave).toHaveBeenCalledWith('user.9')
    expect(mocks.disconnect).toHaveBeenCalledTimes(1)
  })
})
