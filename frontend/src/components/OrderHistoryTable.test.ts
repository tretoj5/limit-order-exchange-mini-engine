import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import OrderHistoryTable from './OrderHistoryTable.vue'
import type { Order } from '../types'

const orders: Order[] = [
  { id: 1, symbol: 'BTC', side: 'buy', price: '95000.00000000', amount: '0.01000000', status: 1, created_at: null },
  { id: 2, symbol: 'BTC', side: 'sell', price: '96000.00000000', amount: '0.01000000', status: 2, created_at: null },
  { id: 3, symbol: 'ETH', side: 'sell', price: '2500.00000000', amount: '0.50000000', status: 3, created_at: null },
]

describe('order history filters', () => {
  it('filters by symbol, side, and status together', async () => {
    const wrapper = mount(OrderHistoryTable, {
      props: { orders, cancellingId: null },
    })
    const symbolFilter = wrapper.get('#order-symbol-filter')
    const sideFilter = wrapper.get('#order-side-filter')
    const statusFilter = wrapper.get('#order-status-filter')

    await symbolFilter.setValue('BTC')
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)

    await sideFilter.setValue('sell')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.text()).toContain('BTC / USD')

    await statusFilter.setValue('filled')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.text()).toContain('Filled')

    await statusFilter.setValue('cancelled')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.text()).toContain('No orders match this filter.')
  })

  it('can show all markets, sides, and statuses again', async () => {
    const wrapper = mount(OrderHistoryTable, {
      props: { orders, cancellingId: null },
    })
    await wrapper.get('#order-symbol-filter').setValue('ETH')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    await wrapper.get('#order-symbol-filter').setValue('all')

    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
  })
})
