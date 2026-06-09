import { router } from '@inertiajs/react';
import { forwardRef } from 'react';
import type { MouseEvent, ReactNode } from 'react';

interface Props {
    children: ReactNode;
    className?: string;
    onClick?: (e: MouseEvent<HTMLAnchorElement>) => void;
}

export const HowItWorksLink = forwardRef<HTMLAnchorElement, Props>(
    function HowItWorksLink({ children, className, onClick }, ref) {
        const handleClick = (e: MouseEvent<HTMLAnchorElement>) => {
            onClick?.(e);

            if (e.defaultPrevented) {
                return;
            }

            e.preventDefault();

            const scroll = () => {
                document
                    .getElementById('how-it-works')
                    ?.scrollIntoView({ behavior: 'smooth' });
            };

            if (window.location.pathname === '/') {
                scroll();

                return;
            }

            router.visit('/', {
                preserveScroll: true,
                onSuccess: () => requestAnimationFrame(scroll),
            });
        };

        return (
            <a
                ref={ref}
                href="/#how-it-works"
                onClick={handleClick}
                className={className}
            >
                {children}
            </a>
        );
    },
);
