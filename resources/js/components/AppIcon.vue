<script setup lang="ts">
import { cn } from '@/lib/utils';
withDefaults(
    defineProps<{
        name:
            | 'chat'
            | 'dashboard'
            | 'users'
            | 'plus'
            | 'arrow'
            | 'logout'
            | 'menu'
            | 'close'
            | 'send'
            | 'shield'
            | 'sun'
            | 'moon'
            | 'monitor'
            | 'check';
    }>(),
    { name: 'chat' },
);
const paths = {
    chat: [
        'M21 11.5a8.5 8.5 0 0 1-8.5 8.5H3l2.5-4A8.5 8.5 0 1 1 21 11.5Z',
        'M8 10h8M8 14h5',
    ],
    dashboard: ['M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z'],
    users: [
        'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2',
        'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
        'M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    ],
    plus: ['M12 5v14M5 12h14'],
    arrow: ['M5 12h14M13 6l6 6-6 6'],
    logout: ['M9 21H4V3h5M9 12h12M17 8l4 4-4 4'],
    menu: ['M4 6h16M4 12h16M4 18h16'],
    close: ['m6 6 12 12M6 18 18 6'],
    send: ['m22 2-7 20-4-9-9-4Z', 'M22 2 11 13'],
    shield: ['M12 3 3 7v5c0 5 9 9 9 9s9-4 9-9V7Z', 'm8 12 3 3 5-6'],
    sun: [
        'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z',
        'M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5 19 19M5 19l1.5-1.5M17.5 6.5 19 5',
    ],
    moon: ['M21 12.8A9 9 0 0 1 11.2 3a9 9 0 1 0 9.8 9.8Z'],
    monitor: ['M3 3h18v13H3zM12 16v5M8 21h8'],
    check: ['m5 12 4 4L19 6'],
};
</script>
<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.7"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        :class="
            cn(
                'size-5 shrink-0',
                typeof $attrs.class === 'string' ? $attrs.class : undefined,
            )
        "
    >
        <path v-for="path in paths[name]" :key="path" :d="path" />
    </svg>
</template>
