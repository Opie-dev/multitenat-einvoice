import { type PageProps as InertiaPageProps } from '@inertiajs/core';

/** Matches App\Enums\Environment. */
export type EnvironmentValue = 'sandbox' | 'production';

export interface AuthUser {
    id: string;
    name: string;
    email: string;
    role: string | null;
    issuer_id: string | null;
}

export interface Tenant {
    name: string;
}

export interface Flash {
    success: string | null;
    secret: string | null;
}

/**
 * The shape returned by App\Http\Middleware\HandleInertiaRequests::share().
 * `auth.user`, `tenant`, and `environment` are populated once Plan 5 Tasks
 * 2/3 wire session auth and tenant context; they are nullable until then.
 */
export interface SharedProps extends InertiaPageProps {
    auth: {
        user: AuthUser | null;
    };
    tenant: Tenant | null;
    environment: EnvironmentValue | null;
    flash: Flash;
}

/**
 * Standard Laravel cursor-paginated JSON shape, as produced by
 * Spatie\LaravelData\CursorPaginatedDataCollection.
 */
export interface CursorPagination<T> {
    data: T[];
    path: string;
    per_page: number;
    next_cursor: string | null;
    next_page_url: string | null;
    prev_cursor: string | null;
    prev_page_url: string | null;
}
