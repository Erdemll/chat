<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, edit, invitation } from '@/routes/admin/users';
type Employee = {
    id: number;
    name: string;
    email: string;
    role: string;
    is_active: boolean;
    created_at: string;
    password_set_at: string | null;
    invited_at: string | null;
    invitation_failed_at: string | null;
};
defineProps<{
    users: {
        data: Employee[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
const resend = useForm({});
function status(user: Employee) {
    if (user.invitation_failed_at) return 'Davet gönderilemedi';
    if (user.password_set_at) return 'Şifre oluşturuldu';
    return user.invited_at ? 'Davet gönderildi' : 'Davet bekliyor';
}
function date(value: string) {
    return new Date(value).toLocaleDateString('tr-TR');
}
</script>
<template>
    <AppLayout>
        <Head title="Kullanıcılar" />
        <div class="overflow-y-auto p-5 sm:p-8">
            <div class="mx-auto grid max-w-6xl gap-5">
                <div class="flex items-center justify-between gap-3">
                    <h1 class="text-2xl font-semibold">Kullanıcılar</h1>
                    <Link
                        :href="create()"
                        class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm text-white"
                        >Yeni kullanıcı</Link
                    >
                </div>
                <div
                    v-for="user in users.data"
                    :key="user.id"
                    class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-5"
                >
                    <div class="min-w-0">
                        <h2 class="font-semibold">{{ user.name }}</h2>
                        <p class="text-sm break-all text-slate-500">
                            {{ user.email }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ user.role === 'admin' ? 'Admin' : 'Çalışan' }} ·
                            {{ user.is_active ? 'Aktif' : 'Pasif' }} ·
                            {{ date(user.created_at) }}
                        </p>
                        <p
                            :class="
                                user.invitation_failed_at
                                    ? 'text-red-700'
                                    : 'text-teal-700'
                            "
                            class="mt-2 text-sm"
                        >
                            {{ status(user)
                            }}<span v-if="user.password_set_at">
                                · {{ date(user.password_set_at) }}</span
                            >
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link
                            :href="edit(user.id)"
                            class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                            >Düzenle</Link
                        ><button
                            :disabled="!user.is_active || resend.processing"
                            class="rounded-lg border border-slate-200 px-3 py-2 text-sm disabled:opacity-40"
                            @click="
                                resend.post(invitation.url(user.id), {
                                    preserveScroll: true,
                                })
                            "
                        >
                            {{
                                user.password_set_at
                                    ? 'Şifre Bağlantısı Gönder'
                                    : 'Davet Mailini Yeniden Gönder'
                            }}
                        </button>
                    </div>
                </div>
                <p v-if="!users.data.length" class="text-slate-500">
                    Henüz kullanıcı yok.
                </p>
                <div class="flex gap-4 text-sm">
                    <Link v-if="users.prev_page_url" :href="users.prev_page_url"
                        >← Önceki</Link
                    ><Link
                        v-if="users.next_page_url"
                        :href="users.next_page_url"
                        >Sonraki →</Link
                    >
                </div>
            </div>
        </div>
    </AppLayout>
</template>
