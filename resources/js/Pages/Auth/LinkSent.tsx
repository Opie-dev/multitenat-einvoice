import GuestLayout from '@/Layouts/GuestLayout';

export default function LinkSent() {
    return (
        <GuestLayout>
            <div className="text-center">
                <h1 className="text-lg font-semibold text-gray-900">Check your email</h1>
                <p className="mt-2 text-sm text-gray-600">
                    If that address has an account, we&apos;ve sent a sign-in link to it. The link expires in 15 minutes and can only
                    be used once.
                </p>
            </div>
        </GuestLayout>
    );
}
