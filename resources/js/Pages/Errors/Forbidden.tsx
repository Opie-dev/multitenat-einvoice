import GuestLayout from '@/Layouts/GuestLayout';

interface ForbiddenProps {
    status?: number;
}

export default function Forbidden({ status = 403 }: ForbiddenProps) {
    return (
        <GuestLayout>
            <div className="text-center">
                <p className="text-sm font-medium text-gray-500">{status}</p>
                <h1 className="mt-2 text-xl font-semibold text-gray-900">Forbidden</h1>
                <p className="mt-2 text-sm text-gray-600">You don&apos;t have permission to view this page.</p>
            </div>
        </GuestLayout>
    );
}
