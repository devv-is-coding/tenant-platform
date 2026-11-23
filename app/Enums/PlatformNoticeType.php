<?php

namespace App\Enums;

/**
 * Backed by the central `platform_notices.notice_type` tinyint column: what
 * the platform is telling an agency's Admin.
 */
enum PlatformNoticeType: int
{
    case RestoreRequestGranted = 1;
    case RestoreRequestDeclined = 2;
    case PlanChangeApproved = 3;
    case PlanChangeDeclined = 4;
    case SubscriptionPastDue = 5;
    case AutoPayFailed = 6;

    /**
     * Get the human-readable label for the type, used as the notice's title.
     */
    public function label(): string
    {
        return match ($this) {
            self::RestoreRequestGranted => __('Restore request granted'),
            self::RestoreRequestDeclined => __('Restore request declined'),
            self::PlanChangeApproved => __('Plan change approved'),
            self::PlanChangeDeclined => __('Plan change declined'),
            self::SubscriptionPastDue => __('Subscription past due'),
            self::AutoPayFailed => __('Automatic payment failed'),
        };
    }
}
