import { type PropsWithChildren, type ReactNode } from 'react';

interface CardProps {
    title?: string;
    actions?: ReactNode;
}

export default function Card({ title, actions, children }: PropsWithChildren<CardProps>) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white shadow-sm">
            {title || actions ? (
                <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                    {title ? <h2 className="text-sm font-semibold text-gray-900">{title}</h2> : <span />}
                    {actions}
                </div>
            ) : null}
            <div className="p-4">{children}</div>
        </div>
    );
}
