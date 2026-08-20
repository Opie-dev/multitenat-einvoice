import { Link } from '@inertiajs/react';
import Button from '@/Components/Button';
import GuestLayout from '@/Layouts/GuestLayout';

export default function LinkInvalid() {
    return (
        <GuestLayout>
            <div className="text-center">
                <h1 className="text-lg font-semibold text-gray-900">Link expired</h1>
                <p className="mt-2 text-sm text-gray-600">
                    This sign-in link is invalid, expired, or has already been used. Request a new one below.
                </p>
                <Link href="/login" className="mt-6 inline-block">
                    <Button type="button">Request a new link</Button>
                </Link>
            </div>
        </GuestLayout>
    );
}
