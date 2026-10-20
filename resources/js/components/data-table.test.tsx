import type { ColumnDef } from '@tanstack/react-table';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import { DataTable } from '@/components/data-table';
import { Button } from '@/components/ui/button';

vi.mock('@inertiajs/react', () => ({ router: { visit: vi.fn() } }));

type Row = { id: number; name: string; amount: number };

const rows: Row[] = [
    { id: 1, name: 'Harbour Coffee', amount: 300 },
    { id: 2, name: 'Atlas Gym', amount: 100 },
    { id: 3, name: 'Bloom Florist', amount: 200 },
];

const columns: ColumnDef<Row, unknown>[] = [
    { id: 'name', accessorKey: 'name', header: 'Client', enableSorting: true },
    {
        id: 'amount',
        accessorKey: 'amount',
        header: 'Amount',
        meta: { label: 'Amount', align: 'right' },
    },
];

function names() {
    return screen
        .getAllByRole('row')
        .slice(1)
        .map((row) => within(row).getAllByRole('cell')[0].textContent);
}

describe('DataTable', () => {
    it('renders the rows with a captioned table', () => {
        render(
            <DataTable
                caption="Clients"
                columns={columns}
                data={rows}
                getRowId={(row) => String(row.id)}
            />,
        );

        expect(
            screen.getByRole('table', { name: 'Clients' }),
        ).toBeInTheDocument();
        expect(names()).toEqual([
            'Harbour Coffee',
            'Atlas Gym',
            'Bloom Florist',
        ]);
    });

    it('sorts opt-in columns in place when the list is client-side', async () => {
        render(
            <DataTable
                columns={columns}
                data={rows}
                getRowId={(row) => String(row.id)}
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: /client/i }));
        expect(names()).toEqual([
            'Atlas Gym',
            'Bloom Florist',
            'Harbour Coffee',
        ]);
        expect(
            screen.getByRole('columnheader', { name: /client/i }),
        ).toHaveAttribute('aria-sort', 'ascending');

        // "Amount" didn't opt in, so it isn't a sort button.
        expect(
            screen.queryByRole('button', { name: /amount/i }),
        ).not.toBeInTheDocument();
    });

    it('hands sorting to the server and never reorders a server page itself', async () => {
        const onSortChange = vi.fn();
        render(
            <DataTable
                columns={columns}
                data={rows}
                getRowId={(row) => String(row.id)}
                sort={{ sort: null, direction: 'asc' }}
                onSortChange={onSortChange}
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: /client/i }));

        expect(onSortChange).toHaveBeenCalledWith({
            sort: 'name',
            direction: 'asc',
        });
        expect(names()).toEqual([
            'Harbour Coffee',
            'Atlas Gym',
            'Bloom Florist',
        ]);
    });

    it('shows a bulk-action bar for selected rows', async () => {
        const archive = vi.fn();
        render(
            <DataTable
                columns={columns}
                data={rows}
                getRowId={(row) => String(row.id)}
                bulkActions={(selected, clear) => (
                    <Button
                        onClick={() => {
                            archive(selected.map((row) => row.id));
                            clear();
                        }}
                    >
                        Archive
                    </Button>
                )}
            />,
        );

        expect(
            screen.queryByRole('region', { name: 'Bulk actions' }),
        ).not.toBeInTheDocument();

        const [, first, second] = screen.getAllByRole('checkbox');
        await userEvent.click(first);
        await userEvent.click(second);

        const bar = screen.getByRole('region', { name: 'Bulk actions' });
        expect(bar).toHaveTextContent('2 selected');

        await userEvent.click(
            within(bar).getByRole('button', { name: 'Archive' }),
        );
        expect(archive).toHaveBeenCalledWith([1, 2]);
        expect(
            screen.queryByRole('region', { name: 'Bulk actions' }),
        ).not.toBeInTheDocument();
    });

    it('shows the empty state instead of an empty table', () => {
        render(
            <DataTable
                columns={columns}
                data={[]}
                getRowId={(row) => String(row.id)}
                empty={<p>No clients yet.</p>}
            />,
        );

        expect(screen.getByText('No clients yet.')).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('pages a long client-side list', async () => {
        const many = Array.from({ length: 30 }, (_, i) => ({
            id: i + 1,
            name: `Client ${String(i + 1).padStart(2, '0')}`,
            amount: i,
        }));
        render(
            <DataTable
                columns={columns}
                data={many}
                getRowId={(row) => String(row.id)}
                pageSize={25}
                paginationLabel="clients"
            />,
        );

        expect(names()).toHaveLength(25);
        expect(screen.getByText('1–25 of 30 clients')).toBeInTheDocument();

        await userEvent.click(
            screen.getByRole('button', { name: 'Next page' }),
        );
        expect(names()).toEqual([
            'Client 26',
            'Client 27',
            'Client 28',
            'Client 29',
            'Client 30',
        ]);
    });
});
