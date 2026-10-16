import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';

export default function AuthLayout({
    title = '',
    description = '',
    peek = false,
    children,
}: {
    title?: string;
    description?: string;
    peek?: boolean;
    children: React.ReactNode;
}) {
    return (
        <AuthLayoutTemplate title={title} description={description} peek={peek}>
            {children}
        </AuthLayoutTemplate>
    );
}
