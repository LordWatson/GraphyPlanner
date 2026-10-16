import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * Alima buttons (§7.3): pills, Poppins SemiBold, 40/48/56 heights.
 *   default   — Primary: Midnight Ink ("Let's grow"). Cream on Ink in dark mode is inverted.
 *   secondary — Brand: Deep Teal ("Start a project"); Acid Lime in dark mode. Alias: `brand`.
 *   outline   — Secondary: ink hairline on paper ("See the work").
 *   link      — Teal underlined text ("Let's talk →").
 *   spark     — Acid Lime with ink text, only for the CTA inside an Ink block ("Ink / Lime").
 *   on-ink    — Cream hairline secondary for use beside `spark` inside an Ink block.
 * Focus is the blue 3px ring with a 2px offset; disabled is a quiet stone fill.
 */
const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full text-sm font-semibold transition-[color,background-color,border-color,box-shadow,transform] duration-150 ease-brand active:translate-y-px disabled:pointer-events-none disabled:bg-disabled disabled:text-disabled-foreground disabled:border-transparent disabled:shadow-none [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-foreground text-background hover:bg-action-hover hover:shadow-md active:bg-action-pressed",
        secondary:
          "bg-primary text-primary-foreground hover:bg-primary-hover hover:shadow-md",
        brand:
          "bg-primary text-primary-foreground hover:bg-primary-hover hover:shadow-md",
        spark:
          "bg-lime text-ink hover:bg-[color-mix(in_srgb,var(--lime)_85%,var(--cream))] hover:shadow-md",
        destructive:
          "bg-destructive text-destructive-foreground hover:bg-destructive/90 focus-visible:ring-destructive/40",
        outline:
          "border-[1.5px] border-foreground/80 bg-transparent text-foreground hover:bg-secondary active:bg-pressed dark:border-foreground/60",
        "on-ink":
          "border-[1.5px] border-cream/45 bg-transparent text-cream hover:border-cream hover:bg-cream/10 focus-visible:ring-offset-ink",
        ghost: "hover:bg-secondary hover:text-foreground",
        link: "rounded-none px-0 text-primary underline decoration-2 underline-offset-4 hover:text-primary-hover dark:hover:text-primary-hover",
      },
      size: {
        default: "h-10 px-5 has-[>svg]:px-4",
        sm: "h-8 px-3.5 text-[13px] has-[>svg]:px-3",
        lg: "h-12 px-7 text-[15px] has-[>svg]:px-6",
        xl: "h-14 px-8 text-base has-[>svg]:px-7",
        icon: "size-10",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant,
  size,
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Button, buttonVariants }
