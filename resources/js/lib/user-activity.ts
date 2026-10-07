const interval = 120_000;
const attempts = new Map<number, number>();

export function createUserActivityTracker(
    userId: number,
    send: (signal: AbortSignal) => Promise<unknown>,
) {
    const storageKey = `user-activity-attempt:${userId}`;
    let pending = false;
    let stopped = false;
    let inFlight = false;
    let lastSignal = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | undefined;

    function visible() {
        return !document.hidden && document.hasFocus();
    }

    function lastAttempt() {
        let timestamp = attempts.get(userId) ?? 0;
        try {
            const stored = Number(localStorage.getItem(storageKey));
            if (Number.isFinite(stored))
                timestamp = Math.max(timestamp, stored);
        } catch {
            // In-memory throttling remains available when storage is blocked.
        }
        return timestamp <= Date.now() ? timestamp : 0;
    }

    function schedule() {
        if (stopped || inFlight || timer || !pending || !visible()) return;
        const delay = Math.max(0, interval - (Date.now() - lastAttempt()));
        timer = setTimeout(() => {
            timer = undefined;
            void flush();
        }, delay);
    }

    async function flush() {
        if (stopped || !pending || !visible()) return;
        if (Date.now() - lastSignal > interval) {
            pending = false;
            return;
        }
        if (Date.now() - lastAttempt() < interval) {
            schedule();
            return;
        }
        pending = false;
        inFlight = true;
        const attemptedAt = Date.now();
        attempts.set(userId, attemptedAt);
        try {
            localStorage.setItem(storageKey, String(attemptedAt));
        } catch {
            // Storage availability does not affect chat usage.
        }
        controller = new AbortController();
        try {
            await send(controller.signal);
        } catch {
            if (!stopped) pending = true;
        } finally {
            inFlight = false;
            controller = undefined;
            schedule();
        }
    }

    function signal() {
        if (stopped || !visible()) return;
        lastSignal = Date.now();
        pending = true;
        schedule();
    }

    function interaction(event: Event) {
        if (event.isTrusted) signal();
    }

    function visibilityChanged() {
        if (timer) clearTimeout(timer);
        timer = undefined;
        pending = false;
        if (visible()) signal();
    }

    const events = ['pointerdown', 'keydown', 'wheel', 'touchstart'];
    for (const event of events)
        document.addEventListener(event, interaction, { passive: true });
    document.addEventListener('visibilitychange', visibilityChanged);
    window.addEventListener('focus', signal);
    window.addEventListener('blur', visibilityChanged);
    signal();

    return {
        signal,
        disconnect() {
            stopped = true;
            pending = false;
            if (timer) clearTimeout(timer);
            controller?.abort();
            for (const event of events)
                document.removeEventListener(event, interaction);
            document.removeEventListener('visibilitychange', visibilityChanged);
            window.removeEventListener('focus', signal);
            window.removeEventListener('blur', visibilityChanged);
        },
    };
}
