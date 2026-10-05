import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// 前端 5173，接口经代理转发到 Laravel 8000
export default defineConfig({
  plugins: [react()],
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
