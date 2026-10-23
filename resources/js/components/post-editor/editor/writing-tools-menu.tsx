import { Loader2, Wand2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { toast } from 'sonner';
import { http } from '@/lib/http';
import aiPosts from '@/routes/posts/ai';

type Tool = 'shorten' | 'expand' | 'change_tone' | 'add_cta' | 'adapt';

const TONES = ['Friendly', 'Professional', 'Playful', 'Bold', 'Warm'];

/**
 * Step M-7.3 (dev plan I-9.4) — inline writing tools over a caption: shorten, lengthen, change
 * tone, add a CTA, and (per channel) adapt to the platform. Each posts to the rewrite endpoint and
 * hands the rewritten text back via `onRewrite`; nothing is saved until the post is saved. Shown
 * only when AI is usable; disabled while a caption is empty or a rewrite is in flight.
 */
export function WritingToolsMenu({
    postId,
    caption,
    platform,
    platformLabel,
    onRewrite,
}: {
    postId: number;
    caption: string;
    platform?: string;
    platformLabel?: string;
    onRewrite: (next: string) => void;
}) {
    const [loading, setLoading] = useState(false);
    const empty = caption.trim() === '';

    const run = async (tool: Tool, tone?: string) => {
        if (empty) {
            return;
        }

        setLoading(true);
        const result = await http.post<{ text: string }>(
            aiPosts.rewrite.url(postId),
            {
                text: caption,
                tool,
                tone,
                platform: tool === 'adapt' ? platform : undefined,
            },
            {
                toastOnError: true,
                rateLimitMessage:
                    'That’s a lot of AI in a short time. Give it a minute.',
            },
        );
        setLoading(false);

        if (!result.ok) {
            return;
        }

        if (result.data.text.trim() === caption.trim()) {
            toast('No change suggested this time.');

            return;
        }

        onRewrite(result.data.text);
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={empty || loading}
                >
                    {loading ? <Loader2 className="animate-spin" /> : <Wand2 />}
                    Writing tools
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={() => void run('shorten')}>
                    Make it shorter
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => void run('expand')}>
                    Make it longer
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => void run('add_cta')}>
                    Add a call to action
                </DropdownMenuItem>
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger>Change tone</DropdownMenuSubTrigger>
                    <DropdownMenuSubContent>
                        {TONES.map((tone) => (
                            <DropdownMenuItem
                                key={tone}
                                onSelect={() => void run('change_tone', tone)}
                            >
                                {tone}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuSubContent>
                </DropdownMenuSub>
                {platform && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={() => void run('adapt')}>
                            Adapt to {platformLabel ?? platform}
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
