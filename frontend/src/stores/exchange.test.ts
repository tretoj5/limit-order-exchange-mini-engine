import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useExchangeStore } from './exchange'
import type { OrderMatchedPayload } from '../types'

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((resolvePromise) => {
    resolve = resolvePromise
  })
  return { promise, resolve }
}

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
    store.orderbook = {
      symbol: 'BTC',
      buy: [{
        id: 12,
        symbol: 'BTC',
        side: 'buy',
        price: '95000.00000000',
        amount: '0.01000000',
        status: 1,
        created_at: null,
      }],
      sell: [],
    }
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
      order: {
        id: 12,
        symbol: 'BTC',
        side: 'buy',
        price: '95000.00000000',
        amount: '0.01000000',
        status: 2,
        created_at: null,
      },
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
    expect(store.orderbook.buy).toHaveLength(0)
    expect(store.trades).toHaveLength(2)
    expect(store.trades[0]?.id).toBe(91)
  })

  it('inserts a matched order and removes it from the orderbook without fetching', () => {
    const store = useExchangeStore()
    store.orders = []
    store.orderbook = {
      symbol: 'BTC',
      buy: [{
        id: 12,
        symbol: 'BTC',
        side: 'buy',
        price: '95000.00000000',
        amount: '0.01000000',
        status: 1,
        created_at: null,
      }],
      sell: [],
    }
    const order: OrderMatchedPayload['order'] = {
      id: 12,
      symbol: 'BTC',
      side: 'buy',
      price: '95000.00000000',
      amount: '0.01000000',
      status: 2,
      created_at: '2026-10-24T10:00:00.000000Z',
    }

    store.applyMatched({
      trade: {
        id: 91,
        symbol: 'BTC',
        price: '95000.00000000',
        amount: '0.01000000',
        usd_volume: '950.00000000',
        fee: '14.25000000',
        side: 'buy',
      },
      order,
      balance: '49035.75000000',
      assets: [{ symbol: 'BTC', amount: '1.01000000', locked_amount: '0.00000000' }],
    })

    expect(store.orders).toEqual([order])
    expect(store.orderbook.buy).toHaveLength(0)
    expect(get).not.toHaveBeenCalled()
  })

  it('does not replace a newer match snapshot with a stale profile response', async () => {
    const store = useExchangeStore()
    store.profile = {
      id: 4,
      name: 'Trader',
      email: 'trader@example.test',
      balance: '50000.00000000',
      assets: [{ symbol: 'BTC', amount: '1.00000000', locked_amount: '0.00000000' }],
    }
    const response = deferred<{ data: typeof store.profile }>()
    get.mockReturnValueOnce(response.promise)

    const refresh = store.refreshProfile()
    store.applyMatched({
      trade: {
        id: 91,
        symbol: 'BTC',
        price: '95000.00000000',
        amount: '0.01000000',
        usd_volume: '950.00000000',
        fee: '14.25000000',
        side: 'buy',
      },
      order: {
        id: 12,
        symbol: 'BTC',
        side: 'buy',
        price: '95000.00000000',
        amount: '0.01000000',
        status: 2,
        created_at: null,
      },
      balance: '49035.75000000',
      assets: [{ symbol: 'BTC', amount: '1.01000000', locked_amount: '0.00000000' }],
    })

    response.resolve({
      data: {
        id: 4,
        name: 'Trader',
        email: 'trader@example.test',
        balance: '50000.00000000',
        assets: [{ symbol: 'BTC', amount: '1.00000000', locked_amount: '0.00000000' }],
      },
    })
    await refresh

    expect(store.profile?.balance).toBe('49035.75000000')
    expect(store.profile?.assets[0]?.amount).toBe('1.01000000')
  })

  it('ignores in-flight profile responses after clearing a user session', async () => {
    const store = useExchangeStore()
    const response = deferred<{ data: NonNullable<typeof store.profile> }>()
    get.mockReturnValueOnce(response.promise)

    const refresh = store.refreshProfile()
    store.clear()
    response.resolve({
      data: {
        id: 4,
        name: 'Previous user',
        email: 'previous@example.test',
        balance: '50000.00000000',
        assets: [],
      },
    })
    await refresh

    expect(store.profile).toBeNull()
  })

  it('ignores stale orderbook responses after the selected market changes', async () => {
    const store = useExchangeStore()
    const oldMarket = deferred<{ data: { symbol: 'ETH'; buy: []; sell: [] } }>()
    const newMarket = deferred<{ data: { symbol: 'BTC'; buy: []; sell: [] } }>()
    get.mockImplementation((_path: string, config: { params: { symbol: string } }) => (
      config.params.symbol === 'ETH' ? oldMarket.promise : newMarket.promise
    ))

    const olderRequest = store.selectSymbol('ETH')
    const latestRequest = store.selectSymbol('BTC')
    newMarket.resolve({ data: { symbol: 'BTC', buy: [], sell: [] } })
    await latestRequest
    oldMarket.resolve({ data: { symbol: 'ETH', buy: [], sell: [] } })
    await olderRequest

    expect(store.symbol).toBe('BTC')
    expect(store.orderbook.symbol).toBe('BTC')
  })

  it('keeps a known terminal order status when an older snapshot says open', async () => {
    const store = useExchangeStore()
    store.orders = [{
      id: 12,
      symbol: 'BTC',
      side: 'buy',
      price: '95000.00000000',
      amount: '0.01000000',
      status: 2,
      created_at: null,
    }]
    get.mockResolvedValueOnce({
      data: {
        data: [{
          id: 12,
          symbol: 'BTC',
          side: 'buy',
          price: '95000.00000000',
          amount: '0.01000000',
          status: 1,
          created_at: null,
        }],
      },
    })

    await store.refreshOrders()

    expect(store.orders[0]?.status).toBe(2)
  })
})
