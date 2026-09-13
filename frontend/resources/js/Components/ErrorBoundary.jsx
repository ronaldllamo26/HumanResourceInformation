import React from 'react';

export default class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null, errorInfo: null };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }

    componentDidCatch(error, errorInfo) {
        console.error('ErrorBoundary caught an error:', error, errorInfo);
        this.setState({ errorInfo });
    }

    render() {
        if (this.state.hasError) {
            return (
                <div style={{ padding: 32, background: '#fff1f2', color: '#9f1239', fontFamily: 'monospace', minHeight: '100vh', zIndex: 9999, position: 'relative' }}>
                    <h1 style={{ fontSize: 22, fontWeight: 'bold', marginBottom: 16 }}>⚠️ React Render Error</h1>
                    <div style={{ background: '#ffffff', padding: 16, borderRadius: 8, border: '1px solid #fecdd3', marginBottom: 16 }}>
                        <p style={{ fontWeight: 'bold', fontSize: 16, color: '#be123c' }}>{this.state.error?.toString()}</p>
                    </div>
                    <h3>Component Stack:</h3>
                    <pre style={{ whiteSpace: 'pre-wrap', background: '#ffffff', padding: 16, borderRadius: 8, fontSize: 13, border: '1px solid #fecdd3' }}>
                        {this.state.errorInfo?.componentStack}
                    </pre>
                    <h3 style={{ marginTop: 16 }}>Error Stack:</h3>
                    <pre style={{ whiteSpace: 'pre-wrap', background: '#ffffff', padding: 16, borderRadius: 8, fontSize: 13, border: '1px solid #fecdd3' }}>
                        {this.state.error?.stack}
                    </pre>
                    <button
                        onClick={() => window.location.reload()}
                        style={{ marginTop: 20, padding: '10px 20px', background: '#e11d48', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontWeight: 'bold' }}
                    >
                        Reload Page
                    </button>
                </div>
            );
        }
        return this.props.children;
    }
}
