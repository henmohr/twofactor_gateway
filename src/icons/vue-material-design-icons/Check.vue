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
                class="mdi-icon"
        >
                <path
                        d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"
                />
        </svg>
</template>

<script lang="ts">
import { defineComponent } from 'vue'

export default defineComponent({
        name: 'CheckIcon',
})
</script>

<style scoped>
.mdi-icon {
        width: 1.5rem;
        height: 1.5rem;
        fill: currentColor;
}
</style>
