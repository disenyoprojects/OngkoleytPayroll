import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      // Precache the built app shell (JS/CSS/HTML) so the page itself can
      // open with no network connection at all — required for offline
      // Clock In/Out to work on a cold load, not just an already-open tab.
      // API calls are NOT cached here; the app's own localStorage-backed
      // queue (src/offline) is what makes clock actions work offline.
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,ico}'],
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//],
      },
      manifest: {
        name: 'Ongkoleyt Payroll',
        short_name: 'Ongkoleyt',
        start_url: '/',
        display: 'standalone',
        background_color: '#FAF6EC',
        theme_color: '#2E2118',
        icons: [
          { src: '/favicon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any' },
        ],
      },
    }),
  ],
})
