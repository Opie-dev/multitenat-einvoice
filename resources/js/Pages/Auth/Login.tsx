import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';
import Button from '@/Components/Button';
import FormField from '@/Components/FormField';
import GuestLayout from '@/Layouts/GuestLayout';

interface LoginForm {
    email: string;
}

export default function Login() {
    const { data, setData, post, processing, errors } = useForm<LoginForm>({ email: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/login/link');
    };

    return (
        <GuestLayout>
            <h1 className="text-lg font-semibold text-gray-900">Sign in</h1>
            <p className="mt-1 text-sm text-gray-600">Enter your email address and we&apos;ll send you a sign-in link.</p>
            <form onSubmit={submit} className="mt-6 space-y-4">
                <FormField
                    label="Email"
                    name="email"
                    type="email"
                    autoComplete="email"
                    autoFocus
                    value={data.email}
                    error={errors.email}
                    onChange={(e) => setData('email', e.target.value)}
                />
                <Button type="submit" disabled={processing} className="w-full">
                    Send sign-in link
                </Button>
            </form>
        </GuestLayout>
    );
}
