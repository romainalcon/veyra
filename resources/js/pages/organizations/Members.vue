<script setup lang="ts">
import { Form, Head, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InvitationController from '@/actions/App/Http/Controllers/Organizations/InvitationController';
import MemberController from '@/actions/App/Http/Controllers/Organizations/MemberController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import LimitNotice from '@/components/LimitNotice.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganizationLimit } from '@/composables/useOrganizationLimit';
import { index as membersIndex } from '@/routes/organizations/members';
import type {
    CurrentOrganization,
    Member,
    PendingInvitation,
    RoleOption,
} from '@/types';
import { currentLocale, useTranslations } from '@/lib/i18n';

const { t, tc } = useTranslations();

const props = defineProps<{
    members: Member[];
    invitations: PendingInvitation[];
    assignableRoles: RoleOption[];
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        { title: t('Members'), href: membersIndex(organization.value.slug) },
    ],
});

const { reached: limitReached } = useOrganizationLimit('members');

const inviteRole = ref<string>(
    props.assignableRoles.find((role) => role.value === 'member')?.value ??
        props.assignableRoles[0]?.value ??
        'viewer',
);

const roleLabels: Record<string, string> = {
    owner: 'Owner',
    admin: 'Admin',
    member: 'Member',
    viewer: 'Viewer',
};

const roleLabel = (value: string) =>
    roleLabels[value] ? t(roleLabels[value]) : value;

const changeRole = (member: Member, role: string) => {
    router.visit(
        MemberController.update([organization.value.slug, member.id]),
        { data: { role }, preserveScroll: true },
    );
};

const removeMember = (member: Member) => {
    router.visit(
        MemberController.destroy([organization.value.slug, member.id]),
        { preserveScroll: true },
    );
};

const revokeInvitation = (invitation: PendingInvitation) => {
    router.visit(
        InvitationController.destroy([organization.value.slug, invitation.id]),
        { preserveScroll: true },
    );
};

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString(currentLocale());
</script>

<template>
    <Head :title="t('Members')" />

    <div class="mx-auto w-full max-w-4xl space-y-10 px-4 py-6">
        <section v-if="organization.can.manageMembers" class="space-y-6">
            <Heading
                variant="small"
                :title="t('Invite a member')"
                :description="
                    t(
                        'Invitations are sent by e-mail and expire automatically.',
                    )
                "
            />
            <LimitNotice
                feature="members"
                :resource="t('members and pending invitations')"
                :hint="
                    t(
                        'Remove a member or revoke an invitation before inviting someone else.',
                    )
                "
            />
            <Form
                v-bind="InvitationController.store.form(organization.slug)"
                :options="{ preserveScroll: true }"
                reset-on-success
                class="flex flex-col gap-4 sm:flex-row sm:items-start"
                v-slot="{ errors, processing }"
            >
                <div class="grid flex-1 gap-2">
                    <Label for="email" class="sr-only">{{
                        t('E-mail address')
                    }}</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        required
                        placeholder="colleague@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="role" class="sr-only">{{ t('Role') }}</Label>
                    <NativeSelect
                        id="role"
                        name="role"
                        v-model="inviteRole"
                        :options="assignableRoles"
                    />
                    <InputError :message="errors.role" />
                </div>
                <Button
                    :disabled="processing || limitReached"
                    data-test="invite-member-button"
                >
                    {{ t('Send invitation') }}
                </Button>
            </Form>
        </section>

        <section class="space-y-4">
            <Heading
                variant="small"
                :title="t('Members')"
                :description="
                    tc(':count member|:count members', members.length)
                "
            />
            <InputError :message="page.props.errors?.member" />
            <ul class="divide-y rounded-lg border" data-test="members-list">
                <li
                    v-for="member in members"
                    :key="member.id"
                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ member.name }}
                            <span
                                v-if="member.is_current_user"
                                class="text-muted-foreground"
                                >{{ t('(you)') }}</span
                            >
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ member.email }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <NativeSelect
                            v-if="member.can_update"
                            :model-value="member.role"
                            :options="assignableRoles"
                            :aria-label="
                                t('Role of :name', { name: member.name })
                            "
                            @update:model-value="
                                (role) => role && changeRole(member, role)
                            "
                        />
                        <Badge v-else variant="secondary">
                            {{ roleLabel(member.role) }}
                        </Badge>
                        <Button
                            v-if="member.can_remove"
                            variant="ghost"
                            size="sm"
                            @click="removeMember(member)"
                        >
                            {{
                                member.is_current_user
                                    ? t('Leave')
                                    : t('Remove')
                            }}
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="organization.can.manageMembers" class="space-y-4">
            <Heading variant="small" :title="t('Pending invitations')" />
            <p
                v-if="invitations.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('No pending invitations.') }}
            </p>
            <ul v-else class="divide-y rounded-lg border">
                <li
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ invitation.email }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ roleLabel(invitation.role) }} ·
                            {{
                                t('expires :date', {
                                    date: formatDate(invitation.expires_at),
                                })
                            }}
                        </p>
                    </div>
                    <Button
                        v-if="invitation.can_revoke"
                        variant="ghost"
                        size="sm"
                        @click="revokeInvitation(invitation)"
                    >
                        {{ t('Revoke') }}
                    </Button>
                </li>
            </ul>
        </section>
    </div>
</template>
