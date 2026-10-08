<script setup lang="ts">
import { cn } from '@/lib/utils';
</script>
<template>
    <img
        src="/tepenet-logo.png"
        alt="Tepenet"
        width="146"
        height="47"
        :class="
            cn(
                'h-auto w-[146px] object-contain dark:rounded-lg dark:bg-white dark:px-2 dark:py-1',
                typeof $attrs.class === 'string' ? $attrs.class : undefined,
            )
        "
    />
</template>
