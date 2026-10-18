/**
 * Chart styling per brand guidelines §3.5 (mirrors the studio dashboard): popover-styled tooltip,
 * DM Mono 11px ticks, dashed border-coloured grid, legend top-right. Shared by every analytics chart.
 */
export const chartTooltipStyle = {
    borderRadius: '1rem',
    border: '1px solid var(--color-border)',
    background: 'var(--color-popover)',
    color: 'var(--color-popover-foreground)',
    fontSize: 12,
    padding: '8px 12px',
    boxShadow: 'var(--shadow-md)',
};

export const axisTick = {
    fontSize: 11,
    fontFamily: 'var(--font-mono)',
    fill: 'var(--color-muted-foreground)',
};

export const chartMargin = { top: 8, right: 8, left: 0, bottom: 0 };

export const gridProps = {
    vertical: false,
    stroke: 'var(--color-border)',
    strokeDasharray: '2 4',
} as const;

export const legendProps = {
    verticalAlign: 'top',
    align: 'right',
    height: 28,
    iconType: 'circle',
    iconSize: 8,
    wrapperStyle: { fontSize: 12 },
} as const;

export const activeDot = {
    r: 4,
    strokeWidth: 2,
    stroke: 'var(--color-card)',
};
