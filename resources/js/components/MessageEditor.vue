<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { createMessageEditor } from '@/lib/message-editor';
import { RequestError, requestJson } from '@/lib/http';
import {
    filterMentionableUsers,
    findMentionQuery,
    insertMention,
    retainMentions,
    type MentionQuery,
} from '@/lib/mention-composer';
import { mentionable } from '@/routes/users';
import type { Message, MentionableUser } from '@/types/chat';

const props = defineProps<{
    message: Message;
    saveMessage: (
        id: number,
        payload: { body: string; mentions: number[]; expected_body: string },
    ) => Promise<void>;
}>();
const emit = defineEmits<{ close: []; sessionError: [cause: unknown] }>();
const page = usePage();
const editor = createMessageEditor();
editor.open(props.message);
const state = editor.state;
const input = ref<HTMLTextAreaElement>();
const query = ref<MentionQuery | null>(null);
const users = ref<MentionableUser[]>([]);
const loading = ref(false);
const mentionError = ref('');
const selectedIndex = ref(0);
const request = new AbortController();
let requested = false;
const options = computed(() =>
    filterMentionableUsers(
        users.value,
        query.value?.query ?? '',
        page.props.auth.user.id,
    ),
);
watch(
    () => state.body,
    (body) => {
        state.mentions = retainMentions(body, state.mentions);
    },
);

async function loadUsers() {
    if (loading.value) return;
    requested = true;
    loading.value = true;
    mentionError.value = '';
    try {
        const response = await requestJson<{ data: MentionableUser[] }>(
            mentionable.url(),
            { signal: request.signal },
        );
        users.value = response.data;
    } catch (cause) {
        if (request.signal.aborted) return;
        mentionError.value = 'Çalışan listesi yüklenemedi.';
        if (
            cause instanceof RequestError &&
            [401, 403, 419].includes(cause.status)
        )
            emit('sessionError', cause);
    } finally {
        loading.value = false;
    }
}
function updateQuery() {
    const element = input.value;
    query.value =
        element && element.selectionStart === element.selectionEnd
            ? findMentionQuery(element.value, element.selectionStart)
            : null;
    selectedIndex.value = 0;
    if (query.value && !requested) void loadUsers();
}
async function choose(user: MentionableUser) {
    if (!query.value) return;
    if (
        state.mentions.length >= 20 &&
        !state.mentions.some((item) => item.id === user.id)
    ) {
        state.error = 'En fazla 20 çalışan seçebilirsiniz.';
        return;
    }
    const insertion = insertMention(state.body, query.value, user);
    state.mentions = [
        ...state.mentions.filter((item) => item.id !== user.id),
        user,
    ];
    state.body = insertion.body;
    query.value = null;
    await nextTick();
    input.value?.focus();
    input.value?.setSelectionRange(insertion.caret, insertion.caret);
}
function keydown(event: KeyboardEvent) {
    if (event.isComposing) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        if (query.value) query.value = null;
        else if (!state.saving) emit('close');
    } else if (
        query.value &&
        options.value.length &&
        ['ArrowUp', 'ArrowDown', 'Enter'].includes(event.key) &&
        !event.shiftKey
    ) {
        event.preventDefault();
        if (event.key === 'Enter')
            void choose(options.value[selectedIndex.value] ?? options.value[0]);
        else
            selectedIndex.value =
                (selectedIndex.value +
                    (event.key === 'ArrowDown' ? 1 : -1) +
                    options.value.length) %
                options.value.length;
    }
}
async function save() {
    try {
        await editor.save(props.saveMessage);
        if (state.messageId === null) emit('close');
    } catch {
        /* The editor preserves the draft and displays the server error. */
    }
}
void nextTick(() => input.value?.focus());
onBeforeUnmount(() => request.abort());
</script>

<template>
    <form class="relative min-w-56 space-y-2" @submit.prevent="save">
        <label :for="`edit-message-${message.id}`" class="sr-only"
            >Mesajı düzenle</label
        >
        <textarea
            :id="`edit-message-${message.id}`"
            ref="input"
            v-model="state.body"
            rows="3"
            :disabled="state.saving"
            :aria-invalid="!!state.error"
            :aria-describedby="
                state.error ? `edit-error-${message.id}` : undefined
            "
            aria-autocomplete="list"
            :aria-controls="
                query && options.length
                    ? `edit-mentions-${message.id}`
                    : undefined
            "
            :aria-activedescendant="
                query && options.length
                    ? `edit-mention-${message.id}-${options[selectedIndex]?.id}`
                    : undefined
            "
            class="block max-h-60 w-full resize-y rounded-lg border border-slate-300 bg-white p-2 text-base text-slate-900 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 sm:text-sm"
            @input="updateQuery"
            @click="updateQuery"
            @select="updateQuery"
            @keydown="keydown"
            @keyup="
                !['ArrowUp', 'ArrowDown', 'Enter', 'Escape'].includes(
                    $event.key,
                ) && updateQuery()
            "
            @blur="query = null"
        />
        <div
            v-if="query"
            class="rounded-lg border border-slate-200 bg-white p-1 text-sm text-slate-900 shadow-sm"
        >
            <p v-if="loading" role="status" class="p-2">
                Çalışanlar yükleniyor…
            </p>
            <p v-else-if="mentionError" role="alert" class="p-2">
                {{ mentionError }}
                <button
                    type="button"
                    class="underline"
                    @mousedown.prevent
                    @click="loadUsers"
                >
                    Tekrar dene
                </button>
            </p>
            <ul
                v-else-if="options.length"
                :id="`edit-mentions-${message.id}`"
                role="listbox"
                aria-label="Mention edilebilecek çalışanlar"
            >
                <li v-for="(user, optionIndex) in options" :key="user.id">
                    <button
                        :id="`edit-mention-${message.id}-${user.id}`"
                        type="button"
                        role="option"
                        :aria-selected="optionIndex === selectedIndex"
                        class="w-full rounded px-2 py-1.5 text-left hover:bg-teal-50"
                        :class="
                            optionIndex === selectedIndex ? 'bg-teal-50' : ''
                        "
                        @mousedown.prevent
                        @click="choose(user)"
                    >
                        {{ user.name }}
                    </button>
                </li>
            </ul>
            <p v-else class="p-2">Eşleşen aktif çalışan bulunamadı.</p>
        </div>
        <p
            v-if="state.error"
            :id="`edit-error-${message.id}`"
            role="alert"
            class="text-xs"
        >
            {{ state.error }}
        </p>
        <div class="flex items-center gap-3 text-xs">
            <button
                type="submit"
                :disabled="
                    state.saving ||
                    !state.body.trim() ||
                    Array.from(state.body).length > 4000
                "
                class="rounded bg-white px-3 py-2 font-semibold text-teal-800 disabled:opacity-50"
            >
                {{ state.saving ? 'Kaydediliyor…' : 'Kaydet' }}
            </button>
            <button
                type="button"
                :disabled="state.saving"
                class="rounded px-2 py-2 underline disabled:opacity-50"
                @click="emit('close')"
            >
                Vazgeç
            </button>
        </div>
    </form>
</template>
