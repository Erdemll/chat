<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import AuthShell from '@/components/AuthShell.vue';
import FormField from '@/components/FormField.vue';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
const form = useForm({ email: '', password: '', remember: false });
function submit() {
    form.post(store.url(), { onFinish: () => form.reset('password') });
}
</script>
<template>
    <AuthShell
        title="Hoş geldiniz"
        description="Şirket hesabınızla ortak sohbet kanalına katılın."
    >
        <form class="grid gap-4" @submit.prevent="submit">
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
                label="Şifre"
                type="password"
                autocomplete="current-password"
                :error="form.errors.password"
            />
            <div class="flex items-center justify-between gap-2 text-sm">
                <label class="flex items-center gap-2"
                    ><input v-model="form.remember" type="checkbox" /> Beni
                    hatırla</label
                >
                <Link :href="request()" class="text-teal-700"
                    >Şifremi unuttum</Link
                >
            </div>
            <button
                :disabled="form.processing"
                class="rounded-lg bg-teal-700 px-4 py-3 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? 'Giriş yapılıyor…' : 'Giriş yap' }}
            </button>
        </form>
    </AuthShell>
</template>
