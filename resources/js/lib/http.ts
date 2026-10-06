export class RequestError extends Error {
    constructor(
        public status: number,
        public errors: Record<string, string[]> = {},
    ) {
        super('İstek tamamlanamadı.');
    }
}
export function csrfHeaders(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));
    return cookie
        ? {
              'X-XSRF-TOKEN': decodeURIComponent(
                  cookie.slice('XSRF-TOKEN='.length),
              ),
          }
        : {};
}
export async function requestJson<T>(
    url: string,
    options: RequestInit = {},
): Promise<T> {
    const headers = new Headers(options.headers);
    headers.set('Accept', 'application/json');
    headers.set('Content-Type', 'application/json');
    for (const [name, value] of Object.entries(csrfHeaders())) {
        headers.set(name, value);
    }
    const response = await fetch(url, {
        ...options,
        credentials: 'same-origin',
        headers,
    });
    if (!response.ok) {
        const payload = (await response.json().catch(() => ({}))) as {
            errors?: Record<string, string[]>;
        };
        throw new RequestError(response.status, payload.errors);
    }
    return response.json() as Promise<T>;
}
