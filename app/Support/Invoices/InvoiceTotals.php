<?php

namespace App\Support\Invoices;

/**
 * Step M-4.1 — the single place an invoice's money is computed. A pure calculator over "lines"
 * (each a net amount with an optional tax rate), so it behaves the same whether it's run while a
 * draft is being edited, at finalize, or when a finalized invoice's items change afterwards.
 *
 * Tax only ever applies where a rate is set, so a line with no rate — and therefore a legacy or
 * zero-rate invoice — produces `subtotal === total`, exactly as before this step.
 */
final class InvoiceTotals
{
    /**
     * @param  list<array{name: string, rate: float, net: float, tax: float}>  $breakdown
     */
    private function __construct(
        public readonly float $subtotal,
        public readonly float $taxTotal,
        public readonly float $total,
        public readonly array $breakdown,
    ) {}

    /**
     * @param  iterable<array{net: float|int|string, rate?: float|int|string|null, name?: string|null}>  $lines
     */
    public static function fromLines(iterable $lines): self
    {
        $subtotal = 0.0;
        $taxTotal = 0.0;

        /** @var array<string, array{name: string, rate: float, net: float, tax: float}> $groups */
        $groups = [];

        foreach ($lines as $line) {
            $net = round((float) $line['net'], 2);
            $subtotal += $net;

            $rate = isset($line['rate']) && $line['rate'] !== null ? (float) $line['rate'] : null;

            if ($rate === null) {
                continue;
            }

            $name = (string) ($line['name'] ?? self::defaultName($rate));
            $tax = round($net * $rate / 100, 2);
            $taxTotal += $tax;

            // Group by rate + label so "VAT 20%" across several items shows as one summary line.
            $key = $name.'|'.number_format($rate, 2, '.', '');

            if (! isset($groups[$key])) {
                $groups[$key] = ['name' => $name, 'rate' => $rate, 'net' => 0.0, 'tax' => 0.0];
            }

            $groups[$key]['net'] = round($groups[$key]['net'] + $net, 2);
            $groups[$key]['tax'] = round($groups[$key]['tax'] + $tax, 2);
        }

        return new self(
            subtotal: round($subtotal, 2),
            taxTotal: round($taxTotal, 2),
            total: round($subtotal + $taxTotal, 2),
            breakdown: array_values($groups),
        );
    }

    /**
     * The columns an invoice stores (`subtotal`, `tax_total`, `tax_breakdown`, `amount`).
     *
     * @return array{subtotal: float, tax_total: float, tax_breakdown: list<array{name: string, rate: float, net: float, tax: float}>|null, amount: float}
     */
    public function toInvoiceColumns(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'tax_total' => $this->taxTotal,
            'tax_breakdown' => $this->breakdown === [] ? null : $this->breakdown,
            'amount' => $this->total,
        ];
    }

    private static function defaultName(float $rate): string
    {
        return 'Tax '.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').'%';
    }
}
