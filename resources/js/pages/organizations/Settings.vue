<script setup lang="ts">
import { Form, Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import OrganizationController from '@/actions/App/Http/Controllers/Organizations/OrganizationController';
import TwoFactorRequirementController from '@/actions/App/Http/Controllers/Organizations/TwoFactorRequirementController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
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
import { useTranslations } from '@/lib/i18n';
import { edit } from '@/routes/organizations';
import { edit as securityEdit } from '@/routes/security';
import type { CurrentOrganization } from '@/types';

const props = defineProps<{
    organization: {
        id: string;
        name: string;
        slug: string;
        locale: string;
        require_two_factor: boolean;
    };
    locales: { code: string; name: string }[];
    twoFactor: {
        canRequire: boolean;
        ownEnabled: boolean;
        membersWithout: number | null;
    };
}>();

const { t, tc } = useTranslations();
const locale = ref(props.organization.locale);
const localeOptions = computed(() =>
    props.locales.map((option) => ({
        value: option.code,
        label: option.name,
    })),
);

const page = usePage();
const current = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        { title: t('Settings'), href: edit(props.organization.slug) },
    ],
});
</script>

<template>
    <Head :title="t('Organization settings')" />

    <div class="mx-auto w-full max-w-2xl space-y-10 px-4 py-6">
        <section class="space-y-6">
            <Heading
                variant="small"
                :title="t('General')"
                :description="
                    t(
                        'The organization name and language apply to all members.',
                    )
                "
            />

            <Form
                v-bind="OrganizationController.update.form(organization.slug)"
                :options="{ preserveScroll: true }"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="name">{{ t('Name') }}</Label>
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
                    <Label for="slug">{{ t('URL identifier') }}</Label>
                    <Input
                        id="slug"
                        :default-value="organization.slug"
                        disabled
                    />
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'The identifier is permanent so that links keep working.',
                            )
                        }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="locale">{{ t('Language') }}</Label>
                    <NativeSelect
                        id="locale"
                        v-model="locale"
                        name="locale"
                        class="w-full"
                        :options="localeOptions"
                        :disabled="!current.can.update"
                        data-test="organization-locale"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Used for the interface of this organization, its public status pages and its notifications.',
                            )
                        }}
                    </p>
                    <InputError :message="errors.locale" />
                </div>

                <Button
                    v-if="current.can.update"
                    :disabled="processing"
                    data-test="update-organization-button"
                >
                    {{ t('Save') }}
                </Button>
            </Form>
        </section>

        <section
            v-if="twoFactor.canRequire"
            class="space-y-6"
            data-test="two-factor-requirement"
        >
            <Heading
                variant="small"
                :title="t('Two-factor authentication')"
                :description="
                    t(
                        'Require every member to sign in with a second factor. Members without one are asked to enable it before they can open this organization.',
                    )
                "
            />

            <p
                v-if="!twoFactor.ownEnabled && !organization.require_two_factor"
                class="text-sm text-muted-foreground"
            >
                {{
                    t(
                        'Enable two-factor authentication on your own account first.',
                    )
                }}
                <Link
                    :href="securityEdit()"
                    class="underline underline-offset-4"
                    >{{ t('Security settings') }}</Link
                >
            </p>

            <Form
                v-else
                v-bind="
                    TwoFactorRequirementController.update.form(
                        organization.slug,
                    )
                "
                :options="{ preserveScroll: true }"
                class="space-y-3"
                v-slot="{ errors, processing }"
            >
                <input
                    type="hidden"
                    name="require_two_factor"
                    :value="organization.require_two_factor ? '0' : '1'"
                />
                <p class="text-sm">
                    {{
                        organization.require_two_factor
                            ? t(
                                  'Two-factor authentication is required for every member.',
                              )
                            : tc(
                                  ':count member would have to enable it before their next visit.|:count members would have to enable it before their next visit.',
                                  twoFactor.membersWithout ?? 0,
                              )
                    }}
                </p>
                <Button
                    :variant="
                        organization.require_two_factor ? 'outline' : 'default'
                    "
                    :disabled="processing"
                    data-test="toggle-two-factor-requirement"
                >
                    {{
                        organization.require_two_factor
                            ? t('Stop requiring two-factor authentication')
                            : t('Require two-factor authentication')
                    }}
                </Button>
                <InputError :message="errors.require_two_factor" />
            </Form>
        </section>

        <section v-if="current.can.delete" class="space-y-6">
            <Heading
                variant="small"
                :title="t('Delete organization')"
                :description="
                    t(
                        'Permanently delete this organization, its members and invitations.',
                    )
                "
            />
            <div
                class="space-y-4 rounded-lg border border-status-down/30 bg-status-down-soft p-4"
            >
                <p class="text-sm text-status-down-soft-foreground">
                    {{ t('This action cannot be undone.') }}
                </p>
                <Dialog>
                    <DialogTrigger as-child>
                        <Button
                            variant="destructive"
                            data-test="delete-organization-button"
                        >
                            {{ t('Delete organization') }}
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
                                <DialogTitle>{{
                                    t('Delete this organization?')
                                }}</DialogTitle>
                                <DialogDescription>
                                    {{
                                        t('Type :slug to confirm.', {
                                            slug: organization.slug,
                                        })
                                    }}
                                </DialogDescription>
                            </DialogHeader>
                            <div class="grid gap-2">
                                <Label for="confirmation" class="sr-only">{{
                                    t('Confirmation')
                                }}</Label>
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
                                    <Button variant="secondary">{{
                                        t('Cancel')
                                    }}</Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    :disabled="processing"
                                    data-test="confirm-delete-organization-button"
                                >
                                    {{ t('Delete organization') }}
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </section>
    </div>
</template>
