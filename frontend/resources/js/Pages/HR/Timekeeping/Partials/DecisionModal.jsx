import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { Button, Field, Modal, Textarea } from '@/Components/ui';

/**
 * Approve or reject one request. A rejection needs a reason — the person who
 * filed it reads it back, and "rejected" alone only gets the same request again.
 */
export default function DecisionModal({ target, onClose, url, title, children }) {
    const form = useForm({ decision: 'approve', remarks: '' });

    useEffect(() => {
        if (target) {
            form.clearErrors();
            form.setData({ decision: 'approve', remarks: '' });
        }
    }, [target?.id]);

    const submit = (decision) => {
        form.transform((data) => ({ ...data, decision }));
        form.post(url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal show={target !== null} onClose={onClose} title={title}>
            <div className="space-y-4">
                {children}

                <Field
                    label="Remarks"
                    hint="Required when rejecting"
                    error={form.errors.remarks}
                >
                    {({ id }) => (
                        <Textarea
                            id={id}
                            rows={3}
                            value={form.data.remarks}
                            onChange={(event) => form.setData('remarks', event.target.value)}
                        />
                    )}
                </Field>

                {form.errors.status && (
                    <p className="text-xs text-destructive">{form.errors.status}</p>
                )}

                <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        loading={form.processing}
                        onClick={() => submit('reject')}
                    >
                        Reject
                    </Button>
                    <Button
                        type="button"
                        loading={form.processing}
                        onClick={() => submit('approve')}
                    >
                        Approve
                    </Button>
                </div>
            </div>
        </Modal>
    );
}
