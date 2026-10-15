import { BrandMark } from '@/components/brand-mark';

export default function AppLogo() {
    return (
        <BrandMark
            size="md"
            wordmark
            caption="Studio OS"
            className="text-sidebar-accent-foreground [&>span:last-child]:group-data-[collapsible=icon]:hidden"
        />
    );
}
