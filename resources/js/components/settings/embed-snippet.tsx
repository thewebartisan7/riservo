import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import { cn } from '@/lib/utils';
import { CheckIcon, ClipboardIcon } from 'lucide-react';
import { useState } from 'react';

interface EmbedSnippetProps {
    label: string;
    code: string;
    variant?: 'code' | 'link';
}

export function EmbedSnippet({ label, code, variant = 'code' }: EmbedSnippetProps) {
    const { t } = useTrans();
    const [copied, setCopied] = useState(false);

    function handleCopy() {
        navigator.clipboard.writeText(code);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-end justify-between gap-3">
                <p className="text-[10px] font-medium uppercase tracking-[0.22em] text-muted-foreground">
                    {label}
                </p>
                <Button
                    variant="ghost"
                    size="xs"
                    onClick={handleCopy}
                    className={cn('gap-1.5', copied && 'text-primary')}
                >
                    {copied ? (
                        <>
                            <CheckIcon aria-hidden="true" />
                            {t('Copied')}
                        </>
                    ) : (
                        <>
                            <ClipboardIcon aria-hidden="true" />
                            {t('Copy')}
                        </>
                    )}
                </Button>
            </div>
            <pre
                className={cn(
                    'overflow-x-auto rounded-lg border border-border/70 bg-muted/60 p-4 leading-relaxed text-foreground/90',
                    variant === 'link'
                        ? 'font-display text-sm tracking-[-0.01em] whitespace-nowrap'
                        : 'font-mono text-xs',
                )}
            >
                <code>{code}</code>
            </pre>
        </div>
    );
}
