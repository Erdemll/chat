import { ref } from 'vue';
import type { History } from '../types/chat';

export function isNearChatBottom(area: HTMLElement | undefined): boolean {
    return (
        !area || area.scrollHeight - area.scrollTop - area.clientHeight < 120
    );
}

export function preserveMessageScroll(
    area: HTMLElement | undefined,
    elements: Iterable<HTMLElement>,
): () => void {
    if (!area) return () => {};
    const bounds = area.getBoundingClientRect();
    let anchor: HTMLElement | undefined;
    let anchorTop = Infinity;
    for (const element of elements) {
        const rectangle = element.getBoundingClientRect();
        if (
            rectangle.bottom > bounds.top &&
            rectangle.top < bounds.bottom &&
            rectangle.top < anchorTop
        ) {
            anchor = element;
            anchorTop = rectangle.top;
        }
    }
    const previousHeight = area.scrollHeight;
    const previousTop = area.scrollTop;
    return () => {
        area.scrollTop =
            previousTop +
            (anchor?.isConnected
                ? anchor.getBoundingClientRect().top - anchorTop
                : area.scrollHeight - previousHeight);
    };
}

export function createMessageHistory(
    initial: History,
    fetchPage: (beforeId: number, signal: AbortSignal) => Promise<History>,
    applyPage: (page: History) => void,
    preserveScroll: () => () => void,
    afterRender: () => Promise<unknown>,
    onError: (cause: unknown) => void,
) {
    const hasMore = ref(initial.has_more);
    const loading = ref(false);
    const failed = ref(false);
    let beforeId = initial.before_id;
    let stopped = false;
    let observer: IntersectionObserver | undefined;
    let sentinel: HTMLElement | undefined;
    let request: AbortController | undefined;

    async function loadOlder(automatic = false): Promise<void> {
        if (
            stopped ||
            loading.value ||
            !hasMore.value ||
            beforeId === null ||
            (automatic && failed.value)
        )
            return;
        loading.value = true;
        failed.value = false;
        const cursor = beforeId;
        request = new AbortController();
        if (sentinel) observer?.unobserve(sentinel);
        try {
            const page = await fetchPage(cursor, request.signal);
            if (stopped) return;
            const restoreScroll = preserveScroll();
            applyPage(page);
            beforeId = page.before_id;
            hasMore.value =
                page.has_more && beforeId !== null && beforeId < cursor;
            await afterRender();
            if (!stopped) restoreScroll();
        } catch (cause) {
            if (stopped) return;
            failed.value = true;
            onError(cause);
        } finally {
            loading.value = false;
            request = undefined;
            if (!stopped && hasMore.value && !failed.value && sentinel)
                observer?.observe(sentinel);
        }
    }

    return {
        hasMore,
        loading,
        failed,
        loadOlder,
        observe(root: HTMLElement, target: HTMLElement) {
            if (stopped || typeof IntersectionObserver === 'undefined') return;
            observer?.disconnect();
            sentinel = target;
            observer = new IntersectionObserver(
                (entries) => {
                    if (entries.some((entry) => entry.isIntersecting))
                        void loadOlder(true);
                },
                { root, rootMargin: '200px 0px 0px 0px', threshold: 0 },
            );
            if (hasMore.value) observer.observe(target);
        },
        disconnect() {
            stopped = true;
            observer?.disconnect();
            request?.abort();
        },
    };
}
