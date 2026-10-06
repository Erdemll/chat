<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, create } from '@/routes/admin/users';
import { chat } from '@/routes';
defineProps<{ counts: { users: number; active: number; messages: number } }>();
</script>
<template>
    <AppLayout>
        <Head title="Yönetim paneli" />
        <div class="overflow-y-auto p-5 sm:p-8">
            <div class="mx-auto grid max-w-5xl gap-6">
                <div>
                    <p class="text-sm text-teal-700">Yönetim</p>
                    <h1 class="mt-1 text-2xl font-semibold">
                        Çalışan iletişimi
                    </h1>
                    <p class="mt-2 text-slate-500">
                        Hesapları yönetin ve ekibinizle iletişimde kalın.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div
                        v-for="(label, key) in {
                            users: 'Toplam kullanıcı',
                            active: 'Aktif kullanıcı',
                            messages: 'Mesaj',
                        }"
                        :key="key"
                        class="rounded-xl border border-slate-200 bg-white p-5"
                    >
                        <p class="text-sm text-slate-500">{{ label }}</p>
                        <p class="mt-3 text-3xl font-semibold">
                            {{ counts[key] }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-3">
                    <Link
                        :href="create()"
                        class="rounded-lg bg-teal-700 px-4 py-3 text-white"
                        >Yeni çalışan</Link
                    ><Link
                        :href="index()"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-3"
                        >Kullanıcılar</Link
                    ><Link
                        :href="chat()"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-3"
                        >Genel sohbet</Link
                    >
                </div>
            </div>
        </div>
    </AppLayout>
</template>
