import { createSSRApp, defineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { expect, test, vi } from 'vitest';
import Login from '../pages/Auth/Login.vue';
import ResetPassword from '../pages/Auth/ResetPassword.vue';
import ForgotPassword from '../pages/Auth/ForgotPassword.vue';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');
    return {
        Form: defineComponent({
            props: ['action', 'method'],
            setup:
                (props, { slots }) =>
                () =>
                    h(
                        'form',
                        { action: props.action, method: props.method },
                        slots.default?.({ errors: {}, processing: false }),
                    ),
        }),
        Head: () => null,
        Link: defineComponent({
            props: ['href'],
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href.url }, slots.default?.()),
        }),
        usePage: () => ({ props: { flash: { status: null } } }),
    };
});

test('login renders named credential inputs and a boolean-compatible remember checkbox', async () => {
    const html = await renderToString(createSSRApp(Login));

    for (const field of [
        'action="/login"',
        'method="post"',
        'name="email"',
        'name="password"',
        'autocomplete="username"',
        'autocomplete="current-password"',
        'name="remember"',
        'value="1"',
        'type="submit"',
    ]) {
        expect(html).toContain(field);
    }
});

test('password reset serializes the token and confirmation under the backend field names', async () => {
    const html = await renderToString(
        createSSRApp(ResetPassword, {
            token: 'reset-token',
            email: 'worker@example.test',
        }),
    );

    for (const field of [
        'action="/reset-password"',
        'name="token"',
        'value="reset-token"',
        'name="email"',
        'value="worker@example.test"',
        'name="password_confirmation"',
    ]) {
        expect(html).toContain(field);
    }
});

test('password reset values are escaped as input attributes', async () => {
    const html = await renderToString(
        createSSRApp(ResetPassword, {
            token: '"/><script>alert(1)</script>',
            email: '" onfocus=alert(1)',
        }),
    );

    expect(html).not.toContain('<script>');
    expect(html).not.toContain('value="" onfocus=');
});

test('password recovery renders a named email field and posts to its existing route', async () => {
    const app = createSSRApp(
        defineComponent({ render: () => h(ForgotPassword) }),
    );
    const html = await renderToString(app);

    for (const field of [
        'action="/forgot-password"',
        'method="post"',
        'name="email"',
    ]) {
        expect(html).toContain(field);
    }
});
