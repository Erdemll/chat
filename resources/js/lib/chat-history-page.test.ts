/// <reference types="node" />
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import * as Vue from 'vue';
import {
    createRenderer,
    defineComponent,
    h,
    nextTick,
    ssrContextKey,
    type App,
} from 'vue';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { readFileSync } from 'node:fs';
import { stdout } from 'node:process';
import { performance as renderPerformance } from 'node:perf_hooks';
import Chat from '../pages/Chat/Index.vue';
import type { History, Message } from '../types/chat';
import { RequestError } from './http';

// Node tests import the SSR build; compile the same SFC's client template for mounting.
const { descriptor } = parse(
    readFileSync(new URL('../pages/Chat/Index.vue', import.meta.url), 'utf8'),
);
const bindings = compileScript(descriptor, {
    id: 'chat-history-test',
}).bindings;
const template = compileTemplate({
    source: descriptor.template!.content,
    filename: 'Index.vue',
    id: 'chat-history-test',
    compilerOptions: { mode: 'function', bindingMetadata: bindings },
});
// oxlint-disable-next-line typescript/no-implied-eval -- Execute trusted local Vue compiler output in this Node test.
Chat.render = new Function('Vue', template.code)(Vue);

const transport = vi.hoisted(() => ({
    request: vi.fn(),
    listeners: new Map<string, (event: unknown) => void>(),
    subscribed: () => {},
}));
vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent(() => () => null),
    router: { visit: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: 1 } } } }),
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent(
        (_, { slots }) =>
            () =>
                h('main', slots.default?.()),
    ),
}));
vi.mock('@/components/MessageEditor.vue', () => ({
    default: defineComponent(() => () => h('editor')),
}));
vi.mock('@/lib/http', async (original) => ({
    ...(await original<typeof import('./http')>()),
    requestJson: transport.request,
}));
vi.mock('@/lib/realtime', () => ({
    createEcho: () => {
        const channel = {
            listen(name: string, callback: (event: unknown) => void) {
                transport.listeners.set(name, callback);
                return channel;
            },
            subscribed(callback: () => void) {
                transport.subscribed = callback;
                return channel;
            },
            error() {
                return channel;
            },
        };
        return {
            private: () => channel,
            connector: { onConnectionChange: () => () => {} },
            disconnect: vi.fn(),
        };
    },
}));

// Vue's real component renderer with deterministic geometry; no browser layout is claimed.
class Node {
    children: Node[] = [];
    parent: Node | null = null;
    props: Record<string, unknown> = {};
    text = '';
    scrollTop = 0;
    clientHeight = 400;
    addEventListener = vi.fn();
    getRootNode = () => ({ activeElement: null });
    patches = 0;
    constructor(public tag: string) {}
    get isConnected(): boolean {
        return this.parent !== null;
    }
    get scrollHeight(): number {
        return (
            walk(this).filter((node) => node.tag === 'article').length * 100 +
            50
        );
    }
    getBoundingClientRect() {
        const area = walk(container).find((node) => node.props.role === 'log');
        const rows = area
            ? walk(area).filter((node) => node.tag === 'article')
            : [];
        const top =
            this.props.role === 'log'
                ? 0
                : this.tag === 'article'
                  ? 50 + rows.indexOf(this) * 100 - (area?.scrollTop ?? 0)
                  : -(area?.scrollTop ?? 0);
        return {
            top,
            bottom:
                top +
                (this.props.role === 'log'
                    ? 400
                    : this.tag === 'article'
                      ? 100
                      : 50),
            height: this.tag === 'article' ? 100 : 400,
        };
    }
}
function walk(node: Node): Node[] {
    return [node, ...node.children.flatMap(walk)];
}
function detach(node: Node) {
    if (node.parent)
        node.parent.children.splice(node.parent.children.indexOf(node), 1);
    node.parent = null;
}
const renderer = createRenderer<Node, Node>({
    createElement: (tag) => new Node(tag),
    createText: (text) => Object.assign(new Node('text'), { text }),
    createComment: (text) => Object.assign(new Node('comment'), { text }),
    setText: (node, text) => {
        node.text = text;
    },
    setElementText: (node, text) => {
        node.text = text;
        node.children = [];
    },
    parentNode: (node) => node.parent,
    nextSibling: (node) =>
        node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insert(node, parent, anchor = null) {
        detach(node);
        node.parent = parent;
        parent.children.splice(
            anchor ? parent.children.indexOf(anchor) : parent.children.length,
            0,
            node,
        );
    },
    remove: detach,
    patchProp: (node, key, _previous, value) => {
        node.patches++;
        node.props[key] = value;
    },
});
let container: Node;
let app: App<Node> | undefined;
let observers: MockObserver[];
class MockObserver {
    targets = new Set<Node>();
    constructor(
        public callback: IntersectionObserverCallback,
        public options: IntersectionObserverInit,
    ) {
        observers.push(this);
    }
    observe(node: Node) {
        this.targets.add(node);
    }
    unobserve(node: Node) {
        this.targets.delete(node);
    }
    disconnect() {
        this.targets.clear();
    }
    intersect(node: Node, visibleHeight = 100) {
        this.callback(
            [
                {
                    target: node,
                    isIntersecting: visibleHeight > 0,
                    boundingClientRect: { height: 100 },
                    rootBounds: { height: 400 },
                    intersectionRect: { height: visibleHeight },
                } as unknown as IntersectionObserverEntry,
            ],
            this as unknown as IntersectionObserver,
        );
    }
}
const message = (id: number, extra: Partial<Message> = {}): Message => ({
    id,
    channel_id: 1,
    body: `message ${id}`,
    created_at: '2026-10-08T10:00:00Z',
    edited_at: null,
    edit_expires_at: '2026-10-08T10:15:00Z',
    can_edit: false,
    can_delete: true,
    read_count: 0,
    mentions: [{ id: 3, name: 'Mention' }],
    user: { id: 2, name: 'Sender' },
    ...extra,
});
function history(ids: number[], hasMore = false): History {
    return {
        data: ids.map((id) => message(id)),
        has_more: hasMore,
        before_id: ids[0] ?? null,
        after_id: ids.at(-1) ?? null,
    };
}
async function settle() {
    for (let index = 0; index < 8; index++) {
        await Promise.resolve();
        await nextTick();
    }
}
async function mount(initial = history([50, 51, 52, 53, 54], true)) {
    app = renderer.createApp(Chat, {
        channel: { id: 1, name: 'General', slug: 'general' },
        history: initial,
    });
    app.provide(ssrContextKey, { modules: new Set() });
    app.mount(container);
    await settle();
    return walk(container).find((node) => node.props.role === 'log')!;
}
function rows() {
    return walk(container).filter((node) => node.tag === 'article');
}
function body(node: Node): string {
    return [node.text, ...node.children.map(body)].join(' ');
}
function event(name: string, id: number) {
    transport.listeners.get(name)?.({ message_id: id, channel_id: 1 });
}
function topObserver() {
    return observers.find((observer) => observer.options.rootMargin)!;
}
function topVisible() {
    const observer = topObserver();
    observer.intersect([...observer.targets][0]);
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-08T10:00:00Z'));
    transport.listeners.clear();
    transport.request.mockReset();
    observers = [];
    container = new Node('root');
    vi.stubGlobal('HTMLElement', Node);
    vi.stubGlobal('Document', class {});
    vi.stubGlobal('ShadowRoot', class {});
    vi.stubGlobal('IntersectionObserver', MockObserver);
    vi.stubGlobal('document', {
        hidden: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    });
});
afterEach(() => {
    app?.unmount();
    app = undefined;
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

test('initial rows are ordered deduplicated and scroll to the bottom', async () => {
    const initial = history([54, 50, 52, 50]);
    const area = await mount(initial);
    expect(rows().map(body)).toEqual([
        expect.stringContaining('message 50'),
        expect.stringContaining('message 52'),
        expect.stringContaining('message 54'),
    ]);
    expect(area.scrollTop).toBe(area.scrollHeight);
});

test('older pages preserve the users position at response time and register new rows for viewport reads', async () => {
    const area = await mount();
    area.scrollTop = 20;
    let resolve!: (page: History) => void;
    transport.request.mockImplementationOnce(
        () =>
            new Promise<History>((done) => {
                resolve = done;
            }),
    );
    topVisible();
    topVisible();
    expect(transport.request).toHaveBeenCalledOnce();
    area.scrollTop = 120;
    resolve(history([47, 48, 49, 50]));
    await settle();
    expect(rows()).toHaveLength(8);
    expect(area.scrollTop).toBe(420);
    expect(rows().map(body)[0]).toContain('message 47');
    const tracker = observers.find((observer) => !observer.options.rootMargin)!;
    expect(tracker.targets.has(rows()[0])).toBe(true);
    transport.request.mockResolvedValue({
        success: true,
        reads: [{ message_id: 47, read_count: 1 }],
    });
    await vi.advanceTimersByTimeAsync(500);
    expect(transport.request).toHaveBeenCalledOnce();
    tracker.intersect(rows()[0]);
    await vi.advanceTimersByTimeAsync(500);
    expect(transport.request.mock.calls[1][1].body).toBe(
        JSON.stringify({ message_ids: [47] }),
    );
});

test.each([true, false])(
    'realtime append at bottom=%s scrolls only when appropriate and deduplicates late HTTP responses',
    async (atBottom) => {
        const area = await mount();
        area.scrollTop = atBottom ? 150 : 0;
        transport.request.mockResolvedValue({ data: message(55) });
        event('.MessageCreated', 55);
        event('.MessageCreated', 55);
        await settle();
        expect(rows()).toHaveLength(6);
        expect(area.scrollTop).toBe(atBottom ? area.scrollHeight : 0);
        expect(body(container).includes('1 yeni mesaj')).toBe(!atBottom);
    },
);

test('loaded edit and delete events update rows while unloaded edits do not fetch history', async () => {
    await mount();
    event('.MessageUpdated', 1);
    event('.MessageDeleted', 1);
    expect(transport.request).not.toHaveBeenCalled();
    transport.request.mockResolvedValue({
        data: message(50, {
            body: 'edited content',
            edited_at: '2026-10-08T10:01:00Z',
        }),
    });
    event('.MessageUpdated', 50);
    await settle();
    expect(body(rows()[0])).toContain('edited content');
    transport.listeners.get('.MessageReadsUpdated')?.({
        channel_id: 1,
        reads: [{ message_id: 50, read_count: 3 }],
    });
    await settle();
    expect(body(rows()[0])).toContain('3 kişi okudu');
    event('.MessageDeleted', 50);
    await settle();
    expect(rows()).toHaveLength(4);
    expect(body(container)).not.toContain('edited content');
});

test('delete during an older request cannot reinsert the deleted row', async () => {
    await mount();
    let resolve!: (page: History) => void;
    transport.request.mockImplementation(
        () =>
            new Promise<History>((done) => {
                resolve = done;
            }),
    );
    topVisible();
    event('.MessageDeleted', 49);
    resolve(history([48, 49]));
    await settle();
    expect(rows()).toHaveLength(6);
    expect(body(container)).not.toContain('message 49');
});

test('reconnect reconciles loaded edits and deletes then deduplicates forward pages without forcing history readers down', async () => {
    const area = await mount();
    area.scrollTop = 0;
    transport.request
        .mockResolvedValueOnce({
            ...history([50, 51, 52, 53]),
            data: [
                message(50, {
                    body: 'reconciled edit',
                    edited_at: '2026-10-08T10:01:00Z',
                }),
                ...history([51, 52, 53]).data,
            ],
        })
        .mockResolvedValueOnce(history([55, 56], true))
        .mockResolvedValueOnce(history([56, 57]));
    transport.subscribed();
    await settle();
    expect(rows()).toHaveLength(7);
    expect(body(container)).toContain('reconciled edit');
    expect(body(container)).not.toContain('message 54');
    expect(body(container)).toContain('3 yeni mesaj');
    expect(area.scrollTop).toBe(0);
    expect(transport.request.mock.calls[1][0]).toContain('after_id=54');
    expect(transport.request.mock.calls[2][0]).toContain('after_id=56');
});

test('history failures leave rows intact and a retry button resumes the same cursor', async () => {
    await mount();
    transport.request
        .mockRejectedValueOnce(new RequestError(500))
        .mockResolvedValueOnce(history([49]));
    topVisible();
    await settle();
    topVisible();
    await settle();
    expect(rows()).toHaveLength(5);
    expect(transport.request).toHaveBeenCalledOnce();
    const retry = walk(container).find(
        (node) => node.tag === 'button' && body(node).includes('Tekrar dene'),
    )!;
    (retry.props.onClick as () => void)();
    await settle();
    expect(rows()).toHaveLength(6);
    expect(transport.request.mock.calls.map(([url]) => url)).toEqual([
        '/messages?before_id=50',
        '/messages?before_id=50',
    ]);
});

test.each([40, 1000, 5000])(
    'Vue renders %i loaded messages and memoizes rows while the user types',
    async (count) => {
        const started = renderPerformance.now();
        await mount(
            history(Array.from({ length: count }, (_, index) => index + 1)),
        );
        const elapsed = renderPerformance.now() - started;
        expect(rows()).toHaveLength(count);
        const existing = rows()[Math.floor(count / 2)];
        const patches = existing.patches;
        const composer = walk(container).find(
            (node) => node.tag === 'textarea',
        )!;
        const typingStarted = renderPerformance.now();
        (composer.props['onUpdate:modelValue'] as (text: string) => void)(
            'new draft',
        );
        await settle();
        expect(rows()[Math.floor(count / 2)]).toBe(existing);
        expect(existing.patches).toBe(patches);
        const typingElapsed = renderPerformance.now() - typingStarted;
        transport.request.mockResolvedValue({ data: message(count + 1) });
        const appendStarted = renderPerformance.now();
        event('.MessageCreated', count + 1);
        await settle();
        expect(rows()).toHaveLength(count + 1);
        stdout.write(
            `${count} messages: Vue simulated initial render ${elapsed.toFixed(2)}ms, typing update ${typingElapsed.toFixed(2)}ms, append ${(renderPerformance.now() - appendStarted).toFixed(2)}ms\n`,
        );
    },
);
