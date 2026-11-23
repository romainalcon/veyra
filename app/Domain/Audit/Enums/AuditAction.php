<?php

namespace App\Domain\Audit\Enums;

/**
 * Audited member actions (ADR-046). Values are stable identifiers stored in
 * `audit_logs.action`; labels are translated when displayed.
 */
enum AuditAction: string
{
    case OrganizationCreated = 'organization.created';
    case OrganizationUpdated = 'organization.updated';
    case TwoFactorRequirementChanged = 'organization.two_factor_changed';

    case MemberInvited = 'member.invited';
    case InvitationRevoked = 'member.invitation_revoked';
    case MemberJoined = 'member.joined';
    case MemberRoleChanged = 'member.role_changed';
    case MemberRemoved = 'member.removed';
    case MemberLeft = 'member.left';

    case ApiTokenCreated = 'api_token.created';
    case ApiTokenRevoked = 'api_token.revoked';

    case MonitorCreated = 'monitor.created';
    case MonitorUpdated = 'monitor.updated';
    case MonitorDeleted = 'monitor.deleted';
    case MonitorPaused = 'monitor.paused';
    case MonitorResumed = 'monitor.resumed';
    case PingTokenRegenerated = 'monitor.ping_token_regenerated';
    case MonitorsImported = 'monitors.imported';
    case MonitorsExported = 'monitors.exported';

    case NotificationChannelCreated = 'notification_channel.created';
    case NotificationChannelUpdated = 'notification_channel.updated';
    case NotificationChannelDeleted = 'notification_channel.deleted';
    case SigningSecretRegenerated = 'notification_channel.secret_regenerated';

    case EscalationPolicyCreated = 'escalation_policy.created';
    case EscalationPolicyUpdated = 'escalation_policy.updated';
    case EscalationPolicyDeleted = 'escalation_policy.deleted';

    case StatusPageCreated = 'status_page.created';
    case StatusPageUpdated = 'status_page.updated';
    case StatusPageDeleted = 'status_page.deleted';
    case StatusPageDomainChanged = 'status_page.domain_changed';
    case StatusPageDomainVerified = 'status_page.domain_verified';
    case StatusPageAssetUploaded = 'status_page.asset_uploaded';
    case StatusPageAssetRemoved = 'status_page.asset_removed';

    /**
     * The resource family, used to filter the log.
     */
    public function category(): AuditCategory
    {
        return AuditCategory::from(explode('.', $this->value)[0]);
    }

    public function label(): string
    {
        return (string) __(match ($this) {
            self::OrganizationCreated => 'Organization created',
            self::OrganizationUpdated => 'Organization settings updated',
            self::TwoFactorRequirementChanged => 'Two-factor requirement changed',
            self::MemberInvited => 'Member invited',
            self::InvitationRevoked => 'Invitation revoked',
            self::MemberJoined => 'Member joined',
            self::MemberRoleChanged => 'Member role changed',
            self::MemberRemoved => 'Member removed',
            self::MemberLeft => 'Member left',
            self::ApiTokenCreated => 'API token created',
            self::ApiTokenRevoked => 'API token revoked',
            self::MonitorCreated => 'Monitor created',
            self::MonitorUpdated => 'Monitor updated',
            self::MonitorDeleted => 'Monitor deleted',
            self::MonitorPaused => 'Monitor paused',
            self::MonitorResumed => 'Monitor resumed',
            self::PingTokenRegenerated => 'Ping URL regenerated',
            self::MonitorsImported => 'Monitors imported',
            self::MonitorsExported => 'Monitors exported',
            self::NotificationChannelCreated => 'Notification channel created',
            self::NotificationChannelUpdated => 'Notification channel updated',
            self::NotificationChannelDeleted => 'Notification channel deleted',
            self::SigningSecretRegenerated => 'Signing secret regenerated',
            self::EscalationPolicyCreated => 'Escalation policy created',
            self::EscalationPolicyUpdated => 'Escalation policy updated',
            self::EscalationPolicyDeleted => 'Escalation policy deleted',
            self::StatusPageCreated => 'Status page created',
            self::StatusPageUpdated => 'Status page updated',
            self::StatusPageDeleted => 'Status page deleted',
            self::StatusPageDomainChanged => 'Custom domain changed',
            self::StatusPageDomainVerified => 'Custom domain verified',
            self::StatusPageAssetUploaded => 'Status page image uploaded',
            self::StatusPageAssetRemoved => 'Status page image removed',
        });
    }
}
