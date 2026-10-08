import { expect, test } from 'vitest';
import { createMessageEditor } from './message-editor';
import { RequestError } from './http';
import type { Message } from '../types/chat';

const ahmet = { id: 2, name: 'Ahmet' };
const mehmet = { id: 3, name: 'Mehmet' };
const message: Message = {
    id: 10,
    channel_id: 1,
    body: '@Ahmet rapora bak',
    created_at: '2026-10-08T10:00:00Z',
    edited_at: null,
    edit_expires_at: '2026-10-08T10:15:00Z',
    can_edit: true,
    can_delete: true,
    read_count: 2,
    mentions: [ahmet],
    user: { id: 1, name: 'Erdem' },
};

test('opening and canceling inline editing preserves the displayed original message', () => {
    const editor = createMessageEditor();
    editor.open(message);
    expect(editor.state.messageId).toBe(10);
    expect(editor.state.body).toBe(message.body);
    expect(editor.state.mentions).toEqual([ahmet]);
    editor.state.body = 'Taslak';
    editor.cancel();
    expect(editor.state.messageId).toBeNull();
    expect(message.body).toBe('@Ahmet rapora bak');
});

test('successful save submits current mentions and original body then closes edit mode', async () => {
    const editor = createMessageEditor();
    editor.open(message);
    editor.state.body = '@Mehmet rapora bak';
    editor.state.mentions = [ahmet, mehmet];
    await editor.save(async (id, payload) => {
        expect(id).toBe(10);
        expect(payload).toEqual({
            body: '@Mehmet rapora bak',
            mentions: [3],
            expected_body: message.body,
        });
        expect(editor.state.saving).toBe(true);
    });
    expect(editor.state.messageId).toBeNull();
    expect(editor.state.saving).toBe(false);
});

test('server validation errors keep the draft and edit mode open for correction', async () => {
    const editor = createMessageEditor();
    editor.open(message);
    editor.state.body = 'Yeni taslak';
    await expect(
        editor.save(async () => {
            throw new RequestError(422, { body: ['Mesaj uygun değil.'] });
        }),
    ).rejects.toBeInstanceOf(RequestError);
    expect(editor.state.body).toBe('Yeni taslak');
    expect(editor.state.messageId).toBe(10);
    expect(editor.state.error).toBe('Mesaj uygun değil.');
    expect(editor.state.saving).toBe(false);
});

test('mention validation and expired edit errors are shown inline', async () => {
    const editor = createMessageEditor();
    editor.open(message);
    await expect(
        editor.save(async () => {
            throw new RequestError(422, { mentions: ['Çalışan aktif değil.'] });
        }),
    ).rejects.toBeInstanceOf(RequestError);
    expect(editor.state.error).toBe('Çalışan aktif değil.');
    await expect(
        editor.save(async () => {
            throw new RequestError(403);
        }),
    ).rejects.toBeInstanceOf(RequestError);
    expect(editor.state.error).toBe('Bu mesaj artık düzenlenemez.');
});

test('stale conflict preserves draft and displays the server conflict message', async () => {
    const editor = createMessageEditor();
    editor.open(message);
    await expect(
        editor.save(async () => {
            throw new RequestError(409, { body: ['Mesaj değişti.'] });
        }),
    ).rejects.toBeInstanceOf(RequestError);
    expect(editor.state.messageId).toBe(10);
    expect(editor.state.error).toBe('Mesaj değişti.');
});

test('empty draft and duplicate submit do not send additional edit requests', async () => {
    const editor = createMessageEditor();
    editor.open(message);
    let submissions = 0;
    const submit = async () => {
        submissions++;
    };
    editor.state.body = '   ';
    await editor.save(submit);
    editor.state.body = 'Yeni mesaj';
    editor.state.saving = true;
    await editor.save(submit);
    expect(submissions).toBe(0);
});
