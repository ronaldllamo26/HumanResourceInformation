import React, { useEffect, useState } from 'react';
import api from '@/lib/api';
import Dashboard from '@/Pages/Dashboard';

export default function DashboardWrapper() {
    const [dashboardData, setDashboardData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        let mounted = true;
        api.get('/api/dashboard')
            .then((res) => {
                if (mounted) {
                    setDashboardData(res.data);
                    setLoading(false);
                }
            })
            .catch((err) => {
                if (mounted) {
                    setError(err);
                    setLoading(false);
                }
            });
        return () => {
            mounted = false;
        };
    }, []);

    if (loading) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-background">
                <div className="flex flex-col items-center gap-3">
                    <div className="h-10 w-10 animate-spin rounded-full border-4 border-primary border-t-transparent" />
                    <p className="text-sm font-medium text-muted-foreground">Loading dashboard...</p>
                </div>
            </div>
        );
    }

    if (error || !dashboardData) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-background p-4">
                <div className="rounded-lg border border-destructive/20 bg-card p-6 text-center shadow">
                    <p className="text-sm font-semibold text-destructive">Failed to load dashboard data.</p>
                    <button
                        type="button"
                        onClick={() => window.location.reload()}
                        className="mt-4 rounded bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                    >
                        Retry
                    </button>
                </div>
            </div>
        );
    }

    return <Dashboard {...dashboardData} />;
}
