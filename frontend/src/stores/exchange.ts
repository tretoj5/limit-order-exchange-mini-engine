import { defineStore } from 'pinia'
import { ref } from 'vue'
import api, { errorMessage } from '../lib/api'
import type {
  Order,
  OrderMatchedPayload,
  OrderResponse,
  Orderbook,
  Profile,
  SymbolCode,
  Trade,
} from '../types'

export const useExchangeStore = defineStore('exchange', () => {
  const symbol = ref<SymbolCode>('BTC')
  const profile = ref<Profile | null>(null)
  const orders = ref<Order[]>([])
  const orderbook = ref<Orderbook>({ symbol: 'BTC', buy: [], sell: [] })
  const trades = ref<Trade[]>([])
  const loading = ref(false)
  const error = ref('')
  const notice = ref('')
  const seenTradeIds = new Set<number>()
  let latestWalletTradeId = 0

  async function refreshAll(): Promise<void> {
    loading.value = true
    error.value = ''
    try {
      await Promise.all([refreshProfile(), refreshOrders(), refreshOrderbook()])
    } catch (cause) {
      error.value = errorMessage(cause)
      throw cause
    } finally {
      loading.value = false
    }
  }

  async function refreshProfile(): Promise<void> {
    const { data } = await api.get<Profile>('/profile')
    profile.value = data
  }

  async function refreshOrders(): Promise<void> {
    const { data } = await api.get<{ data: Order[] }>('/orders/mine')
    orders.value = data.data
  }

  async function refreshOrderbook(): Promise<void> {
    const { data } = await api.get<Orderbook>('/orders', { params: { symbol: symbol.value } })
    orderbook.value = data
  }

  async function selectSymbol(nextSymbol: SymbolCode): Promise<void> {
    symbol.value = nextSymbol
    await refreshOrderbook()
  }

  async function placeOrder(input: {
    symbol: SymbolCode
    side: 'buy' | 'sell'
    price: string
    amount: string
  }): Promise<OrderResponse> {
    const { data } = await api.post<OrderResponse>('/orders', input)
    if (data.trade) addRecentTrade(data.trade)
    await Promise.all([refreshProfile(), refreshOrders(), refreshOrderbook()])
    notice.value = data.trade ? 'Your order matched successfully.' : 'Your order is now on the book.'
    return data
  }

  async function cancelOrder(orderId: number): Promise<void> {
    await api.post(`/orders/${orderId}/cancel`)
    await Promise.all([refreshProfile(), refreshOrders(), refreshOrderbook()])
    notice.value = 'Order cancelled. Reserved funds have been released.'
  }

  function applyMatched(payload: OrderMatchedPayload): void {
    if (seenTradeIds.has(payload.trade.id)) return

    seenTradeIds.add(payload.trade.id)
    if (seenTradeIds.size > 1000) {
      const oldestId = seenTradeIds.values().next().value
      if (oldestId !== undefined) seenTradeIds.delete(oldestId)
    }

    if (profile.value && payload.trade.id >= latestWalletTradeId) {
      profile.value = {
        ...profile.value,
        balance: payload.balance,
        assets: payload.assets,
      }
      latestWalletTradeId = payload.trade.id
    }

    const existingOrder = orders.value.find((order) => order.id === payload.order.id)
    if (existingOrder && existingOrder.status < payload.order.status) {
      existingOrder.status = payload.order.status
    }

    addRecentTrade(payload.trade)
    notice.value = `${payload.trade.symbol} order matched at ${payload.trade.price}.`
  }

  function addRecentTrade(trade: Trade): void {
    if (trades.value.some((item) => item.id === trade.id)) return
    trades.value = [trade, ...trades.value]
      .sort((first, second) => second.id - first.id)
      .slice(0, 8)
  }

  function clear(): void {
    profile.value = null
    orders.value = []
    orderbook.value = { symbol: symbol.value, buy: [], sell: [] }
    trades.value = []
    seenTradeIds.clear()
    latestWalletTradeId = 0
    error.value = ''
    notice.value = ''
  }

  return {
    symbol,
    profile,
    orders,
    orderbook,
    trades,
    loading,
    error,
    notice,
    refreshAll,
    refreshProfile,
    refreshOrders,
    refreshOrderbook,
    selectSymbol,
    placeOrder,
    cancelOrder,
    applyMatched,
    clear,
  }
})
