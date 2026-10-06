<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthShell from '@/components/AuthShell.vue';
import FormField from '@/components/FormField.vue';
import { update } from '@/routes/password';
const props = defineProps<{ token: string; email: string }>();
const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});
</script>
<template>
    <AuthShell
        title="Şifrenizi belirleyin"
        description="En az 12 karakter, büyük ve küçük harf, sayı ve sembol kullanın."
    >
        <form
            class="grid gap-4"
            @submit.prevent="
                form.post(update.url(), {
                    onFinish: () =>
                        form.reset('password', 'password_confirmation'),
                })
            "
        >
            <FormField
                id="email"
                v-model="form.email"
                label="E-posta"
                type="email"
                autocomplete="username"
                :error="form.errors.email"
            />
            <FormField
                id="password"
                v-model="form.password"
                label="Yeni şifre"
                type="password"
                autocomplete="new-password"
                :error="form.errors.password"
            />
            <FormField
                id="confirmation"
                v-model="form.password_confirmation"
                label="Yeni şifre (tekrar)"
                type="password"
                autocomplete="new-password"
                :error="form.errors.password_confirmation"
            />
            <p
                v-if="form.errors.token"
                role="alert"
                class="text-sm text-red-700"
            >
                {{ form.errors.token }}
            </p>
            <button
                :disabled="form.processing"
                class="rounded-lg bg-teal-700 px-4 py-3 text-white disabled:opacity-50"
            >
                {{ form.processing ? 'Kaydediliyor…' : 'Şifremi kaydet' }}
            </button>
        </form>
    </AuthShell>
</template>
