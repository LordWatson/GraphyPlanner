import { AUTO } from '@/components/post-editor/composer/composer-parts';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PlatformCapability } from '@/lib/platforms';
import { resolveFormat } from '@/lib/platforms';

/** Step M-2.3: a channel's format (feed, reel, story…), "Auto" picking from the media. */
export function ChannelFormatField({
    id,
    capability,
    format,
    mediaKinds,
    onChange,
}: {
    id: string;
    capability: PlatformCapability;
    format: string | null;
    mediaKinds: string[];
    onChange: (format: string | null) => void;
}) {
    const resolved = resolveFormat(capability, format, mediaKinds);
    const autoLabel = resolveFormat(capability, null, mediaKinds);

    return (
        <div className="grid gap-2 sm:max-w-xs">
            <Label htmlFor={id}>Format</Label>
            <Select
                value={format ?? AUTO}
                onValueChange={(value) =>
                    onChange(value === AUTO ? null : value)
                }
            >
                <SelectTrigger id={id}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={AUTO}>
                        Auto — {autoLabel?.label ?? 'from the media'}
                    </SelectItem>
                    {capability.formats.map((option) => (
                        <SelectItem key={option.key} value={option.key}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {resolved && (
                <p className="text-muted-foreground text-xs">
                    {resolved.min_items === resolved.max_items
                        ? `Takes ${resolved.max_items} ${resolved.media.join(' or ')}`
                        : `Takes ${resolved.min_items}–${resolved.max_items} items`}
                    {resolved.crop_to ? `, shown ${resolved.crop_to}.` : '.'}
                </p>
            )}
        </div>
    );
}
