import { Head, useForm, usePage } from '@inertiajs/react';
import { LogoMark } from '@/Components/layout/PrimePowerLogo';
import { Button, Input, InputError, Label } from '@/Components/ui';

export default function Login({ status }) {
    const brand = usePage().props.brand ?? {};
    const name = brand.name ?? 'PrimePower';

    const { data, setData, post, processing, errors, reset } = useForm({
        username: '',
        password: '',
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-background p-4 sm:p-6">
            <Head title="Log in" />

            <div className="grid w-full max-w-4xl overflow-hidden rounded-xl border border-border bg-card shadow-lg lg:grid-cols-2">
                {/* Form */}
                <div className="px-6 py-10 sm:px-10">
                    <div className="mb-8 flex items-center justify-center gap-2.5">
                        <LogoMark className="h-20 w-20" />
                        <span className="text-lg font-bold tracking-tight text-logo-primary">
                            {name.toUpperCase()}
                        </span>
                    </div>

                    <div className="mb-7 text-center">
                        <h1 className="text-2xl font-bold tracking-tight text-foreground">
                            Welcome back
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Login to your {name} account
                        </p>
                    </div>

                    {status && (
                        <p
                            role="status"
                            className="mb-5 rounded-md border border-success/20 bg-success/10 px-3 py-2 text-sm font-medium text-success"
                        >
                            {status}
                        </p>
                    )}

                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <Label htmlFor="username">Username</Label>
                            <Input
                                id="username"
                                type="text"
                                name="username"
                                value={data.username}
                                autoComplete="username"
                                placeholder="name@primepower.test or admin"
                                autoCapitalize="none"
                                spellCheck={false}
                                autoFocus
                                error={errors.username}
                                onChange={(event) => setData('username', event.target.value)}
                            />
                            <InputError message={errors.username} />
                        </div>

                        <div>
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                value={data.password}
                                autoComplete="current-password"
                                error={errors.password}
                                onChange={(event) => setData('password', event.target.value)}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <Button
                            type="submit"
                            size="lg"
                            className="w-full"
                            loading={processing}
                            disabled={processing}
                        >
                            Login
                        </Button>
                    </form>

                    <p className="mt-8 text-center text-xs text-muted-foreground">
                        Accounts are issued by HR. Can&apos;t sign in? Ask your administrator.
                    </p>
                </div>

                {/* Brand panel */}
                <div className="hidden place-items-center border-l border-border bg-card p-10 lg:grid">
                    <img
                        src="/images/logo.png"
                        alt=""
                        className="max-h-72 w-full object-contain"
                    />
                </div>
            </div>
        </div>
    );
}
