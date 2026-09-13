import React, { createContext, useContext, useEffect, useState } from 'react';
import api, { csrf } from '@/lib/api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);
    const [brand, setBrand] = useState({ name: 'PrimePower' });

    const fetchUser = async () => {
        try {
            const response = await api.get('/api/user', { timeout: 3000 });
            const userData = response.data?.data || response.data?.user || response.data;
            if (userData?.id) {
                setUser(userData);
                return userData;
            }
            return null;
        } catch {
            setUser(null);
            return null;
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchUser();

        const handleUnauthorized = () => {
            setUser(null);
        };

        window.addEventListener('auth:unauthorized', handleUnauthorized);
        return () => window.removeEventListener('auth:unauthorized', handleUnauthorized);
    }, []);

    const login = async ({ email, password }) => {
        await csrf();
        const response = await api.post('/login', { email, password });
        if (response.data?.user) {
            setUser(response.data.user);
        } else {
            await fetchUser();
        }
        return response.data;
    };

    const logout = async () => {
        try {
            await api.post('/logout');
        } finally {
            setUser(null);
        }
    };

    return (
        <AuthContext.Provider
            value={{
                user,
                role: user?.role,
                loading,
                login,
                logout,
                brand,
                refreshUser: fetchUser,
                isAdmin: user?.role === 'admin',
                isHrAdmin: user?.role === 'admin' || user?.role === 'hr_staff',
                isSupervisor: user?.role === 'supervisor',
                isEmployee: user?.role === 'employee',
            }}
        >
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    const context = useContext(AuthContext);
    if (!context) {
        throw new Error('useAuth must be used within an AuthProvider');
    }
    return context;
}
