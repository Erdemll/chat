<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { useAppearance, type Appearance } from '@/lib/appearance';

const { appearance, setAppearance } = useAppearance();
const mounted = ref(false);
const visibleAppearance = computed(() =>
    mounted.value ? appearance.value : 'system',
);
const panel = ref<HTMLDetailsElement>();
const trigger = ref<HTMLElement>();
const options = [
    { value: 'system', label: 'Sistem', icon: 'monitor' },
    { value: 'light', label: 'Açık', icon: 'sun' },
    { value: 'dark', label: 'Koyu', icon: 'moon' },
] as const;

function choose(value: Appearance) {
    setAppearance(value);
    if (panel.value) panel.value.open = false;
    trigger.value?.focus();
}

function outside(event: PointerEvent) {
    if (
        event.target instanceof Node &&
        !panel.value?.contains(event.target) &&
        panel.value
    )
        panel.value.open = false;
}

function escape(event: KeyboardEvent) {
    if (event.key !== 'Escape' || !panel.value?.open) return;
    event.preventDefault();
    panel.value.open = false;
    trigger.value?.focus();
}

onMounted(() => {
    mounted.value = true;
    document.addEventListener('pointerdown', outside);
});
onBeforeUnmount(() => document.removeEventListener('pointerdown', outside));
</script>
<template>
    <details ref="panel" class="relative shrink-0" @keydown="escape">
        <summary
            ref="trigger"
            aria-label="Görünüm temasını seç"
            title="Tema seçimi"
            class="grid size-10 cursor-pointer list-none place-items-center rounded-xl border border-line bg-surface text-brand-900 hover:bg-brand-50 [&::-webkit-details-marker]:hidden"
        >
            <AppIcon
                :name="
                    visibleAppearance === 'system'
                        ? 'monitor'
                        : visibleAppearance === 'dark'
                          ? 'moon'
                          : 'sun'
                "
            />
        </summary>
        <div
            role="group"
            aria-label="Görünüm teması"
            class="ui-card absolute top-full right-0 z-30 mt-2 w-40 p-1.5"
        >
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                :aria-pressed="visibleAppearance === option.value"
                class="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-brand-50"
                :class="
                    visibleAppearance === option.value
                        ? 'bg-brand-50 font-semibold text-brand-700'
                        : 'text-ink'
                "
                @click="choose(option.value)"
            >
                <AppIcon :name="option.icon" class="size-4" />
                {{ option.label }}
                <AppIcon
                    v-if="visibleAppearance === option.value"
                    name="check"
                    class="ml-auto size-4"
                />
            </button>
        </div>
    </details>
</template>
