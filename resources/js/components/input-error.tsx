export function InputError({ message }: { message?: string }) {
    if (!message) return null;

    return (
        <p className="text-destructive-foreground text-xs">{message}</p>
    );
}
