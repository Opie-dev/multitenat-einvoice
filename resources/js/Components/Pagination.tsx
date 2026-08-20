import { Link } from '@inertiajs/react';
import type { CursorPagination } from '@/types';

interface PaginationProps<T> {
    pagination: Pick<CursorPagination<T>, 'next_page_url' | 'prev_page_url'>;
}

export default function Pagination<T>({ pagination }: PaginationProps<T>) {
    if (!pagination.next_page_url && !pagination.prev_page_url) {
        return null;
    }

    return (
        <div className="flex items-center justify-between border-t border-gray-200 px-4 py-3 text-sm">
            {pagination.prev_page_url ? (
                <Link href={pagination.prev_page_url} className="text-gray-600 hover:text-gray-900" preserveScroll>
                    Previous
                </Link>
            ) : (
                <span />
            )}
            {pagination.next_page_url ? (
                <Link href={pagination.next_page_url} className="text-gray-600 hover:text-gray-900" preserveScroll>
                    Next
                </Link>
            ) : (
                <span />
            )}
        </div>
    );
}
