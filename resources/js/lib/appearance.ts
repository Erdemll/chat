import { readonly, ref } from 'vue';

export type Appearance = 'system' | 'light' | 'dark';
const storageKey = 'tepenet.appearance';

function preference(value: string | null): Appearance {
    return value === 'light' || value === 'dark' ? value : 'system';
}

export function createAppearance() {
    const appearance = ref<Appearance>('system');
    const isDark = ref(false);
    let media: MediaQueryList | undefined;

    function apply() {
        isDark.value =
            appearance.value === 'dark' ||
            (appearance.value === 'system' && (media?.matches ?? false));
        if (typeof document !== 'undefined') {
            document.documentElement.classList.toggle('dark', isDark.value);
            document.documentElement.style.colorScheme = isDark.value
                ? 'dark'
                : 'light';
        }
    }

    function systemChanged() {
        if (appearance.value === 'system') apply();
    }

    function storageChanged(event: StorageEvent) {
        if (event.key !== storageKey && event.key !== null) return;
        try {
            if (event.storageArea && event.storageArea !== window.localStorage)
                return;
        } catch {
            return;
        }
        appearance.value = preference(event.newValue);
        apply();
    }

    function setAppearance(value: Appearance) {
        appearance.value = value;
        apply();
        try {
            if (value === 'system') window.localStorage.removeItem(storageKey);
            else window.localStorage.setItem(storageKey, value);
        } catch {
            // The current page can still change theme when storage is unavailable.
        }
    }

    if (typeof window !== 'undefined') {
        media = window.matchMedia('(prefers-color-scheme: dark)');
        try {
            appearance.value = preference(
                window.localStorage.getItem(storageKey),
            );
        } catch {
            appearance.value = 'system';
        }
        apply();
        media.addEventListener('change', systemChanged);
        window.addEventListener('storage', storageChanged);
    }

    function disconnect() {
        media?.removeEventListener('change', systemChanged);
        if (typeof window !== 'undefined')
            window.removeEventListener('storage', storageChanged);
    }

    return {
        appearance: readonly(appearance),
        isDark: readonly(isDark),
        setAppearance,
        disconnect,
    };
}

let shared: ReturnType<typeof createAppearance> | undefined;

export function useAppearance() {
    if (typeof window === 'undefined') return createAppearance();
    return (shared ??= createAppearance());
}
