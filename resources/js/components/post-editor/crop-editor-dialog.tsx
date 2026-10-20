import type { PreviewAsset } from '@/components/asset-preview-carousel';
import { isImageAsset } from '@/components/social-preview/media';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import type { Crop, MediaFormat, MediaFormatOptions } from '@/lib/media-format';
import { DialogBody } from '@/components/post-editor/crop/crop-dialog-body';
export { FormatPicker } from '@/components/post-editor/crop/format-picker';

/**
 * Edit the post's image framing: per-channel format plus each image's focus point and zoom.
 * Works on a draft — nothing reaches the editor until "Apply framing", and nothing is saved until
 * the post is.
 */
export function CropEditorDialog({
    open,
    onOpenChange,
    assets,
    crops,
    platforms,
    activePlatform,
    mediaFormat,
    formatOptions,
    onApply,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assets: PreviewAsset[];
    crops: Record<number, Crop>;
    /** The post's channels (unique platforms), in display order. */
    platforms: string[];
    activePlatform: string;
    mediaFormat: MediaFormat;
    formatOptions: MediaFormatOptions;
    onApply: (crops: Record<number, Crop>, format: MediaFormat) => void;
}) {
    const images = assets.filter((asset) => asset.url && isImageAsset(asset));

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-4xl">
                {open && images.length > 0 && (
                    <DialogBody
                        images={images}
                        initialCrops={crops}
                        platforms={platforms}
                        initialPlatform={activePlatform}
                        initialFormat={mediaFormat}
                        formatOptions={formatOptions}
                        onApply={(nextCrops, nextFormat) => {
                            onApply(nextCrops, nextFormat);
                            onOpenChange(false);
                        }}
                        onCancel={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
