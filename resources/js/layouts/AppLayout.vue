<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import { RequestError, requestJson } from '@/lib/http';
import { createUserActivityTracker } from '@/lib/user-activity';
import { store as recordActivity } from '@/routes/activity';
import { chat, logout } from '@/routes';
import { dashboard } from '@/routes/admin';
import { index } from '@/routes/admin/users';
const page = usePage();
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
    unsubscribe = router.on('navigate', () => tracker?.signal());
});
onBeforeUnmount(() => {
    tracker?.disconnect();
    unsubscribe?.();
});
</script>
<template>
    <div
        class="flex h-dvh min-w-0 flex-col overflow-hidden bg-slate-50 text-slate-900"
    >
        <header
            class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 sm:px-6"
        >
            <Link
                :href="chat()"
                class="font-semibold tracking-tight text-teal-800"
                >TEPENET
                <span class="hidden text-slate-400 sm:inline"
                    >/ İletişim</span
                ></Link
            >
            <nav class="flex items-center gap-3 text-sm">
                <template v-if="page.props.auth.user.role === 'admin'">
                    <Link
                        :href="dashboard()"
                        class="text-slate-600 hover:text-teal-700"
                        >Panel</Link
                    >
                    <Link
                        :href="index()"
                        class="text-slate-600 hover:text-teal-700"
                        >Kullanıcılar</Link
                    >
                </template>
                <span
                    class="hidden max-w-40 truncate text-slate-500 sm:block"
                    >{{ page.props.auth.user.name }}</span
                >
                <Link
                    :href="logout()"
                    as="button"
                    class="rounded-lg border border-slate-200 px-3 py-1.5 hover:bg-slate-50"
                    >Çıkış</Link
                >
            </nav>
        </header>
        <p
            v-if="page.props.flash.status"
            role="status"
            class="shrink-0 border-b border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900"
        >
            {{ page.props.flash.status }}
        </p>
        <main class="flex min-h-0 min-w-0 flex-1 flex-col"><slot /></main>
    </div>
</template>
