<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';
const props = defineProps<{ name: string; userId: number }>();
const initials = computed(() =>
    props.name
        .trim()
        .split(/\s+/u)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => Array.from(part)[0])
        .join('')
        .toLocaleUpperCase('tr-TR'),
);
const colors = [
    'bg-brand-50 text-brand-700',
    'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-200',
    'bg-orange-50 text-orange-800 dark:bg-orange-950 dark:text-orange-200',
    'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
];
</script>
<template>
    <span
        aria-hidden="true"
        :class="
            cn(
                'grid size-10 shrink-0 place-items-center rounded-xl text-xs font-semibold',
                colors[Math.abs(userId) % colors.length],
                typeof $attrs.class === 'string' ? $attrs.class : undefined,
            )
        "
        >{{ initials }}</span
    >
</template>
