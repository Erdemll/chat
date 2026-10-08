<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AppIcon from '@/components/AppIcon.vue';
import { index, create } from '@/routes/admin/users';
import { chat } from '@/routes';
defineProps<{ counts: { users: number; active: number; messages: number } }>();
</script>
<template>
    <AppLayout>
        <Head title="Yönetim paneli" />
        <div class="min-h-0 flex-1 overflow-y-auto p-5 sm:p-7">
            <div class="mx-auto grid w-full max-w-6xl gap-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="ui-page-title">Yönetim paneli</h1>
                        <p class="ui-page-description">
                            Çalışan iletişimine genel bakış
                        </p>
                    </div>
                    <Link :href="index()" class="ui-button-secondary"
                        >Kullanıcılar <AppIcon name="arrow" class="size-4"
                    /></Link>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <section
                        v-for="(label, key) in {
                            users: 'Toplam kullanıcı',
                            active: 'Aktif kullanıcı',
                            messages: 'Silinmemiş mesaj',
                        }"
                        :key="key"
                        class="ui-card p-6"
                    >
                        <p
                            class="text-3xl font-semibold tracking-tight text-brand-900"
                        >
                            {{ counts[key].toLocaleString('tr-TR') }}
                        </p>
                        <h2 class="mt-2 text-sm font-medium text-muted">
                            {{ label }}
                        </h2>
                    </section>
                </div>
                <section class="ui-card p-6 sm:p-7">
                    <div class="ui-accent mb-5" />
                    <h2 class="text-lg font-semibold">Hızlı erişim</h2>
                    <p class="ui-page-description">
                        Yeni çalışan ekleyebilir veya Genel sohbet ekranına
                        dönebilirsiniz.
                    </p>
                    <div class="mt-5 flex flex-wrap gap-3">
                        <Link :href="create()" class="ui-button-primary"
                            ><AppIcon name="plus" class="size-4" /> Yeni
                            çalışan</Link
                        >
                        <Link :href="chat()" class="ui-button-secondary"
                            >Genel sohbete git
                            <AppIcon name="arrow" class="size-4"
                        /></Link>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
