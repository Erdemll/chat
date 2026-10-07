import { createInertiaApp } from '@inertiajs/vue3';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
}).catch((error: unknown) => {
    console.error('Uygulama başlatılamadı.', error);
    if (typeof document === 'undefined') throw error;
    const container = document.getElementById('app');
    if (container)
        container.textContent =
            'Sayfa yüklenemedi. Sayfayı yenileyin veya güncel bir tarayıcı ile tekrar deneyin.';
});
