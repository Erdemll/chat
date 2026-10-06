<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { login } from '@/routes';
defineProps<{ title: string; description: string }>();
const page = usePage();
</script>
<template>
    <main
        class="flex min-h-dvh items-center justify-center bg-slate-50 p-5 text-slate-900"
    >
        <Head :title="title" />
        <section
            class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-7 shadow-sm"
        >
            <p class="mb-6 text-sm font-semibold tracking-wide text-teal-700">
                TEPENET · Çalışan iletişimi
            </p>
            <h1 class="text-2xl font-semibold">{{ title }}</h1>
            <p class="mt-2 mb-6 text-sm text-slate-500">{{ description }}</p>
            <p
                v-if="page.props.flash.status"
                role="status"
                class="mb-4 rounded-lg bg-teal-50 p-3 text-sm text-teal-800"
            >
                {{ page.props.flash.status }}
            </p>
            <slot />
            <Link
                :href="login()"
                class="mt-6 block text-center text-sm text-teal-700"
                >Giriş ekranı</Link
            >
        </section>
    </main>
</template>
