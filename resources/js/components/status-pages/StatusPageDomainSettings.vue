<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { RefreshCw, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/monitoring';
import {
    destroy,
    update,
    verify,
} from '@/routes/organizations/status-pages/domain';
import type { StatusPageDomain } from '@/types';

const props = defineProps<{
    params: [string, string];
    domain: StatusPageDomain | null;
}>();

const verifyNow = () =>
    router.visit(verify(props.params), { preserveScroll: true });

const remove = () =>
    router.visit(destroy(props.params), { preserveScroll: true });
</script>

<template>
    <section
        class="space-y-4 rounded-lg border p-4"
        data-test="domain-settings"
    >
        <div>
            <h2 class="text-base font-medium">Custom domain</h2>
            <p class="text-sm text-muted-foreground">
                Serve this page on your own host name, e.g.
                <code>status.example.com</code>. Only the status page is ever
                served on that domain.
            </p>
        </div>

        <Form
            v-bind="update.form(params)"
            :options="{ preserveScroll: true }"
            class="flex flex-col gap-2 sm:flex-row sm:items-start"
            v-slot="{ errors, processing }"
        >
            <div class="grid flex-1 gap-1">
                <Label for="custom_domain" class="sr-only">Domain</Label>
                <Input
                    id="custom_domain"
                    name="custom_domain"
                    maxlength="253"
                    placeholder="status.example.com"
                    :default-value="domain?.name"
                />
                <InputError :message="errors.custom_domain" />
            </div>
            <Button
                type="submit"
                variant="outline"
                :disabled="processing"
                data-test="save-domain-button"
                >Save domain</Button
            >
        </Form>

        <div v-if="domain" class="space-y-3 text-sm">
            <p class="flex flex-wrap items-center gap-2">
                <Badge
                    :variant="domain.verified ? 'default' : 'secondary'"
                    data-test="domain-status"
                    >{{ domain.verified ? 'Verified' : 'Pending' }}</Badge
                >
                <span class="text-muted-foreground">
                    <template v-if="domain.checked_at">
                        Last checked {{ formatDateTime(domain.checked_at) }}
                    </template>
                    <template v-else>Not checked yet</template>
                    <template v-if="domain.verified && domain.failures > 0">
                        · record missing on the last
                        {{ domain.failures }} check(s)</template
                    >
                </span>
            </p>

            <ol class="list-decimal space-y-2 pl-5 text-muted-foreground">
                <li>
                    Add this TXT record at your DNS provider:
                    <div class="mt-1 grid gap-1 sm:grid-cols-[6rem_1fr]">
                        <span>Name</span>
                        <code class="break-all text-foreground">{{
                            domain.txt_name
                        }}</code>
                        <span>Value</span>
                        <code
                            class="break-all text-foreground"
                            data-test="txt-value"
                            >{{ domain.txt_value }}</code
                        >
                    </div>
                </li>
                <li>
                    Point <code>{{ domain.name }}</code> (CNAME or A/AAAA) to
                    this Veyra instance. Your administrator must enable TLS for
                    custom domains on the reverse proxy.
                </li>
                <li>
                    Verify. Veyra re-checks the record every day and stops
                    serving the domain if it disappears.
                </li>
            </ol>

            <div class="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    data-test="verify-domain-button"
                    @click="verifyNow"
                >
                    <RefreshCw class="size-4" />
                    Verify now
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    data-test="remove-domain-button"
                    @click="remove"
                >
                    <Trash2 class="size-4" />
                    Remove domain
                </Button>
            </div>
        </div>
    </section>
</template>
