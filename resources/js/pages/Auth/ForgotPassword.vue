<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthShell from '@/components/AuthShell.vue';
import FormField from '@/components/FormField.vue';
import { email } from '@/routes/password';
const form = useForm({ email: '' });
</script>
<template>
    <AuthShell
        title="Şifrenizi yenileyin"
        description="Hesabınızın e-posta adresine güvenli bir bağlantı gönderelim."
    >
        <form class="grid gap-4" @submit.prevent="form.post(email.url())">
            <FormField
                id="email"
                v-model="form.email"
                label="E-posta"
                type="email"
                autocomplete="email"
                :error="form.errors.email"
            />
            <button
                :disabled="form.processing"
                class="rounded-lg bg-teal-700 px-4 py-3 text-white disabled:opacity-50"
            >
                {{
                    form.processing
                        ? 'Gönderiliyor…'
                        : 'Şifre bağlantısı gönder'
                }}
            </button>
        </form>
    </AuthShell>
</template>
