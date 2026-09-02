// @ts-check
import { defineConfig } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';

// https://astro.build/config
export default defineConfig({
	base: process.env.NODE_ENV === 'production' ? '/Kredia/dist' : '/',
	vite: {
		plugins: [tailwindcss()],
		server: {
			proxy: {
				'/api': {
					target: 'http://localhost',
					changeOrigin: true,
					rewrite: (path) => `/Kredia/public${path}`,
				},
			},
		},
	},
});
