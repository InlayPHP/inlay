<script setup lang="ts">
defineProps<{ name: string; className?: string }>()

// These paths are from the open-source Lucide set (ISC). The registry can
// replace any icon; this keeps unregistered navigation icons useful by default.
const paths: Record<string, string[]> = {
    home: ['M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8', 'M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'],
    users: ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 3.128a4 4 0 0 1 0 7.744M22 21v-2a4 4 0 0 0-3-3.87', 'circle,9,7,4'],
    'user-circle': ['circle,12,12,10', 'circle,12,10,3', 'M7 20.662V19a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v1.662'],
    settings: ['M9.671 4.136a2.34 2.34 0 0 1 4.659 0a2.34 2.34 0 0 0 3.319 1.915a2.34 2.34 0 0 1 2.33 4.033a2.34 2.34 0 0 0 0 3.831a2.34 2.34 0 0 1-2.33 4.033a2.34 2.34 0 0 0-3.319 1.915a2.34 2.34 0 0 1-4.659 0a2.34 2.34 0 0 0-3.32-1.915a2.34 2.34 0 0 1-2.33-4.033a2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915', 'circle,12,12,3'],
    folder: ['M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z'],
    image: ['rect,3,3,18,18,2', 'circle,9,9,2', 'm21 15l-3.086-3.086a2 2 0 0 0-2.828 0L6 21'],
    photo: ['rect,3,3,18,18,2', 'circle,9,9,2', 'm21 15l-3.086-3.086a2 2 0 0 0-2.828 0L6 21'],
    'table-2': ['M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18'],
    table: ['M4 5h16v14H4zM4 10h16M10 5v14M16 5v14'],
    shield: ['M12 3 20 6v6c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6Z'],
    search: ['circle,11,11,8', 'm21 21-4.3-4.3'],
    'chevron-left': ['m15 18-6-6 6-6'],
    'chevron-right': ['m9 18 6-6-6-6'],
    default: ['circle,12,12,9', 'M12 8v4l3 2'],
}
</script>

<template>
    <svg
        :class="className ?? 'size-5'"
        :data-icon="name"
        aria-hidden="true"
        fill="none"
        focusable="false"
        role="img"
        stroke="currentColor"
        stroke-linecap="round"
        stroke-linejoin="round"
        stroke-width="1.8"
        viewBox="0 0 24 24"
    >
        <template v-for="(path, index) in paths[name] ?? paths.default" :key="index">
            <circle v-if="path.startsWith('circle,')" :cx="path.split(',')[1]" :cy="path.split(',')[2]" :r="path.split(',')[3]" />
            <rect v-else-if="path.startsWith('rect,')" :height="path.split(',')[4]" :rx="path.split(',')[5]" :width="path.split(',')[3]" :x="path.split(',')[1]" :y="path.split(',')[2]" />
            <path v-else :d="path" />
        </template>
    </svg>
</template>
