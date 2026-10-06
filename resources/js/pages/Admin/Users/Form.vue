<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import FormField from '@/components/FormField.vue';
import { index, store, update } from '@/routes/admin/users';
const props = defineProps<{
    employee?: {
        id: number;
        name: string;
        email: string;
        role: 'admin' | 'employee';
        is_active: boolean;
    };
}>();
const form = useForm({
    name: props.employee?.name ?? '',
    email: props.employee?.email ?? '',
    role: props.employee?.role ?? 'employee',
    is_active: props.employee?.is_active ?? true,
});
function submit() {
    if (props.employee) {
        form.put(update.url(props.employee.id));
    } else {
        form.post(store.url());
    }
}
</script>
<template>
    <AppLayout>
        <Head :title="employee ? 'Kullanıcı düzenle' : 'Yeni kullanıcı'" />
        <div class="overflow-y-auto p-5 sm:p-8">
            <form
                class="mx-auto grid max-w-xl gap-5 rounded-xl border border-slate-200 bg-white p-6"
                @submit.prevent="submit"
            >
                <h1 class="text-xl font-semibold">
                    {{ employee ? 'Kullanıcı düzenle' : 'Yeni kullanıcı' }}
                </h1>
                <p class="text-sm text-slate-500">
                    Aktif yeni kullanıcıya şifre oluşturma bağlantısı
                    gönderilir. E-posta değiştiğinde mevcut erişim iptal edilir
                    ve yeni davet gönderilir.
                </p>
                <FormField
                    id="name"
                    v-model="form.name"
                    label="İsim"
                    autocomplete="name"
                    :error="form.errors.name"
                />
                <FormField
                    id="email"
                    v-model="form.email"
                    label="E-posta"
                    type="email"
                    autocomplete="email"
                    :error="form.errors.email"
                />
                <label class="grid gap-2 text-sm font-medium"
                    >Rol<select
                        v-model="form.role"
                        class="rounded-lg border border-slate-300 px-3 py-3"
                    >
                        <option value="employee">Çalışan</option>
                        <option value="admin">Admin</option></select
                    ><span v-if="form.errors.role" class="text-red-700">{{
                        form.errors.role
                    }}</span></label
                >
                <label class="flex items-center gap-2 text-sm"
                    ><input v-model="form.is_active" type="checkbox" /> Hesap
                    aktif</label
                >
                <p
                    v-if="form.errors.is_active"
                    role="alert"
                    class="text-sm text-red-700"
                >
                    {{ form.errors.is_active }}
                </p>
                <div class="flex gap-3">
                    <button
                        :disabled="form.processing"
                        class="rounded-lg bg-teal-700 px-5 py-3 text-white disabled:opacity-50"
                    >
                        {{
                            form.processing ? 'Kaydediliyor…' : 'Kaydet'
                        }}</button
                    ><Link
                        :href="index()"
                        class="rounded-lg px-4 py-3 text-slate-600"
                        >Vazgeç</Link
                    >
                </div>
            </form>
        </div>
    </AppLayout>
</template>
