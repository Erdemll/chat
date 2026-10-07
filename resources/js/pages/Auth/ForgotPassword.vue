<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import AuthShell from '@/components/AuthShell.vue';
import FormField from '@/components/FormField.vue';
import { useAuthFormFeedback } from '@/lib/auth-form-feedback';
import { email } from '@/routes/password';
const { requestError, clearRequestError, onHttpException, onNetworkError } =
    useAuthFormFeedback();
</script>
<template>
    <AuthShell
        title="Şifrenizi yenileyin"
        description="Hesabınızın e-posta adresine güvenli bir bağlantı gönderelim."
    >
        <Form
            v-bind="email.form()"
            v-slot="{ errors, processing }"
            class="grid gap-4"
            :on-start="clearRequestError"
            :on-http-exception="onHttpException"
            :on-network-error="onNetworkError"
        >
            <FormField
                id="email"
                label="E-posta"
                type="email"
                autocomplete="email"
                :error="errors.email"
            />
            <p v-if="requestError" role="alert" class="text-sm text-red-700">
                {{ requestError }}
            </p>
            <button
                type="submit"
                :disabled="processing"
                class="rounded-lg bg-teal-700 px-4 py-3 text-white disabled:opacity-50"
            >
                {{ processing ? 'Gönderiliyor…' : 'Şifre bağlantısı gönder' }}
            </button>
        </Form>
    </AuthShell>
</template>
