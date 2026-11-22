<script setup lang="ts">
import { Form, Head, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
import ApiTokenController from '@/actions/App/Http/Controllers/Organizations/ApiTokenController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/monitoring';
import { index as apiTokensIndex } from '@/routes/organizations/api-tokens';
import type { ApiTokenSummary, CurrentOrganization } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

const props = defineProps<{
    tokens: ApiTokenSummary[];
    abilities: { value: string; label: string }[];
    expiryDays: number[];
    maxTokens: number;
    apiBaseUrl: string;
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        {
            title: t('API tokens'),
            href: apiTokensIndex(organization.value.slug),
        },
    ],
});

/** Flashed once after creation; the server only keeps its hash. */
const createdToken = computed(
    () => (page.flash as { apiToken?: string } | undefined)?.apiToken ?? null,
);
const copied = ref(false);

const ability = ref('read');
const expiresInDays = ref('90');

const abilityLabel = (value: string) =>
    props.abilities.find((option) => option.value === value)?.label ?? value;

const expiryOptions = computed(() => [
    ...props.expiryDays.map((days) => ({
        value: String(days),
        label: t(':count days', { count: days }),
    })),
    { value: 'never', label: t('No expiry') },
]);

const limitReached = computed(() => props.tokens.length >= props.maxTokens);

const example = computed(
    () =>
        `curl -H "Authorization: Bearer ${createdToken.value ?? '<token>'}" ${props.apiBaseUrl}/monitors`,
);

const copy = async () => {
    if (!createdToken.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(createdToken.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
};

const revoke = (token: ApiTokenSummary) => {
    router.visit(
        ApiTokenController.destroy([organization.value.slug, token.id]),
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head :title="t('API tokens')" />

    <div class="mx-auto w-full max-w-4xl space-y-10 px-4 py-6">
        <section class="space-y-6">
            <Heading
                variant="small"
                :title="t('Create an API token')"
                :description="
                    t(
                        'Tokens belong to the organization, not to you: they keep working if you leave. Read-only tokens act as a viewer, read and write tokens as a member.',
                    )
                "
            />

            <div
                v-if="createdToken"
                class="space-y-2 rounded-lg border p-4"
                data-test="created-api-token"
            >
                <div class="flex flex-col gap-2 sm:flex-row">
                    <code
                        class="min-w-0 flex-1 rounded-md border bg-muted/40 px-3 py-2 font-mono text-sm break-all"
                        >{{ createdToken }}</code
                    >
                    <Button variant="outline" size="sm" @click="copy">
                        <Check v-if="copied" />
                        <Copy v-else />
                        {{ copied ? t('Copied') : t('Copy') }}
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Copy it now: it is not stored and will not be shown again.',
                        )
                    }}
                </p>
            </div>

            <Form
                v-bind="ApiTokenController.store.form(organization.slug)"
                :options="{ preserveScroll: true }"
                reset-on-success
                class="flex flex-col gap-4 sm:flex-row sm:items-start"
                v-slot="{ errors, processing }"
            >
                <div class="grid flex-1 gap-2">
                    <Label for="token-name" class="sr-only">{{
                        t('Name')
                    }}</Label>
                    <Input
                        id="token-name"
                        name="name"
                        required
                        maxlength="100"
                        :placeholder="t('e.g. Deployment pipeline')"
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="token-ability" class="sr-only">{{
                        t('Access')
                    }}</Label>
                    <NativeSelect
                        id="token-ability"
                        name="ability"
                        v-model="ability"
                        :options="abilities"
                    />
                    <InputError :message="errors.ability" />
                </div>
                <div class="grid gap-2">
                    <Label for="token-expiry" class="sr-only">{{
                        t('Expiry')
                    }}</Label>
                    <NativeSelect
                        id="token-expiry"
                        name="expires_in_days"
                        v-model="expiresInDays"
                        :options="expiryOptions"
                    />
                    <InputError :message="errors.expires_in_days" />
                </div>
                <Button
                    :disabled="processing || limitReached"
                    data-test="create-api-token-button"
                >
                    {{ t('Create token') }}
                </Button>
            </Form>
            <p v-if="limitReached" class="text-sm text-muted-foreground">
                {{
                    t(
                        'An organization can have at most :count active API tokens.',
                        { count: maxTokens },
                    )
                }}
            </p>

            <details class="text-sm">
                <summary class="cursor-pointer font-medium">
                    {{ t('Example') }}
                </summary>
                <pre
                    class="mt-2 overflow-x-auto rounded-md border bg-muted/40 px-3 py-2 text-xs"
                ><code>{{ example }}</code></pre>
            </details>
        </section>

        <section class="space-y-4">
            <Heading variant="small" :title="t('Active tokens')" />
            <p v-if="tokens.length === 0" class="text-sm text-muted-foreground">
                {{ t('No API tokens yet.') }}
            </p>
            <ul
                v-else
                class="divide-y rounded-lg border"
                data-test="api-tokens"
            >
                <li
                    v-for="token in tokens"
                    :key="token.id"
                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center"
                >
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="flex items-center gap-2 text-sm font-medium">
                            <span class="truncate">{{ token.name }}</span>
                            <Badge variant="secondary">{{
                                abilityLabel(token.ability)
                            }}</Badge>
                            <Badge v-if="token.expired" variant="destructive">{{
                                t('Expired')
                            }}</Badge>
                        </p>
                        <p class="text-sm text-muted-foreground">
                            <code class="font-mono">{{ token.masked }}</code>
                            ·
                            {{
                                token.last_used_at
                                    ? t('last used :date', {
                                          date: formatDateTime(
                                              token.last_used_at,
                                          ),
                                      })
                                    : t('never used')
                            }}
                            <template v-if="token.expires_at">
                                ·
                                {{
                                    t('expires :date', {
                                        date: formatDateTime(token.expires_at),
                                    })
                                }}
                            </template>
                        </p>
                        <p
                            v-if="token.creator"
                            class="text-xs text-muted-foreground"
                        >
                            {{ t('Created by :name', { name: token.creator }) }}
                        </p>
                    </div>
                    <Button variant="ghost" size="sm" @click="revoke(token)">
                        {{ t('Revoke') }}
                    </Button>
                </li>
            </ul>
        </section>
    </div>
</template>
