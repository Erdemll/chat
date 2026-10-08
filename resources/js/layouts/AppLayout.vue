<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import AppNavigation from '@/components/AppNavigation.vue';
import BrandLogo from '@/components/BrandLogo.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import ThemeSwitcher from '@/components/ThemeSwitcher.vue';
import { RequestError, requestJson } from '@/lib/http';
import { createUserActivityTracker } from '@/lib/user-activity';
import { store as recordActivity } from '@/routes/activity';
import { chat, logout } from '@/routes';
const page = usePage();
const mobileMenu = ref<HTMLDialogElement>();
const menuOpen = ref(false);
let desktopMedia: MediaQueryList | undefined;
function closeMenu() {
    mobileMenu.value?.close();
    menuOpen.value = false;
}
function openMenu() {
    mobileMenu.value?.showModal();
    menuOpen.value = true;
}
function resizeMenu() {
    if (desktopMedia?.matches) closeMenu();
}
let tracker: ReturnType<typeof createUserActivityTracker> | undefined;
let unsubscribe: (() => void) | undefined;
onMounted(() => {
    tracker = createUserActivityTracker(
        page.props.auth.user.id,
        async (signal) => {
            try {
                await requestJson(recordActivity.url(), {
                    method: 'POST',
                    body: '{}',
                    signal,
                });
            } catch (cause) {
                if (
                    cause instanceof RequestError &&
                    [401, 403, 419].includes(cause.status)
                )
                    tracker?.disconnect();
                throw cause;
            }
        },
    );
    unsubscribe = router.on('navigate', () => {
        tracker?.signal();
        closeMenu();
    });
    desktopMedia = window.matchMedia('(min-width: 801px)');
    desktopMedia.addEventListener('change', resizeMenu);
});
onBeforeUnmount(() => {
    tracker?.disconnect();
    unsubscribe?.();
    desktopMedia?.removeEventListener('change', resizeMenu);
    closeMenu();
});
</script>
<template>
    <div class="flex h-dvh min-w-0 flex-col overflow-hidden bg-canvas text-ink">
        <header
            class="flex h-[62px] shrink-0 items-center justify-between gap-3 border-b border-line bg-surface px-3 min-[801px]:h-[76px] min-[801px]:px-7"
        >
            <div class="flex min-w-0 items-center gap-3 min-[801px]:gap-4">
                <button
                    type="button"
                    aria-label="Menüyü aç"
                    aria-controls="mobile-navigation"
                    :aria-expanded="menuOpen"
                    class="grid size-10 shrink-0 place-items-center rounded-xl border border-line text-brand-900 min-[801px]:hidden"
                    @click="openMenu"
                >
                    <AppIcon name="menu" />
                </button>
                <Link :href="chat()" aria-label="Tepenet İletişim, genel sohbet"
                    ><BrandLogo class="w-[104px] min-[801px]:w-[146px]"
                /></Link>
                <span class="hidden h-8 w-px bg-line min-[801px]:block" />
                <span
                    class="hidden text-sm font-semibold text-muted min-[801px]:block"
                    >İletişim</span
                >
            </div>
            <div class="flex items-center gap-2 sm:gap-4">
                <ThemeSwitcher />
                <span
                    class="hidden rounded-full border border-line px-3 py-2 text-xs font-medium text-muted lg:block"
                    >Şirket içi iletişim</span
                >
                <div class="hidden items-center gap-2 sm:flex">
                    <UserAvatar
                        :name="page.props.auth.user.name"
                        :user-id="page.props.auth.user.id"
                        class="size-9 rounded-full"
                    />
                    <span
                        class="hidden max-w-40 truncate text-sm font-semibold min-[801px]:block"
                        >{{ page.props.auth.user.name }}</span
                    >
                </div>
                <Link
                    :href="logout()"
                    as="button"
                    class="ui-button-secondary min-h-10 px-3 py-2 text-xs"
                    ><AppIcon name="logout" class="size-4" /> Çıkış</Link
                >
            </div>
        </header>
        <p
            v-if="page.props.flash.status"
            role="status"
            class="shrink-0 border-b border-brand-100 bg-brand-50 px-4 py-3 text-sm text-brand-900"
        >
            {{ page.props.flash.status }}
        </p>
        <div
            class="mx-auto flex min-h-0 w-full max-w-[1680px] flex-1 gap-5 min-[801px]:p-5"
        >
            <aside
                class="hidden w-[238px] shrink-0 flex-col gap-4 overflow-y-auto min-[801px]:flex"
            >
                <AppNavigation />
                <div class="ui-card p-5">
                    <div class="ui-accent mb-4" />
                    <p class="text-sm font-semibold">Tek kanal, tüm ekip.</p>
                    <p class="mt-2 text-xs leading-relaxed text-muted">
                        Mesajlar, bahsetmeler ve okunma bilgileri tek bir yerde.
                    </p>
                </div>
                <div
                    class="mt-auto px-4 py-5 text-xs leading-relaxed text-muted"
                >
                    <p class="font-semibold text-brand-900">TEPENET İletişim</p>
                    <p class="mt-1">Şirket içi iletişim alanı</p>
                </div>
            </aside>
            <main class="flex min-h-0 min-w-0 flex-1 flex-col"><slot /></main>
        </div>
        <dialog
            id="mobile-navigation"
            ref="mobileMenu"
            aria-labelledby="mobile-navigation-title"
            class="fixed inset-0 m-0 h-dvh max-h-none w-full max-w-none border-0 bg-transparent p-0 text-ink backdrop:bg-black/40"
            @click.self="closeMenu"
            @close="menuOpen = false"
        >
            <div
                class="flex h-full w-[min(280px,90vw)] flex-col gap-5 overflow-y-auto bg-canvas p-4"
            >
                <div class="flex items-center justify-between gap-3">
                    <h2
                        id="mobile-navigation-title"
                        class="text-sm font-semibold"
                    >
                        TEPENET İletişim
                    </h2>
                    <button
                        type="button"
                        autofocus
                        aria-label="Menüyü kapat"
                        class="grid size-11 place-items-center rounded-xl border border-line bg-surface"
                        @click="closeMenu"
                    >
                        <AppIcon name="close" />
                    </button>
                </div>
                <AppNavigation @navigate="closeMenu" />
                <div
                    class="mt-auto flex items-center gap-3 border-t border-line pt-5"
                >
                    <UserAvatar
                        :name="page.props.auth.user.name"
                        :user-id="page.props.auth.user.id"
                    />
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">
                            {{ page.props.auth.user.name }}
                        </p>
                        <p class="text-xs text-muted">
                            {{
                                page.props.auth.user.role === 'admin'
                                    ? 'Yönetici'
                                    : 'Çalışan'
                            }}
                        </p>
                    </div>
                </div>
            </div>
        </dialog>
    </div>
</template>
