import { Panel } from '@/components/page';
import { ChannelSchedule } from '@/components/post-editor/channel-schedule';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { TargetFields } from '@/components/post-editor/target-fields';

/** 02 Where & when — the channels and each one's own local date and time. */
export function ChannelsSection({ error }: { error?: string }) {
    const { editor, nav, page } = usePostEditorContext();
    const { state, view } = editor;

    return (
        <div ref={nav.sectionRef('channels')} className="scroll-mt-24">
            <Panel
                eyebrow="02"
                title="Where & when"
                description="Tick the channels, then give each its own local time."
                className={nav.flash('channels')}
            >
                <ChannelSchedule
                    accounts={page.targetAccounts}
                    rows={state.schedule}
                    onToggle={editor.toggleChannel}
                    onChange={editor.updateSchedule}
                    onApplyToAll={editor.applyScheduleToAll}
                    onSameInstant={editor.applySameInstant}
                    usage={view.channelUsage}
                    error={error}
                />
                <TargetFields
                    accountIds={Object.keys(state.schedule).map(Number)}
                    schedule={state.schedule}
                    channels={view.saved.channels}
                />
            </Panel>
        </div>
    );
}
