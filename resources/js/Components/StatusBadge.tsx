import { statusColor, type StatusColor, type StatusKind } from '@/lib/status';

interface StatusBadgeProps {
    kind: StatusKind;
    value: string;
    label?: string;
}

const COLOR_CLASSES: Record<StatusColor, string> = {
    gray: 'bg-gray-100 text-gray-700',
    blue: 'bg-blue-100 text-blue-700',
    green: 'bg-green-100 text-green-700',
    amber: 'bg-amber-100 text-amber-800',
    red: 'bg-red-100 text-red-700',
};

export default function StatusBadge({ kind, value, label }: StatusBadgeProps) {
    const color = statusColor(kind, value);

    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${COLOR_CLASSES[color]}`}>
            {label ?? value.replace(/_/g, ' ')}
        </span>
    );
}
