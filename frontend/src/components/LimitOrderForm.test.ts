import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import LimitOrderForm from './LimitOrderForm.vue'
import { useToastStore } from '../stores/toast'
import type { Profile } from '../types'

const profile: Profile = {
  id: 7,
  name: 'Trader',
  email: 'trader@example.test',
  balance: '1000.00000000',
  assets: [{ symbol: 'BTC', amount: '0.50000000', locked_amount: '0.00000000' }],
}

describe('limit order form', () => {
  it('highlights the selected order side', async () => {
    const pinia = createPinia()
    const wrapper = mount(LimitOrderForm, {
      global: { plugins: [pinia] },
      props: {
        symbol: 'BTC',
        profile,
        submitting: false,
      },
    })
    const buyButton = wrapper.get('button[aria-pressed="true"]')
    const sellButton = wrapper.get('button[aria-pressed="false"]')

    expect(buyButton.classes()).toContain('border-[#fa3812]')
    expect(buyButton.classes()).toContain('bg-[#fa3812]/10')
    expect(sellButton.classes()).not.toContain('border-[#fa3812]')

    await sellButton.trigger('click')

    expect(wrapper.get('button[aria-pressed="true"]').text()).toBe('Sell BTC')
    expect(wrapper.get('button[aria-pressed="true"]').classes()).toContain('bg-[#fa3812]/10')
    expect(wrapper.get('button[aria-pressed="false"]').text()).toBe('Buy BTC')
  })

  it('validates the buy reserve with the buyer fee before submission', async () => {
    const pinia = createPinia()
    const wrapper = mount(LimitOrderForm, {
      global: { plugins: [pinia] },
      props: {
        symbol: 'BTC',
        profile,
        submitting: false,
      },
    })
    const inputs = wrapper.findAll('input')
    await inputs[0]?.setValue('1000')
    await inputs[1]?.setValue('1')
    await wrapper.get('form').trigger('submit')

    expect(wrapper.emitted('submitOrder')).toBeUndefined()
    expect(useToastStore(pinia).messages[0]?.message).toBe('Insufficient USD balance for the order value and maximum buyer fee.')
    useToastStore(pinia).clear()
  })

  it('shows an asset hold for sells and emits the valid string-valued order', async () => {
    const pinia = createPinia()
    const wrapper = mount(LimitOrderForm, {
      global: { plugins: [pinia] },
      props: {
        symbol: 'BTC',
        profile,
        submitting: false,
      },
    })
    await wrapper.get('button[aria-pressed="false"]').trigger('click')
    const inputs = wrapper.findAll('input')
    await inputs[0]?.setValue('95000')
    await inputs[1]?.setValue('0.01')

    expect(wrapper.text()).toContain('Asset locked on placement')
    expect(wrapper.text()).toContain('0.01000000 BTC')
    await wrapper.get('form').trigger('submit')

    expect(wrapper.emitted('submitOrder')).toEqual([[
      {
        symbol: 'BTC',
        side: 'sell',
        price: '95000',
        amount: '0.01',
      },
    ]])
  })
})
