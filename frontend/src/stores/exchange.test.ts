import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useExchangeStore } from './exchange'
import type { OrderMatchedPayload } from '../types'

const { get, post } = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('../lib/api', () => ({
  default: { get, post },
  errorMessage: () => 'Request failed',
}))

describe('exchange store live updates', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('applies matched snapshots once and ignores stale wallet data', () => {
    const store = useExchangeStore()
    store.profile = {
      id: 4,
      name: 'Trader',
      email: 'trader@example.test',
      balance: '50000.00000000',
      assets: [{ symbol: 'BTC', amount: '1.00000000', locked_amount: '0.00000000' }],
    }
    store.orders = [{
      id: 12,
      symbol: 'BTC',
      side: 'buy',
      price: '95000.00000000',
      amount: '0.01000000',
      status: 1,
      created_at: null,
    }]
    const match: OrderMatchedPayload = {
      trade: {
        id: 91,
        symbol: 'BTC',
        price: '95000.00000000',
        amount: '0.01000000',
        usd_volume: '950.00000000',
        fee: '14.25000000',
        side: 'buy',
      },
      order: { id: 12, status: 2 },
      balance: '49035.75000000',
      assets: [{ symbol: 'BTC', amount: '1.01000000', locked_amount: '0.00000000' }],
    }

    store.applyMatched(match)
    store.applyMatched(match)
    store.applyMatched({
      ...match,
      trade: { ...match.trade, id: 90, price: '90000.00000000' },
      balance: '51000.00000000',
    })

    expect(store.profile?.balance).toBe('49035.75000000')
    expect(store.profile?.assets[0]?.amount).toBe('1.01000000')
    expect(store.orders[0]?.status).toBe(2)
    expect(store.trades).toHaveLength(2)
    expect(store.trades[0]?.id).toBe(91)
  })
})
