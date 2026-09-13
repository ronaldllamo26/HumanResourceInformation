const ROUTE_MAP = {
    'dashboard': '/dashboard',
    'login': '/login',
    'logout': '/logout',
    'register': '/register',
    'password.request': '/forgot-password',
    'password.email': '/forgot-password',
    'password.reset': '/reset-password',
    'password.store': '/reset-password',
    'password.confirm': '/confirm-password',
    'verification.send': '/email/verification-notification',
    'profile.edit': '/settings/security',
};

export function route(name) {
    if (ROUTE_MAP[name]) {
        return ROUTE_MAP[name];
    }
    return `/${name?.replace(/\./g, '/') || ''}`;
}

if (typeof window !== 'undefined') {
    window.route = route;
}

export default route;
