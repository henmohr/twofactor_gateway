/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import path from 'path'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import eslint from 'vite-plugin-eslint'
import stylelint from 'vite-plugin-stylelint'

const isProduction = process.env.NODE_ENV === 'production'

export default defineConfig({
plugins: [vue(), eslint(), stylelint()],
resolve: {
alias: {
'vue-material-design-icons': path.join(__dirname, 'src', 'icons', 'vue-material-design-icons'),
},
},
build: {
minify: isProduction,
lib: {
entry: path.resolve(__dirname, 'src/main.ts'),
name: 'TwoFactorGateway',
formats: ['umd'],
fileName: (format) => `twofactor-gateway.${format}.js`,
},
rollupOptions: {
external: [
/^vite-plugin-node-polyfills/,
'@nextcloud/axios',
'@nextcloud/dialogs',
'@nextcloud/l10n',
'@nextcloud/router',
'@nextcloud/vue',
'vue',
],
output: {
globals: {
vue: 'Vue',
'@nextcloud/vue': 'NextcloudVue',
'@nextcloud/router': 'NextcloudRouter',
'@nextcloud/axios': 'NextcloudAxios',
'@nextcloud/dialogs': 'NextcloudDialogs',
'@nextcloud/l10n': 'NextcloudL10n',
},
},
},
},
})
