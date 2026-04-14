"use client";

import { mergeProps } from "@base-ui/react/merge-props";
import { useRender } from "@base-ui/react/use-render";
import type React from "react";
import { cn } from "@/lib/utils";

export function Display({
  className,
  render,
  ...props
}: useRender.ComponentProps<"span">): React.ReactElement {
  const defaultProps = {
    className: cn(
      "font-display [font-feature-settings:'ss01'] tracking-[-0.02em]",
      className,
    ),
    "data-slot": "display",
  };

  return useRender({
    defaultTagName: "span",
    props: mergeProps<"span">(defaultProps, props),
    render,
  });
}
