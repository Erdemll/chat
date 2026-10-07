import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { createUserActivityTracker } from './user-activity';

function eventTarget() {
    const listeners = new Map<string, Set<EventListener>>();
    return {
        addEventListener(name: string, listener: EventListener) {
            const registered = listeners.get(name) ?? new Set();
            registered.add(listener);
            listeners.set(name, registered);
        },
        removeEventListener(name: string, listener: EventListener) {
            listeners.get(name)?.delete(listener);
        },
        dispatch(name: string, trusted = true) {
            for (const listener of listeners.get(name) ?? [])
                listener({ isTrusted: trusted } as Event);
        },
        listenerCount() {
            return [...listeners.values()].reduce(
                (count, registered) => count + registered.size,
                0,
            );
        },
    };
}

let userId = 1000;
let focused: boolean;
let page: ReturnType<typeof eventTarget> & {
    hidden: boolean;
    hasFocus: () => boolean;
};
let browser: ReturnType<typeof eventTarget>;
let storage: Map<string, string>;
const trackers: ReturnType<typeof createUserActivityTracker>[] = [];

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-07T12:00:00Z'));
    focused = true;
    page = { ...eventTarget(), hidden: false, hasFocus: () => focused };
    browser = eventTarget();
    storage = new Map();
    vi.stubGlobal('document', page);
    vi.stubGlobal('window', browser);
    vi.stubGlobal('localStorage', {
        getItem: (key: string) => storage.get(key) ?? null,
        setItem: (key: string, value: string) => storage.set(key, value),
    });
});

afterEach(() => {
    for (const tracker of trackers.splice(0)) tracker.disconnect();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

function track(
    send = vi
        .fn<(signal: AbortSignal) => Promise<unknown>>()
        .mockResolvedValue(undefined),
    id = ++userId,
) {
    const tracker = createUserActivityTracker(id, send);
    trackers.push(tracker);
    return { tracker, send, id };
}

test('opening a visible focused page records once without an idle heartbeat', async () => {
    const { send } = track();

    await vi.advanceTimersByTimeAsync(600_000);

    expect(send).toHaveBeenCalledTimes(1);
});

test('hidden or unfocused pages wait for visible focus before recording activity', async () => {
    page.hidden = true;
    focused = false;
    const { tracker, send } = track();
    tracker.signal();
    await vi.advanceTimersByTimeAsync(120_000);
    expect(send).not.toHaveBeenCalled();

    page.hidden = false;
    page.dispatch('visibilitychange');
    await vi.advanceTimersByTimeAsync(0);
    expect(send).not.toHaveBeenCalled();
    focused = true;
    browser.dispatch('focus');
    await vi.advanceTimersByTimeAsync(0);

    expect(send).toHaveBeenCalledTimes(1);
});

test('bursts of trusted interactions coalesce into at most one request per two minutes', async () => {
    const { send } = track();
    await vi.advanceTimersByTimeAsync(0);
    await vi.advanceTimersByTimeAsync(30_000);

    for (let interaction = 0; interaction < 100; interaction++) {
        page.dispatch('pointerdown');
        page.dispatch('keydown');
        page.dispatch('wheel');
        page.dispatch('touchstart');
    }
    await vi.advanceTimersByTimeAsync(89_999);
    expect(send).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(send).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(600_000);

    expect(send).toHaveBeenCalledTimes(2);
});

test('synthetic interactions and automatic scrolls do not record activity', async () => {
    const { send } = track();
    await vi.advanceTimersByTimeAsync(0);

    page.dispatch('pointerdown', false);
    page.dispatch('keydown', false);
    page.dispatch('scroll');
    await vi.advanceTimersByTimeAsync(600_000);

    expect(send).toHaveBeenCalledTimes(1);
});

test('hiding the page cancels pending activity instead of sending in the background', async () => {
    const { send } = track();
    await vi.advanceTimersByTimeAsync(0);
    page.dispatch('wheel');

    page.hidden = true;
    page.dispatch('visibilitychange');
    await vi.advanceTimersByTimeAsync(600_000);

    expect(send).toHaveBeenCalledTimes(1);
    expect(vi.getTimerCount()).toBe(0);
});

test('navigation remounts and other tabs reuse the recent attempt timestamp', async () => {
    const first = track();
    await vi.advanceTimersByTimeAsync(0);
    first.tracker.disconnect();

    const second = track(undefined, first.id);
    await vi.advanceTimersByTimeAsync(0);
    expect(second.send).not.toHaveBeenCalled();
    second.tracker.disconnect();
    const nextId = ++userId;
    storage.set(`user-activity-attempt:${nextId}`, String(Date.now()));
    const otherTab = track(undefined, nextId);
    await vi.advanceTimersByTimeAsync(119_999);

    expect(otherTab.send).not.toHaveBeenCalled();
});

test('blocked storage still throttles activity across navigation in memory', async () => {
    vi.stubGlobal('localStorage', {
        getItem() {
            throw new Error('blocked');
        },
        setItem() {
            throw new Error('blocked');
        },
    });
    const first = track();
    await vi.advanceTimersByTimeAsync(0);
    first.tracker.disconnect();

    const second = track(undefined, first.id);
    await vi.advanceTimersByTimeAsync(0);

    expect(first.send).toHaveBeenCalledTimes(1);
    expect(second.send).not.toHaveBeenCalled();
});

test('a future storage timestamp does not permanently block activity after a clock change', async () => {
    const id = ++userId;
    storage.set(`user-activity-attempt:${id}`, String(Date.now() + 600_000));
    const { send } = track(undefined, id);

    await vi.advanceTimersByTimeAsync(0);

    expect(send).toHaveBeenCalledTimes(1);
});

test('network failures retry at the bounded interval and stop when the user becomes idle', async () => {
    const send = vi
        .fn<(signal: AbortSignal) => Promise<unknown>>()
        .mockRejectedValue(new Error('offline'));
    track(send);

    await vi.advanceTimersByTimeAsync(600_000);

    expect(send).toHaveBeenCalledTimes(2);
    expect(vi.getTimerCount()).toBe(0);
});

test('in flight activity requests never overlap and disconnect aborts and removes listeners', async () => {
    let finish: (() => void) | undefined;
    const send = vi
        .fn<(signal: AbortSignal) => Promise<unknown>>()
        .mockImplementation(
            () =>
                new Promise<void>((resolve) => {
                    finish = resolve;
                }),
        );
    const { tracker } = track(send);
    await vi.advanceTimersByTimeAsync(0);

    page.dispatch('wheel');
    await vi.advanceTimersByTimeAsync(120_000);
    expect(send).toHaveBeenCalledTimes(1);
    const signal = send.mock.calls[0][0];
    tracker.disconnect();
    finish?.();
    await vi.advanceTimersByTimeAsync(600_000);

    expect(signal.aborted).toBe(true);
    expect(send).toHaveBeenCalledTimes(1);
    expect(page.listenerCount()).toBe(0);
    expect(browser.listenerCount()).toBe(0);
    expect(vi.getTimerCount()).toBe(0);
});
