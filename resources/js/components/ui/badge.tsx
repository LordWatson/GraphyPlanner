import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * Pills (§7.4 — chips are pill-shaped). Status variants are tinted with their status colour;
 * `lime` / `lilac` / `electric` are Alima's decorative label tags ("NEW", "BETA", "DRAFT") —
 * ink text on a palette tint, never a status.
 */
const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-full border px-2.5 py-0.5 text-[11px] leading-4 font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1.5 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden",
  {
    variants: {
      variant: {
        default:
          "border-primary/20 bg-accent text-accent-foreground [a&]:hover:bg-primary/15",
        secondary:
          "border-border bg-muted text-foreground/80 [a&]:hover:bg-secondary",
        destructive:
          "border-destructive/20 bg-destructive/10 text-destructive [a&]:hover:bg-destructive/15",
        success:
          "border-success/20 bg-success/10 text-success [a&]:hover:bg-success/15",
        warning:
          "border-warning/25 bg-warning/10 text-warning [a&]:hover:bg-warning/15",
        info:
          "border-info/20 bg-info/10 text-info [a&]:hover:bg-info/15",
        outline:
          "border-border bg-transparent text-muted-foreground [a&]:hover:bg-muted [a&]:hover:text-foreground",
        solid:
          "border-transparent bg-ink text-ink-foreground",
        lime: "eyebrow rounded-[3px] border-transparent bg-lime px-1.5 text-[10px] text-ink",
        lilac: "eyebrow rounded-[3px] border-transparent bg-lilac px-1.5 text-[10px] text-ink",
        electric: "eyebrow rounded-[3px] border-transparent bg-[color-mix(in_srgb,var(--electric)_45%,var(--cream))] px-1.5 text-[10px] text-ink",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant,
  asChild = false,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "span"

  return (
    <Comp
      data-slot="badge"
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    />
  )
}

export { Badge, badgeVariants }
