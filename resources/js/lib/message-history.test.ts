import { afterEach, expect, test, vi } from 'vitest';
import {
    createMessageHistory,
    isNearChatBottom,
    preserveMessageScroll,
} from './message-history';
import type { History } from '../types/chat';

const initial: History = {
    data: [],
    has_more: true,
    before_id: 50,
    after_id: 89,
};
const older: History = {
    data: [],
    has_more: true,
    before_id: 10,
    after_id: 49,
};

function start(fetchPage = vi.fn().mockResolvedValue(older)) {
    const restore = vi.fn();
    const capture = vi.fn(() => restore);
    const apply = vi.fn();
    const onError = vi.fn();
    const render = vi.fn().mockResolvedValue(undefined);
    const loader = createMessageHistory(
        initial,
        fetchPage,
        apply,
        capture,
        render,
        onError,
    );
    return { loader, fetchPage, restore, capture, apply, onError, render };
}

afterEach(() => vi.unstubAllGlobals());

test('rapid top observer triggers share one request and advance the cursor only after rendering', async () => {
    let callback!: IntersectionObserverCallback;
    const observe = vi.fn();
    vi.stubGlobal(
        'IntersectionObserver',
        class {
            observe = observe;
            unobserve = vi.fn();
            disconnect = vi.fn();
            constructor(
                handler: IntersectionObserverCallback,
                options: IntersectionObserverInit,
            ) {
                callback = handler;
                expect(options.rootMargin).toBe('200px 0px 0px 0px');
            }
        },
    );
    let resolve!: (page: History) => void;
    const fetchPage = vi
        .fn()
        .mockImplementationOnce(
            () =>
                new Promise<History>((done) => {
                    resolve = done;
                }),
        )
        .mockResolvedValue({ ...older, has_more: false, before_id: 1 });
    const { loader, capture, restore, apply } = start(fetchPage);
    loader.observe({} as HTMLElement, {} as HTMLElement);
    const intersect = () =>
        callback(
            [{ isIntersecting: true } as IntersectionObserverEntry],
            {} as IntersectionObserver,
        );

    intersect();
    intersect();
    intersect();
    expect(fetchPage).toHaveBeenCalledTimes(1);
    expect(capture).not.toHaveBeenCalled();
    expect(loader.loading.value).toBe(true);
    resolve(older);
    await vi.waitFor(() => expect(loader.loading.value).toBe(false));
    expect(apply).toHaveBeenCalledWith(older);
    expect(capture).toHaveBeenCalledOnce();
    expect(restore).toHaveBeenCalledOnce();
    intersect();
    await vi.waitFor(() => expect(loader.loading.value).toBe(false));
    expect(fetchPage.mock.calls.map(([cursor]) => cursor)).toEqual([50, 10]);
    intersect();
    expect(fetchPage).toHaveBeenCalledTimes(2);
    loader.disconnect();
});

test.each([null, 50, 60])(
    'non-advancing cursor %s stops automatic loading',
    async (cursor) => {
        const { loader, fetchPage } = start(
            vi.fn().mockResolvedValue({ ...older, before_id: cursor }),
        );
        await loader.loadOlder();
        await loader.loadOlder();
        expect(loader.hasMore.value).toBe(false);
        expect(fetchPage).toHaveBeenCalledOnce();
    },
);

test('end of history does not issue any request', async () => {
    const fetchPage = vi.fn();
    const loader = createMessageHistory(
        { ...initial, has_more: false },
        fetchPage,
        vi.fn(),
        vi.fn(),
        vi.fn(),
        vi.fn(),
    );
    await loader.loadOlder();
    expect(fetchPage).not.toHaveBeenCalled();
});

test('failed history requests preserve rows and allow manual retry without an automatic retry loop', async () => {
    const failure = new Error('offline');
    const { loader, fetchPage, apply, onError } = start(
        vi.fn().mockRejectedValueOnce(failure).mockResolvedValue(older),
    );
    await loader.loadOlder(true);
    await loader.loadOlder(true);
    expect(fetchPage).toHaveBeenCalledOnce();
    expect(apply).not.toHaveBeenCalled();
    expect(loader.failed.value).toBe(true);
    expect(loader.loading.value).toBe(false);
    expect(onError).toHaveBeenCalledWith(failure);
    await loader.loadOlder();
    expect(fetchPage.mock.calls.map(([cursor]) => cursor)).toEqual([50, 50]);
    expect(apply).toHaveBeenCalledWith(older);
    expect(loader.failed.value).toBe(false);
});

test('unmount aborts a pending request and ignores its late response', async () => {
    let resolve!: (page: History) => void;
    const { loader, fetchPage, apply, restore } = start(
        vi.fn().mockImplementation(
            () =>
                new Promise<History>((done) => {
                    resolve = done;
                }),
        ),
    );
    const loading = loader.loadOlder();
    const signal = fetchPage.mock.calls[0][1] as AbortSignal;
    loader.disconnect();
    resolve(older);
    await loading;
    expect(signal.aborted).toBe(true);
    expect(apply).not.toHaveBeenCalled();
    expect(restore).not.toHaveBeenCalled();
});

test('a visible anchor preserves position even when realtime appends also change total height', () => {
    const area = {
        scrollTop: 320,
        scrollHeight: 1200,
        getBoundingClientRect: () => ({ top: 0, bottom: 400 }),
    } as HTMLElement;
    let top = -20;
    const anchor = {
        isConnected: true,
        getBoundingClientRect: () => ({ top, bottom: top + 100 }),
    } as HTMLElement;
    const offscreen = {
        getBoundingClientRect: () => ({ top: -200, bottom: -100 }),
    } as HTMLElement;
    const restore = preserveMessageScroll(area, [offscreen, anchor]);
    top += 300;
    Object.assign(area, { scrollHeight: 1700 });
    restore();
    expect(area.scrollTop).toBe(620);
});

test('height difference preserves position when the visible anchor is removed', () => {
    const area = {
        scrollTop: 120,
        scrollHeight: 800,
        getBoundingClientRect: () => ({ top: 0, bottom: 400 }),
    } as HTMLElement;
    const anchor = {
        isConnected: false,
        getBoundingClientRect: () => ({ top: 20, bottom: 120 }),
    } as HTMLElement;
    const restore = preserveMessageScroll(area, [anchor]);
    Object.assign(area, { scrollHeight: 1100 });
    restore();
    expect(area.scrollTop).toBe(420);
    preserveMessageScroll(undefined, [])();
});

test.each([
    [0, false],
    [481, true],
    [480, false],
    [600, true],
])('bottom proximity at %ipx uses a 120px threshold', (scrollTop, expected) => {
    expect(
        isNearChatBottom({
            scrollTop,
            scrollHeight: 1000,
            clientHeight: 400,
        } as HTMLElement),
    ).toBe(expected);
});
