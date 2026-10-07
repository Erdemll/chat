import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import {
    createMessageReadTracker,
    type MessageReadTracker,
} from './message-reads';

let callback: IntersectionObserverCallback;
let options: IntersectionObserverInit | undefined;
let tracker: MessageReadTracker | undefined;
let hidden = false;
const observe = vi.fn();
const unobserve = vi.fn();
const disconnect = vi.fn();
const root = { clientHeight: 400 } as HTMLElement;

function entry(
    target: HTMLElement,
    height = 200,
    visibleHeight = 100,
): IntersectionObserverEntry {
    const rectangle = (value: number): DOMRectReadOnly => ({
        x: 0,
        y: 0,
        width: 100,
        height: value,
        top: 0,
        right: 100,
        bottom: value,
        left: 0,
        toJSON: () => ({}),
    });
    return {
        target,
        time: 0,
        intersectionRatio: visibleHeight / height,
        isIntersecting: visibleHeight > 0,
        boundingClientRect: rectangle(height),
        intersectionRect: rectangle(visibleHeight),
        rootBounds: rectangle(400),
    };
}

function visible(entries: IntersectionObserverEntry[]) {
    callback(entries, {} as IntersectionObserver);
}

function start(
    send = vi
        .fn<(ids: number[], signal: AbortSignal) => Promise<void>>()
        .mockResolvedValue(undefined),
    error = vi.fn(),
) {
    tracker = createMessageReadTracker(root, 1, send, error);
    return { send, error, tracker };
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    hidden = false;
    vi.stubGlobal('document', {
        get hidden() {
            return hidden;
        },
    });
    vi.stubGlobal(
        'IntersectionObserver',
        class {
            observe = observe;
            unobserve = unobserve;
            disconnect = disconnect;
            constructor(
                handler: IntersectionObserverCallback,
                config?: IntersectionObserverInit,
            ) {
                callback = handler;
                options = config;
            }
        },
    );
});

afterEach(() => {
    tracker?.disconnect();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

test('loaded offscreen partial and own messages do not create read requests', async () => {
    const { tracker, send } = start();
    const element = {} as HTMLElement;
    const own = {} as HTMLElement;
    tracker.observe(element, 10, 2);
    tracker.observe(own, 11, 1);

    await vi.advanceTimersByTimeAsync(1000);
    expect(send).not.toHaveBeenCalled();
    visible([entry(element, 200, 0), entry(own), entry(element, 200, 40)]);
    await vi.advanceTimersByTimeAsync(1000);

    expect(send).not.toHaveBeenCalled();
    expect(observe).toHaveBeenCalledTimes(1);
    expect(options?.root).toBe(root);
});

test('visible messages share one 500ms batch and successful IDs are never resent', async () => {
    const { tracker, send } = start();
    const first = {} as HTMLElement;
    const second = {} as HTMLElement;
    tracker.observe(first, 10, 2);
    tracker.observe(second, 11, 2);
    visible([entry(first), entry(second), entry(first)]);

    await vi.advanceTimersByTimeAsync(499);
    expect(send).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(send).toHaveBeenCalledTimes(1);
    expect(send.mock.calls[0][0]).toEqual([10, 11]);
    visible([entry(first), entry(second)]);
    await vi.advanceTimersByTimeAsync(1000);

    expect(send).toHaveBeenCalledTimes(1);
});

test('large queues are split into requests of no more than 100 IDs', async () => {
    const { tracker, send } = start();
    const entries = Array.from({ length: 205 }, (_, index) => {
        const element = {} as HTMLElement;
        tracker.observe(element, index + 10, 2);
        return entry(element);
    });

    visible(entries);
    await vi.advanceTimersByTimeAsync(1500);

    expect(send.mock.calls.map(([ids]) => ids.length)).toEqual([100, 100, 5]);
    expect(new Set(send.mock.calls.flatMap(([ids]) => ids)).size).toBe(205);
});

test('failed reads stay pending and retry before becoming sent', async () => {
    const send = vi
        .fn<(ids: number[], signal: AbortSignal) => Promise<void>>()
        .mockRejectedValueOnce(new Error('network unavailable'))
        .mockResolvedValue(undefined);
    const { tracker, error } = start(send);
    const element = {} as HTMLElement;
    tracker.observe(element, 10, 2);
    visible([entry(element)]);

    await vi.advanceTimersByTimeAsync(500);
    expect(error).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1000);

    expect(send.mock.calls.map(([ids]) => ids)).toEqual([[10], [10]]);
    visible([entry(element)]);
    await vi.advanceTimersByTimeAsync(1000);
    expect(send).toHaveBeenCalledTimes(2);
});

test('hidden tabs wait for fresh visibility observation before recording new reads', async () => {
    const { tracker, send } = start();
    const element = {} as HTMLElement;
    tracker.observe(element, 10, 2);
    hidden = true;
    visible([entry(element)]);
    await vi.advanceTimersByTimeAsync(1000);
    expect(send).not.toHaveBeenCalled();

    hidden = false;
    tracker.resume();
    await vi.advanceTimersByTimeAsync(1000);
    expect(send).not.toHaveBeenCalled();
    visible([entry(element)]);
    await vi.advanceTimersByTimeAsync(500);

    expect(send.mock.calls[0][0]).toEqual([10]);
});

test('already visible pending reads pause while the tab is hidden and resume as one batch', async () => {
    const { tracker, send } = start();
    const element = {} as HTMLElement;
    tracker.observe(element, 10, 2);
    visible([entry(element)]);
    hidden = true;
    await vi.advanceTimersByTimeAsync(500);
    expect(send).not.toHaveBeenCalled();

    hidden = false;
    tracker.resume();
    await vi.advanceTimersByTimeAsync(500);

    expect(send.mock.calls[0][0]).toEqual([10]);
});

test.each([2000, 50000])(
    'messages %ipx tall can be read when half the viewport contains them',
    async (height) => {
        const { tracker, send } = start();
        const element = {} as HTMLElement;
        tracker.observe(element, 10, 2);
        visible([entry(element, height, 150)]);
        await vi.advanceTimersByTimeAsync(500);
        expect(send).not.toHaveBeenCalled();

        visible([entry(element, height, 200)]);
        await vi.advanceTimersByTimeAsync(500);

        expect(send.mock.calls[0][0]).toEqual([10]);
        const thresholds = options?.threshold as number[];
        expect(
            thresholds.some(
                (ratio) => ratio >= 200 / height && ratio <= 400 / height,
            ),
        ).toBe(true);
    },
);

test('disconnect cancels pending timers and ignores future observer callbacks', async () => {
    const { tracker, send } = start();
    const element = {} as HTMLElement;
    tracker.observe(element, 10, 2);
    visible([entry(element)]);

    tracker.disconnect();
    visible([entry(element)]);
    await vi.advanceTimersByTimeAsync(1000);

    expect(send).not.toHaveBeenCalled();
    expect(disconnect).toHaveBeenCalled();
});

test('disconnect aborts in-flight reads and does not schedule more work', async () => {
    let resolve: (() => void) | undefined;
    const send = vi
        .fn<(ids: number[], signal: AbortSignal) => Promise<void>>()
        .mockImplementation(
            () =>
                new Promise<void>((done) => {
                    resolve = done;
                }),
        );
    const { tracker } = start(send);
    const element = {} as HTMLElement;
    tracker.observe(element, 10, 2);
    visible([entry(element)]);
    await vi.advanceTimersByTimeAsync(500);

    tracker.disconnect();
    resolve?.();
    await vi.advanceTimersByTimeAsync(1000);

    expect(send.mock.calls[0][1].aborted).toBe(true);
    expect(send).toHaveBeenCalledTimes(1);
});
