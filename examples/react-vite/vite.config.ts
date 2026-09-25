import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd());
  const csp = `frame-ancestors 'self' ${env.VITE_WORDPRESS_URL}`;

  return {
    plugins: [react()],
    server: { headers: { 'Content-Security-Policy': csp } },
    preview: { headers: { 'Content-Security-Policy': csp } },
  };
});
