export type MessageReadTracker = {
    observe: (
        element: HTMLElement,
        messageId: number,
        authorId: number,
    ) => void;
    unobserve: (messageId: number) => void;
    resume: () => void;
    disconnect: () => void;
};

export function createMessageReadTracker(
    root: HTMLElement,
    currentUserId: number,
    sendBatch: (ids: number[], signal: AbortSignal) => Promise<unknown>,
    onError: (cause: unknown) => void,
): MessageReadTracker {
    const pendingReads = new Set<number>();
    const sentReads = new Set<number>();
    const targets = new Map<Element, number>();
    const elements = new Map<number, HTMLElement>();
    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | undefined;
    let inFlight = false;
    let stopped = false;
    let failures = 0;

    function schedule(delay = 500) {
        if (stopped || timer || !pendingReads.size || document.hidden) return;
        timer = setTimeout(() => {
            timer = undefined;
            void flush();
        }, delay);
    }

    async function flush() {
        if (stopped || inFlight || document.hidden) return;
        const ids = [...pendingReads].slice(0, 100);
        if (!ids.length) return;
        inFlight = true;
        controller = new AbortController();
        let failed = false;
        try {
            await sendBatch(ids, controller.signal);
            if (stopped) return;
            for (const id of ids) {
                pendingReads.delete(id);
                sentReads.add(id);
            }
            failures = 0;
        } catch (cause) {
            if (stopped) return;
            failed = true;
            failures++;
            onError(cause);
        } finally {
            inFlight = false;
            controller = undefined;
            schedule(
                failed ? Math.min(1000 * 2 ** (failures - 1), 30000) : 500,
            );
        }
    }

    const observer = new IntersectionObserver(
        (entries) => {
            if (stopped || document.hidden) return;
            for (const entry of entries) {
                const id = targets.get(entry.target);
                if (id === undefined || !entry.isIntersecting) continue;
                // A long message only needs to fill half of the chat viewport.
                const visibleHeight = Math.min(
                    entry.boundingClientRect.height,
                    entry.rootBounds?.height ?? root.clientHeight,
                );
                if (
                    visibleHeight <= 0 ||
                    entry.intersectionRect.height < visibleHeight * 0.5 ||
                    sentReads.has(id) ||
                    pendingReads.has(id)
                )
                    continue;
                pendingReads.add(id);
                observer.unobserve(entry.target);
            }
            schedule();
        },
        {
            root,
            // Intermediate thresholds also cover messages taller than the viewport.
            threshold: [
                ...Array.from({ length: 10 }, (_, index) => index / 1000),
                ...Array.from({ length: 50 }, (_, index) => (index + 1) / 100),
            ],
        },
    );

    function unobserve(messageId: number) {
        const element = elements.get(messageId);
        if (!element) return;
        observer.unobserve(element);
        targets.delete(element);
        elements.delete(messageId);
    }

    return {
        observe(element, messageId, authorId) {
            if (stopped || authorId === currentUserId) return;
            if (elements.get(messageId) === element) return;
            unobserve(messageId);
            elements.set(messageId, element);
            targets.set(element, messageId);
            if (!sentReads.has(messageId) && !pendingReads.has(messageId)) {
                observer.observe(element);
            }
        },
        unobserve,
        resume() {
            if (stopped || document.hidden) return;
            for (const [id, element] of elements) {
                if (sentReads.has(id) || pendingReads.has(id)) continue;
                observer.unobserve(element);
                observer.observe(element);
            }
            schedule();
        },
        disconnect() {
            stopped = true;
            observer.disconnect();
            if (timer) clearTimeout(timer);
            controller?.abort();
            pendingReads.clear();
            sentReads.clear();
            targets.clear();
            elements.clear();
        },
    };
}
