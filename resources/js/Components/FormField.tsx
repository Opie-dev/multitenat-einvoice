import { type InputHTMLAttributes } from 'react';

interface FormFieldProps extends InputHTMLAttributes<HTMLInputElement> {
    label: string;
    name: string;
    error?: string;
}

export default function FormField({ label, name, error, className = '', ...props }: FormFieldProps) {
    return (
        <div className="space-y-1">
            <label htmlFor={name} className="block text-sm font-medium text-gray-700">
                {label}
            </label>
            <input
                id={name}
                name={name}
                className={`block w-full rounded-md border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900 ${
                    error ? 'border-red-400' : 'border-gray-300'
                } ${className}`.trim()}
                {...props}
            />
            {error ? <p className="text-sm text-red-600">{error}</p> : null}
        </div>
    );
}
