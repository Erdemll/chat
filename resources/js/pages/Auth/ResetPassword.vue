<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import AuthShell from '@/components/AuthShell.vue';
import FormField from '@/components/FormField.vue';
import { useAuthFormFeedback } from '@/lib/auth-form-feedback';
import { update } from '@/routes/password';
defineProps<{ token: string; email: string }>();
const { requestError, clearRequestError, onHttpException, onNetworkError } =
    useAuthFormFeedback();
</script>
<template>
    <AuthShell
        title="Şifrenizi belirleyin"
        description="En az 12 karakter, büyük ve küçük harf, sayı ve sembol kullanın."
    >
        <Form
            v-bind="update.form()"
            v-slot="{ errors, processing }"
            class="grid gap-4"
            :reset-on-error="['password', 'password_confirmation']"
            :reset-on-success="['password', 'password_confirmation']"
            :on-start="clearRequestError"
            :on-http-exception="onHttpException"
            :on-network-error="onNetworkError"
        >
            <input type="hidden" name="token" :value="token" />
            <FormField
                id="email"
                :model-value="email"
                label="E-posta"
                type="email"
                autocomplete="username"
                :error="errors.email"
            />
            <FormField
                id="password"
                label="Yeni şifre"
                type="password"
                autocomplete="new-password"
                :error="errors.password"
            />
            <FormField
                id="confirmation"
                name="password_confirmation"
                label="Yeni şifre (tekrar)"
                type="password"
                autocomplete="new-password"
                :error="errors.password_confirmation"
            />
            <p v-if="errors.token" role="alert" class="text-sm text-red-700">
                {{ errors.token }}
            </p>
            <p v-if="requestError" role="alert" class="text-sm text-red-700">
                {{ requestError }}
            </p>
            <button
                type="submit"
                :disabled="processing"
                class="rounded-lg bg-teal-700 px-4 py-3 text-white disabled:opacity-50"
            >
                {{ processing ? 'Kaydediliyor…' : 'Şifremi kaydet' }}
            </button>
        </Form>
    </AuthShell>
</template>
