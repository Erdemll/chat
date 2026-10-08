<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { login } from '@/routes';
import BrandLogo from '@/components/BrandLogo.vue';
import AppIcon from '@/components/AppIcon.vue';
import ThemeSwitcher from '@/components/ThemeSwitcher.vue';
defineProps<{ title: string; description: string }>();
const page = usePage();
</script>
<template>
    <main
        class="flex min-h-dvh items-center justify-center bg-canvas p-4 py-8 text-ink sm:p-8"
    >
        <Head :title="title" />
        <section
            class="ui-card grid w-full max-w-md overflow-hidden lg:max-w-4xl lg:grid-cols-2"
        >
            <div
                class="hidden flex-col justify-between gap-10 border-r border-line bg-linear-to-br from-brand-50 via-surface to-rose-50 p-9 lg:flex dark:bg-brand-50 dark:bg-none"
            >
                <BrandLogo />
                <div>
                    <div class="ui-accent mb-6" />
                    <p
                        class="text-xs font-semibold tracking-widest text-brand-700"
                    >
                        ÇALIŞAN İLETİŞİMİ
                    </p>
                    <h2
                        class="mt-4 text-3xl leading-tight font-semibold tracking-tight text-brand-900"
                    >
                        Tek kanal,<br />tüm ekip.
                    </h2>
                    <p class="mt-4 text-sm leading-7 text-muted">
                        Mesajlar, bahsetmeler ve okunma bilgileri şirketinizin
                        ortak iletişim alanında bir araya gelir.
                    </p>
                </div>
                <p
                    class="flex items-start gap-3 text-xs leading-relaxed text-muted"
                >
                    <AppIcon name="shield" class="size-5 text-brand-700" />
                    Hesaplar şirket yöneticisi tarafından oluşturulur.
                </p>
            </div>
            <div class="p-6 sm:p-8 lg:p-9">
                <div
                    class="mb-7 flex items-center justify-between gap-4 lg:justify-end"
                >
                    <BrandLogo class="lg:hidden" /><ThemeSwitcher />
                </div>
                <h1 class="ui-page-title">{{ title }}</h1>
                <p class="mt-2 mb-6 text-sm leading-relaxed text-muted">
                    {{ description }}
                </p>
                <p
                    v-if="page.props.flash.status"
                    role="status"
                    class="mb-4 rounded-xl bg-brand-50 p-4 text-sm text-brand-900"
                >
                    {{ page.props.flash.status }}
                </p>
                <slot />
                <Link
                    v-if="page.component !== 'Auth/Login'"
                    :href="login()"
                    class="mt-6 block text-center text-sm font-medium text-brand-700 hover:underline"
                    >Giriş ekranı</Link
                >
                <p
                    v-else
                    class="mt-6 text-center text-xs leading-relaxed text-muted"
                >
                    TEPENET · Şirket içi iletişim
                </p>
            </div>
        </section>
    </main>
</template>
