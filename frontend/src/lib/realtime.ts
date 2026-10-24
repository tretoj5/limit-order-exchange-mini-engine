import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import type { MarketDepthUpdatedPayload, OrderMatchedPayload, SymbolCode } from '../types'

declare global {
  interface Window {
    Pusher: typeof Pusher
  }
}

type EchoClient = Echo<'pusher'>

let echo: EchoClient | null = null
let subscribedUserId: number | null = null
let subscribedToken: string | null = null
let subscribedMarketSymbol: SymbolCode | null = null
let activeHandler: ((payload: OrderMatchedPayload) => void) | null = null
let activeMarketHandler: ((payload: MarketDepthUpdatedPayload) => void) | null = null
let hasConnected = false
let onReconnect: (() => void) | null = null
let onStatusChange: ((status: string) => void) | null = null

export function subscribeToMatches(
  userId: number,
  token: string,
  marketSymbol: SymbolCode,
  onMatch: (payload: OrderMatchedPayload) => void,
  onMarketDepthUpdate: (payload: MarketDepthUpdatedPayload) => void,
  resync: () => void,
  statusChange: (status: string) => void,
): void {
  if (subscribedUserId === userId && subscribedToken === token && echo) {
    activeHandler = onMatch
    activeMarketHandler = onMarketDepthUpdate
    onReconnect = resync
    onStatusChange = statusChange
    subscribeToMarket(marketSymbol)
    return
  }

  unsubscribeFromMatches()
  const key = import.meta.env.VITE_PUSHER_APP_KEY
  const cluster = import.meta.env.VITE_PUSHER_APP_CLUSTER
  if (!key || !cluster) {
    throw new Error('Set VITE_PUSHER_APP_KEY and VITE_PUSHER_APP_CLUSTER to enable live updates.')
  }

  window.Pusher = Pusher
  const authEndpoint = import.meta.env.VITE_BROADCAST_AUTH_URL
    || `${(import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api').replace(/\/api\/?$/, '')}/broadcasting/auth`
  echo = new Echo({
    broadcaster: 'pusher',
    key,
    cluster,
    forceTLS: true,
    authEndpoint,
    auth: { headers: { Authorization: `Bearer ${token}` } },
  })

  subscribedUserId = userId
  subscribedToken = token
  activeHandler = onMatch
  activeMarketHandler = onMarketDepthUpdate
  onReconnect = resync
  onStatusChange = statusChange
  hasConnected = false
  echo.private(`user.${userId}`).listen('.OrderMatched', (payload: OrderMatchedPayload) => {
    activeHandler?.(payload)
  })
  subscribeToMarket(marketSymbol)

  echo.connector.pusher.connection.bind('state_change', ({ current }: { current: string }) => {
    if (current === 'connected') {
      if (hasConnected) onReconnect?.()
      hasConnected = true
      onStatusChange?.('Live updates connected')
    } else if (current === 'disconnected' || current === 'unavailable' || current === 'failed') {
      onStatusChange?.(hasConnected ? 'Reconnecting to live updates' : 'Waiting for live updates')
    }
  })
}

function subscribeToMarket(symbol: SymbolCode): void {
  if (!echo || subscribedMarketSymbol === symbol) return
  if (subscribedMarketSymbol !== null) echo.leave(`market.${subscribedMarketSymbol}`)

  subscribedMarketSymbol = symbol
  echo.private(`market.${symbol}`).listen('.MarketDepthUpdated', (payload: MarketDepthUpdatedPayload) => {
    if (payload.symbol === subscribedMarketSymbol) activeMarketHandler?.(payload)
  })
}

export function unsubscribeFromMatches(): void {
  if (echo && subscribedUserId !== null) {
    echo.leave(`user.${subscribedUserId}`)
    if (subscribedMarketSymbol !== null) echo.leave(`market.${subscribedMarketSymbol}`)
    echo.disconnect()
  }
  echo = null
  subscribedUserId = null
  subscribedToken = null
  subscribedMarketSymbol = null
  activeHandler = null
  activeMarketHandler = null
  onReconnect = null
  onStatusChange = null
  hasConnected = false
}
