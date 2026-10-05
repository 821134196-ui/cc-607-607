import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// 前端 5173；/api 代理到 Laravel 8000，一条命令启动前后端即可联调
export default defineConfig({
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
