<script setup lang="ts">
withDefaults(
    defineProps<{
        id: string;
        label: string;
        type?: string;
        error?: string;
        autocomplete?: string;
        required?: boolean;
    }>(),
    { type: 'text', required: true },
);
const model = defineModel<string>({ required: true });
</script>
<template>
    <div class="grid gap-1.5">
        <label :for="id" class="text-sm font-medium">{{ label }}</label>
        <input
            :id="id"
            v-model="model"
            :type="type"
            :autocomplete="autocomplete"
            :required="required"
            :aria-invalid="!!error"
            :aria-describedby="error ? `${id}-error` : undefined"
            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-600/15"
        />
        <p
            v-if="error"
            :id="`${id}-error`"
            role="alert"
            class="text-sm text-red-700"
        >
            {{ error }}
        </p>
    </div>
</template>
