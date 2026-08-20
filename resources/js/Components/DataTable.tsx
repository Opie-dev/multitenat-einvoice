import { type ReactNode } from 'react';

export interface DataTableColumn<T> {
    key: string;
    header: string;
    render: (row: T) => ReactNode;
}

interface DataTableProps<T> {
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: (row: T) => string;
    empty?: ReactNode;
}

export default function DataTable<T>({ columns, rows, rowKey, empty }: DataTableProps<T>) {
    if (rows.length === 0) {
        return <div className="p-6 text-center text-sm text-gray-500">{empty ?? 'No results.'}</div>;
    }

    return (
        <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead>
                <tr>
                    {columns.map((column) => (
                        <th
                            key={column.key}
                            className="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500"
                        >
                            {column.header}
                        </th>
                    ))}
                </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
                {rows.map((row) => (
                    <tr key={rowKey(row)}>
                        {columns.map((column) => (
                            <td key={column.key} className="px-4 py-3">
                                {column.render(row)}
                            </td>
                        ))}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
