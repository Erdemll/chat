<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
    type ComponentPublicInstance,
} from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import MessageEditor from '@/components/MessageEditor.vue';
import {
    createMessageHistory,
    isNearChatBottom,
    preserveMessageScroll,
} from '@/lib/message-history';
import {
    createMessageMutations,
    type MessageMutationEvent,
} from '@/lib/message-mutations';
import { createEcho } from '@/lib/realtime';
import { RequestError, requestJson } from '@/lib/http';
import {
    filterMentionableUsers,
    findMentionQuery,
    insertMention,
    retainMentions,
    type MentionQuery,
} from '@/lib/mention-composer';
import { mentionable } from '@/routes/users';
import {
    createMessageReadCounts,
    type MessageReadsEvent,
} from '@/lib/message-read-counts';
import {
    createMessageReadTracker,
    type MessageReadTracker,
} from '@/lib/message-reads';
import {
    destroy,
    index,
    read,
    reads,
    show,
    store,
    update,
} from '@/routes/messages';
import { status as sessionStatus } from '@/routes/session';
import { login } from '@/routes';
import type {
    History,
    Message,
    MessageReader,
    MessageReadUpdate,
    MentionableUser,
} from '@/types/chat';
import type Echo from 'laravel-echo';
const props = defineProps<{
    channel: { id: number; name: string; slug: string };
    history: History;
}>();
const page = usePage();
const mutations = createMessageMutations(props.channel.id);
const messages = ref<Message[]>(mutations.merge([], props.history.data));
const actionsFor = ref<number | null>(null);
const editingId = ref<number | null>(null);
const deletingId = ref<number | null>(null);
const clockTime = ref(Date.now());
const readCounts = createMessageReadCounts(props.channel.id);
const draft = ref('');
const composer = ref<HTMLTextAreaElement>();
const mentionQuery = ref<MentionQuery | null>(null);
const mentionableUsers = ref<MentionableUser[]>([]);
const selectedMentions = ref<MentionableUser[]>([]);
const mentionLoading = ref(false);
const mentionError = ref('');
const mentionIndex = ref(0);
const mentionOptions = computed(() =>
    filterMentionableUsers(
        mentionableUsers.value,
        mentionQuery.value?.query ?? '',
        page.props.auth.user.id,
    ),
);
let mentionUsersRequested = false;
let mentionRequest: AbortController | undefined;
const sending = ref(false);
const error = ref('');
const warning = ref('');
const connected = ref(false);
const scrollArea = ref<HTMLElement>();
const historySentinel = ref<HTMLElement>();
const unread = ref(0);
const readError = ref('');
const readersFor = ref<number | null>(null);
const readersLoading = ref(false);
const readersError = ref('');
const readers = ref<MessageReader[]>([]);
const count = computed(() => Array.from(draft.value).length);
let echo: Echo<'reverb'> | null = null;
let heartbeat: ReturnType<typeof setInterval> | undefined;
let unsubscribe: (() => void) | undefined;
let destroyed = false;
let catchingUp = false;
let syncCursor = messages.value.at(-1)?.id ?? 0;
let readTracker: MessageReadTracker | undefined;
let readersRequest: AbortController | undefined;
const messageElements = new Map<
    number,
    { element: HTMLElement; authorId: number }
>();
const historyLoader = createMessageHistory(
    props.history,
    (beforeId, signal) =>
        requestJson<History>(index.url({ query: { before_id: beforeId } }), {
            signal,
        }),
    (result) => {
        merge(result.data);
    },
    () =>
        preserveMessageScroll(
            scrollArea.value,
            Array.from(messageElements.values(), ({ element }) => element),
        ),
    nextTick,
    (cause) => handleError(cause, 'Eski mesajlar yüklenemedi. Tekrar deneyin.'),
);
const { hasMore, loading, failed: historyFailed } = historyLoader;

watch(draft, (body) => {
    selectedMentions.value = retainMentions(body, selectedMentions.value);
});

async function loadMentionableUsers() {
    if (destroyed || mentionLoading.value) return;
    mentionUsersRequested = true;
    mentionLoading.value = true;
    mentionError.value = '';
    const request = new AbortController();
    mentionRequest = request;
    try {
        const result = await requestJson<{ data: MentionableUser[] }>(
            mentionable.url(),
            { signal: request.signal },
        );
        if (!destroyed) mentionableUsers.value = result.data;
    } catch (cause) {
        if (destroyed || request.signal.aborted) return;
        if (
            cause instanceof RequestError &&
            [401, 403, 419].includes(cause.status)
        ) {
            handleError(cause, 'Çalışan listesi yüklenemedi.');
        } else {
            mentionError.value = 'Çalışan listesi yüklenemedi.';
        }
    } finally {
        mentionLoading.value = false;
    }
}

function updateMentionQuery() {
    const element = composer.value;
    mentionQuery.value =
        element && element.selectionStart === element.selectionEnd
            ? findMentionQuery(element.value, element.selectionStart)
            : null;
    mentionIndex.value = 0;
    if (mentionQuery.value && !mentionUsersRequested)
        void loadMentionableUsers();
}

function composerKeyup(event: KeyboardEvent) {
    if (!['ArrowUp', 'ArrowDown', 'Enter', 'Escape'].includes(event.key))
        updateMentionQuery();
}

async function chooseMention(user: MentionableUser) {
    const query = mentionQuery.value;
    if (!query || user.id === page.props.auth.user.id) return;
    const selected = retainMentions(draft.value, selectedMentions.value);
    if (
        selected.length >= 20 &&
        !selected.some((item) => item.id === user.id)
    ) {
        error.value = 'En fazla 20 çalışan seçebilirsiniz.';
        return;
    }
    const insertion = insertMention(draft.value, query, user);
    selectedMentions.value = [
        ...selected.filter((item) => item.id !== user.id),
        user,
    ];
    draft.value = insertion.body;
    mentionQuery.value = null;
    mentionError.value = '';
    await nextTick();
    composer.value?.focus();
    composer.value?.setSelectionRange(insertion.caret, insertion.caret);
}

function registerMessage(
    message: Message,
    element: Element | ComponentPublicInstance | null,
) {
    if (!(element instanceof HTMLElement)) {
        messageElements.delete(message.id);
        readTracker?.unobserve(message.id);
        return;
    }
    messageElements.set(message.id, { element, authorId: message.user.id });
    readTracker?.observe(element, message.id, message.user.id);
}

async function toggleReaders(message: Message) {
    readersRequest?.abort();
    if (readersFor.value === message.id) {
        readersFor.value = null;
        return;
    }
    readersFor.value = message.id;
    readers.value = [];
    await loadReaders(message.id);
}

async function loadReaders(messageId: number, background = false) {
    readersRequest?.abort();
    readersError.value = '';
    if (!background) readersLoading.value = true;
    const request = new AbortController();
    readersRequest = request;
    try {
        const result = await requestJson<{ data: MessageReader[] }>(
            reads.url(messageId),
            { signal: request.signal },
        );
        if (
            destroyed ||
            readersFor.value !== messageId ||
            request.signal.aborted
        )
            return;
        readers.value = result.data;
    } catch (cause) {
        if (destroyed || request.signal.aborted) return;
        if (
            cause instanceof RequestError &&
            [401, 403, 419].includes(cause.status)
        ) {
            handleError(cause, 'Okuyanlar listesi yüklenemedi.');
            return;
        }
        readersError.value = 'Okuyanlar listesi yüklenemedi. Tekrar deneyin.';
    } finally {
        if (readersFor.value === messageId && !request.signal.aborted)
            readersLoading.value = false;
    }
}
function receiveReads(event: MessageReadsEvent) {
    if (destroyed || event.channel_id !== props.channel.id) return;
    const openMessage = readersFor.value;
    const previousCount = messages.value.find(
        (message) => message.id === openMessage,
    )?.read_count;
    readCounts.receive(event);
    messages.value = messages.value.map(readCounts.apply);
    const currentCount = messages.value.find(
        (message) => message.id === openMessage,
    )?.read_count;
    if (openMessage !== null && currentCount !== previousCount) {
        void loadReaders(openMessage, true);
    }
}
function nearBottom(): boolean {
    return isNearChatBottom(scrollArea.value);
}
async function scrollBottom() {
    await nextTick();
    const area = scrollArea.value;
    if (area) {
        area.scrollTop = area.scrollHeight;
        unread.value = 0;
    }
}
function merge(incoming: Message[]): number {
    const previousIds = new Set(messages.value.map((message) => message.id));
    messages.value = mutations
        .merge(messages.value, incoming)
        .map(readCounts.apply);
    return messages.value.filter((message) => !previousIds.has(message.id))
        .length;
}
function canEdit(message: Message): boolean {
    return (
        message.can_edit &&
        clockTime.value <= Date.parse(message.edit_expires_at)
    );
}
function removeMessage(event: MessageMutationEvent) {
    if (destroyed || event.channel_id !== props.channel.id) return;
    messages.value = mutations.remove(messages.value, event);
    if (editingId.value === event.message_id) editingId.value = null;
    if (actionsFor.value === event.message_id) actionsFor.value = null;
    if (readersFor.value === event.message_id) {
        readersRequest?.abort();
        readersFor.value = null;
        readers.value = [];
    }
    readTracker?.unobserve(event.message_id);
    messageElements.delete(event.message_id);
}
async function deleteMessage(message: Message) {
    if (
        deletingId.value !== null ||
        !window.confirm('Bu mesajı silmek istediğinizden emin misiniz?')
    )
        return;
    deletingId.value = message.id;
    error.value = '';
    warning.value = '';
    actionsFor.value = null;
    try {
        const result = await requestJson<
            MessageMutationEvent & { realtime: boolean }
        >(destroy.url(message.id), { method: 'DELETE' });
        removeMessage(result);
        if (!destroyed && !result.realtime)
            warning.value =
                'Mesaj silindi fakat canlı iletim yapılamadı. Diğer istemciler yeniden bağlandığında güncellenecek.';
    } catch (cause) {
        if (destroyed) return;
        if (cause instanceof RequestError && cause.status === 404)
            removeMessage({
                message_id: message.id,
                channel_id: props.channel.id,
            });
        else if (cause instanceof RequestError && cause.status === 403) {
            error.value = 'Bu mesajı silme yetkiniz yok.';
            void checkSession();
        } else
            handleError(
                cause,
                'Silme işlemi doğrulanamadı. Sohbeti yenileyin.',
            );
    } finally {
        deletingId.value = null;
    }
}
async function saveEdit(
    id: number,
    payload: { body: string; mentions: number[]; expected_body: string },
): Promise<void> {
    warning.value = '';
    try {
        const result = await requestJson<{ data: Message; realtime: boolean }>(
            update.url(id),
            { method: 'PATCH', body: JSON.stringify(payload) },
        );
        if (destroyed) return;
        merge([result.data]);
        if (!result.realtime)
            warning.value =
                'Düzenleme kaydedildi fakat canlı iletim yapılamadı. Diğer istemciler yeniden bağlandığında güncellenecek.';
    } catch (cause) {
        if (cause instanceof RequestError) {
            if (cause.status === 404)
                removeMessage({ message_id: id, channel_id: props.channel.id });
            else if (cause.status === 403) void checkSession();
            else if ([401, 419].includes(cause.status))
                handleError(cause, 'Oturumunuz sona erdi.');
            else if (cause.status === 409)
                void receiveUpdated({
                    message_id: id,
                    channel_id: props.channel.id,
                });
        }
        throw cause;
    }
}
async function receiveUpdated(event: MessageMutationEvent) {
    if (
        destroyed ||
        !messages.value.some((message) => message.id === event.message_id)
    )
        return;
    try {
        await mutations.refresh(
            event,
            async (id) =>
                (await requestJson<{ data: Message }>(show.url(id))).data,
            (message) => {
                if (!destroyed) merge([message]);
            },
        );
    } catch (cause) {
        if (destroyed) return;
        if (cause instanceof RequestError && cause.status === 404)
            removeMessage(event);
        else
            handleError(
                cause,
                'Düzenlenen mesaj alınamadı. Sohbeti yenileyin.',
            );
    }
}
async function reconcileLoadedMessages() {
    const ids = messages.value.map((message) => message.id);
    for (let offset = 0; offset < ids.length && !destroyed; offset += 100) {
        const batch = ids.slice(offset, offset + 100);
        const result = await requestJson<History>(
            index.url({ query: { message_ids: batch } }),
        );
        if (destroyed) return;
        const present = new Set(result.data.map((message) => message.id));
        for (const id of batch)
            if (!present.has(id))
                removeMessage({ message_id: id, channel_id: props.channel.id });
        merge(result.data);
    }
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
            : cause instanceof RequestError && cause.errors.mentions?.[0]
              ? cause.errors.mentions[0]
              : cause instanceof RequestError && cause.status === 429
                ? 'Çok hızlı mesaj gönderiyorsunuz. Bir dakika bekleyin.'
                : fallback;
}
function loadOlder() {
    error.value = '';
    return historyLoader.loadOlder();
}
async function send() {
    if (sending.value || !draft.value.trim()) return;
    sending.value = true;
    error.value = '';
    warning.value = '';
    const body = draft.value;
    const mentions = retainMentions(body, selectedMentions.value).map(
        (user) => user.id,
    );
    try {
        const result = await requestJson<{ data: Message; realtime: boolean }>(
            store.url(),
            { method: 'POST', body: JSON.stringify({ body, mentions }) },
        );
        if (destroyed) return;
        merge([result.data]);
        if (draft.value === body) {
            draft.value = '';
            selectedMentions.value = [];
            mentionQuery.value = null;
        }
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
        const added = merge([response.data]);
        if (added && shouldScroll) await scrollBottom();
        else unread.value += added;
    } catch (cause) {
        if (cause instanceof RequestError && cause.status === 404) {
            removeMessage(event);
            return;
        }
        handleError(cause, 'Yeni mesaj alınamadı. Sohbeti yenileyin.');
    }
}
async function catchUp() {
    if (catchingUp || destroyed) return;
    catchingUp = true;
    let followBottom = nearBottom();
    let cursor = syncCursor;
    try {
        await reconcileLoadedMessages();
        while (!destroyed) {
            const result = await requestJson<History>(
                index.url({ query: { after_id: cursor } }),
            );
            if (destroyed) break;
            const shouldScroll = followBottom && nearBottom();
            followBottom = shouldScroll;
            const added = merge(result.data);
            if (added && shouldScroll) await scrollBottom();
            else unread.value += added;
            syncCursor = result.after_id ?? syncCursor;
            if (!result.has_more || result.after_id === null) break;
            cursor = result.after_id;
        }
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
        readTracker?.resume();
        void checkSession();
        void catchUp();
    }
}
function cleanup() {
    destroyed = true;
    historyLoader.disconnect();
    readTracker?.disconnect();
    readersRequest?.abort();
    mentionRequest?.abort();
    messageElements.clear();
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
    if (mentionQuery.value && !event.isComposing) {
        if (event.key === 'Escape') {
            event.preventDefault();
            mentionQuery.value = null;
            return;
        }
        if (
            mentionOptions.value.length &&
            ['ArrowUp', 'ArrowDown', 'Enter'].includes(event.key) &&
            !event.shiftKey
        ) {
            event.preventDefault();
            if (event.key === 'Enter') {
                void chooseMention(
                    mentionOptions.value[mentionIndex.value] ??
                        mentionOptions.value[0],
                );
            } else {
                mentionIndex.value =
                    (mentionIndex.value +
                        (event.key === 'ArrowDown' ? 1 : -1) +
                        mentionOptions.value.length) %
                    mentionOptions.value.length;
            }
            return;
        }
    }
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
            .listen('.MessageReadsUpdated', (event: MessageReadsEvent) =>
                receiveReads(event),
            )
            .listen('.MessageDeleted', (event: MessageMutationEvent) =>
                removeMessage(event),
            )
            .listen('.MessageUpdated', (event: MessageMutationEvent) => {
                void receiveUpdated(event);
            })
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
        clockTime.value = Date.now();
        void checkSession();
    }, 30000);
    document.addEventListener('visibilitychange', visibilityChanged);
});
onMounted(async () => {
    await scrollBottom();
    if (!destroyed && scrollArea.value && historySentinel.value)
        historyLoader.observe(scrollArea.value, historySentinel.value);
    if (
        destroyed ||
        !scrollArea.value ||
        typeof IntersectionObserver === 'undefined'
    )
        return;
    readTracker = createMessageReadTracker(
        scrollArea.value,
        page.props.auth.user.id,
        async (ids, signal) => {
            const result = await requestJson<{
                success: boolean;
                reads: MessageReadUpdate[];
            }>(read.url(), {
                method: 'POST',
                body: JSON.stringify({ message_ids: ids }),
                signal,
            });
            receiveReads({ channel_id: props.channel.id, reads: result.reads });
            if (!destroyed) readError.value = '';
        },
        (cause) => {
            if (
                cause instanceof RequestError &&
                [401, 403, 419].includes(cause.status)
            ) {
                handleError(cause, 'Okunma bilgisi kaydedilemedi.');
                return;
            }
            readError.value =
                'Okunma bilgisi kaydedilemedi. Yeniden denenecek.';
        },
    );
    for (const [id, { element, authorId }] of messageElements)
        readTracker.observe(element, id, authorId);
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
                class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-5 [overflow-anchor:none] sm:px-6"
                @scroll.passive="nearBottom() && (unread = 0)"
            >
                <div ref="historySentinel" class="mb-5 flex justify-center">
                    <button
                        v-if="hasMore"
                        :disabled="loading"
                        class="rounded-full border border-slate-200 bg-white px-4 py-2 text-xs text-slate-600 disabled:opacity-50"
                        @click="loadOlder"
                    >
                        {{
                            loading
                                ? 'Yükleniyor…'
                                : historyFailed
                                  ? 'Tekrar dene'
                                  : 'Daha eski mesajlar'
                        }}
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
                        v-memo="[
                            message,
                            canEdit(message),
                            editingId === message.id,
                            actionsFor === message.id,
                            deletingId,
                            readersFor === message.id,
                            readersFor === message.id ? readers : null,
                            readersFor === message.id && readersLoading,
                            readersFor === message.id ? readersError : '',
                        ]"
                        :ref="(element) => registerMessage(message, element)"
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
                            <div
                                class="mb-1 flex items-start justify-between gap-3"
                            >
                                <p
                                    class="text-xs font-semibold"
                                    :class="
                                        message.user.id ===
                                        page.props.auth.user.id
                                            ? 'text-teal-100'
                                            : 'text-teal-700'
                                    "
                                >
                                    {{ message.user.name }}
                                </p>
                                <div
                                    v-if="
                                        message.can_delete || canEdit(message)
                                    "
                                    class="relative"
                                >
                                    <button
                                        type="button"
                                        :aria-label="`${message.user.name} mesajı için işlemler`"
                                        :aria-expanded="
                                            actionsFor === message.id
                                        "
                                        :disabled="deletingId === message.id"
                                        class="rounded px-2 text-base leading-5 opacity-75 hover:opacity-100 focus-visible:outline-2"
                                        @click="
                                            actionsFor =
                                                actionsFor === message.id
                                                    ? null
                                                    : message.id
                                        "
                                        @keydown.esc="actionsFor = null"
                                    >
                                        ⋮
                                    </button>
                                    <div
                                        v-if="actionsFor === message.id"
                                        class="absolute right-0 z-10 min-w-28 rounded-lg border border-slate-200 bg-white p-1 text-xs text-slate-900 shadow-lg"
                                        @keydown.esc="actionsFor = null"
                                    >
                                        <button
                                            v-if="canEdit(message)"
                                            type="button"
                                            class="block w-full rounded px-3 py-2 text-left hover:bg-slate-100"
                                            @click="
                                                editingId = message.id;
                                                actionsFor = null;
                                            "
                                        >
                                            Düzenle
                                        </button>
                                        <button
                                            v-if="message.can_delete"
                                            type="button"
                                            :disabled="deletingId !== null"
                                            class="block w-full rounded px-3 py-2 text-left text-red-700 hover:bg-red-50 disabled:opacity-50"
                                            @click="deleteMessage(message)"
                                        >
                                            Sil
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <MessageEditor
                                v-if="editingId === message.id"
                                :message="message"
                                :save-message="saveEdit"
                                @close="editingId = null"
                                @session-error="
                                    handleError($event, 'Oturumunuz sona erdi.')
                                "
                            />
                            <p
                                v-else
                                class="text-sm leading-relaxed [overflow-wrap:anywhere] whitespace-pre-wrap"
                            >
                                {{ message.body }}
                            </p>
                            <time
                                :datetime="message.created_at"
                                class="mt-2 block text-right text-[10px] opacity-65"
                                >{{ time(message.created_at)
                                }}<span v-if="message.edited_at">
                                    · düzenlendi</span
                                ></time
                            >
                            <button
                                v-if="message.read_count > 0"
                                type="button"
                                :aria-expanded="readersFor === message.id"
                                class="mt-1 block text-right text-[11px] underline-offset-2 opacity-75 hover:underline focus-visible:underline"
                                @click="toggleReaders(message)"
                            >
                                {{ message.read_count }} kişi okudu
                            </button>
                            <div
                                v-if="readersFor === message.id"
                                class="mt-2 border-t border-current/15 pt-2 text-xs"
                            >
                                <div
                                    class="mb-2 flex items-center justify-between gap-4"
                                >
                                    <span class="font-semibold">Okuyanlar</span>
                                    <button
                                        type="button"
                                        class="underline"
                                        @click="toggleReaders(message)"
                                    >
                                        Kapat
                                    </button>
                                </div>
                                <p v-if="readersLoading" role="status">
                                    Yükleniyor…
                                </p>
                                <div v-else-if="readersError" role="alert">
                                    <p>{{ readersError }}</p>
                                    <button
                                        type="button"
                                        class="mt-1 underline"
                                        @click="
                                            readersFor = null;
                                            toggleReaders(message);
                                        "
                                    >
                                        Tekrar dene
                                    </button>
                                </div>
                                <ul
                                    v-else-if="readers.length"
                                    class="space-y-2"
                                >
                                    <li
                                        v-for="reader in readers"
                                        :key="reader.id"
                                    >
                                        <span class="block">{{
                                            reader.name
                                        }}</span>
                                        <time
                                            :datetime="reader.read_at"
                                            class="text-[10px] opacity-65"
                                            >{{ time(reader.read_at) }}</time
                                        >
                                    </li>
                                </ul>
                                <p v-else>Henüz okuyan yok.</p>
                            </div>
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
                    v-if="readError"
                    role="status"
                    class="mb-2 text-xs text-amber-700"
                >
                    {{ readError }}
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
                    <div class="relative min-w-0 flex-1">
                        <div
                            v-if="mentionQuery"
                            class="absolute inset-x-0 bottom-full z-20 mb-2 max-h-52 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-lg"
                        >
                            <p
                                v-if="mentionLoading"
                                role="status"
                                class="px-3 py-2 text-sm text-slate-500"
                            >
                                Çalışanlar yükleniyor…
                            </p>
                            <div
                                v-else-if="mentionError"
                                role="alert"
                                class="px-3 py-2 text-sm text-red-700"
                            >
                                {{ mentionError }}
                                <button
                                    type="button"
                                    class="ml-2 underline"
                                    @mousedown.prevent
                                    @click="loadMentionableUsers"
                                >
                                    Tekrar dene
                                </button>
                            </div>
                            <ul
                                v-else-if="mentionOptions.length"
                                id="mention-options"
                                role="listbox"
                                aria-label="Mention edilebilecek çalışanlar"
                            >
                                <li
                                    v-for="(
                                        user, optionIndex
                                    ) in mentionOptions"
                                    :key="user.id"
                                >
                                    <button
                                        :id="`mention-option-${user.id}`"
                                        type="button"
                                        role="option"
                                        :aria-selected="
                                            optionIndex === mentionIndex
                                        "
                                        class="w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-teal-50 focus:bg-teal-50"
                                        :class="
                                            optionIndex === mentionIndex
                                                ? 'bg-teal-50 text-teal-800'
                                                : 'text-slate-700'
                                        "
                                        @mousedown.prevent
                                        @click="chooseMention(user)"
                                    >
                                        {{ user.name }}
                                    </button>
                                </li>
                            </ul>
                            <p v-else class="px-3 py-2 text-sm text-slate-500">
                                Eşleşen aktif çalışan bulunamadı.
                            </p>
                        </div>
                        <textarea
                            id="message-body"
                            ref="composer"
                            v-model="draft"
                            rows="2"
                            placeholder="Genel kanalına mesaj yazın…"
                            aria-autocomplete="list"
                            :aria-controls="
                                mentionQuery && mentionOptions.length
                                    ? 'mention-options'
                                    : undefined
                            "
                            :aria-activedescendant="
                                mentionQuery && mentionOptions.length
                                    ? `mention-option-${mentionOptions[mentionIndex]?.id}`
                                    : undefined
                            "
                            class="block max-h-36 min-h-14 w-full resize-none rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-base outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-600/10 sm:text-sm"
                            @input="updateMentionQuery"
                            @click="updateMentionQuery"
                            @select="updateMentionQuery"
                            @keyup="composerKeyup"
                            @blur="mentionQuery = null"
                            @keydown="onKeydown"
                        />
                    </div>
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
