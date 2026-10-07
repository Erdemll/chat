export type Message = {
    id: number;
    channel_id: number;
    body: string;
    created_at: string;
    read_count: number;
    mentions: MentionableUser[];
    user: { id: number; name: string };
};
export type MentionableUser = { id: number; name: string };
export type MessageReader = {
    id: number;
    name: string;
    read_at: string;
};
export type MessageReadUpdate = {
    message_id: number;
    read_count: number;
};
export type History = {
    data: Message[];
    has_more: boolean;
    before_id: number | null;
    after_id: number | null;
};
