import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import { useState } from 'react';

interface EmbedSnippetProps {
    label: string;
    code: string;
}

export function EmbedSnippet({ label, code }: EmbedSnippetProps) {
    const { t } = useTrans();
    const [copied, setCopied] = useState(false);

    function handleCopy() {
        navigator.clipboard.writeText(code);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium">{label}</span>
                <Button variant="ghost" size="sm" onClick={handleCopy}>
                    {copied ? t('Copied!') : t('Copy')}
                </Button>
            </div>
            <pre className="overflow-x-auto rounded-lg bg-muted p-3 text-xs">
                <code>{code}</code>
            </pre>
        </div>
    );
}
