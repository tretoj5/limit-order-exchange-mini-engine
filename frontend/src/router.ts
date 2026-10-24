import { createRouter, createWebHistory } from 'vue-router'
import { errorMessage } from './lib/api'
import { useAuthStore } from './stores/auth'
import { useToastStore } from './stores/toast'
import ExchangeLayout from './views/ExchangeLayout.vue'
import LoginView from './views/LoginView.vue'
import OverviewView from './views/OverviewView.vue'
import TradeView from './views/TradeView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: LoginView, meta: { guestOnly: true } },
    { path: '/register', component: LoginView, meta: { guestOnly: true, register: true } },
    {
      path: '/',
      component: ExchangeLayout,
      meta: { requiresAuth: true },
      children: [
        { path: '', component: OverviewView },
        { path: 'trade', component: TradeView },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  try {
    await auth.initialize()
  } catch (error) {
    useToastStore().show(errorMessage(error), 'error')
    if (!auth.token) return '/login'
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated()) return '/login'
  if (to.meta.guestOnly && auth.isAuthenticated()) return '/'
})

export default router
