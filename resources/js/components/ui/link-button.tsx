import { Link, type InertiaLinkProps } from '@inertiajs/react';
import { type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';
import { buttonVariants } from '@/components/ui/button';

export type LinkButtonProps = Omit<InertiaLinkProps, 'size'> &
    VariantProps<typeof buttonVariants>;

export function LinkButton({
    className,
    variant,
    size,
    ...props
}: LinkButtonProps) {
    return (
        <Link
            className={cn(buttonVariants({ variant, size, className }))}
            {...props}
        />
    );
}
