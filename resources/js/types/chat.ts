export type Message = {
    id: number;
    channel_id: number;
    body: string;
    created_at: string;
    user: { id: number; name: string };
};
export type History = {
    data: Message[];
    has_more: boolean;
    before_id: number | null;
    after_id: number | null;
};
