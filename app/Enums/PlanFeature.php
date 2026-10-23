<?php

namespace App\Enums;

/**
 * Backed by the `plan_features.feature` string(64) column, and sent as-is to
 * the agency's app and in a `feature_not_in_plan` API error.
 *
 * A second deliberate exception to the house rule that enums are int-backed,
 * for the reason AuditAction is one: the value crosses the API to the Flutter
 * apps and must stay readable there and in raw SQL, which an integer would not.
 *
 * The list is code, not data, because a feature exists only where code checks
 * for it: the super admin decides which tier each one starts at, but cannot
 * invent one. Each case names the TODO items whose screens and endpoints enforce
 * it (TODO.md → *Plan gates*); services never check the tier. Until those are
 * built the case is only a row in the console's tier matrix, so the tiers can be
 * arranged before launch.
 */
enum PlanFeature: string
{
    /** Tours, stays and visas in the catalog. Enforced in TS-1, TM-1 and CU-4. */
    case Catalog = 'catalog';

    /** Inventory and date-ranged availability. Enforced in TS-2 and CU-4's availability. */
    case Inventory = 'inventory';

    /** The bookings desk, approvals, and cash and partial payments. Enforced in TS-4, TM-2, TM-3, CU-5 and CU-7. */
    case Bookings = 'bookings';

    /** The agency's branded app, with its logo and colours. Enforced in TA-10 and CU-2's branding fields. */
    case BrandedApp = 'branded_app';

    /** The booking-event notification feed. Enforced in TS-6 and CU-9; platform notices are never gated. */
    case Notifications = 'notifications';

    /** Creating and editing Manager and Staff accounts. Enforced in TA-9; deactivating is never gated. */
    case Team = 'team';

    /** Extras on the agency's own audit log. Enforced in TA-28; reading the log is never gated. */
    case AuditLog = 'audit_log';

    /** QR check-in and the guide's code. Enforced in TS-5 and CU-10. */
    case QrCheckIn = 'qr_check_in';

    /** Card payments through Stripe. Enforced in CU-6's payment link. */
    case StripePayments = 'stripe_payments';

    /** Refunds through Stripe. Enforced in TM-3's Stripe step; cash refunds are never gated. */
    case StripeRefunds = 'stripe_refunds';

    /** The itinerary map. Enforced in TS-3 and CU-4's itinerary coordinates. */
    case ItineraryMap = 'itinerary_map';

    /** Reviews and wishlists, with moderation. Enforced in TM-4 and CU-8. */
    case Reviews = 'reviews';

    /** CSV export of bookings and payments. Not on the roadmap yet: wired by its own TODO item. */
    case CsvExport = 'csv_export';

    /** Hiding "Powered by", with a custom splash and font in app-config. Not on the roadmap yet. */
    case WhiteLabel = 'white_label';

    /** Revenue trend, occupancy heat map, top listings and booking lead time. Not on the roadmap yet. */
    case Analytics = 'analytics';

    /** A weekly report emailed to the agency's Admin. Not on the roadmap yet. */
    case WeeklyReport = 'weekly_report';

    /** Promo codes and early-bird discounts. Not on the roadmap yet. */
    case Promotions = 'promotions';

    /** Announcements broadcast to every customer. Not on the roadmap yet. */
    case Broadcasts = 'broadcasts';

    /** Listings in more than one language. Not on the roadmap yet. */
    case MultilingualListings = 'multilingual_listings';

    /** The agency's restore requests listed first in SA-15's queue. Not on the roadmap yet. */
    case PriorityRestore = 'priority_restore';

    /**
     * Get the human-readable label for the feature.
     */
    public function label(): string
    {
        return match ($this) {
            self::Catalog => __('Catalog'),
            self::Inventory => __('Inventory & availability'),
            self::Bookings => __('Bookings desk'),
            self::BrandedApp => __('Branded app'),
            self::Notifications => __('Notification feed'),
            self::Team => __('Team accounts'),
            self::AuditLog => __('Audit log'),
            self::QrCheckIn => __('QR check-in'),
            self::StripePayments => __('Card payments'),
            self::StripeRefunds => __('Card refunds'),
            self::ItineraryMap => __('Itinerary map'),
            self::Reviews => __('Reviews & wishlist'),
            self::CsvExport => __('CSV export'),
            self::WhiteLabel => __('Full white-label'),
            self::Analytics => __('Analytics'),
            self::WeeklyReport => __('Weekly report'),
            self::Promotions => __('Promo codes & discounts'),
            self::Broadcasts => __('Broadcast announcements'),
            self::MultilingualListings => __('Multilingual listings'),
            self::PriorityRestore => __('Priority restore requests'),
        };
    }

    /**
     * Get the one-line description the console shows under the label.
     */
    public function description(): string
    {
        return match ($this) {
            self::Catalog => __('Tours, stays and visas for sale.'),
            self::Inventory => __('Stock and date-ranged availability.'),
            self::Bookings => __('Approvals, and cash and partial payments.'),
            self::BrandedApp => __('The agency\'s own app, with its logo and colours.'),
            self::Notifications => __('The in-app notification feed.'),
            self::Team => __('Admin, Manager and Staff accounts.'),
            self::AuditLog => __('A record of who changed what at the agency.'),
            self::QrCheckIn => __('Check-in by QR code, and the guide\'s code.'),
            self::StripePayments => __('Customers pay by card in the app.'),
            self::StripeRefunds => __('Card payments refunded through Stripe.'),
            self::ItineraryMap => __('Each tour\'s stops on a map.'),
            self::Reviews => __('Customer reviews and wishlists, with moderation.'),
            self::CsvExport => __('Bookings and payments as a spreadsheet.'),
            self::WhiteLabel => __('No "Powered by", and a custom splash screen and font.'),
            self::Analytics => __('Revenue trend, occupancy, top listings and booking lead time.'),
            self::WeeklyReport => __('A summary emailed to the agency\'s Admin every week.'),
            self::Promotions => __('Promo codes and early-bird discounts.'),
            self::Broadcasts => __('Announcements sent to every customer.'),
            self::MultilingualListings => __('Listings in more than one language.'),
            self::PriorityRestore => __('The agency\'s restore requests are handled first.'),
        };
    }

    /**
     * Get the tier the feature starts at until the super admin moves it.
     */
    public function defaultTier(): PlanTier
    {
        return match ($this) {
            self::Catalog, self::Inventory, self::Bookings, self::BrandedApp,
            self::Notifications, self::Team, self::AuditLog, self::QrCheckIn => PlanTier::Basic,

            self::StripePayments, self::StripeRefunds, self::ItineraryMap,
            self::Reviews, self::CsvExport, self::WhiteLabel => PlanTier::Advanced,

            self::Analytics, self::WeeklyReport, self::Promotions, self::Broadcasts,
            self::MultilingualListings, self::PriorityRestore => PlanTier::Pro,
        };
    }
}
