import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
var sourcePath = new URL('./src', import.meta.url).pathname.replace(/^\/([A-Za-z]:\/)/, '$1');
export default defineConfig(function (_a) {
    var mode = _a.mode;
    return ({
        plugins: [react()],
        resolve: {
            alias: {
                '@': sourcePath,
            },
        },
        server: {
            proxy: {
                '/api': {
                    target: loadEnv(mode, '.', 'SQLUIT_').SQLUIT_API_TARGET || 'http://127.0.0.1:8000',
                    changeOrigin: true
                }
            }
        }
    });
});
