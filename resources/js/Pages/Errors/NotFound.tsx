import GuestLayout from '@/Layouts/GuestLayout';

interface NotFoundProps {
    status?: number;
}

const TITLES: Record<number, string> = {
    404: 'Page not found',
    419: 'Session expired',
    500: 'Something went wrong',
};

const MESSAGES: Record<number, string> = {
    404: 'The page you are looking for could not be found.',
    419: 'Your session has expired. Please refresh and try again.',
    500: 'Something went wrong on our end. Please try again.',
};

export default function NotFound({ status = 404 }: NotFoundProps) {
    return (
        <GuestLayout>
            <div className="text-center">
                <p className="text-sm font-medium text-gray-500">{status}</p>
                <h1 className="mt-2 text-xl font-semibold text-gray-900">{TITLES[status] ?? TITLES[404]}</h1>
                <p className="mt-2 text-sm text-gray-600">{MESSAGES[status] ?? MESSAGES[404]}</p>
            </div>
        </GuestLayout>
    );
}
