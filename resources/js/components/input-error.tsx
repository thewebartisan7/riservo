/** Used for error summary blocks (not per-field). For per-field errors, use FieldError from @/components/ui/field. */
export function InputError({ message }: { message?: string }) {
    if (!message) return null;

    return (
        <p className="text-destructive-foreground text-xs">{message}</p>
    );
}
