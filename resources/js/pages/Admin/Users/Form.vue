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
        <div class="min-h-0 flex-1 overflow-y-auto p-5 sm:p-7">
            <div class="mx-auto w-full max-w-6xl">
                <h1 class="ui-page-title">
                    {{ employee ? 'Kullanıcı düzenle' : 'Yeni kullanıcı' }}
                </h1>
                <p class="ui-page-description">
                    {{
                        employee
                            ? 'Çalışanın hesap bilgilerini ve erişimini yönetin.'
                            : 'Çalışana davet bağlantısı göndererek hesap oluşturun.'
                    }}
                </p>
                <form
                    class="ui-card mt-6 grid w-full max-w-[600px] gap-5 p-6 sm:p-7"
                    @submit.prevent="submit"
                >
                    <p
                        class="rounded-xl bg-brand-50 p-4 text-xs leading-relaxed text-brand-900"
                    >
                        Aktif yeni kullanıcıya şifre oluşturma bağlantısı
                        gönderilir. E-posta değiştiğinde mevcut erişim iptal
                        edilir ve yeni davet gönderilir.
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
                        >Rol<select v-model="form.role" class="ui-input">
                            <option value="employee">Çalışan</option>
                            <option value="admin">Admin</option></select
                        ><span
                            v-if="form.errors.role"
                            class="text-red-700 dark:text-red-300"
                            >{{ form.errors.role }}</span
                        ></label
                    >
                    <label
                        class="flex items-center gap-3 rounded-xl border border-line p-4 text-sm"
                        ><input
                            v-model="form.is_active"
                            type="checkbox"
                            class="size-4 accent-brand-700"
                        />
                        Hesap aktif</label
                    >
                    <p
                        v-if="form.errors.is_active"
                        role="alert"
                        class="text-sm text-red-700 dark:text-red-300"
                    >
                        {{ form.errors.is_active }}
                    </p>
                    <div
                        class="flex flex-wrap justify-end gap-3 border-t border-line pt-5"
                    >
                        <Link :href="index()" class="ui-button-secondary"
                            >Vazgeç</Link
                        >
                        <button
                            :disabled="form.processing"
                            class="ui-button-primary px-5"
                        >
                            {{ form.processing ? 'Kaydediliyor…' : 'Kaydet' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
