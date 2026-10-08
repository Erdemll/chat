import { createSSRApp, defineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { beforeEach, expect, test, vi } from 'vitest';
import AppNavigation from '../components/AppNavigation.vue';
import AppLayout from '../layouts/AppLayout.vue';

const context = vi.hoisted(() => ({
    component: 'Chat/Index',
    props: {
        auth: {
            user: {
                id: 1,
                name: 'Ayşe Demir',
                role: 'employee' as 'employee' | 'admin',
            },
        },
        flash: { status: null },
    },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');
    return {
        usePage: () => context,
        router: { on: vi.fn() },
        Link: defineComponent({
            props: ['href', 'as'],
            setup:
                (props, { slots }) =>
                () =>
                    h(
                        props.as === 'button' ? 'button' : 'a',
                        props.as === 'button'
                            ? {
                                  'data-method': props.href.method,
                                  'data-url': props.href.url,
                              }
                            : { href: props.href.url },
                        slots.default?.(),
                    ),
        }),
    };
});

beforeEach(() => {
    context.component = 'Chat/Index';
    context.props.auth.user.role = 'employee';
});

test('employee navigation provides chat without management links', async () => {
    const html = await renderToString(createSSRApp(AppNavigation));

    expect(html).toContain('href="/chat"');
    expect(html).not.toContain('href="/admin');
    expect(html).not.toContain('YÖNETİM');
});

test('admin navigation includes dashboard and user management', async () => {
    context.props.auth.user.role = 'admin';
    context.component = 'Admin/Dashboard';

    const html = await renderToString(createSSRApp(AppNavigation));

    expect(html).toContain('href="/admin"');
    expect(html).toContain('href="/admin/users"');
    expect(html).toMatch(/href="\/admin"[^>]*aria-current="page"/u);
});

test('editing a user keeps the user management navigation selected', async () => {
    context.props.auth.user.role = 'admin';
    context.component = 'Admin/Users/Form';

    const html = await renderToString(createSSRApp(AppNavigation));

    expect(html).toMatch(/href="\/admin\/users"[^>]*aria-current="page"/u);
    expect(html.match(/aria-current="page"/gu)).toHaveLength(1);
});

test('shared layout keeps logout as a POST action for employees', async () => {
    const app = createSSRApp(
        defineComponent({
            render: () =>
                h(AppLayout, null, { default: () => h('p', 'Sohbet alanı') }),
        }),
    );

    const html = await renderToString(app);

    expect(html).toContain('data-method="post"');
    expect(html).toContain('data-url="/logout"');
    expect(html).toContain('Sohbet alanı');
});
