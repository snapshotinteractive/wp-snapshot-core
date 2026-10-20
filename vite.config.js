import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  base: './',
  publicDir: false,
  plugins: [tailwindcss()],
  build: {
    outDir: 'build',
    emptyOutDir: true,
    manifest: 'manifest.json',
    rollupOptions: {
      input: [
        'resources/css/login.css',
        'resources/css/security-admin.css',
        'resources/css/security-adminbar.css',
        'resources/css/snap-admin.css',
        'resources/css/seo-admin.css',
        'resources/js/security.js',
        'resources/js/seo-admin.js',
      ],
    },
  },
})
