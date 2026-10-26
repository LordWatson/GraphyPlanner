import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import McpConnectionController from '@/actions/App/Http/Controllers/Settings/McpConnectionController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { StatStrip } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { edit } from '@/routes/mcp-connections';

type TokenRow = {
    id: number;
    name: string;
    scopes: string[];
    scope_labels: string[];
    last_used_at: string | null;
    created_at: string | null;
    revoked: boolean;
};

type ScopeOption = {
    value: string;
    label: string;
    default: boolean;
};

type Usage = {
    window_days: number;
    totals: { calls: number; errors: number };
    by_tool: { tool: string; calls: number; errors: number }[];
    recent: {
        tool: string;
        outcome: string;
        duration_ms: number | null;
        token: string | null;
        at: string | null;
    }[];
};

type AiConnectionsProps = {
    tokens: TokenRow[];
    scopes: ScopeOption[];
    usage: Usage;
    settings: { mcp_enabled: boolean };
    httpEnabled: boolean;
    connectionUrl: string;
    newToken: string | null;
};

const number = new Intl.NumberFormat();

function formatWhen(value: string | null): string {
    if (!value) {
        return 'Never';
    }

    return new Date(value).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function CopyButton({ value, label }: { value: string; label: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => {
                void navigator.clipboard?.writeText(value).then(() => {
                    setCopied(true);
                    window.setTimeout(() => setCopied(false), 1500);
                });
            }}
        >
            {copied ? 'Copied' : label}
        </Button>
    );
}

/**
 * Settings → AI connections (Owner only). The Phase MCP-6.1 surface: turn MCP on for the studio,
 * mint scoped connection tokens (shown once), see what connected assistants have been doing
 * (MCP-7.1), and revoke a connection instantly (MCP-7.2). Read-only is the default scope; publishing
 * and AI are explicit, warned opt-ins.
 */
export default function AiConnections({
    tokens,
    scopes,
    usage,
    settings,
    httpEnabled,
    connectionUrl,
    newToken,
}: AiConnectionsProps) {
    const [enabled, setEnabled] = useState(settings.mcp_enabled);

    const activeTokens = tokens.filter((token) => !token.revoked);

    return (
        <>
            <Head title="AI connections" />

            <h1 className="sr-only">AI connections</h1>

            <div className="space-y-10">
                <Heading
                    variant="small"
                    title="Run your agency from your AI assistant"
                    description="Connect Claude, ChatGPT or Cursor to Graphy over MCP. They read and draft on your behalf — a person always confirms before anything is scheduled or published."
                />

                {!httpEnabled && (
                    <p className="border-border bg-muted/40 text-muted-foreground rounded-2xl border p-4 text-sm">
                        The MCP endpoint is switched off for this environment,
                        so the connection URL below won&apos;t accept calls yet.
                        Tokens you create here will work once it&apos;s enabled.
                    </p>
                )}

                {newToken && (
                    <div className="border-primary/40 bg-primary/5 space-y-3 rounded-2xl border p-5">
                        <p className="text-foreground text-sm font-semibold">
                            Your new connection token
                        </p>
                        <p className="text-muted-foreground text-sm">
                            Copy it now — for security it is never shown again.
                            Paste it into your assistant&apos;s connector as the
                            Bearer token.
                        </p>
                        <div className="flex items-center gap-2">
                            <code className="bg-background border-border flex-1 overflow-x-auto rounded-lg border px-3 py-2 font-mono text-xs">
                                {newToken}
                            </code>
                            <CopyButton value={newToken} label="Copy token" />
                        </div>
                    </div>
                )}

                {/* MCP-7.2: the per-org master switch. */}
                <Form
                    {...McpConnectionController.updateSettings.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing }) => (
                        <label
                            htmlFor="mcp_enabled"
                            className="border-border bg-card hover:border-foreground/30 flex cursor-pointer items-start gap-4 rounded-2xl border p-5 transition-colors"
                        >
                            <Checkbox
                                id="mcp_enabled"
                                checked={enabled}
                                onCheckedChange={(checked) =>
                                    setEnabled(checked === true)
                                }
                                className="mt-0.5"
                            />
                            <input
                                type="hidden"
                                name="mcp_enabled"
                                value={enabled ? '1' : '0'}
                            />
                            <div className="flex-1 space-y-1">
                                <span className="text-foreground block text-sm font-semibold">
                                    MCP access
                                </span>
                                <span className="text-muted-foreground block text-sm">
                                    When off, every connected assistant is
                                    rejected immediately — a one-switch kill
                                    switch for the whole organisation.
                                </span>
                            </div>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                Save
                            </Button>
                        </label>
                    )}
                </Form>

                <div className="grid gap-2">
                    <Label>Connection URL</Label>
                    <div className="flex items-center gap-2">
                        <code className="bg-background border-border flex-1 overflow-x-auto rounded-lg border px-3 py-2 font-mono text-xs">
                            {connectionUrl}
                        </code>
                        <CopyButton value={connectionUrl} label="Copy URL" />
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Add this as a custom MCP server in your assistant, with
                        a connection token as the Bearer credential.
                    </p>
                </div>

                <Separator />

                {/* MCP-6.1: mint a new scoped token. */}
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="New connection"
                        description="Name the assistant and choose what it may do. Read-only is the safe default; publishing and AI are explicit opt-ins."
                    />

                    <Form
                        {...McpConnectionController.store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="space-y-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid max-w-sm gap-2">
                                    <Label htmlFor="name">
                                        Connection name
                                    </Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        placeholder="e.g. Claude Desktop"
                                        aria-invalid={
                                            errors.name ? true : undefined
                                        }
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <fieldset className="space-y-3">
                                    <legend className="text-foreground text-sm font-medium">
                                        Permissions
                                    </legend>
                                    {scopes.map((scope) => (
                                        <label
                                            key={scope.value}
                                            htmlFor={`scope-${scope.value}`}
                                            className="flex cursor-pointer items-start gap-3 text-sm"
                                        >
                                            <input
                                                id={`scope-${scope.value}`}
                                                type="checkbox"
                                                name="scopes[]"
                                                value={scope.value}
                                                defaultChecked={scope.default}
                                                className="border-border text-primary mt-0.5 size-4 rounded"
                                            />
                                            <span className="text-muted-foreground">
                                                <span className="text-foreground font-medium">
                                                    {scope.label}
                                                </span>
                                                {scope.value ===
                                                    'write:schedule' &&
                                                    ' — can publish and schedule. Grant with care.'}
                                            </span>
                                        </label>
                                    ))}
                                    <InputError message={errors.scopes} />
                                </fieldset>

                                <Button type="submit" disabled={processing}>
                                    Create connection token
                                </Button>
                            </>
                        )}
                    </Form>
                </div>

                <Separator />

                {/* The live connections. */}
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Connections"
                        description="The tokens that can reach this organisation. Revoke one and it stops working immediately."
                    />

                    {activeTokens.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No active connections yet.
                        </p>
                    ) : (
                        <ul className="divide-border border-border divide-y rounded-2xl border">
                            {activeTokens.map((token) => (
                                <li
                                    key={token.id}
                                    className="flex items-start justify-between gap-4 p-4"
                                >
                                    <div className="space-y-1">
                                        <p className="text-foreground text-sm font-semibold">
                                            {token.name}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {token.scope_labels.join(', ')}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            Last used{' '}
                                            {formatWhen(token.last_used_at)}
                                        </p>
                                    </div>
                                    <Form
                                        {...McpConnectionController.destroy.form(
                                            token.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Revoke
                                            </Button>
                                        )}
                                    </Form>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <Separator />

                {/* MCP-7.1: usage + audit. */}
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Activity"
                        description={`What your connected assistants did in the last ${usage.window_days} days.`}
                    />

                    <StatStrip
                        items={[
                            {
                                label: 'Calls',
                                value: number.format(usage.totals.calls),
                                caption: 'Tool calls made',
                            },
                            {
                                label: 'Errors',
                                value: number.format(usage.totals.errors),
                                caption: 'Rejected or failed',
                            },
                            {
                                label: 'Tools used',
                                value: usage.by_tool.length,
                                caption: 'Distinct tools',
                            },
                        ]}
                    />

                    {usage.recent.length > 0 && (
                        <div className="border-border overflow-hidden rounded-2xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/40 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">
                                            Tool
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Outcome
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Connection
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            When
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-border divide-y">
                                    {usage.recent.map((row, index) => (
                                        <tr key={index}>
                                            <td className="px-4 py-2 font-mono text-xs">
                                                {row.tool}
                                            </td>
                                            <td className="px-4 py-2">
                                                <span
                                                    className={
                                                        row.outcome === 'error'
                                                            ? 'text-destructive'
                                                            : 'text-muted-foreground'
                                                    }
                                                >
                                                    {row.outcome}
                                                </span>
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2">
                                                {row.token ?? '—'}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2">
                                                {formatWhen(row.at)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

AiConnections.layout = {
    breadcrumbs: [
        {
            title: 'AI connections',
            href: edit(),
        },
    ],
};
