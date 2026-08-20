import { useEffect, useState } from 'react';
import type { Flash } from '@/types';

interface FlashToastProps {
    flash: Flash;
}

export default function FlashToast({ flash }: FlashToastProps) {
    const [visible, setVisible] = useState(Boolean(flash.success));

    useEffect(() => {
        setVisible(Boolean(flash.success));
    }, [flash.success]);

    if (!visible || !flash.success) {
        return null;
    }

    return (
        <div className="mb-4 flex items-center justify-between rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            <span>{flash.success}</span>
            <button type="button" onClick={() => setVisible(false)} className="text-green-700 hover:text-green-900">
                Dismiss
            </button>
        </div>
    );
}
