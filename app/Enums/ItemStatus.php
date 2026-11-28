<?php

namespace App\Enums;

/**
 * Backed by the `items.item_status` tinyint column.
 *
 * The legacy values are preserved deliberately. Staff create and submit; a
 * manager approves; approval and publication are separate steps, which is the
 * separation of duties the capstone already had.
 *
 * Rejected (3) returns the listing to an editable state and requires a reason.
 */
enum ItemStatus: int
{
    case Draft = 0;
    case Pending = 1;
    case Approved = 2;
    case Rejected = 3;
    case Published = 4;

    /**
     * Get the human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Pending => __('Pending approval'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Published => __('Published'),
        };
    }

    /**
     * Get the Flux badge color the status is shown in.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Pending => 'amber',
            self::Approved => 'blue',
            self::Rejected => 'red',
            self::Published => 'green',
        };
    }

    /**
     * Determine if customers may see a listing in this status.
     *
     * Only published listings are visible. Every customer-facing query must
     * gate on this - an approved but unpublished listing is still internal.
     */
    public function isVisibleToCustomers(): bool
    {
        return $this === self::Published;
    }

    /**
     * Determine if staff may still edit the listing's content.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /**
     * Get the statuses this status may legally transition to.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Pending],
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [self::Published, self::Rejected],
            self::Rejected => [self::Pending],
            self::Published => [self::Approved],
        };
    }
}
