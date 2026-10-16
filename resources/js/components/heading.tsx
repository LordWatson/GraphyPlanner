export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    // Small = a section within a page (settings, staff): a ruled spec header — ink hairline over
    // the title — so stacked form sections read like the book's spreads rather than floating text.
    if (variant === 'small') {
        return (
            <header className="border-foreground/85 flex flex-col gap-1 border-t pt-4">
                <h2 className="text-[17px] font-semibold tracking-[-0.01em]">
                    {title}
                </h2>
                {description && (
                    <p className="text-muted-foreground text-[13px]">
                        {description}
                    </p>
                )}
            </header>
        );
    }

    return (
        <header className="mb-8 flex flex-col gap-2">
            <h2 className="font-display text-3xl leading-[1.1]">{title}</h2>
            {description && (
                <p className="text-muted-foreground text-[15px]">
                    {description}
                </p>
            )}
        </header>
    );
}
