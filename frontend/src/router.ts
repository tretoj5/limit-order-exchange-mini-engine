import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from './stores/auth'
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
  await auth.initialize()

  if (to.meta.requiresAuth && !auth.isAuthenticated()) return '/login'
  if (to.meta.guestOnly && auth.isAuthenticated()) return '/'
})

export default router
