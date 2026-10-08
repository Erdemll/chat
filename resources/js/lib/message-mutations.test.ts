import { expect, test } from 'vitest';
import { createMessageMutations } from './message-mutations';
import type { Message } from '../types/chat';

const message = (overrides: Partial<Message> = {}): Message => ({
    id: 10,
    channel_id: 1,
    body: 'Eski mesaj',
    created_at: '2026-10-08T10:00:00Z',
    edited_at: null,
    edit_expires_at: '2026-10-08T10:15:00Z',
    can_edit: true,
    can_delete: true,
    read_count: 2,
    mentions: [],
    user: { id: 1, name: 'Erdem' },
    ...overrides,
});
const event = { message_id: 10, channel_id: 1 };

test('successful local deletion and repeated realtime deletion remove the message entirely', () => {
    const state = createMessageMutations(1);
    const rows = [message(), message({ id: 11 })];
    const removed = state.remove(rows, event);
    expect(removed.map((row) => row.id)).toEqual([11]);
    expect(state.remove(removed, event)).toEqual(removed);
});

test('a late history or show response cannot resurrect a deleted message', () => {
    const state = createMessageMutations(1);
    const removed = state.remove([message()], event);
    expect(state.merge(removed, [message()])).toEqual([]);
});

test('foreign channel mutations and messages do not affect the current list', async () => {
    const state = createMessageMutations(1);
    const rows = [message()];
    expect(state.remove(rows, { ...event, channel_id: 2 })).toBe(rows);
    expect(state.merge(rows, [message({ channel_id: 2 })])).toEqual(rows);
    let requested = false;
    await state.refresh(
        { ...event, channel_id: 2 },
        async () => {
            requested = true;
            return message();
        },
        () => {},
    );
    expect(requested).toBe(false);
});

test('MessageUpdated fetches and replaces existing content without duplicates or lost read counts', async () => {
    const state = createMessageMutations(1);
    let rows = [message()];
    const updated = message({
        body: 'Güncel mesaj',
        edited_at: '2026-10-08T10:05:00Z',
    });
    await state.refresh(
        event,
        async (id) => {
            expect(id).toBe(10);
            return updated;
        },
        (row) => {
            rows = state.merge(rows, [row]);
        },
    );
    expect(rows).toEqual([updated]);
    expect(state.merge(rows, [updated])).toEqual([updated]);
    expect(rows[0].read_count).toBe(2);
});

test('a stale history response does not overwrite a newer edited message', () => {
    const state = createMessageMutations(1);
    const updated = message({
        body: 'Güncel mesaj',
        edited_at: '2026-10-08T10:05:00Z',
    });
    expect(state.merge([updated], [message()])).toEqual([updated]);
});

test('a delete event during a pending update fetch prevents reinsertion', async () => {
    const state = createMessageMutations(1);
    let resolve!: (value: Message) => void;
    let rows = [message()];
    const pending = state.refresh(
        event,
        () =>
            new Promise<Message>((done) => {
                resolve = done;
            }),
        (row) => {
            rows = state.merge(rows, [row]);
        },
    );
    rows = state.remove(rows, event);
    resolve(message({ body: 'Düzenlenen mesaj' }));
    await pending;
    expect(rows).toEqual([]);
});

test('overlapping update fetches ignore an older response that arrives last', async () => {
    const state = createMessageMutations(1);
    let firstResolve!: (value: Message) => void;
    let rows = [message()];
    const apply = (row: Message) => {
        rows = state.merge(rows, [row]);
    };
    const first = state.refresh(
        event,
        () =>
            new Promise<Message>((done) => {
                firstResolve = done;
            }),
        apply,
    );
    const newest = message({
        body: 'Son mesaj',
        edited_at: '2026-10-08T10:05:00Z',
    });
    await state.refresh(event, async () => newest, apply);
    firstResolve(message({ body: 'Ara mesaj', edited_at: newest.edited_at }));
    await first;
    expect(rows).toEqual([newest]);
});

test('merging forward and backward pages keeps a unique ascending message list', () => {
    const state = createMessageMutations(1);
    const result = state.merge(
        [message()],
        [message({ id: 11 }), message({ id: 9 }), message()],
    );
    expect(result.map((row) => row.id)).toEqual([9, 10, 11]);
});
