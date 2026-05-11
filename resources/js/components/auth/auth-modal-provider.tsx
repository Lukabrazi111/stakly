import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useState,
} from 'react';

export type AuthView = 'login' | 'register' | 'forgot-password';

const VIEW_VALUES = ['login', 'register', 'forgot-password'] as const;

interface State {
    open: boolean;
    view: AuthView;
}

function readState(): State {
    if (typeof window === 'undefined') {
        return { open: false, view: 'login' };
    }

    const auth = new URLSearchParams(window.location.search).get('auth');

    if (auth && (VIEW_VALUES as readonly string[]).includes(auth)) {
        return { open: true, view: auth as AuthView };
    }

    return { open: false, view: 'login' };
}

function writeAuthParam(view: AuthView | null, mode: 'push' | 'replace') {
    const url = new URL(window.location.href);

    if (view) {
        url.searchParams.set('auth', view);
    } else {
        url.searchParams.delete('auth');
    }

    const next = url.pathname + url.search + url.hash;

    if (mode === 'push') {
        window.history.pushState(window.history.state, '', next);
    } else {
        window.history.replaceState(window.history.state, '', next);
    }
}

interface AuthModalContextValue {
    open: boolean;
    view: AuthView;
    openLogin: () => void;
    openRegister: () => void;
    openForgotPassword: () => void;
    setView: (view: AuthView) => void;
    close: () => void;
}

const AuthModalContext = createContext<AuthModalContextValue | null>(null);

export function useAuthModal() {
    const ctx = useContext(AuthModalContext);

    if (!ctx) {
        throw new Error(
            'useAuthModal must be used inside <AuthModalProvider>',
        );
    }

    return ctx;
}

export function AuthModalProvider({ children }: { children: ReactNode }) {
    const [state, setState] = useState<State>(readState);

    useEffect(() => {
        const sync = () => setState(readState());

        window.addEventListener('popstate', sync);
        const removeInertiaListener = router.on('navigate', sync);

        return () => {
            window.removeEventListener('popstate', sync);
            removeInertiaListener();
        };
    }, []);

    const setView = useCallback((next: AuthView) => {
        setState((prev) => {
            writeAuthParam(next, prev.open ? 'replace' : 'push');

            return { open: true, view: next };
        });
    }, []);

    const openLogin = useCallback(() => setView('login'), [setView]);
    const openRegister = useCallback(() => setView('register'), [setView]);
    const openForgotPassword = useCallback(
        () => setView('forgot-password'),
        [setView],
    );

    const close = useCallback(() => {
        writeAuthParam(null, 'replace');
        setState((prev) => ({ open: false, view: prev.view }));
    }, []);

    return (
        <AuthModalContext.Provider
            value={{
                open: state.open,
                view: state.view,
                openLogin,
                openRegister,
                openForgotPassword,
                setView,
                close,
            }}
        >
            {children}
        </AuthModalContext.Provider>
    );
}
