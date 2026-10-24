import { defineStore } from 'pinia'
import { ref } from 'vue'
import api, { errorMessage } from '../lib/api'
import type {
  Order,
  OrderInput,
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
  const matchedOrderIds = new Set<number>()
  let latestWalletTradeId = 0
  let sessionGeneration = 0
  let profileRequestId = 0
  let ordersRequestId = 0
  let orderbookRequestId = 0
  let activeRefreshes = 0

  async function refreshAll(): Promise<void> {
    const generation = sessionGeneration
    activeRefreshes += 1
    loading.value = true
    error.value = ''
    try {
      await Promise.all([refreshProfile(), refreshOrders(), refreshOrderbook()])
    } catch (cause) {
      if (generation === sessionGeneration) error.value = errorMessage(cause)
      throw cause
    } finally {
      if (generation === sessionGeneration) {
        activeRefreshes -= 1
        loading.value = activeRefreshes > 0
      }
    }
  }

  async function refreshProfile(): Promise<void> {
    const requestId = ++profileRequestId
    const generation = sessionGeneration
    const tradeIdAtRequest = latestWalletTradeId
    const { data } = await api.get<Profile>('/profile')
    if (
      generation !== sessionGeneration
      || requestId !== profileRequestId
      || tradeIdAtRequest !== latestWalletTradeId
    ) return
    profile.value = data
  }

  async function refreshOrders(): Promise<void> {
    const requestId = ++ordersRequestId
    const generation = sessionGeneration
    const { data } = await api.get<{ data: Order[] }>('/orders/mine')
    if (generation !== sessionGeneration || requestId !== ordersRequestId) return

    const existingOrders = new Map(orders.value.map((order) => [order.id, order]))
    orders.value = data.data.map((order) => {
      const current = existingOrders.get(order.id)
      return current && current.status !== 1 && order.status === 1
        ? { ...order, status: current.status }
        : order
    })
  }

  async function refreshOrderbook(): Promise<void> {
    const requestId = ++orderbookRequestId
    const generation = sessionGeneration
    const requestedSymbol = symbol.value
    const { data } = await api.get<Orderbook>('/orders', { params: { symbol: requestedSymbol } })
    if (
      generation !== sessionGeneration
      || requestId !== orderbookRequestId
      || symbol.value !== requestedSymbol
    ) return
    orderbook.value = {
      ...data,
      buy: data.buy.filter((order) => !matchedOrderIds.has(order.id)),
      sell: data.sell.filter((order) => !matchedOrderIds.has(order.id)),
    }
  }

  async function selectSymbol(nextSymbol: SymbolCode): Promise<void> {
    symbol.value = nextSymbol
    orderbook.value = { symbol: nextSymbol, buy: [], sell: [] }
    await refreshOrderbook()
  }

  async function placeOrder(input: OrderInput): Promise<OrderResponse> {
    const generation = sessionGeneration
    const { data } = await api.post<OrderResponse>('/orders', input)
    if (generation !== sessionGeneration) return data
    if (data.trade) {
      matchedOrderIds.add(data.order.id)
      upsertMatchedOrder(data.order)
      removeMatchedOrderFromOrderbook(data.order.id)
      addRecentTrade(data.trade)
      await refreshMarketDepth(data.trade.symbol)
    } else {
      await Promise.all([refreshProfile(), refreshOrders(), refreshOrderbook()])
    }
    notice.value = data.trade ? 'Your order matched successfully.' : 'Your order is now on the book.'
    return data
  }

  async function cancelOrder(orderId: number): Promise<void> {
    const generation = sessionGeneration
    await api.post(`/orders/${orderId}/cancel`)
    if (generation !== sessionGeneration) return
    await Promise.all([refreshProfile(), refreshOrders(), refreshOrderbook()])
    notice.value = 'Order cancelled. Reserved funds have been released.'
  }

  function applyMatched(payload: OrderMatchedPayload): void {
    if (seenTradeIds.has(payload.trade.id)) return

    const isLatestWalletSnapshot = payload.trade.id >= latestWalletTradeId
    seenTradeIds.add(payload.trade.id)
    latestWalletTradeId = Math.max(latestWalletTradeId, payload.trade.id)
    if (seenTradeIds.size > 1000) {
      const oldestId = seenTradeIds.values().next().value
      if (oldestId !== undefined) seenTradeIds.delete(oldestId)
    }

    if (profile.value && isLatestWalletSnapshot) {
      profile.value = {
        ...profile.value,
        balance: payload.balance,
        assets: payload.assets,
      }
    }

    matchedOrderIds.add(payload.order.id)
    upsertMatchedOrder(payload.order)
    removeMatchedOrderFromOrderbook(payload.order.id)
    addRecentTrade(payload.trade)
    notice.value = `${payload.trade.symbol} order matched at ${payload.trade.price}.`
  }

  async function refreshMarketDepth(matchedSymbol: SymbolCode): Promise<void> {
    if (symbol.value !== matchedSymbol) return

    try {
      await refreshOrderbook()
    } catch (cause) {
      error.value = errorMessage(cause)
    }
  }

  function upsertMatchedOrder(order: Order): void {
    const existingIndex = orders.value.findIndex((item) => item.id === order.id)
    if (existingIndex === -1) {
      orders.value = [order, ...orders.value]
        .sort((first, second) => second.id - first.id)
        .slice(0, 50)
      return
    }

    const existingOrder = orders.value[existingIndex]
    if (existingOrder && (existingOrder.status === 1 || existingOrder.status === order.status)) {
      orders.value[existingIndex] = order
    }
  }

  function removeMatchedOrderFromOrderbook(orderId: number): void {
    orderbook.value = {
      ...orderbook.value,
      buy: orderbook.value.buy.filter((order) => order.id !== orderId),
      sell: orderbook.value.sell.filter((order) => order.id !== orderId),
    }
  }

  function addRecentTrade(trade: Trade): void {
    if (trades.value.some((item) => item.id === trade.id)) return
    trades.value = [trade, ...trades.value]
      .sort((first, second) => second.id - first.id)
      .slice(0, 8)
  }

  function clear(): void {
    sessionGeneration += 1
    profileRequestId += 1
    ordersRequestId += 1
    orderbookRequestId += 1
    activeRefreshes = 0
    profile.value = null
    orders.value = []
    orderbook.value = { symbol: symbol.value, buy: [], sell: [] }
    trades.value = []
    seenTradeIds.clear()
    matchedOrderIds.clear()
    latestWalletTradeId = 0
    loading.value = false
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
    refreshMarketDepth,
    clear,
  }
})
