<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppIcon from '@/components/AppIcon.vue';
import { chat } from '@/routes';
import { dashboard } from '@/routes/admin';
import { index } from '@/routes/admin/users';
const page = usePage();
const emit = defineEmits<{ navigate: [] }>();
</script>
<template>
    <nav aria-label="Ana menü" class="ui-card p-3">
        <p
            class="px-3 pt-3 pb-2 text-[10px] font-semibold tracking-[0.14em] text-muted"
        >
            ÇALIŞMA ALANI
        </p>
        <Link
            :href="chat()"
            :aria-current="page.component === 'Chat/Index' ? 'page' : undefined"
            class="flex min-h-12 items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium transition-colors"
            :class="
                page.component === 'Chat/Index'
                    ? 'bg-brand-50 text-brand-700'
                    : 'text-muted hover:bg-canvas hover:text-ink'
            "
            @click="emit('navigate')"
        >
            <AppIcon name="chat" /> Genel sohbet
        </Link>
        <template v-if="page.props.auth.user.role === 'admin'">
            <p
                class="px-3 pt-5 pb-2 text-[10px] font-semibold tracking-[0.14em] text-muted"
            >
                YÖNETİM
            </p>
            <Link
                :href="dashboard()"
                :aria-current="
                    page.component === 'Admin/Dashboard' ? 'page' : undefined
                "
                class="flex min-h-12 items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium transition-colors"
                :class="
                    page.component === 'Admin/Dashboard'
                        ? 'bg-brand-50 text-brand-700'
                        : 'text-muted hover:bg-canvas hover:text-ink'
                "
                @click="emit('navigate')"
            >
                <AppIcon name="dashboard" /> Yönetim paneli
            </Link>
            <Link
                :href="index()"
                :aria-current="
                    page.component.startsWith('Admin/Users/')
                        ? 'page'
                        : undefined
                "
                class="flex min-h-12 items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium transition-colors"
                :class="
                    page.component.startsWith('Admin/Users/')
                        ? 'bg-brand-50 text-brand-700'
                        : 'text-muted hover:bg-canvas hover:text-ink'
                "
                @click="emit('navigate')"
            >
                <AppIcon name="users" /> Kullanıcılar
            </Link>
        </template>
    </nav>
</template>
