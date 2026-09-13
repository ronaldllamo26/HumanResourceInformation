import { Link, usePage } from '@inertiajs/react';
import { LogoMark } from '@/Components/layout/PrimePowerLogo';

/**
 * Shell for the guest pages that are not Login — password reset, email
 * verification, password confirmation.
 *
 * Login draws its own split layout, but these share the same surface tokens
 * and the same mark, so following "Forgot your password?" does not land the
 * visitor somewhere that looks like a different product.
 */
export default function GuestLayout({ children }) {
    const brand = usePage().props.brand ?? {};
    const name = brand.name ?? 'PrimePower';

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-background px-4 py-10">
            <Link href="/" className="mb-6 flex items-center gap-2.5">
                {/* Big enough for the artwork's own "PRIMEPOWER" to read, which it\n                            does not at sidebar size. */}
                <LogoMark className="h-20 w-20" />
                <span className="text-lg font-bold tracking-tight text-logo-primary">
                    {name.toUpperCase()}
                </span>
            </Link>

            <div className="w-full max-w-md rounded-xl border border-border bg-card px-6 py-8 text-card-foreground shadow-lg sm:px-8">
                {children}
            </div>
        </div>
    );
}
