import type { RouteRecordRaw } from 'vue-router'
import { view } from '@multi-tenant-saas/console/module-loader'

const routes: RouteRecordRaw[] = [
  // 小程序发布（平台配置 + 构建记录，两 tab 单页）
  {
    path: 'miniapp',
    name: 'MiniappConsole',
    component: view('miniapp', 'MiniappConsole'),
    meta: { title: '小程序发布' },
  },
]

export default routes
