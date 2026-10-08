<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AppIcon from '@/components/AppIcon.vue';
import UserAvatar from '@/components/UserAvatar.vue';
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
    last_login_at: string | null;
    last_active_at: string | null;
    last_message_read_at: string | null;
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
    return new Date(value).toLocaleDateString('tr-TR', {
        timeZone: 'Europe/Istanbul',
    });
}
function activityDate(value: string | null) {
    return value
        ? new Date(value).toLocaleString('tr-TR', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: 'Europe/Istanbul',
          })
        : 'Kayıt yok';
}
</script>
<template>
    <AppLayout>
        <Head title="Kullanıcılar" />
        <div class="min-h-0 flex-1 overflow-y-auto p-5 sm:p-7">
            <div class="mx-auto grid w-full max-w-6xl gap-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="ui-page-title">Kullanıcılar</h1>
                        <p class="ui-page-description">
                            Çalışan hesapları ve davet durumları
                        </p>
                    </div>
                    <Link :href="create()" class="ui-button-primary"
                        ><AppIcon name="plus" class="size-4" /> Yeni
                        kullanıcı</Link
                    >
                </div>
                <p
                    class="rounded-xl border border-line bg-surface/60 px-4 py-3 text-xs leading-relaxed text-muted"
                >
                    Son aktif bilgisi yaklaşık iki dakikalık aralıklarla
                    güncellenir. Son mesaj görüntüleme ilk okuma kayıtlarını
                    esas alır; tekrar görüntüleme bu zamanı değiştirmez.
                    Tarihler Türkiye saatiyle gösterilir.
                </p>
                <article
                    v-for="user in users.data"
                    :key="user.id"
                    class="ui-card min-w-0 p-5 sm:p-6"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-4"
                    >
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <UserAvatar
                                :name="user.name"
                                :user-id="user.id"
                                class="size-11"
                            />
                            <div class="min-w-0">
                                <h2
                                    class="text-sm font-semibold [overflow-wrap:anywhere]"
                                >
                                    {{ user.name }}
                                </h2>
                                <p class="mt-1 text-sm break-all text-muted">
                                    {{ user.email }}
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="rounded-full px-3 py-1 text-xs font-medium"
                                :class="
                                    user.is_active
                                        ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                                        : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
                                "
                                >{{ user.is_active ? 'Aktif' : 'Pasif' }}</span
                            >
                            <span
                                class="rounded-full bg-brand-50 px-3 py-1 text-xs font-medium text-brand-700"
                                >{{
                                    user.role === 'admin' ? 'Admin' : 'Çalışan'
                                }}</span
                            >
                        </div>
                    </div>
                    <dl
                        class="mt-5 grid gap-4 rounded-xl bg-canvas/70 p-4 text-xs sm:grid-cols-3"
                    >
                        <div>
                            <dt class="text-muted">Son giriş</dt>
                            <dd class="mt-1 text-ink">
                                <time
                                    v-if="user.last_login_at"
                                    :datetime="user.last_login_at"
                                    >{{
                                        activityDate(user.last_login_at)
                                    }}</time
                                ><span v-else>Kayıt yok</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted">Son aktif</dt>
                            <dd class="mt-1 text-ink">
                                <time
                                    v-if="user.last_active_at"
                                    :datetime="user.last_active_at"
                                    >{{
                                        activityDate(user.last_active_at)
                                    }}</time
                                ><span v-else>Kayıt yok</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted">Son mesaj görüntüleme</dt>
                            <dd class="mt-1 text-ink">
                                <time
                                    v-if="user.last_message_read_at"
                                    :datetime="user.last_message_read_at"
                                    >{{
                                        activityDate(user.last_message_read_at)
                                    }}</time
                                ><span v-else>Kayıt yok</span>
                            </dd>
                        </div>
                    </dl>
                    <div
                        class="mt-4 flex flex-wrap items-end justify-between gap-4"
                    >
                        <div class="grid gap-1">
                            <p class="text-xs text-muted">
                                Kayıt tarihi · {{ date(user.created_at) }}
                            </p>
                            <p
                                :class="
                                    user.invitation_failed_at
                                        ? 'text-red-700 dark:text-red-300'
                                        : 'text-brand-700'
                                "
                                class="text-xs font-medium"
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
                                class="ui-button-secondary px-3 text-xs"
                                >Düzenle</Link
                            ><button
                                :disabled="!user.is_active || resend.processing"
                                class="ui-button-secondary px-3 text-xs"
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
                </article>
                <div
                    v-if="!users.data.length"
                    class="ui-card grid justify-items-center gap-3 p-10 text-center"
                >
                    <AppIcon name="users" class="size-8 text-brand-500" />
                    <p class="font-medium">Henüz kullanıcı yok.</p>
                    <p class="text-sm text-muted">
                        Yeni kullanıcı ekleyerek ekibinizi davet edin.
                    </p>
                </div>
                <nav
                    v-if="users.prev_page_url || users.next_page_url"
                    aria-label="Kullanıcı sayfaları"
                    class="flex justify-between gap-4 text-sm"
                >
                    <Link
                        v-if="users.prev_page_url"
                        :href="users.prev_page_url"
                        class="ui-button-secondary"
                        >← Önceki</Link
                    ><Link
                        v-if="users.next_page_url"
                        :href="users.next_page_url"
                        class="ui-button-secondary ml-auto"
                        >Sonraki →</Link
                    >
                </nav>
            </div>
        </div>
    </AppLayout>
</template>
