import { router } from '@inertiajs/react';
import type {
    ColumnDef,
    OnChangeFn,
    RowData,
    RowSelectionState,
    SortingState,
    VisibilityState,
} from '@tanstack/react-table';
import {
    getCoreRowModel,
    getPaginationRowModel,
    getSortedRowModel,
    useReactTable,
} from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ArrowUpDown, Columns3, X } from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

declare module '@tanstack/react-table' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface ColumnMeta<TData extends RowData, TValue> {
        /** Label for the column-visibility menu (defaults to the column id). */
        label?: string;
        /** Hide the column below this breakpoint (phone layouts keep the essentials). */
        hideBelow?: 'sm' | 'md' | 'lg';
        /** Extra classes for this column's cells (e.g. `text-right tabular-nums`). */
        className?: string;
        /** Right-align header and cells (money, counts). */
        align?: 'right';
    }
}

/** Literal classes so Tailwind sees them. */
const hideBelowClass = {
    sm: 'hidden sm:table-cell',
    md: 'hidden md:table-cell',
    lg: 'hidden lg:table-cell',
} as const;

export type ServerSort = { sort: string | null; direction: 'asc' | 'desc' };

/**
 * Step I-14.2 / M-1.4 — the shared list table (TanStack Table on the shadcn `Table`), used by
 * every list page. Brand guidelines §1.5: flat, hairline rows, `hover:bg-muted/50`, mono
 * uppercase headers.
 *
 * Two modes, per list:
 * - **Server** (paginated lists): pass `pagination` + `onPageChange`, and `sort` +
 *   `onSortChange` for the columns the server can sort (`enableSorting: true` on those columns
 *   only). Nothing is sorted or sliced in the browser, so a sort covers every page.
 * - **Client** (short, unpaginated lists): leave those out; columns with `enableSorting: true`
 *   sort in place and
 *   `pageSize` pages locally.
 *
 * `bulkActions` adds a selection column and a bar that appears once rows are picked.
 * `rowHref` makes the whole row open a page (keep a real link in the main cell for keyboards).
 * `storageKey` remembers which columns a person hid, per list.
 */
export function DataTable<TData>({
    columns,
    data,
    getRowId,
    pagination,
    onPageChange,
    paginationLabel,
    sort,
    onSortChange,
    pageSize,
    bulkActions,
    rowHref,
    empty,
    toolbar,
    storageKey,
    caption,
    className,
}: {
    columns: ColumnDef<TData, unknown>[];
    data: TData[];
    getRowId: (row: TData) => string;
    pagination?: Pick<
        Paginated<unknown>,
        'current_page' | 'last_page' | 'total' | 'from' | 'to'
    >;
    onPageChange?: (page: number) => void;
    paginationLabel?: string;
    sort?: ServerSort;
    onSortChange?: (sort: ServerSort) => void;
    pageSize?: number;
    bulkActions?: (selected: TData[], clear: () => void) => ReactNode;
    rowHref?: (row: TData) => string | undefined;
    empty?: ReactNode;
    /** Rendered to the left of the column menu (filters, search). */
    toolbar?: ReactNode;
    storageKey?: string;
    /** Screen-reader caption naming the table. */
    caption?: string;
    className?: string;
}) {
    const serverSorting = onSortChange !== undefined;
    const [clientSorting, setClientSorting] = useState<SortingState>([]);
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        () => readVisibility(storageKey),
    );

    useEffect(() => {
        writeVisibility(storageKey, columnVisibility);
    }, [storageKey, columnVisibility]);

    // A new page of server data is a new set of rows: drop a stale selection (keeping the same
    // object when nothing is selected, so a freshly mapped `data` array can't loop renders).
    useEffect(
        () =>
            setRowSelection((current) =>
                Object.keys(current).length > 0 ? {} : current,
            ),
        [data],
    );

    const sorting: SortingState = serverSorting
        ? sort?.sort
            ? [{ id: sort.sort, desc: sort.direction === 'desc' }]
            : []
        : clientSorting;

    const handleSorting: OnChangeFn<SortingState> = (updater) => {
        const next = typeof updater === 'function' ? updater(sorting) : updater;

        if (!serverSorting) {
            setClientSorting(next);

            return;
        }

        const first = next[0];
        onSortChange?.({
            sort: first?.id ?? null,
            direction: first?.desc ? 'desc' : 'asc',
        });
    };

    const selectionColumn: ColumnDef<TData, unknown> = {
        id: '__select',
        enableSorting: false,
        enableHiding: false,
        header: ({ table }) => (
            <Checkbox
                aria-label="Select all rows"
                checked={
                    table.getIsAllPageRowsSelected() ||
                    (table.getIsSomePageRowsSelected() && 'indeterminate')
                }
                onCheckedChange={(value) =>
                    table.toggleAllPageRowsSelected(value === true)
                }
            />
        ),
        cell: ({ row }) => (
            <Checkbox
                aria-label="Select row"
                checked={row.getIsSelected()}
                onCheckedChange={(value) => row.toggleSelected(value === true)}
            />
        ),
        meta: { className: 'w-10' },
    };

    const table = useReactTable({
        data,
        columns: bulkActions ? [selectionColumn, ...columns] : columns,
        getRowId: (row) => getRowId(row),
        // Sorting is opt-in per column (`enableSorting: true`), so a server-paginated list only
        // offers the sorts its endpoint supports.
        defaultColumn: { enableSorting: false },
        state: { sorting, rowSelection, columnVisibility },
        enableSortingRemoval: true,
        enableMultiSort: false,
        manualSorting: serverSorting,
        manualPagination: pagination !== undefined,
        enableRowSelection: bulkActions !== undefined,
        onSortingChange: handleSorting,
        onRowSelectionChange: setRowSelection,
        onColumnVisibilityChange: setColumnVisibility,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: serverSorting ? undefined : getSortedRowModel(),
        getPaginationRowModel:
            pagination === undefined && pageSize
                ? getPaginationRowModel()
                : undefined,
        initialState: pageSize
            ? { pagination: { pageIndex: 0, pageSize } }
            : undefined,
    });

    const selected = table
        .getSelectedRowModel()
        .rows.map((row) => row.original);
    const hideable = table
        .getAllLeafColumns()
        .filter((column) => column.getCanHide() && column.id !== '__select');
    const localPages = pagination === undefined && pageSize !== undefined;

    const openRow = (event: MouseEvent, row: TData) => {
        const href = rowHref?.(row);
        const target = event.target as HTMLElement;

        if (
            !href ||
            target.closest(
                'a, button, input, label, [role="checkbox"], [role="menuitem"]',
            )
        ) {
            return;
        }

        if (event.metaKey || event.ctrlKey) {
            window.open(href, '_blank');

            return;
        }

        router.visit(href);
    };

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            {(toolbar || hideable.length > 0) && (
                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                        {toolbar}
                    </div>
                    {hideable.length > 0 && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline" size="sm">
                                    <Columns3 />
                                    Columns
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-48">
                                <DropdownMenuLabel>
                                    Show columns
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                {hideable.map((column) => (
                                    <DropdownMenuCheckboxItem
                                        key={column.id}
                                        checked={column.getIsVisible()}
                                        onSelect={(event) =>
                                            event.preventDefault()
                                        }
                                        onCheckedChange={(value) =>
                                            column.toggleVisibility(
                                                value === true,
                                            )
                                        }
                                    >
                                        {column.columnDef.meta?.label ??
                                            column.id}
                                    </DropdownMenuCheckboxItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            )}

            {bulkActions && selected.length > 0 && (
                <div
                    role="region"
                    aria-label="Bulk actions"
                    className="bg-ink text-ink-foreground animate-rise flex flex-wrap items-center gap-2 rounded-xl px-4 py-2.5"
                >
                    <span className="font-mono text-xs tracking-[0.12em] uppercase">
                        {selected.length} selected
                    </span>
                    <div className="flex flex-1 flex-wrap items-center gap-2">
                        {bulkActions(selected, () => setRowSelection({}))}
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-ink-foreground hover:bg-ink-foreground/10 hover:text-ink-foreground"
                        onClick={() => setRowSelection({})}
                    >
                        <X />
                        Clear
                    </Button>
                </div>
            )}

            {data.length === 0 && empty ? (
                empty
            ) : (
                <div className="border-border bg-card overflow-hidden rounded-2xl border">
                    <Table>
                        {caption && (
                            <caption className="sr-only">{caption}</caption>
                        )}
                        <TableHeader>
                            {table.getHeaderGroups().map((group) => (
                                <TableRow
                                    key={group.id}
                                    className="hover:bg-transparent"
                                >
                                    {group.headers.map((header) => {
                                        const meta =
                                            header.column.columnDef.meta;
                                        const sorted =
                                            header.column.getIsSorted();

                                        return (
                                            <TableHead
                                                key={header.id}
                                                aria-sort={
                                                    sorted === 'asc'
                                                        ? 'ascending'
                                                        : sorted === 'desc'
                                                          ? 'descending'
                                                          : undefined
                                                }
                                                className={cn(
                                                    'first:pl-5 last:pr-5',
                                                    meta?.hideBelow &&
                                                        hideBelowClass[
                                                            meta.hideBelow
                                                        ],
                                                    meta?.align === 'right' &&
                                                        'text-right',
                                                    meta?.className,
                                                )}
                                            >
                                                {header.isPlaceholder ? null : header.column.getCanSort() ? (
                                                    <button
                                                        type="button"
                                                        className={cn(
                                                            'hover:text-foreground -mx-1 inline-flex items-center gap-1 rounded px-1 uppercase transition-colors',
                                                            sorted &&
                                                                'text-foreground',
                                                        )}
                                                        onClick={header.column.getToggleSortingHandler()}
                                                    >
                                                        {renderSlot(
                                                            header.column
                                                                .columnDef
                                                                .header,
                                                            header.getContext(),
                                                        )}
                                                        {sorted === 'asc' ? (
                                                            <ArrowUp className="size-3" />
                                                        ) : sorted ===
                                                          'desc' ? (
                                                            <ArrowDown className="size-3" />
                                                        ) : (
                                                            <ArrowUpDown className="size-3 opacity-40" />
                                                        )}
                                                    </button>
                                                ) : (
                                                    renderSlot(
                                                        header.column.columnDef
                                                            .header,
                                                        header.getContext(),
                                                    )
                                                )}
                                            </TableHead>
                                        );
                                    })}
                                </TableRow>
                            ))}
                        </TableHeader>
                        <TableBody>
                            {table.getRowModel().rows.map((row) => (
                                <TableRow
                                    key={row.id}
                                    data-state={
                                        row.getIsSelected()
                                            ? 'selected'
                                            : undefined
                                    }
                                    className={cn(rowHref && 'cursor-pointer')}
                                    onClick={
                                        rowHref
                                            ? (event) =>
                                                  openRow(event, row.original)
                                            : undefined
                                    }
                                >
                                    {row.getVisibleCells().map((cell) => {
                                        const meta = cell.column.columnDef.meta;

                                        return (
                                            <TableCell
                                                key={cell.id}
                                                className={cn(
                                                    'first:pl-5 last:pr-5',
                                                    meta?.hideBelow &&
                                                        hideBelowClass[
                                                            meta.hideBelow
                                                        ],
                                                    meta?.align === 'right' &&
                                                        'text-right tabular-nums',
                                                    meta?.className,
                                                )}
                                            >
                                                {renderSlot(
                                                    cell.column.columnDef.cell,
                                                    cell.getContext(),
                                                )}
                                            </TableCell>
                                        );
                                    })}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            {pagination && onPageChange && (
                <Pagination
                    page={pagination}
                    onPageChange={onPageChange}
                    label={paginationLabel}
                />
            )}
            {localPages && table.getPageCount() > 1 && (
                <Pagination
                    label={paginationLabel}
                    page={{
                        current_page: table.getState().pagination.pageIndex + 1,
                        last_page: table.getPageCount(),
                        total: data.length,
                        from:
                            table.getState().pagination.pageIndex *
                                (pageSize ?? 0) +
                            1,
                        to: Math.min(
                            (table.getState().pagination.pageIndex + 1) *
                                (pageSize ?? 0),
                            data.length,
                        ),
                    }}
                    onPageChange={(page) => table.setPageIndex(page - 1)}
                />
            )}
        </div>
    );
}

/**
 * Renders a column's `header`/`cell`. Unlike TanStack's `flexRender`, a function is *called*,
 * not mounted as a component: column definitions are usually written inline, so as components
 * they'd be a new type every render and every cell would remount (losing focus on a ticked
 * checkbox). Cell renderers therefore mustn't use hooks.
 */
function renderSlot<TProps extends object>(
    slot: ReactNode | ((props: TProps) => ReactNode) | undefined,
    props: TProps,
): ReactNode {
    return typeof slot === 'function' ? slot(props) : slot;
}

function readVisibility(key: string | undefined): VisibilityState {
    if (!key) {
        return {};
    }

    try {
        const stored = window.localStorage.getItem(`data-table:${key}`);

        return stored ? (JSON.parse(stored) as VisibilityState) : {};
    } catch {
        return {};
    }
}

function writeVisibility(
    key: string | undefined,
    state: VisibilityState,
): void {
    if (!key) {
        return;
    }

    try {
        window.localStorage.setItem(`data-table:${key}`, JSON.stringify(state));
    } catch {
        // Private mode / storage full: the choice just isn't remembered.
    }
}
