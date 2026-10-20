import { ChevronLeftIcon, ChevronRightIcon } from "lucide-react"
import * as React from "react"
import { DayPicker, getDefaultClassNames } from "react-day-picker"

import { buttonVariants } from "@/components/ui/button"
import { cn } from "@/lib/utils"

function Calendar({
  className,
  classNames,
  showOutsideDays = true,
  ...props
}: React.ComponentProps<typeof DayPicker>) {
  const defaults = getDefaultClassNames()

  return (
    <DayPicker
      data-slot="calendar"
      showOutsideDays={showOutsideDays}
      weekStartsOn={1}
      className={cn("p-1", className)}
      classNames={{
        root: cn("w-fit", defaults.root),
        months: cn("relative flex flex-col gap-4 sm:flex-row", defaults.months),
        month: cn("flex w-full flex-col gap-3", defaults.month),
        nav: cn(
          "absolute inset-x-0 top-0 flex w-full items-center justify-between",
          defaults.nav
        ),
        button_previous: cn(
          buttonVariants({ variant: "ghost", size: "icon" }),
          "size-8 p-0 aria-disabled:opacity-50",
          defaults.button_previous
        ),
        button_next: cn(
          buttonVariants({ variant: "ghost", size: "icon" }),
          "size-8 p-0 aria-disabled:opacity-50",
          defaults.button_next
        ),
        month_caption: cn(
          "flex h-8 w-full items-center justify-center px-8",
          defaults.month_caption
        ),
        caption_label: cn("text-sm font-semibold", defaults.caption_label),
        weekdays: cn("flex", defaults.weekdays),
        weekday: cn(
          "text-muted-foreground flex-1 font-mono text-[11px] font-medium uppercase select-none",
          defaults.weekday
        ),
        week: cn("mt-1 flex w-full", defaults.week),
        day: cn(
          "relative aspect-square w-full p-0 text-center text-sm select-none",
          defaults.day
        ),
        day_button: cn(
          "hover:bg-accent focus-visible:ring-ring/50 inline-flex size-9 items-center justify-center rounded-lg font-normal outline-none focus-visible:ring-[3px] aria-selected:opacity-100",
          defaults.day_button
        ),
        selected: cn(
          "[&>button]:bg-primary [&>button]:text-primary-foreground [&>button]:hover:bg-primary",
          defaults.selected
        ),
        today: cn("[&>button]:ring-foreground/40 [&>button]:ring-1", defaults.today),
        outside: cn("text-muted-foreground opacity-60", defaults.outside),
        disabled: cn("text-muted-foreground opacity-40", defaults.disabled),
        hidden: cn("invisible", defaults.hidden),
        ...classNames,
      }}
      components={{
        Chevron: ({ orientation, className: chevronClass, ...chevronProps }) =>
          orientation === "left" ? (
            <ChevronLeftIcon className={cn("size-4", chevronClass)} {...chevronProps} />
          ) : (
            <ChevronRightIcon className={cn("size-4", chevronClass)} {...chevronProps} />
          ),
      }}
      {...props}
    />
  )
}

export { Calendar }
