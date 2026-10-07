import type { Message, MessageReadUpdate } from '../types/chat';

export type MessageReadsEvent = {
    channel_id: number;
    reads: MessageReadUpdate[];
};

export function createMessageReadCounts(channelId: number) {
    const counts = new Map<number, number>();

    return {
        receive(event: MessageReadsEvent) {
            if (event.channel_id !== channelId) return;
            for (const update of event.reads) {
                counts.set(
                    update.message_id,
                    Math.max(
                        counts.get(update.message_id) ?? 0,
                        update.read_count,
                    ),
                );
            }
        },
        apply(message: Message): Message {
            if (message.channel_id !== channelId) return message;
            const readCount = Math.max(
                message.read_count,
                counts.get(message.id) ?? 0,
            );
            counts.set(message.id, readCount);
            return readCount === message.read_count
                ? message
                : { ...message, read_count: readCount };
        },
    };
}
