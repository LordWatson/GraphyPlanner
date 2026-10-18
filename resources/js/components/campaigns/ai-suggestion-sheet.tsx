import { Check, CheckCheck, ShieldAlert, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

export type SuggestionSection = {
    key: string;
    label: string;
    current: string;
    suggested: string;
    flags: string[];
};

/**
 * Plan §1.3.4 "AI suggests, humans accept" — a side sheet showing, per section, what's there now
 * against what the AI suggests, with guardrail flags called out. Accepting fills the editor only;
 * nothing is saved until the person saves. Shared by the brief (C-2.2) and wrap report (C-7.3).
 */
export function AiSuggestionSheet({
    open,
    onOpenChange,
    title,
    description,
    sections,
    onAccept,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    sections: SuggestionSection[];
    onAccept: (key: string, value: string) => void;
}) {
    const [accepted, setAccepted] = useState<Record<string, boolean>>({});
    const offered = sections.filter(
        (section) => section.suggested.trim() !== '',
    );

    const accept = (section: SuggestionSection) => {
        onAccept(section.key, section.suggested);
        setAccepted((current) => ({ ...current, [section.key]: true }));
    };

    const acceptAll = () => {
        offered.forEach((section) => {
            if (!accepted[section.key]) {
                onAccept(section.key, section.suggested);
            }
        });
        setAccepted(
            Object.fromEntries(offered.map((section) => [section.key, true])),
        );
    };

    return (
        <Sheet
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setAccepted({});
                }

                onOpenChange(next);
            }}
        >
            <SheetContent className="flex w-full flex-col gap-0 sm:max-w-2xl">
                <SheetHeader className="border-border border-b">
                    <SheetTitle className="flex items-center gap-2">
                        <Sparkles className="text-primary size-4" />
                        {title}
                    </SheetTitle>
                    <SheetDescription>{description}</SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-y-auto px-4 py-2">
                    {offered.length === 0 ? (
                        <p className="text-muted-foreground py-10 text-center text-sm">
                            The AI came back empty this time. Try again in a
                            moment, or write it yourself.
                        </p>
                    ) : (
                        <ol className="divide-border/70 flex flex-col divide-y">
                            {offered.map((section) => (
                                <li
                                    key={section.key}
                                    className="flex flex-col gap-3 py-5"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="eyebrow text-foreground">
                                            {section.label}
                                        </span>
                                        <Button
                                            size="sm"
                                            variant={
                                                accepted[section.key]
                                                    ? 'ghost'
                                                    : 'outline'
                                            }
                                            disabled={accepted[section.key]}
                                            onClick={() => accept(section)}
                                        >
                                            {accepted[section.key] ? (
                                                <Check />
                                            ) : null}
                                            {accepted[section.key]
                                                ? 'Accepted'
                                                : 'Use this'}
                                        </Button>
                                    </div>
                                    {section.flags.length > 0 && (
                                        <p className="border-warning/25 bg-warning/10 text-warning flex items-start gap-2 rounded-lg border px-3 py-2 text-xs">
                                            <ShieldAlert className="mt-0.5 size-3.5 shrink-0" />
                                            Mentions something the Brand Brain
                                            rules out:{' '}
                                            {section.flags.join(', ')}. Edit
                                            before you use it.
                                        </p>
                                    )}
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        <div className="flex flex-col gap-1">
                                            <span className="text-muted-foreground text-[11px] font-medium">
                                                Now
                                            </span>
                                            <p
                                                className={cn(
                                                    'bg-muted/60 min-h-12 rounded-lg px-3 py-2 text-[13px] whitespace-pre-line',
                                                    !section.current &&
                                                        'text-muted-foreground italic',
                                                )}
                                            >
                                                {section.current || 'Empty'}
                                            </p>
                                        </div>
                                        <div className="flex flex-col gap-1">
                                            <span className="text-primary text-[11px] font-medium">
                                                Suggested
                                            </span>
                                            <p className="border-primary/25 bg-accent/60 min-h-12 rounded-lg border px-3 py-2 text-[13px] whitespace-pre-line">
                                                {section.suggested}
                                            </p>
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>

                <SheetFooter className="border-border flex-row justify-between border-t">
                    <p className="text-muted-foreground self-center text-xs">
                        Nothing is saved until you save.
                    </p>
                    <div className="flex gap-2">
                        <Button
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            Done
                        </Button>
                        {offered.length > 1 && (
                            <Button onClick={acceptAll}>
                                <CheckCheck />
                                Use all
                            </Button>
                        )}
                    </div>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
