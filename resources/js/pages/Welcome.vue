<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { chat, login } from '@/routes';
const page = usePage();
</script>
<template>
    <main
        class="flex min-h-dvh items-center justify-center bg-slate-50 px-6 text-slate-900"
    >
        <Head title="Çalışan iletişimi" />
        <section
            class="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-8 shadow-sm sm:p-12"
        >
            <p class="text-sm font-semibold tracking-wide text-teal-700">
                TEPENET
            </p>
            <h1 class="mt-5 text-3xl leading-tight font-semibold">
                Ekibinizle aynı kanalda.
            </h1>
            <p class="mt-4 text-slate-500">
                Şirket içi iletişim için güvenli, sade bir ortak sohbet alanı.
                Hesabınıza giriş yaparak Genel kanalına katılın.
            </p>
            <Link
                :href="page.props.auth.user ? chat() : login()"
                class="mt-8 inline-flex rounded-lg bg-teal-700 px-5 py-3 font-medium text-white"
                >{{
                    page.props.auth.user ? 'Sohbete git' : 'Giriş yap'
                }}
                →</Link
            >
            <p class="mt-6 text-xs text-slate-400">
                Hesaplar şirket yöneticisi tarafından oluşturulur.
            </p>
        </section>
    </main>
</template>
