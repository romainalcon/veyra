<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { usePublicNavigation } from '@/composables/usePublicNavigation';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const footerLinks = usePublicNavigation('footer');
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <div
                            class="mb-1 flex h-9 w-9 items-center justify-center rounded-md"
                        >
                            <AppLogoIcon
                                class="size-9 fill-current text-[var(--foreground)] dark:text-white"
                            />
                        </div>
                        <span class="sr-only">{{ title }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
        <nav
            v-if="footerLinks.length > 0"
            class="flex flex-wrap justify-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            <Link
                v-for="link in footerLinks"
                :key="link.id"
                :href="link.href"
                class="hover:underline"
                >{{ link.title }}</Link
            >
        </nav>
    </div>
</template>
