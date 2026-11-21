<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import OrganizationController from '@/actions/App/Http/Controllers/Organizations/OrganizationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { create } from '@/routes/organizations';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

setLayoutProps({
    breadcrumbs: [{ title: t('New organization'), href: create() }],
});
</script>

<template>
    <Head :title="t('Create an organization')" />

    <div class="mx-auto w-full max-w-xl px-4 py-10">
        <Heading
            :title="t('Create an organization')"
            :description="
                t(
                    'Organizations group your monitors, incidents and team members.',
                )
            "
        />

        <Form
            v-bind="OrganizationController.store.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">{{ t('Organization name') }}</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    v-focus
                    maxlength="100"
                    autocomplete="organization"
                    placeholder="Acme Inc."
                />
                <InputError :message="errors.name" />
            </div>

            <Button
                type="submit"
                :disabled="processing"
                data-test="create-organization-button"
            >
                <Spinner v-if="processing" />
                {{ t('Create organization') }}
            </Button>
        </Form>
    </div>
</template>
