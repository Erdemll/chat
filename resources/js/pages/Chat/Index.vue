<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { createEcho } from '@/lib/realtime';
import { RequestError, requestJson } from '@/lib/http';
import { index, show, store } from '@/routes/messages';
import { status as sessionStatus } from '@/routes/session';
import { login } from '@/routes';
import type { History, Message } from '@/types/chat';
import type Echo from 'laravel-echo';
const props = defineProps<{
    channel: { id: number; name: string; slug: string };
    history: History;
}>();
const page = usePage();
const messages = ref<Message[]>(props.history.data);
const hasMore = ref(props.history.has_more);
const beforeId = ref(props.history.before_id);
const draft = ref('');
const sending = ref(false);
const loading = ref(false);
const error = ref('');
const warning = ref('');
const connected = ref(false);
const scrollArea = ref<HTMLElement>();
const unread = ref(0);
const count = computed(() => Array.from(draft.value).length);
let echo: Echo<'reverb'> | null = null;
let heartbeat: ReturnType<typeof setInterval> | undefined;
let unsubscribe: (() => void) | undefined;
let destroyed = false;
let catchingUp = false;
let syncCursor = props.history.data.at(-1)?.id ?? 0;
function nearBottom(): boolean {
    const area = scrollArea.value;
    return (
        !area || area.scrollHeight - area.scrollTop - area.clientHeight < 120
    );
}
async function scrollBottom() {
    await nextTick();
    const area = scrollArea.value;
    if (area) {
        area.scrollTop = area.scrollHeight;
        unread.value = 0;
    }
}
function merge(incoming: Message[]) {
    const existing = new Map(
        messages.value.map((message) => [message.id, message]),
    );
    for (const message of incoming) {
        if (message.channel_id === props.channel.id)
            existing.set(message.id, message);
    }
    messages.value = [...existing.values()].sort((a, b) => a.id - b.id);
}
function handleError(cause: unknown, fallback: string) {
    if (
        cause instanceof RequestError &&
        [401, 403, 419].includes(cause.status)
    ) {
        cleanup();
        messages.value = [];
        router.visit(login(), { replace: true });
        return;
    }
    error.value =
        cause instanceof RequestError && cause.errors.body?.[0]
            ? cause.errors.body[0]
            : cause instanceof RequestError && cause.status === 429
              ? 'Çok hızlı mesaj gönderiyorsunuz. Bir dakika bekleyin.'
              : fallback;
}
async function loadOlder() {
    if (loading.value || !hasMore.value || beforeId.value === null) return;
    loading.value = true;
    error.value = '';
    const area = scrollArea.value;
    const previousHeight = area?.scrollHeight ?? 0;
    const previousTop = area?.scrollTop ?? 0;
    try {
        const result = await requestJson<History>(
            index.url({ query: { before_id: beforeId.value } }),
        );
        if (destroyed) return;
        merge(result.data);
        hasMore.value = result.has_more;
        beforeId.value = result.before_id;
        await nextTick();
        if (area)
            area.scrollTop = previousTop + area.scrollHeight - previousHeight;
    } catch (cause) {
        handleError(cause, 'Eski mesajlar yüklenemedi. Tekrar deneyin.');
    } finally {
        loading.value = false;
    }
}
async function send() {
    if (sending.value || !draft.value.trim()) return;
    sending.value = true;
    error.value = '';
    warning.value = '';
    const body = draft.value;
    try {
        const result = await requestJson<{ data: Message; realtime: boolean }>(
            store.url(),
            { method: 'POST', body: JSON.stringify({ body }) },
        );
        if (destroyed) return;
        merge([result.data]);
        if (draft.value === body) draft.value = '';
        if (!result.realtime)
            warning.value =
                'Mesajınız kaydedildi fakat canlı iletim yapılamadı. Lütfen aynı mesajı tekrar göndermeyin.';
        await scrollBottom();
    } catch (cause) {
        handleError(
            cause,
            'Mesaj gönderimi doğrulanamadı. Tekrar göndermeden önce sohbeti yenileyin.',
        );
    } finally {
        sending.value = false;
    }
}
async function receive(event: { message_id: number; channel_id: number }) {
    if (
        event.channel_id !== props.channel.id ||
        messages.value.some((message) => message.id === event.message_id)
    )
        return;
    try {
        const response = await requestJson<{ data: Message }>(
            show.url(event.message_id),
        );
        if (destroyed) return;
        const shouldScroll = nearBottom();
        const isNew = !messages.value.some(
            (message) => message.id === response.data.id,
        );
        merge([response.data]);
        if (shouldScroll) await scrollBottom();
        else if (isNew) unread.value++;
    } catch (cause) {
        handleError(cause, 'Yeni mesaj alınamadı. Sohbeti yenileyin.');
    }
}
async function catchUp() {
    if (catchingUp || destroyed) return;
    catchingUp = true;
    const shouldScroll = nearBottom();
    let cursor = syncCursor;
    try {
        while (!destroyed) {
            const result = await requestJson<History>(
                index.url({ query: { after_id: cursor } }),
            );
            if (destroyed) break;
            merge(result.data);
            syncCursor = result.after_id ?? syncCursor;
            if (!result.has_more || result.after_id === null) break;
            cursor = result.after_id;
        }
        if (shouldScroll && !destroyed) await scrollBottom();
    } catch (cause) {
        handleError(cause, 'Eksik mesajlar yüklenemedi. Sohbeti yenileyin.');
    } finally {
        catchingUp = false;
    }
}
async function checkSession() {
    if (destroyed) return;
    try {
        await requestJson(sessionStatus.url());
    } catch (cause) {
        handleError(
            cause,
            'Bağlantı kontrol edilemiyor. İnternet bağlantınızı kontrol edin.',
        );
    }
}
function visibilityChanged() {
    if (!document.hidden) {
        void checkSession();
        void catchUp();
    }
}
function cleanup() {
    destroyed = true;
    if (heartbeat) clearInterval(heartbeat);
    unsubscribe?.();
    echo?.disconnect();
    document.removeEventListener('visibilitychange', visibilityChanged);
}
function time(value: string) {
    return new Date(value).toLocaleString('tr-TR', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}
function onKeydown(event: KeyboardEvent) {
    if (
        event.key === 'Enter' &&
        !event.shiftKey &&
        !event.isComposing &&
        window.matchMedia('(min-width: 640px)').matches
    ) {
        event.preventDefault();
        void send();
    }
}
onMounted(() => {
    void scrollBottom();
    echo = createEcho();
    if (echo) {
        unsubscribe = echo.connector.onConnectionChange((state) => {
            if (state !== 'connected') connected.value = false;
        });
        echo.private('company.general')
            .listen(
                '.MessageCreated',
                (event: { message_id: number; channel_id: number }) => {
                    void receive(event);
                },
            )
            .subscribed(() => {
                connected.value = true;
                void catchUp();
            })
            .error(() => {
                connected.value = false;
                error.value = 'Canlı bağlantı kurulamadı. Sohbeti yenileyin.';
            });
    }
    heartbeat = setInterval(() => {
        void checkSession();
    }, 30000);
    document.addEventListener('visibilitychange', visibilityChanged);
});
onBeforeUnmount(cleanup);
</script>
<template>
    <AppLayout>
        <Head :title="channel.name" />
        <div
            class="mx-auto flex min-h-0 w-full max-w-5xl flex-1 flex-col sm:border-x sm:border-slate-200"
        >
            <div
                class="flex shrink-0 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-4 sm:px-6"
            >
                <div>
                    <h1 class="font-semibold"># {{ channel.name }}</h1>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Şirket ortak sohbet kanalı
                    </p>
                </div>
                <span
                    role="status"
                    class="flex items-center gap-1.5 text-xs text-slate-500"
                    ><span
                        :class="connected ? 'bg-teal-500' : 'bg-amber-500'"
                        class="h-2 w-2 rounded-full"
                    />{{
                        connected
                            ? 'Canlı bağlantı'
                            : 'Canlı bağlantı kullanılamıyor'
                    }}</span
                >
            </div>
            <div
                ref="scrollArea"
                role="log"
                aria-label="Sohbet mesajları"
                aria-live="polite"
                class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-5 sm:px-6"
                @scroll="nearBottom() && (unread = 0)"
            >
                <div class="mb-5 flex justify-center">
                    <button
                        v-if="hasMore"
                        :disabled="loading"
                        class="rounded-full border border-slate-200 bg-white px-4 py-2 text-xs text-slate-600 disabled:opacity-50"
                        @click="loadOlder"
                    >
                        {{ loading ? 'Yükleniyor…' : 'Daha eski mesajlar' }}
                    </button>
                    <p v-else class="text-xs text-slate-400">
                        Sohbetin başlangıcı
                    </p>
                </div>
                <p
                    v-if="!messages.length"
                    class="py-10 text-center text-sm text-slate-500"
                >
                    Henüz mesaj yok. İlk mesajı siz gönderin.
                </p>
                <div class="grid gap-4">
                    <article
                        v-for="message in messages"
                        :key="message.id"
                        class="flex"
                        :class="
                            message.user.id === page.props.auth.user.id
                                ? 'justify-end'
                                : 'justify-start'
                        "
                    >
                        <div
                            class="max-w-[88%] min-w-0 rounded-2xl px-4 py-3 shadow-sm sm:max-w-[75%]"
                            :class="
                                message.user.id === page.props.auth.user.id
                                    ? 'rounded-tr-sm bg-teal-700 text-white'
                                    : 'rounded-tl-sm border border-slate-200 bg-white'
                            "
                        >
                            <p
                                class="mb-1 text-xs font-semibold"
                                :class="
                                    message.user.id === page.props.auth.user.id
                                        ? 'text-teal-100'
                                        : 'text-teal-700'
                                "
                            >
                                {{ message.user.name }}
                            </p>
                            <p
                                class="text-sm leading-relaxed [overflow-wrap:anywhere] whitespace-pre-wrap"
                            >
                                {{ message.body }}
                            </p>
                            <time
                                :datetime="message.created_at"
                                class="mt-2 block text-right text-[10px] opacity-65"
                                >{{ time(message.created_at) }}</time
                            >
                        </div>
                    </article>
                </div>
            </div>
            <button
                v-if="unread"
                class="mx-auto mb-2 rounded-full bg-teal-700 px-4 py-2 text-xs text-white"
                @click="scrollBottom"
            >
                {{ unread }} yeni mesaj ↓
            </button>
            <form
                class="shrink-0 border-t border-slate-200 bg-white p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:p-4"
                @submit.prevent="send"
            >
                <p v-if="error" role="alert" class="mb-2 text-sm text-red-700">
                    {{ error }}
                </p>
                <p
                    v-if="warning"
                    role="status"
                    class="mb-2 text-sm text-amber-700"
                >
                    {{ warning }}
                </p>
                <div class="flex items-end gap-2 sm:gap-3">
                    <label for="message-body" class="sr-only">Mesajınız</label>
                    <textarea
                        id="message-body"
                        v-model="draft"
                        rows="2"
                        placeholder="Genel kanalına mesaj yazın…"
                        class="max-h-36 min-h-14 min-w-0 flex-1 resize-none rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-base outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-600/10 sm:text-sm"
                        @keydown="onKeydown"
                    />
                    <button
                        :disabled="sending || !draft.trim() || count > 4000"
                        class="rounded-xl bg-teal-700 px-4 py-3 text-sm font-medium text-white disabled:opacity-40"
                    >
                        {{ sending ? 'Gönderiliyor…' : 'Gönder' }}
                    </button>
                </div>
                <div
                    class="mt-2 flex justify-between gap-3 text-[10px] text-slate-400"
                >
                    <span class="hidden sm:block"
                        >Enter ile gönder · Shift + Enter ile yeni satır</span
                    ><span
                        class="ml-auto"
                        :class="count > 4000 ? 'text-red-700' : ''"
                        >{{ count }} / 4000</span
                    >
                </div>
            </form>
        </div>
    </AppLayout>
</template>
