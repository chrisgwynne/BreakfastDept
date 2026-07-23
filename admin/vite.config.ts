import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// The standalone Breakfast admin app. Served in production at /breakfast-admin/
// by Kirby (SPA fallback), talking to /api/breakfast-admin/v1 on the same origin.
// Built assets land in public/breakfast-admin/ so the FTP deploy ships them.
export default defineConfig({
  base: '/breakfast-admin/',
  plugins: [vue()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  build: {
    outDir: '../public/breakfast-admin',
    emptyOutDir: true,
    sourcemap: false,
    // Route-level code splitting keeps the initial payload small.
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['vue', 'vue-router', 'pinia'],
        },
      },
    },
  },
  server: {
    port: 5177,
    // In dev the SPA runs on Vite; proxy the API to the PHP backend.
    proxy: {
      '/api': { target: 'http://127.0.0.1:8787', changeOrigin: true },
      '/media': { target: 'http://127.0.0.1:8787', changeOrigin: true },
    },
  },
})
