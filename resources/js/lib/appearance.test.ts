/// <reference types="node" />
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { afterEach, expect, test, vi } from 'vitest';
import { createAppearance } from './appearance';

function browser(systemDark = false, saved: string | null = null) {
    const entries = new Map<string, string>();
    if (saved !== null) entries.set('tepenet.appearance', saved);
    const classes = new Set<string>();
    const root = {
        classList: {
            toggle: (name: string, enabled: boolean) => {
                if (enabled) classes.add(name);
                else classes.delete(name);
            },
        },
        style: { colorScheme: '' },
    };
    const changes = new Set<() => void>();
    const storageChanges = new Set<(event: StorageEvent) => void>();
    const media = {
        matches: systemDark,
        addEventListener: (_event: string, callback: () => void) =>
            changes.add(callback),
        removeEventListener: (_event: string, callback: () => void) =>
            changes.delete(callback),
    };
    const storage = {
        getItem: vi.fn((key: string) => entries.get(key) ?? null),
        setItem: vi.fn((key: string, value: string) => entries.set(key, value)),
        removeItem: vi.fn((key: string) => entries.delete(key)),
    };
    const windowObject = {
        localStorage: storage,
        matchMedia: vi.fn(() => media),
        addEventListener: (
            _event: string,
            callback: (event: StorageEvent) => void,
        ) => storageChanges.add(callback),
        removeEventListener: (
            _event: string,
            callback: (event: StorageEvent) => void,
        ) => storageChanges.delete(callback),
    };
    const documentObject = { documentElement: root };
    vi.stubGlobal('window', windowObject);
    vi.stubGlobal('document', documentObject);
    return {
        entries,
        classes,
        root,
        changes,
        storageChanges,
        media,
        storage,
        windowObject,
        documentObject,
    };
}

afterEach(() => vi.unstubAllGlobals());

test.each([
    ['dark', false, true],
    ['light', true, false],
    [null, true, true],
    ['invalid', false, false],
] as const)(
    'saved preference %s resolves against system dark=%s',
    (saved, systemDark, expected) => {
        const env = browser(systemDark, saved);

        const theme = createAppearance();

        expect(theme.isDark.value).toBe(expected);
        expect(env.classes.has('dark')).toBe(expected);
        expect(env.root.style.colorScheme).toBe(expected ? 'dark' : 'light');
    },
);

test('system changes update the theme until a manual preference is chosen', () => {
    const env = browser();
    const theme = createAppearance();

    env.media.matches = true;
    env.changes.forEach((callback) => callback());

    expect(theme.isDark.value).toBe(true);
    theme.setAppearance('light');
    env.changes.forEach((callback) => callback());
    expect(theme.isDark.value).toBe(false);
    expect(env.entries.get('tepenet.appearance')).toBe('light');
});

test('manual selection survives reload and choosing system removes the saved override', () => {
    const env = browser(false);
    const first = createAppearance();
    first.setAppearance('dark');
    first.disconnect();

    const next = createAppearance();

    expect(next.appearance.value).toBe('dark');
    expect(next.isDark.value).toBe(true);
    next.setAppearance('system');
    expect(env.entries.has('tepenet.appearance')).toBe(false);
    expect(next.isDark.value).toBe(false);
});

test('blocked storage still permits system detection and manual theme changes', () => {
    const env = browser(true);
    Object.defineProperty(env.windowObject, 'localStorage', {
        get: () => {
            throw new Error('Storage blocked');
        },
    });
    const theme = createAppearance();

    theme.setAppearance('light');

    expect(theme.appearance.value).toBe('light');
    expect(theme.isDark.value).toBe(false);
    expect(env.classes.has('dark')).toBe(false);
});

test('another tab can update or reset the theme without unrelated keys changing it', () => {
    const env = browser(false);
    const theme = createAppearance();

    for (const callback of env.storageChanges)
        callback({
            key: 'tepenet.appearance',
            newValue: 'dark',
            storageArea: env.storage,
        } as unknown as StorageEvent);

    expect(theme.isDark.value).toBe(true);
    for (const callback of env.storageChanges)
        callback({
            key: 'unrelated',
            newValue: 'light',
            storageArea: env.storage,
        } as unknown as StorageEvent);
    expect(theme.isDark.value).toBe(true);
    for (const callback of env.storageChanges)
        callback({
            key: null,
            newValue: null,
            storageArea: env.storage,
        } as unknown as StorageEvent);
    expect(theme.appearance.value).toBe('system');
    expect(theme.isDark.value).toBe(false);
});

test('disconnect removes media and storage listeners', () => {
    const env = browser();
    const theme = createAppearance();

    theme.disconnect();

    expect(env.changes.size).toBe(0);
    expect(env.storageChanges.size).toBe(0);
});

test('server rendering has no browser dependency or shared user preference', () => {
    const first = createAppearance();

    first.setAppearance('dark');
    const second = createAppearance();

    expect(second.appearance.value).toBe('system');
    expect(second.isDark.value).toBe(false);
});

test.each([
    ['dark', false, true],
    ['light', true, false],
    [null, true, true],
    ['invalid', true, true],
] as const)(
    'head bootstrap applies %s before Vue loads',
    (saved, systemDark, expected) => {
        const env = browser(systemDark, saved);
        const blade = readFileSync(
            new URL('../../views/app.blade.php', import.meta.url),
            'utf8',
        );
        const script = blade.match(/<script>([\s\S]*?)<\/script>/u)?.[1];
        expect(script).toBeDefined();

        runInNewContext(script!, {
            window: env.windowObject,
            document: env.documentObject,
            localStorage: env.storage,
        });

        expect(env.classes.has('dark')).toBe(expected);
        expect(blade.indexOf('<script>')).toBeLessThan(blade.indexOf('@vite'));
    },
);

test('head bootstrap falls back to the system theme when storage is blocked', () => {
    const env = browser(true);
    const blade = readFileSync(
        new URL('../../views/app.blade.php', import.meta.url),
        'utf8',
    );
    const context = { window: env.windowObject, document: env.documentObject };
    Object.defineProperty(context, 'localStorage', {
        get: () => {
            throw new Error('Storage blocked');
        },
    });

    runInNewContext(blade.match(/<script>([\s\S]*?)<\/script>/u)![1], context);

    expect(env.classes.has('dark')).toBe(true);
});
