import {
    Dialog,
    DialogPanel,
    DialogTitle,
    Transition,
    TransitionChild,
} from '@headlessui/react';
import { Fragment } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

const MAX_WIDTHS = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl',
    '2xl': 'sm:max-w-2xl',
    '3xl': 'sm:max-w-3xl',
};

export default function Modal({
    show = false,
    onClose,
    title,
    description,
    maxWidth = 'lg',
    closeable = true,
    footer,
    children,
}) {
    return (
        <Transition show={show} as={Fragment}>
            <Dialog as="div" className="relative z-50" onClose={() => closeable && onClose?.()}>
                <TransitionChild
                    as={Fragment}
                    enter="ease-out duration-200"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-150"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-foreground/40 backdrop-blur-sm" />
                </TransitionChild>

                <div className="fixed inset-0 overflow-y-auto">
                    <div className="flex min-h-full items-center justify-center p-4">
                        <TransitionChild
                            as={Fragment}
                            enter="ease-out duration-200"
                            enterFrom="opacity-0 translate-y-2 sm:scale-95"
                            enterTo="opacity-100 translate-y-0 sm:scale-100"
                            leave="ease-in duration-150"
                            leaveFrom="opacity-100 translate-y-0 sm:scale-100"
                            leaveTo="opacity-0 translate-y-2 sm:scale-95"
                        >
                            <DialogPanel
                                className={cn(
                                    'w-full overflow-hidden rounded-lg border border-border bg-popover text-popover-foreground shadow-xl',
                                    MAX_WIDTHS[maxWidth],
                                )}
                            >
                                {(title || closeable) && (
                                    <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                                        <div className="min-w-0">
                                            {title && (
                                                <DialogTitle className="text-sm font-semibold text-foreground">
                                                    {title}
                                                </DialogTitle>
                                            )}
                                            {description && (
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {description}
                                                </p>
                                            )}
                                        </div>

                                        {closeable && (
                                            <button
                                                type="button"
                                                onClick={onClose}
                                                aria-label="Close dialog"
                                                className="-mr-1 rounded-md p-1 text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                                            >
                                                <X className="h-4 w-4" aria-hidden="true" />
                                            </button>
                                        )}
                                    </div>
                                )}

                                <div className="px-5 py-4">{children}</div>

                                {footer && (
                                    <div className="flex items-center justify-end gap-2 border-t border-border bg-secondary/30 px-5 py-3">
                                        {footer}
                                    </div>
                                )}
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </Transition>
    );
}
