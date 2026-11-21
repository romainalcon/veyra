<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InvitationAcceptanceController from '@/actions/App/Http/Controllers/InvitationAcceptanceController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { login, logout, register } from '@/routes';

defineOptions({
    layout: {
        title: 'You have been invited',
        description: 'Join your team on Veyra',
    },
});

defineProps<{
    token: string;
    organizationName: string;
    role: string;
    isAuthenticated: boolean;
    emailMatches: boolean;
}>();
</script>

<template>
    <Head title="Invitation" />

    <div class="space-y-6 text-center">
        <p class="text-sm">
            You have been invited to join
            <strong>{{ organizationName }}</strong> as
            <strong>{{ role }}</strong>.
        </p>

        <Form
            v-if="isAuthenticated && emailMatches"
            v-bind="InvitationAcceptanceController.store.form(token)"
            v-slot="{ errors, processing }"
            class="space-y-4"
        >
            <InputError :message="errors.invitation" />
            <Button
                class="w-full"
                :disabled="processing"
                data-test="accept-invitation-button"
            >
                Accept invitation
            </Button>
        </Form>

        <div v-else-if="isAuthenticated" class="space-y-4">
            <p class="text-sm text-muted-foreground">
                This invitation was sent to another e-mail address. Log in with
                the invited account to accept it.
            </p>
            <Button variant="secondary" class="w-full" as-child>
                <Link :href="logout()" as="button">Log out</Link>
            </Button>
        </div>

        <div v-else class="flex flex-col gap-3">
            <Button as-child class="w-full">
                <Link :href="login()">Log in to accept</Link>
            </Button>
            <Button as-child variant="secondary" class="w-full">
                <Link :href="register()">Create an account</Link>
            </Button>
        </div>
    </div>
</template>
