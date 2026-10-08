<script setup lang="ts">
withDefaults(
    defineProps<{
        id: string;
        name?: string;
        label: string;
        type?: string;
        error?: string;
        autocomplete?: string;
        required?: boolean;
    }>(),
    { type: 'text', required: true },
);
const model = defineModel<string>({ default: '' });
</script>
<template>
    <div class="grid gap-1.5">
        <label :for="id" class="text-sm font-medium text-ink">{{
            label
        }}</label>
        <input
            :id="id"
            :name="name ?? id"
            v-model="model"
            :type="type"
            :autocomplete="autocomplete"
            :required="required"
            :aria-invalid="!!error"
            :aria-describedby="error ? `${id}-error` : undefined"
            class="ui-input"
        />
        <p
            v-if="error"
            :id="`${id}-error`"
            role="alert"
            class="text-sm text-red-700 dark:text-red-300"
        >
            {{ error }}
        </p>
    </div>
</template>
