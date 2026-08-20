/**
 * Single source of truth for status -> color mapping across the dashboard.
 *
 * `value` must match the wire value of the corresponding server enum exactly:
 * - 'document' -> App\Enums\DocumentStatus
 * - 'issuer'   -> App\Enums\IssuerStatus
 * - 'delivery' -> App\Enums\WebhookDeliveryStatus
 */

export type StatusKind = 'document' | 'issuer' | 'delivery';

export type StatusColor = 'gray' | 'blue' | 'green' | 'amber' | 'red';

const DOCUMENT_STATUS_COLORS: Record<string, StatusColor> = {
    draft: 'gray',
    validated: 'blue',
    held: 'amber',
    queued: 'blue',
    submitted: 'blue',
    valid: 'green',
    invalid: 'red',
    cancelled: 'gray',
    rejected: 'red',
    awaiting_consolidation: 'amber',
    consolidated: 'green',
};

const ISSUER_STATUS_COLORS: Record<string, StatusColor> = {
    draft: 'gray',
    tin_verified: 'blue',
    authorized: 'blue',
    active: 'green',
    suspended: 'red',
};

const DELIVERY_STATUS_COLORS: Record<string, StatusColor> = {
    pending: 'gray',
    retrying: 'amber',
    delivered: 'green',
    exhausted: 'red',
};

const COLORS_BY_KIND: Record<StatusKind, Record<string, StatusColor>> = {
    document: DOCUMENT_STATUS_COLORS,
    issuer: ISSUER_STATUS_COLORS,
    delivery: DELIVERY_STATUS_COLORS,
};

export function statusColor(kind: StatusKind, value: string): StatusColor {
    return COLORS_BY_KIND[kind][value] ?? 'gray';
}
