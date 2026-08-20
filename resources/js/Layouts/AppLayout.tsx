import { usePage } from '@inertiajs/react';
import { type PropsWithChildren, type ReactNode } from 'react';
import FlashToast from '@/Components/FlashToast';
import type { SharedProps } from '@/types';

interface AppLayoutProps {
    title?: string;
    /** Slot for the sidebar navigation links; filled in by later Plan 5 tasks. */
    nav?: ReactNode;
}

export default function AppLayout({ title, nav, children }: PropsWithChildren<AppLayoutProps>) {
    const { auth, tenant, environment, flash } = usePage<SharedProps>().props;

    return (
        <div className="flex min-h-screen bg-gray-50 text-gray-900">
            <aside className="w-64 shrink-0 border-r border-gray-200 bg-white">
                <div className="flex h-16 items-center border-b border-gray-200 px-4 font-semibold">
                    {tenant?.name ?? 'Billplz E-Invoice'}
                </div>
                <nav className="space-y-1 p-4 text-sm">{nav}</nav>
            </aside>
            <div className="flex flex-1 flex-col">
                <header className="flex h-16 items-center justify-between border-b border-gray-200 bg-white px-6">
                    <h1 className="text-lg font-semibold">{title}</h1>
                    <div className="flex items-center gap-3 text-sm text-gray-600">
                        {environment ? (
                            <span className="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium uppercase tracking-wide text-gray-600">
                                {environment}
                            </span>
                        ) : null}
                        <span>{auth.user?.name ?? 'Guest'}</span>
                    </div>
                </header>
                <main className="flex-1 p-6">
                    <FlashToast flash={flash} />
                    {children}
                </main>
            </div>
        </div>
    );
}
