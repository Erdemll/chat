<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import AuthShell from '@/components/AuthShell.vue';
import FormField from '@/components/FormField.vue';
import { useAuthFormFeedback } from '@/lib/auth-form-feedback';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
const { requestError, clearRequestError, onHttpException, onNetworkError } =
    useAuthFormFeedback();
</script>
<template>
    <AuthShell
        title="Hoş geldiniz"
        description="Şirket hesabınızla ortak sohbet kanalına katılın."
    >
        <Form
            v-bind="store.form()"
            v-slot="{ errors, processing }"
            class="grid gap-4"
            :reset-on-error="['password']"
            :reset-on-success="['password']"
            :on-start="clearRequestError"
            :on-http-exception="onHttpException"
            :on-network-error="onNetworkError"
        >
            <FormField
                id="email"
                label="E-posta"
                type="email"
                autocomplete="username"
                :error="errors.email"
            />
            <FormField
                id="password"
                label="Şifre"
                type="password"
                autocomplete="current-password"
                :error="errors.password"
            />
            <div class="flex items-center justify-between gap-2 text-sm">
                <label class="flex items-center gap-2"
                    ><input name="remember" type="checkbox" value="1" /> Beni
                    hatırla</label
                >
                <Link :href="request()" class="text-teal-700"
                    >Şifremi unuttum</Link
                >
            </div>
            <p v-if="requestError" role="alert" class="text-sm text-red-700">
                {{ requestError }}
            </p>
            <button
                type="submit"
                :disabled="processing"
                class="rounded-lg bg-teal-700 px-4 py-3 font-medium text-white disabled:opacity-50"
            >
                {{ processing ? 'Giriş yapılıyor…' : 'Giriş yap' }}
            </button>
        </Form>
    </AuthShell>
</template>
