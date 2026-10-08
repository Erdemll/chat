import { reactive } from 'vue';
import { RequestError } from './http';
import { retainMentions } from './mention-composer';
import type { Message, MentionableUser } from '../types/chat';

export function createMessageEditor() {
    const state = reactive({
        messageId: null as number | null,
        body: '',
        expectedBody: '',
        mentions: [] as MentionableUser[],
        saving: false,
        error: '',
    });

    function open(message: Message) {
        if (state.saving) return;
        state.messageId = message.id;
        state.body = message.body;
        state.expectedBody = message.body;
        state.mentions = [...message.mentions];
        state.error = '';
    }

    function cancel() {
        state.messageId = null;
        state.body = '';
        state.expectedBody = '';
        state.mentions = [];
        state.error = '';
    }

    async function save(
        submit: (
            id: number,
            payload: {
                body: string;
                mentions: number[];
                expected_body: string;
            },
        ) => Promise<void>,
    ) {
        if (state.saving || state.messageId === null || !state.body.trim())
            return;
        state.saving = true;
        state.error = '';
        const id = state.messageId;
        try {
            await submit(id, {
                body: state.body,
                mentions: retainMentions(state.body, state.mentions).map(
                    (user) => user.id,
                ),
                expected_body: state.expectedBody,
            });
            if (state.messageId === id) cancel();
        } catch (cause) {
            if (state.messageId !== id) return;
            state.error =
                cause instanceof RequestError &&
                (cause.errors.body?.[0] || cause.errors.mentions?.[0])
                    ? cause.errors.body?.[0] || cause.errors.mentions[0]
                    : cause instanceof RequestError && cause.status === 403
                      ? 'Bu mesaj artık düzenlenemez.'
                      : 'Düzenleme doğrulanamadı. Güncel mesajı kontrol edip tekrar deneyin.';
            throw cause;
        } finally {
            state.saving = false;
        }
    }

    return { state, open, cancel, save };
}
