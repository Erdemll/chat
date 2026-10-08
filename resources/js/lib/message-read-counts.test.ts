import { expect, test } from 'vitest';
import { createMessageReadCounts } from './message-read-counts';
import type { Message } from '../types/chat';

const message = (readCount = 0): Message => ({
    id: 10,
    channel_id: 1,
    body: 'Merhaba',
    created_at: '2026-10-07T12:00:00Z',
    edited_at: null,
    edit_expires_at: '2026-10-07T12:15:00Z',
    can_edit: false,
    can_delete: false,
    read_count: readCount,
    mentions: [],
    user: { id: 2, name: 'Ahmet' },
});

test('realtime batches immediately replace counts without counting duplicate delivery twice', () => {
    const counts = createMessageReadCounts(1);
    const event = { channel_id: 1, reads: [{ message_id: 10, read_count: 4 }] };
    counts.receive(event);
    const updated = counts.apply(message());
    counts.receive(event);

    expect(updated.read_count).toBe(4);
    expect(counts.apply(updated).read_count).toBe(4);
});

test('older events and HTTP responses cannot overwrite newer read counts', () => {
    const counts = createMessageReadCounts(1);
    counts.receive({
        channel_id: 1,
        reads: [{ message_id: 10, read_count: 5 }],
    });
    counts.receive({
        channel_id: 1,
        reads: [{ message_id: 10, read_count: 3 }],
    });

    expect(counts.apply(message(2)).read_count).toBe(5);
    expect(counts.apply(message(6)).read_count).toBe(6);
    expect(counts.apply(message(1)).read_count).toBe(6);
});

test('read events arriving before a message response survive its later insertion', () => {
    const counts = createMessageReadCounts(1);
    counts.receive({
        channel_id: 1,
        reads: [{ message_id: 10, read_count: 1 }],
    });

    expect(counts.apply(message(0)).read_count).toBe(1);
});

test('events and messages from other channels cannot change the current chat', () => {
    const counts = createMessageReadCounts(1);
    counts.receive({
        channel_id: 2,
        reads: [{ message_id: 10, read_count: 9 }],
    });

    expect(counts.apply(message()).read_count).toBe(0);
    expect(counts.apply({ ...message(3), channel_id: 2 }).read_count).toBe(3);
});
