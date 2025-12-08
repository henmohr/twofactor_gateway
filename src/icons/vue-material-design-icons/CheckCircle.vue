<!--
  - SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		role="img"
		aria-hidden="true"
		class="mdi-icon">
		<path d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M10,16.5L5.5,12L6.91,10.58L10,13.67L17.09,6.58L18.5,8L10,16.5Z" />
	</svg>
</template>

<script lang="ts">
import { defineComponent } from 'vue'

export default defineComponent({
	name: 'CheckCircle',
})
</script>

<style scoped>
.mdi-icon {
	width: 1.5rem;
	height: 1.5rem;
	fill: currentColor;
}
</style>
