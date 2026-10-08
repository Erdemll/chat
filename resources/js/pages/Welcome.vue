<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { chat, login } from '@/routes';
import BrandLogo from '@/components/BrandLogo.vue';
import AppIcon from '@/components/AppIcon.vue';
import ThemeSwitcher from '@/components/ThemeSwitcher.vue';
const page = usePage();
</script>
<template>
    <main
        class="flex min-h-dvh items-center justify-center bg-canvas p-5 text-ink"
    >
        <Head title="Çalışan iletişimi" />
        <section
            class="ui-card relative w-full max-w-xl overflow-hidden p-8 sm:p-12"
        >
            <div class="flex items-center justify-between gap-4">
                <BrandLogo /><ThemeSwitcher />
            </div>
            <div class="ui-accent mt-8 mb-5" />
            <p class="text-xs font-semibold tracking-widest text-brand-700">
                ÇALIŞAN İLETİŞİMİ
            </p>
            <h1
                class="mt-4 text-3xl leading-tight font-semibold tracking-tight text-brand-900 sm:text-4xl"
            >
                Ekibinizle aynı kanalda.
            </h1>
            <p class="mt-4 leading-relaxed text-muted">
                Şirket içi iletişim için güvenli, sade bir ortak sohbet alanı.
                Hesabınıza giriş yaparak Genel kanalına katılın.
            </p>
            <Link
                :href="page.props.auth.user ? chat() : login()"
                class="ui-button-primary mt-8 px-5 py-3"
                >{{ page.props.auth.user ? 'Sohbete git' : 'Giriş yap' }}
                <AppIcon name="arrow" class="size-4"
            /></Link>
            <p class="mt-6 text-xs text-muted">
                Hesaplar şirket yöneticisi tarafından oluşturulur.
            </p>
        </section>
    </main>
</template>
