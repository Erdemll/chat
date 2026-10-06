import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { authenticate } from '@/actions/Illuminate/Broadcasting/BroadcastController';
import { csrfHeaders } from '@/lib/http';
export function createEcho(): Echo<'reverb'> | null {
    const key = import.meta.env.VITE_REVERB_APP_KEY;
    const host = import.meta.env.VITE_REVERB_HOST;
    if (!key || !host) return null;
    return new Echo<'reverb'>({
        broadcaster: 'reverb',
        Pusher,
        key,
        wsHost: host,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT || 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
        forceTLS: import.meta.env.VITE_REVERB_SCHEME !== 'http',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: authenticate.url(),
        channelAuthorization: {
            endpoint: authenticate.url(),
            transport: 'ajax',
            headersProvider: csrfHeaders,
        },
    });
}
