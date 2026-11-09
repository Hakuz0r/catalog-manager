import vue from '@vitejs/plugin-vue'
import { defineConfig, loadEnv } from 'vite'
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const target = env.UKM_STAGE_TARGET || 'http://127.0.0.1:18080'
  return { plugins: [vue()], server: { host: '127.0.0.1', port: 5173, strictPort: true, proxy: {
    '/ukm-api': { target, changeOrigin: true, rewrite: path => path.replace(/^\/ukm-api/, '/wp-json/ukm-office/v1'), cookieDomainRewrite: '' },
    '/ukm-auth': { target, changeOrigin: true, rewrite: path => `/wp-admin/admin-ajax.php?action=ukm_office_${path.split('/')[2]}`, cookieDomainRewrite: '' },
    '/wp-content/uploads': { target, changeOrigin: true },
    '/office-media': { target, changeOrigin: true },
  } } }
})
