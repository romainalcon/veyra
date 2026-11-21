<script setup lang="ts">
import { Form, Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import OrganizationController from '@/actions/App/Http/Controllers/Organizations/OrganizationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/organizations';
import type { CurrentOrganization } from '@/types';

const props = defineProps<{
    organization: { id: string; name: string; slug: string };
}>();

const page = usePage();
const current = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [{ title: 'Settings', href: edit(props.organization.slug) }],
});
</script>

<template>
    <Head title="Organization settings" />

    <div class="mx-auto w-full max-w-2xl space-y-10 px-4 py-6">
        <section class="space-y-6">
            <Heading
                variant="small"
                title="General"
                description="The organization name is visible to all members."
            />

            <Form
                v-bind="OrganizationController.update.form(organization.slug)"
                :options="{ preserveScroll: true }"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input
                        id="name"
                        name="name"
                        required
                        maxlength="100"
                        :default-value="organization.name"
                        :disabled="!current.can.update"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="slug">URL identifier</Label>
                    <Input
                        id="slug"
                        :default-value="organization.slug"
                        disabled
                    />
                    <p class="text-xs text-muted-foreground">
                        The identifier is permanent so that links keep working.
                    </p>
                </div>

                <Button
                    v-if="current.can.update"
                    :disabled="processing"
                    data-test="update-organization-button"
                >
                    Save
                </Button>
            </Form>
        </section>

        <section v-if="current.can.delete" class="space-y-6">
            <Heading
                variant="small"
                title="Delete organization"
                description="Permanently delete this organization, its members and invitations."
            />
            <div
                class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
            >
                <p class="text-sm text-red-600 dark:text-red-100">
                    This action cannot be undone.
                </p>
                <Dialog>
                    <DialogTrigger as-child>
                        <Button
                            variant="destructive"
                            data-test="delete-organization-button"
                        >
                            Delete organization
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <Form
                            v-bind="
                                OrganizationController.destroy.form(
                                    organization.slug,
                                )
                            "
                            class="space-y-6"
                            v-slot="{ errors, processing }"
                        >
                            <DialogHeader class="space-y-3">
                                <DialogTitle
                                    >Delete this organization?</DialogTitle
                                >
                                <DialogDescription>
                                    Type
                                    <strong>{{ organization.slug }}</strong>
                                    to confirm.
                                </DialogDescription>
                            </DialogHeader>
                            <div class="grid gap-2">
                                <Label for="confirmation" class="sr-only"
                                    >Confirmation</Label
                                >
                                <Input
                                    id="confirmation"
                                    name="confirmation"
                                    autocomplete="off"
                                    :placeholder="organization.slug"
                                />
                                <InputError :message="errors.confirmation" />
                            </div>
                            <DialogFooter class="gap-2">
                                <DialogClose as-child>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    :disabled="processing"
                                    data-test="confirm-delete-organization-button"
                                >
                                    Delete organization
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </section>
    </div>
</template>
