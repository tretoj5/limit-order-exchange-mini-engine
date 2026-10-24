import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from './stores/auth'
import LoginView from './views/LoginView.vue'
import WorkspaceView from './views/WorkspaceView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: LoginView, meta: { guestOnly: true } },
    { path: '/register', component: LoginView, meta: { guestOnly: true, register: true } },
    { path: '/', component: WorkspaceView, meta: { requiresAuth: true } },
    { path: '/trade', component: WorkspaceView, meta: { requiresAuth: true } },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.initialize()

  if (to.meta.requiresAuth && !auth.isAuthenticated()) return '/login'
  if (to.meta.guestOnly && auth.isAuthenticated()) return '/'
})

export default router
