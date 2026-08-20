import { useState } from 'react';
import Button from '@/Components/Button';

interface SecretOnceModalProps {
    open: boolean;
    title: string;
    /** The one-time secret value (API key plaintext, webhook secret, etc). Never persisted beyond this render. */
    secret: string;
    onClose: () => void;
}

export default function SecretOnceModal({ open, title, secret, onClose }: SecretOnceModalProps) {
    const [copied, setCopied] = useState(false);

    if (!open) {
        return null;
    }

    const copy = () => {
        void navigator.clipboard.writeText(secret).then(() => setCopied(true));
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-lg">
                <h2 className="text-base font-semibold text-gray-900">{title}</h2>
                <p className="mt-2 text-sm text-gray-600">
                    This value is shown once and cannot be retrieved again. Copy it now and store it securely.
                </p>
                <code className="mt-4 block break-all rounded-md bg-gray-100 p-3 text-sm">{secret}</code>
                <div className="mt-6 flex justify-end gap-3">
                    <Button variant="secondary" onClick={copy}>
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                    <Button onClick={onClose}>Done</Button>
                </div>
            </div>
        </div>
    );
}
