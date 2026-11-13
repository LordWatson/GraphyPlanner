import { Plus, X } from 'lucide-react';
import { useRef } from 'react';
import {
    Counter,
    FieldHint,
} from '@/components/post-editor/story-extras/parts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { STORY_LIMITS } from '@/lib/story-extras';

type Poll = { question: string; options: string[] };

/** A poll sticker: a question and two to four options. */
export function PollField({
    id,
    poll,
    onChange,
}: {
    id: string;
    poll: Poll;
    onChange: (poll: Poll) => void;
}) {
    // Always at least two option fields, however many the saved poll has.
    const options = [
        ...poll.options,
        ...Array<string>(
            Math.max(0, STORY_LIMITS.minPollOptions - poll.options.length),
        ).fill(''),
    ];
    const optionsRef = useRef<HTMLFieldSetElement>(null);
    const setOption = (index: number, value: string) =>
        onChange({
            ...poll,
            options: options.map((option, i) => (i === index ? value : option)),
        });

    const removeOption = (index: number) => {
        onChange({ ...poll, options: options.filter((_, i) => i !== index) });
        // The removed row's button is gone; the option before it (which stays put) takes focus.
        optionsRef.current
            ?.querySelectorAll('input')
            [Math.max(0, index - 1)]?.focus();
    };

    return (
        <div className="grid gap-3">
            <div className="grid gap-2">
                <span className="flex items-baseline">
                    <Label htmlFor={`${id}-question`}>Question</Label>
                    <Counter
                        used={poll.question.length}
                        limit={STORY_LIMITS.pollQuestion}
                    />
                </span>
                <Input
                    id={`${id}-question`}
                    dir="auto"
                    value={poll.question}
                    maxLength={STORY_LIMITS.pollQuestion}
                    onChange={(event) =>
                        onChange({ ...poll, question: event.target.value })
                    }
                    placeholder="Which one?"
                />
            </div>
            <fieldset ref={optionsRef} className="grid gap-2">
                <legend className="mb-2 text-sm leading-none font-medium">
                    Options
                </legend>
                {options.map((option, index) => (
                    <div key={index} className="flex items-center gap-2">
                        <Input
                            dir="auto"
                            value={option}
                            maxLength={STORY_LIMITS.pollOption}
                            onChange={(event) =>
                                setOption(index, event.target.value)
                            }
                            placeholder={`Option ${index + 1}`}
                            aria-label={`Poll option ${index + 1}`}
                        />
                        {options.length > STORY_LIMITS.minPollOptions && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="text-muted-foreground size-11 shrink-0 sm:size-10"
                                onClick={() => removeOption(index)}
                                aria-label={`Remove option ${index + 1}`}
                            >
                                <X />
                            </Button>
                        )}
                    </div>
                ))}
                {options.length < STORY_LIMITS.maxPollOptions && (
                    <Button
                        type="button"
                        variant="link"
                        className="h-11 justify-self-start text-[13px] sm:h-8"
                        onClick={() =>
                            onChange({ ...poll, options: [...options, ''] })
                        }
                    >
                        <Plus className="size-3.5" />
                        Add an option
                    </Button>
                )}
                <FieldHint>
                    {STORY_LIMITS.minPollOptions} to{' '}
                    {STORY_LIMITS.maxPollOptions} options, up to{' '}
                    {STORY_LIMITS.pollOption} characters each.
                </FieldHint>
            </fieldset>
        </div>
    );
}
