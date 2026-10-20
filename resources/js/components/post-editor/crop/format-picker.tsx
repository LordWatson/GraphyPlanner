import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { MediaFormatOptions } from '@/lib/media-format';
import { usePlatforms } from '@/lib/platforms';
import { cn } from '@/lib/utils';

/** Display name per platform, from the capability registry. */
export function usePlatformNames(): Record<string, string> {
    const registry = usePlatforms();

    return Object.fromEntries(
        Object.values(registry).map((platform) => [
            platform.value,
            platform.label,
        ]),
    );
}

export function FormatPicker({
    platform,
    value,
    options,
    onChange,
    className,
}: {
    platform: string;
    value: string;
    options: MediaFormatOptions;
    onChange: (value: string) => void;
    className?: string;
}) {
    const platformNames = usePlatformNames();
    const choices = options[platform] ?? [];

    if (choices.length < 2) {
        return null;
    }

    return (
        <ToggleGroup
            type="single"
            variant="outline"
            size="sm"
            value={value}
            onValueChange={(next) => next && onChange(next)}
            aria-label={`${platformNames[platform] ?? platform} image format`}
            className={cn('max-w-full overflow-x-auto', className)}
        >
            {choices.map((choice) => (
                <ToggleGroupItem
                    key={choice.value}
                    value={choice.value}
                    title={choice.label}
                    className="px-2.5 font-mono text-[11px] whitespace-nowrap"
                >
                    {choice.value === 'original' ? 'Original' : choice.value}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}
