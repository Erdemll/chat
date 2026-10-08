import type { Message } from '../types/chat';

export type MessageMutationEvent = { message_id: number; channel_id: number };

export function createMessageMutations(channelId: number) {
    const deleted = new Set<number>();
    const requests = new Map<number, number>();

    function remove(
        messages: Message[],
        event: MessageMutationEvent,
    ): Message[] {
        if (event.channel_id !== channelId) return messages;
        deleted.add(event.message_id);
        requests.delete(event.message_id);
        return messages.filter((message) => message.id !== event.message_id);
    }

    function merge(messages: Message[], incoming: Message[]): Message[] {
        const rows = new Map(messages.map((message) => [message.id, message]));
        for (const message of incoming) {
            if (message.channel_id !== channelId || deleted.has(message.id))
                continue;
            const existing = rows.get(message.id);
            if (
                existing &&
                Date.parse(existing.edited_at ?? existing.created_at) >
                    Date.parse(message.edited_at ?? message.created_at)
            )
                continue;
            rows.set(message.id, message);
        }
        return [...rows.values()]
            .filter((message) => !deleted.has(message.id))
            .sort((a, b) => a.id - b.id);
    }

    async function refresh(
        event: MessageMutationEvent,
        fetchMessage: (id: number) => Promise<Message>,
        apply: (message: Message) => void,
    ): Promise<void> {
        if (event.channel_id !== channelId || deleted.has(event.message_id))
            return;
        const request = (requests.get(event.message_id) ?? 0) + 1;
        requests.set(event.message_id, request);
        const message = await fetchMessage(event.message_id);
        if (
            !deleted.has(event.message_id) &&
            requests.get(event.message_id) === request
        )
            apply(message);
    }

    return { remove, merge, refresh };
}
