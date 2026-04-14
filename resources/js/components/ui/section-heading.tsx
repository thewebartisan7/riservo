"use client";

import { mergeProps } from "@base-ui/react/merge-props";
import { useRender } from "@base-ui/react/use-render";
import type React from "react";
import { cn } from "@/lib/utils";

/**
 * Editorial section heading — wraps an eyebrow label, a hairline rule, and an optional action.
 * Matches the dashboard / welcome pattern used across riservo surfaces.
 */
export function SectionHeading({
  className,
  render,
  ...props
}: useRender.ComponentProps<"div">): React.ReactElement {
  const defaultProps = {
    className: cn("flex items-center gap-3", className),
    "data-slot": "section-heading",
  };

  return useRender({
    defaultTagName: "div",
    props: mergeProps<"div">(defaultProps, props),
    render,
  });
}

export function SectionTitle({
  className,
  render,
  ...props
}: useRender.ComponentProps<"h2">): React.ReactElement {
  const defaultProps = {
    className: cn(
      "shrink-0 text-[11px] font-medium uppercase tracking-[0.22em] text-muted-foreground",
      className,
    ),
    "data-slot": "section-title",
  };

  return useRender({
    defaultTagName: "h2",
    props: mergeProps<"h2">(defaultProps, props),
    render,
  });
}

export function SectionRule({
  className,
  ...props
}: React.ComponentProps<"span">): React.ReactElement {
  return (
    <span
      aria-hidden="true"
      className={cn("h-px flex-1 bg-border/70", className)}
      data-slot="section-rule"
      {...props}
    />
  );
}
