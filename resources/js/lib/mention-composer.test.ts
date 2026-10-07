import { expect, test } from 'vitest';
import {
    filterMentionableUsers,
    findMentionQuery,
    insertMention,
    retainMentions,
} from './mention-composer';

const ahmet = { id: 17, name: 'Ahmet Yılmaz' };
const isil = { id: 18, name: 'Işıl İzmir' };

test('typing @ and Turkish search terms at the caret opens mention suggestions', () => {
    expect(findMentionQuery('@', 1)).toEqual({ start: 0, end: 1, query: '' });
    expect(findMentionQuery('Merhaba @ış rapor', 11)).toEqual({
        start: 8,
        end: 11,
        query: 'ış',
    });
    expect(findMentionQuery('(@ah', 4)?.query).toBe('ah');
});

test('email addresses ordinary text and finished mentions do not open autocomplete', () => {
    for (const body of [
        'ahmet@example.com',
        'Normal mesaj',
        '@Ahmet Yılmaz ',
    ]) {
        expect(findMentionQuery(body, body.length)).toBeNull();
    }
});

test('filtering respects Turkish casing excludes the actor and limits visible suggestions', () => {
    expect(filterMentionableUsers([ahmet, isil], 'IŞ', 1)).toEqual([isil]);
    expect(filterMentionableUsers([ahmet, isil], 'İZ', 1)).toEqual([isil]);
    expect(filterMentionableUsers([ahmet, isil], 'ah', ahmet.id)).toEqual([]);
    expect(
        filterMentionableUsers(
            Array.from({ length: 20 }, (_, id) => ({
                id: id + 10,
                name: 'Çalışan',
            })),
            '',
            1,
        ),
    ).toHaveLength(8);
});

test('selecting a coworker inserts their full name while preserving text after the caret', () => {
    const body = 'Merhaba @ah rapora bak';
    const query = findMentionQuery(body, 11);
    expect(query).not.toBeNull();
    const insertion = insertMention(body, query!, ahmet);
    expect(insertion.body).toBe('Merhaba @Ahmet Yılmaz  rapora bak');
    expect(insertion.caret).toBe(22);
});

test('deleted and edited mention names are removed before submission without duplicate IDs', () => {
    expect(
        retainMentions('@Ahmet Yılmaz rapora bak', [ahmet, ahmet, isil]),
    ).toEqual([ahmet]);
    expect(retainMentions('rapora bak', [ahmet])).toEqual([]);
    expect(retainMentions('@Ahmet Yıl rapora bak', [ahmet])).toEqual([]);
    expect(retainMentions('@Ahmet Yılmazer', [ahmet])).toEqual([]);
    expect(retainMentions('user@Ahmet Yılmaz', [ahmet])).toEqual([]);
    expect(retainMentions('(@Ahmet Yılmaz), teşekkürler', [ahmet])).toEqual([
        ahmet,
    ]);
});

test('names containing markup and regex symbols stay literal text', () => {
    const user = { id: 19, name: '<script>alert(1)</script> A+B' };
    const query = findMentionQuery('@', 1)!;
    const insertion = insertMention('@', query, user);
    expect(insertion.body).toBe('@<script>alert(1)</script> A+B ');
    expect(retainMentions(insertion.body, [user])).toEqual([user]);
});
