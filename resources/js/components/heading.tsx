export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    if (variant === 'small') {
        return (
            <header className="flex flex-col gap-1">
                <h2 className="text-[15px] font-semibold tracking-[-0.005em]">
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
            <h2 className="font-serif text-4xl leading-none tracking-[-0.02em]">
                {title}
            </h2>
            {description && (
                <p className="text-muted-foreground text-[15px]">
                    {description}
                </p>
            )}
        </header>
    );
}
