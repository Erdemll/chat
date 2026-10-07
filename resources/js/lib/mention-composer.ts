import type { MentionableUser } from '../types/chat';

export type MentionQuery = { start: number; end: number; query: string };

export function findMentionQuery(
    body: string,
    caret: number,
): MentionQuery | null {
    const prefix = body.slice(0, caret);
    const match = /(?:^|[\s([{])@([\p{L}\p{N}_-]*)$/u.exec(prefix);
    if (!match) return null;
    return { start: prefix.lastIndexOf('@'), end: caret, query: match[1] };
}

export function filterMentionableUsers(
    users: MentionableUser[],
    query: string,
    currentUserId: number,
): MentionableUser[] {
    const normalized = query.toLocaleLowerCase('tr-TR');
    return users
        .filter(
            (user) =>
                user.id !== currentUserId &&
                user.name.toLocaleLowerCase('tr-TR').includes(normalized),
        )
        .slice(0, 8);
}

export function retainMentions(
    body: string,
    selected: MentionableUser[],
): MentionableUser[] {
    const seen = new Set<number>();
    return selected.filter((user) => {
        if (seen.has(user.id)) return false;
        seen.add(user.id);
        const token = `@${user.name}`.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return new RegExp(
            `(?:^|[^\\p{L}\\p{N}_@])${token}(?=$|[^\\p{L}\\p{N}_])`,
            'u',
        ).test(body);
    });
}

export function insertMention(
    body: string,
    query: MentionQuery,
    user: MentionableUser,
): { body: string; caret: number } {
    const token = `@${user.name} `;
    return {
        body: body.slice(0, query.start) + token + body.slice(query.end),
        caret: query.start + token.length,
    };
}
