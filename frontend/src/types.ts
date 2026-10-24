export type SymbolCode = 'BTC' | 'ETH'
export type OrderSide = 'buy' | 'sell'
export type OrderStatus = 1 | 2 | 3

export interface User {
  id: number
  name: string
  email: string
}

export interface Asset {
  symbol: SymbolCode
  amount: string
  locked_amount: string
}

export interface Profile extends User {
  balance: string
  assets: Asset[]
}

export interface Order {
  id: number
  symbol: SymbolCode
  side: OrderSide
  price: string
  amount: string
  status: OrderStatus
  created_at: string | null
}

export interface Trade {
  id: number
  symbol: SymbolCode
  price: string
  amount: string
  usd_volume: string
  fee: string
  side: OrderSide
  created_at?: string | null
}

export interface OrderMatchedPayload {
  trade: Trade
  order: Order
  balance: string
  assets: Asset[]
}

export interface MarketDepthUpdatedPayload {
  symbol: SymbolCode
}

export interface Orderbook {
  symbol: SymbolCode
  buy: Order[]
  sell: Order[]
}

export interface OrderResponse {
  order: Order
  trade: Trade | null
}

export interface OrderInput {
  symbol: SymbolCode
  side: OrderSide
  price: string
  amount: string
}
