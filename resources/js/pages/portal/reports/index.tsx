import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, FileBarChart } from 'lucide-react';
import { reported, signed } from '@/components/analytics/engagement-section';
import type { ClientReportRow } from '@/components/client-reports/client-report-document';
import { ChapterHero, EmptyState, HeroStat, Page } from '@/components/page';
import { formatDate } from '@/lib/dates';
import { show as portalReport } from '@/routes/portal/reports';

/**
 * Step M-3.3 — the client's monthly reports from the studio, newest first. Only published reports
 * ever reach this list.
 */
export default function PortalReportsIndex({
    reports,
}: {
    reports: ClientReportRow[];
}) {
    return (
        <>
            <Head title="Reports — Client portal" />
            <Page>
                <ChapterHero
                    eyebrow="Results"
                    title={
                        <>
                            Your monthly <em>reports</em>.
                        </>
                    }
                    description="What went out each month, how people responded and how your audience grew."
                    aside={
                        <HeroStat
                            icon={FileBarChart}
                            eyebrow="Shared with you"
                            value={String(reports.length).padStart(2, '0')}
                            caption={
                                reports.length === 1 ? 'Report' : 'Reports'
                            }
                        />
                    }
                />

                {reports.length === 0 ? (
                    <EmptyState
                        icon={FileBarChart}
                        title="No reports yet."
                        description="Your first monthly report will appear here early next month."
                    />
                ) : (
                    <section className="animate-rise border-border bg-card overflow-hidden rounded-2xl border">
                        <ul className="divide-border/70 divide-y">
                            {reports.map((report) => (
                                <li key={report.id}>
                                    <Link
                                        href={portalReport(report.id)}
                                        className="hover:bg-muted/50 group flex flex-col gap-2 px-5 py-4 transition-colors sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <span className="flex flex-col">
                                            <span className="font-display text-lg font-semibold">
                                                {report.period_label}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                Shared{' '}
                                                {formatDate(
                                                    report.published_at,
                                                )}
                                            </span>
                                        </span>
                                        <span className="text-muted-foreground flex items-center gap-4 font-mono text-xs tabular-nums">
                                            <span>
                                                {reported(
                                                    report.posts_published,
                                                )}{' '}
                                                posts
                                            </span>
                                            <span>
                                                {reported(report.engagement)}{' '}
                                                interactions
                                            </span>
                                            <span>
                                                {signed(report.follower_growth)}{' '}
                                                followers
                                            </span>
                                            <ArrowUpRight
                                                className="group-hover:text-foreground size-4"
                                                aria-hidden
                                            />
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </Page>
        </>
    );
}
