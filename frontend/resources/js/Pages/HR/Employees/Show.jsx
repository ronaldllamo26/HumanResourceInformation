import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import axios from 'axios';
import {
    ArrowLeft,
    CalendarDays,
    CheckCircle2,
    ChevronRight,
    Clock,
    Download,
    Eye,
    FileText,
    History,
    Loader2,
    MinusCircle,
    Pencil,
    Plus,
    ScanLine,
    Trash2,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    DateInput,
    Field,
    Input,
    Modal,
    Select,
    Table,
    TableEmpty,
    TBody,
    TD,
    Textarea,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import Qualifications from './Partials/Qualifications';
import { cn, formatCurrency, formatDate, initials } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

/**
 * The whole 201 file is on one page — these are jump links, not tabs. The
 * record used to be split four ways, which meant checking whether someone's
 * licence was on file was a click away from the licence number itself.
 */
const SECTIONS = [
    { id: 'records', label: 'My Records' },
    { id: 'overview', label: 'Overview' },
    { id: 'employment', label: 'Employment' },
    { id: 'qualifications', label: 'Qualifications' },
    { id: 'documents', label: 'Documents' },
];

/** Groups the cards that have no single card title of their own. */
function SectionHeading({ id, title }) {
    return (
        <h2
            id={id}
            className="mb-3 scroll-mt-6 text-xs font-semibold uppercase tracking-wider text-muted-foreground"
        >
            {title}
        </h2>
    );
}

function DetailRow({ label, value, className }) {
    return (
        <div className={cn('min-w-0', className)}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 break-words text-sm text-foreground">{value || '—'}</dd>
        </div>
    );
}

/**
 * One value the scanner read.
 *
 * A field it looked for and did not find is shown as "Not found" rather than
 * hidden: the difference between "the scanner missed this" and "the scanner
 * never looks at this" is the difference between checking the document again
 * and not bothering.
 */
function ScanField({ label, value, filled = false, mono = false }) {
    return (
        <div className="flex items-baseline gap-2 px-3 py-1.5">
            <dt className="w-20 shrink-0 text-xs text-muted-foreground">{label}</dt>
            <dd
                className={cn(
                    'min-w-0 flex-1 break-words text-xs',
                    value ? 'text-foreground' : 'italic text-muted-foreground/70',
                    mono && value && 'font-mono',
                )}
            >
                {value || 'Not found'}
            </dd>
            {/* Which values landed in the form. Without this the panel and the
                fields above it look like two unrelated readings of the same
                document. */}
            {filled && (
                <span className="shrink-0 text-[10px] uppercase tracking-wide text-muted-foreground">
                    Filled in
                </span>
            )}
        </div>
    );
}

/**
 * How the document's type was decided, in the order the server tries them.
 *
 * Shown because "this is a Clearance" and "this is a Clearance because its own
 * heading says so" are different claims, and only the second is worth refusing
 * an upload over. Saying which one it is lets HR judge the machine rather than
 * take it on faith.
 */
const TYPE_SOURCES = {
    stored_number: 'its number is already on this employee’s file',
    heading: 'the heading printed on it',
    number_format: 'the shape of its number',
    validity: 'how long it is valid for',
    model: 'the scanner’s best guess',
};
/** One verdict. `state` is the meaning; the icon and colour follow from it. */
function ScanCheck({ state, children }) {
    const Icon =
        state === 'ok' ? CheckCircle2 : state === 'unknown' ? MinusCircle : TriangleAlert;

    return (
        <p
            className={cn(
                'flex items-start gap-1.5 text-xs',
                state === 'ok' && 'text-success',
                state === 'bad' && 'text-destructive',
                state === 'note' && 'text-warning',
                state === 'unknown' && 'text-muted-foreground',
            )}
        >
            <Icon className="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span className="min-w-0">{children}</span>
        </p>
    );
}

function formatBytes(bytes) {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export default function Show({
    employee,
    subordinates,
    expiringTypes = [],
    documentTypes = [],
    // The ladder and the grades as config states them. Sent rather than
    // restated here, because the *order* of the levels is what makes "highest
    // attainment" mean anything and a second copy would drift from it.
    educationLevels = {},
    levelsWithCourse = [],
    proficiencyLevels = {},
    licence,
    can,
    isMyProfile = false,
}) {
    const { auth } = usePage().props;
    const record = employee.data ?? employee;
    const isSelf = isMyProfile || (Boolean(auth?.user?.id) && record.user_id === auth.user.id);

    const [uploadOpen, setUploadOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [pendingDocument, setPendingDocument] = useState(null);
    const [preview, setPreview] = useState(null);

    const upload = useForm({
        type: 'contract',
        title: '',
        description: '',
        issued_at: '',
        expires_at: '',
        file: null,
        // Which scan this upload is answering, so the accuracy figures can
        // tell a corrected proposal from an accepted one. An identifier only;
        // the proposal itself is read back server-side.
        scan_id: null,
    });

    // What the scanner proposed, kept beside the form rather than merged into
    // it — HR needs to see that a value was read rather than typed, and see
    // the name check, before saving.
    const [scan, setScan] = useState(null);
    const [scanning, setScanning] = useState(false);

    /*
     * A document that belongs to someone else cannot be filed here at all.
     *
     * This is the one place the scanner *stops* an action rather than warning
     * about it — a deliberate departure from PayrollReadiness, salary bands,
     * and wage floors, which all flag without blocking. Filing under the wrong
     * employee was judged worth refusing, because the error is silent
     * afterwards: nobody goes looking through another person's 201 file for a
     * document that should never have been there.
     *
     * **The ID number outranks the name.** A licence number belongs to one
     * person and OCR reads digits well; a name is shared by thousands and
     * arrives truncated or misread. So a number matching the 201 file settles
     * it even when the name reading looks wrong — which is exactly the case
     * that refused a real licence: the card printed "JOHN GAVE" with no
     * surname, the model read "JONN GAVE", and the number underneath was right
     * all along. A number that *contradicts* the file is the strongest
     * evidence available that the document is someone else's, and blocks on
     * its own.
     *
     * The scanner only reads images (`config('scanner.accepts')`), so a
     * document that genuinely names someone else and still belongs on the
     * file — a dependant's birth certificate, an HMO beneficiary form — is
     * uploaded as a PDF, which never reaches this check. That is the escape
     * hatch, and it is deliberate rather than a gap.
     */
    const numberMatch = scan?.number_matches;

    /*
     * Filed under a type the document contradicts.
     *
     * Before this, choosing "Government ID" and uploading a clearance saved
     * quietly under the wrong type — and a clearance filed as an ID lands in
     * the wrong renewal window in CredentialExpiryScanner, so it stops being
     * chased at all.
     *
     * Only refused when the type was read off the document's own heading. The
     * model's bare guess at a type is wrong often enough that blocking on it
     * would refuse correct filings; that case is said out loud and left to HR.
     */
    const typeConflict = Boolean(
        scan?.type && upload.data.type && scan.type !== upload.data.type,
    );

    /*
     * Refused only on evidence from the document itself — a number already
     * on this employee's 201 file, or the heading printed across the top.
     * The server decides which sources count (`type_certain`); the weaker
     * ones are still said out loud in the panel, because a number *shape* is
     * shared between cards and a validity period overlaps between types.
     */
    const blockedByType = typeConflict && scan?.type_certain === true;

    /*
     * The file is not a recognisable HR document at all.
     *
     * A screenshot of a dashboard, a meme, a photo of lunch — the scanner
     * reads them, finds nothing it recognises, and until now the Upload
     * button stayed active anyway. The file would be saved under type
     * "Other" with no name, no number and no dates, cluttering a 201 file
     * with something that has no business being there.
     *
     * Blocked when every signal the scanner has says "this is not a
     * document": type is 'other' or null, no name was read, no ID number,
     * no issue or expiry date. Any one of those being present means the
     * scanner found *something* on the page, and the honest answer is to
     * let HR decide — the same rule the other blocks follow.
     *
     * The escape hatch is the same as everywhere else: upload as a PDF,
     * which the scanner does not read. That covers the edge case where a
     * legitimate document happens to look like nothing the scanner knows.
     */
    const blockedByNotDocument = Boolean(
        scan &&
        !scanning &&
        (scan.type === 'other' || scan.type === null) &&
        !scan.name_on_document &&
        !scan.document_number &&
        !scan.issued_at &&
        !scan.expires_at,
    );

    /*
     * The name verdict has a case the other two do not: a name that does not
     * match while the ID number *does*. That is not a failure — it is the
     * married-name and truncated-card reading the number already settled — so
     * it is reported as a note rather than in the colour that means "refused".
     */
    const nameCheckState =
        scan?.name_matches === true
            ? 'ok'
            : scan?.name_matches === false
              ? // Not a failure where a different name is the norm, and not a
                // failure where the ID number already settled it.
                numberMatch === true || scan?.name_may_differ
                  ? 'note'
                  : 'bad'
              : 'unknown';

    /*
     * An expiry date already in the past.
     *
     * Read off the **form field**, not off the scan, and that is the whole
     * design of it. The scanner misreads this date — a real upload came back
     * with "Jul 25, 2024" taken from a line that was actually a date of birth
     * — so blocking on what the scanner said would refuse a current document
     * over a bad reading, with nothing the user could do about it. Blocking on
     * the field means correcting the date lifts the block, which is exactly
     * the recovery the misreading case needs.
     *
     * It also covers a date nobody scanned at all: typed by hand, in the past,
     * still refused.
     *
     * The escape hatch for a document that genuinely must be filed expired —
     * a lapsed licence kept for the record while the renewal is in progress —
     * is the one the name check already uses: upload it as a PDF, which the
     * scanner does not read and this does not reach. That is deliberate
     * rather than an oversight, and it is the reason this can be absolute.
     */
    const expiryOnForm = upload.data.expires_at
        ? new Date(`${upload.data.expires_at}T00:00:00`)
        : null;

    const blockedByExpiry = Boolean(
        expiryOnForm &&
        !Number.isNaN(expiryOnForm.getTime()) &&
        expiryOnForm < new Date(new Date().toDateString()),
    );

    /*
     * A name that does not match is only a refusal where a match was expected.
     *
     * A PSA birth certificate filed in a 201 file is usually the employee's
     * child's — kept for BIR and PhilHealth dependant claims — and it names
     * the child. Refusing that is refusing the document for being what it is.
     * The server decides which types are like this; the check still runs and
     * the panel still says whose name is on the paper.
     */
    const nameMustMatch = scan?.name_may_differ !== true;

    const blockedByMismatch =
        blockedByNotDocument ||
        blockedByType ||
        blockedByExpiry ||
        numberMatch === false ||
        (nameMustMatch && numberMatch !== true && scan?.name_matches === false);

    /*
     * Whether the Expiry Date field is on screen.
     *
     * Two things can put it there: the chosen type is one that normally lapses
     * (`expiringTypes`, from config/credentials.php), or HR asked for it on a
     * type that usually does not. The second is remembered per open form, so
     * revealing it and then correcting the type does not snatch the field back
     * with a date already in it.
     */
    const [expiryShown, setExpiryShown] = useState(false);

    const showExpiry =
        expiryShown ||
        expiringTypes.includes(upload.data.type) ||
        // A date already read off the document — by the scanner or typed
        // before the type was changed — has to stay visible, or it would be
        // saved from a field nobody can see.
        Boolean(upload.data.expires_at);

    /**
     * Reads the picked file and fills the form. Nothing is saved here; the
     * Upload button below still does that, so every field stays editable.
     * A failed scan is silent by design — the form simply stays empty and
     * HR types it, exactly as before this existed.
     */
    const scanDocument = async (file) => {
        if (!can.scanDocuments || !file?.type?.startsWith('image/')) return;

        setScanning(true);
        setScan(null);

        try {
            const body = new FormData();
            body.append('file', file);

            const { data } = await axios.post(
                `/hr/employees/${record.id}/documents/scan`,
                body,
            );

            if (!data.scanned) return;

            setScan(data.fields);
            upload.setData('scan_id', data.scan_id ?? null);

            // Only fill what came back. A null from the scanner means "not
            // legible" — overwriting a field with it would erase a correction
            // HR had already typed.
            const filled = {};
            if (data.fields.type) filled.type = data.fields.type;
            if (data.fields.title) filled.title = data.fields.title;
            if (data.fields.issued_at) filled.issued_at = data.fields.issued_at;
            if (data.fields.expires_at) filled.expires_at = data.fields.expires_at;

            upload.setData((current) => ({ ...current, ...filled }));
        } catch {
            // Network or server trouble: leave the form alone.
        } finally {
            setScanning(false);
        }
    };

    // The scan panel describes one file, so it has to go whenever the form
    // does — otherwise reopening shows the previous document's reading.
    const closeUpload = () => {
        setUploadOpen(false);
        setScan(null);
        setExpiryShown(false);
    };

    const submitDocument = (event) => {
        event.preventDefault();

        upload.post(`/hr/employees/${record.id}/documents`, {
            forceFormData: true,
            onSuccess: () => {
                upload.reset();
                closeUpload();
            },
        });
    };

    const confirmDeleteEmployee = () => {
        router.delete(`/hr/employees/${record.id}`, { onFinish: () => setDeleteOpen(false) });
    };

    const deleteDocument = () => {
        router.delete(`/hr/employees/${record.id}/documents/${pendingDocument.id}`, {
            preserveScroll: true,
            onFinish: () => setPendingDocument(null),
        });
    };

    const documents = record.documents ?? [];

    return (
        <AppLayout
            title={isSelf ? 'My Profile' : record.full_name}
            breadcrumbs={
                isSelf
                    ? [
                          { label: 'Human Resource' },
                          { label: 'Employee Information', href: '/hr/employees' },
                          { label: 'My Profile' },
                      ]
                    : [
                          { label: 'Human Resource' },
                          { label: 'Employee Information', href: '/hr/employees' },
                          { label: record.full_name },
                      ]
            }
            actions={
                <div className="flex items-center gap-1.5">
                    {can.update && (
                        <Button
                            href={`/hr/employees/${record.id}/edit`}
                            size="sm"
                            variant="outline"
                        >
                            <Pencil className="h-4 w-4" />
                            <span className="hidden sm:inline">Edit</span>
                        </Button>
                    )}
                    {can.delete && (
                        <Button
                            size="sm"
                            variant="destructive"
                            onClick={() => setDeleteOpen(true)}
                        >
                            <Trash2 className="h-4 w-4" />
                            <span className="hidden sm:inline">Archive</span>
                        </Button>
                    )}
                </div>
            }
        >
            <div className="mb-4 flex items-center justify-between">
                <Button
                    href={isSelf ? '/dashboard' : '/hr/employees'}
                    variant="ghost"
                    size="sm"
                >
                    <ArrowLeft className="h-4 w-4" />
                    {isSelf ? 'Back to Dashboard' : 'Back to directory'}
                </Button>
                {isSelf && (
                    <span className="text-xs font-medium text-muted-foreground">
                        Personal 201 File & Records Hub
                    </span>
                )}
            </div>

            {/* Profile header */}
            <Card className="mb-5">
                <CardBody className="flex flex-col gap-5 sm:flex-row sm:items-center">
                    {record.photo_url ? (
                        <img
                            src={record.photo_url}
                            alt=""
                            className="h-20 w-20 shrink-0 rounded-full object-cover ring-2 ring-border"
                        />
                    ) : (
                        <span className="grid h-20 w-20 shrink-0 place-items-center rounded-full bg-primary/10 text-xl font-semibold text-primary">
                            {initials(record.full_name)}
                        </span>
                    )}

                    <div className="min-w-0 flex-1">
                        <h2 className="truncate text-lg font-semibold text-foreground">
                            {record.full_name}
                        </h2>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {record.position?.title ?? 'No position assigned'}
                            {record.department?.name ? ` · ${record.department.name}` : ''}
                        </p>

                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            {isSelf && <Badge variant="primary">My Profile</Badge>}
                            <Badge variant="outline">{record.employee_number}</Badge>
                            <Badge status={record.employment_status} />
                            <Badge status={record.status} />
                            {record.has_account && <Badge variant="muted">Has login</Badge>}
                        </div>
                    </div>

                    <dl className="grid shrink-0 grid-cols-2 gap-4 sm:grid-cols-1 sm:text-right">
                        <DetailRow label="Date Hired" value={formatDate(record.date_hired)} />
                        <DetailRow label="Supervisor" value={record.supervisor?.full_name} />
                    </dl>
                </CardBody>
            </Card>

            {/* Jump links. Every section is already on the page; these only
                scroll to one, so nothing is hidden behind a click. */}
            <nav
                aria-label="Sections"
                className="scrollbar-thin mb-5 flex gap-1.5 overflow-x-auto border-b border-border pb-3"
            >
                {SECTIONS.map((item) => {
                    const label =
                        item.id === 'records' && !isSelf ? 'Work Records' : item.label;

                    return (
                        <a
                            key={item.id}
                            href={`#${item.id}`}
                            className="whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                        >
                            {label}
                            {item.id === 'documents' && documents.length > 0 && (
                                <span className="ml-1.5 rounded-full bg-secondary px-1.5 py-0.5 text-[10px] text-muted-foreground">
                                    {documents.length}
                                </span>
                            )}
                        </a>
                    );
                })}
            </nav>

            {/* Quick Records & Self-Service Section */}
            <section id="records" className="mb-8 scroll-mt-6">
                <SectionHeading
                    id="records-heading"
                    title={
                        isSelf
                            ? 'My Work Records & Activities'
                            : 'Employee Records & Activities'
                    }
                />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Link
                        href={`/hr/timekeeping/employee/${record.id}`}
                        className="group flex flex-col justify-between rounded-xl border border-border bg-card p-4 transition-all hover:border-primary/50 hover:shadow-sm"
                    >
                        <div className="flex items-start justify-between">
                            <span className="grid h-10 w-10 place-items-center rounded-lg bg-primary/10 text-primary transition-colors group-hover:bg-primary group-hover:text-primary-foreground">
                                <Clock className="h-5 w-5" />
                            </span>
                            <ChevronRight className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-foreground" />
                        </div>
                        <div className="mt-3">
                            <h3 className="text-sm font-semibold text-foreground group-hover:text-primary">
                                {isSelf ? 'My Attendance & DTR' : 'Attendance & DTR'}
                            </h3>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Daily attendance logs, punch-ins & cutoffs.
                            </p>
                        </div>
                    </Link>

                    <Link
                        href="/hr/leave"
                        className="group flex flex-col justify-between rounded-xl border border-border bg-card p-4 transition-all hover:border-info/50 hover:shadow-sm"
                    >
                        <div className="flex items-start justify-between">
                            <span className="group-hover:text-info-foreground grid h-10 w-10 place-items-center rounded-lg bg-info/10 text-info transition-colors group-hover:bg-info">
                                <CalendarDays className="h-5 w-5" />
                            </span>
                            <ChevronRight className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-foreground" />
                        </div>
                        <div className="mt-3">
                            <h3 className="text-sm font-semibold text-foreground group-hover:text-info">
                                {isSelf ? 'My Leave & Absence' : 'Leave Requests'}
                            </h3>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Filed leave history, balances & approvals.
                            </p>
                        </div>
                    </Link>

                    <Link
                        href="/hr/payroll/payslips"
                        className="group flex flex-col justify-between rounded-xl border border-border bg-card p-4 transition-all hover:border-success/50 hover:shadow-sm"
                    >
                        <div className="flex items-start justify-between">
                            <span className="group-hover:text-success-foreground grid h-10 w-10 place-items-center rounded-lg bg-success/10 text-success transition-colors group-hover:bg-success">
                                <Wallet className="h-5 w-5" />
                            </span>
                            <ChevronRight className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-foreground" />
                        </div>
                        <div className="mt-3">
                            <h3 className="text-sm font-semibold text-foreground group-hover:text-success">
                                {isSelf ? 'My Payslips' : 'Issued Payslips'}
                            </h3>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Net pay, allowances & statutory deductions.
                            </p>
                        </div>
                    </Link>

                    <Link
                        href={`/hr/performance/employees/${record.id}/history`}
                        className="group flex flex-col justify-between rounded-xl border border-border bg-card p-4 transition-all hover:border-warning/50 hover:shadow-sm"
                    >
                        <div className="flex items-start justify-between">
                            <span className="grid h-10 w-10 place-items-center rounded-lg bg-warning/10 text-warning transition-colors group-hover:bg-warning group-hover:text-warning-foreground">
                                <History className="h-5 w-5" />
                            </span>
                            <ChevronRight className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-foreground" />
                        </div>
                        <div className="mt-3">
                            <h3 className="text-sm font-semibold text-foreground group-hover:text-warning">
                                {isSelf ? 'My Performance' : 'Performance History'}
                            </h3>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Past evaluation cycles, scores & ratings.
                            </p>
                        </div>
                    </Link>
                </div>
            </section>

            <section className="mb-8">
                <SectionHeading id="overview" title="Overview" />
                <div className="grid gap-5 lg:grid-cols-2">
                    <Card>
                        <CardHeader title="Personal Information" />
                        <CardBody>
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <DetailRow
                                    label="Date of Birth"
                                    value={formatDate(record.birth_date)}
                                />
                                <DetailRow label="Place of Birth" value={record.birth_place} />
                                <DetailRow label="Gender" value={titleCase(record.gender)} />
                                <DetailRow
                                    label="Civil Status"
                                    value={titleCase(record.civil_status)}
                                />
                                <DetailRow label="Nationality" value={record.nationality} />
                                <DetailRow label="Religion" value={record.religion} />
                                <DetailRow label="Blood Type" value={record.blood_type} />
                            </dl>
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Contact Information" />
                        <CardBody>
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <DetailRow label="Email" value={record.email} />
                                <DetailRow label="Mobile" value={record.mobile_number} />
                                <DetailRow label="Phone" value={record.phone_number} />
                                <div className="hidden sm:block" />
                                <DetailRow
                                    label="Present Address"
                                    value={record.present_address}
                                    className="col-span-2"
                                />
                                <DetailRow
                                    label="Permanent Address"
                                    value={record.permanent_address}
                                    className="col-span-2"
                                />
                            </dl>
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Emergency Contact" />
                        <CardBody>
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <DetailRow label="Name" value={record.emergency_contact_name} />
                                <DetailRow
                                    label="Relationship"
                                    value={record.emergency_contact_relationship}
                                />
                                <DetailRow
                                    label="Contact Number"
                                    value={record.emergency_contact_number}
                                />
                            </dl>
                        </CardBody>
                    </Card>

                    {can.viewSensitive && (
                        <Card>
                            <CardHeader
                                title="Government IDs"
                                description="Visible to HR and the employee only."
                            />
                            <CardBody>
                                <dl className="grid gap-4 sm:grid-cols-2">
                                    <DetailRow label="SSS" value={record.sss_number} />
                                    <DetailRow
                                        label="PhilHealth"
                                        value={record.philhealth_number}
                                    />
                                    <DetailRow label="Pag-IBIG" value={record.pagibig_number} />
                                    <DetailRow label="TIN" value={record.tin} />
                                </dl>
                            </CardBody>
                        </Card>
                    )}
                </div>
            </section>

            <section className="mb-8">
                <SectionHeading id="employment" title="Employment" />
                <div className="grid gap-5 lg:grid-cols-2">
                    <Card>
                        <CardHeader title="Employment Details" />
                        <CardBody>
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <DetailRow label="Department" value={record.department?.name} />
                                <DetailRow label="Position" value={record.position?.title} />
                                <DetailRow
                                    label="Supervisor"
                                    value={record.supervisor?.full_name}
                                />
                                <DetailRow
                                    label="Employment Status"
                                    value={titleCase(record.employment_status)}
                                />
                                <DetailRow
                                    label="Employment Type"
                                    value={titleCase(record.employment_type)}
                                />
                                <DetailRow
                                    label="Date Hired"
                                    value={formatDate(record.date_hired)}
                                />
                                <DetailRow
                                    label="Date Regularized"
                                    value={formatDate(record.date_regularized)}
                                />
                                <DetailRow
                                    label="Date Separated"
                                    value={formatDate(record.date_separated)}
                                />
                                {record.separation_reason && (
                                    <DetailRow
                                        label="Separation Reason"
                                        value={record.separation_reason}
                                        className="col-span-2"
                                    />
                                )}
                            </dl>
                        </CardBody>
                    </Card>

                    {can.viewSensitive && (
                        <Card>
                            <CardHeader title="Compensation & Banking" />
                            <CardBody>
                                <dl className="grid gap-4 sm:grid-cols-2">
                                    <DetailRow
                                        label="Basic Salary"
                                        value={formatCurrency(record.basic_salary)}
                                    />
                                    <DetailRow
                                        label="Pay Frequency"
                                        value={titleCase(record.pay_frequency)}
                                    />
                                    <DetailRow label="Bank" value={record.bank_name} />
                                    <DetailRow
                                        label="Account Number"
                                        value={record.bank_account_number}
                                    />
                                </dl>
                            </CardBody>
                        </Card>
                    )}

                    <Card>
                        <CardHeader
                            title="Driver's License"
                            description="Structure is checked here; authenticity is checked on LTMS."
                        />
                        <CardBody className="space-y-4">
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <DetailRow
                                    label="License Number"
                                    value={record.drivers_license_number}
                                />
                                <DetailRow
                                    label="Expiry"
                                    value={formatDate(record.license_expiry)}
                                />
                            </dl>

                            {/* The two panels the card itself prints, spelled
                                out rather than shown as bare codes: "C" means
                                nothing to whoever is deciding a dispatch, and
                                being able to act on them is the whole reason
                                they are held. */}
                            {licence.dl_codes.length > 0 && (
                                <div>
                                    <p className="text-xs text-muted-foreground">DL Codes</p>
                                    <ul className="mt-1 space-y-0.5">
                                        {licence.dl_codes.map((code) => (
                                            <li
                                                key={code.code}
                                                className="text-sm text-foreground"
                                            >
                                                <span className="font-semibold">
                                                    {code.code}
                                                </span>
                                                {code.label && (
                                                    <span className="text-muted-foreground">
                                                        {' — '}
                                                        {code.label}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            <div>
                                <p className="text-xs text-muted-foreground">Conditions</p>
                                {licence.conditions.length === 0 ? (
                                    <p className="mt-0.5 text-sm text-foreground">NONE</p>
                                ) : (
                                    <ul className="mt-1 space-y-0.5">
                                        {licence.conditions.map((item) => (
                                            <li
                                                key={item.code}
                                                className="text-sm text-foreground"
                                            >
                                                <span className="font-semibold">
                                                    {item.code}
                                                </span>
                                                {item.label && (
                                                    <span className="text-muted-foreground">
                                                        {' — '}
                                                        {item.label}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>

                            {/* What this system checked by itself. Never
                                called "valid": passing means the card is
                                internally consistent, which is a much smaller
                                claim than being genuine. */}
                            {licence.checks.length > 0 && (
                                <ul className="space-y-1.5 border-t border-border pt-3">
                                    {licence.checks.map((finding, index) => (
                                        <li
                                            key={`${finding.field}-${index}`}
                                            className="flex items-start gap-2 text-xs"
                                        >
                                            <TriangleAlert
                                                className={cn(
                                                    'mt-0.5 h-3.5 w-3.5 shrink-0',
                                                    finding.severity === 'error'
                                                        ? 'text-destructive'
                                                        : 'text-warning',
                                                )}
                                                aria-hidden="true"
                                            />
                                            <span>
                                                <span className="font-medium text-foreground">
                                                    {finding.summary}
                                                </span>{' '}
                                                <span className="text-muted-foreground">
                                                    {finding.detail}
                                                </span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader
                            title="Direct Reports"
                            description={`${subordinates.length} employee(s) reporting to this person.`}
                        />
                        <CardBody>
                            {subordinates.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No direct reports.
                                </p>
                            ) : (
                                <ul className="space-y-2">
                                    {subordinates.map((subordinate) => (
                                        <li key={subordinate.id}>
                                            <Link
                                                href={`/hr/employees/${subordinate.id}`}
                                                className="flex items-center gap-2.5 rounded-md p-2 transition-colors hover:bg-secondary/60"
                                            >
                                                <span className="grid h-7 w-7 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                    {initials(subordinate.full_name)}
                                                </span>
                                                <span className="text-sm text-foreground">
                                                    {subordinate.full_name}
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardBody>
                    </Card>
                </div>
            </section>

            {/* Between Employment and Documents on purpose: what somebody is
                qualified for is read with what they are employed as, and the
                diplomas and certificates that back it up sit directly below. */}
            <section id="qualifications" className="mb-8 scroll-mt-6">
                <SectionHeading id="qualifications-heading" title="Qualifications" />

                <Qualifications
                    employee={record}
                    educationLevels={educationLevels}
                    levelsWithCourse={levelsWithCourse}
                    proficiencyLevels={proficiencyLevels}
                    canManage={can.update}
                />
            </section>

            {/* These two carry their own CardHeader, so the card title is the
                heading and the anchor sits on the section itself. */}
            <section id="documents" className="mb-8 scroll-mt-6">
                <Card>
                    <CardHeader
                        title="Documents"
                        description="Contracts, IDs, clearances, and certificates."
                        action={
                            can.manageDocuments && (
                                <Button size="sm" onClick={() => setUploadOpen(true)}>
                                    <Plus className="h-4 w-4" />
                                    Upload
                                </Button>
                            )
                        }
                    />

                    <Table>
                        <THead>
                            <TR>
                                <TH>Document</TH>
                                <TH>Type</TH>
                                <TH>Issued</TH>
                                <TH>Expires</TH>
                                <TH>Size</TH>
                                <TH className="text-right">Actions</TH>
                            </TR>
                        </THead>

                        <TBody>
                            {documents.length === 0 ? (
                                <TableEmpty
                                    colSpan={6}
                                    icon={FileText}
                                    title="No documents uploaded"
                                    description="Upload the employment contract, government IDs, and clearances here."
                                />
                            ) : (
                                documents.map((document) => (
                                    <TR key={document.id}>
                                        <TD>
                                            <div className="flex items-center gap-2.5">
                                                <FileText
                                                    className="h-4 w-4 shrink-0 text-muted-foreground"
                                                    aria-hidden="true"
                                                />
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium text-foreground">
                                                        {document.title}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {document.file_name}
                                                    </p>
                                                </div>
                                            </div>
                                        </TD>

                                        <TD>
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                <Badge variant="muted">
                                                    {titleCase(document.type)}
                                                </Badge>

                                                {/* Said on the row, not only
                                                    in the table: filing
                                                    without a person is only
                                                    defensible if "the system
                                                    decided this" can be told
                                                    from "somebody typed this"
                                                    by whoever is reading the
                                                    file, not by whoever can
                                                    write a query. */}
                                                {document.filed_automatically && (
                                                    <Badge
                                                        variant="info"
                                                        title="Filed by the document scanner — every check agreed"
                                                    >
                                                        <ScanLine
                                                            className="h-3 w-3"
                                                            aria-hidden="true"
                                                        />
                                                        Auto
                                                    </Badge>
                                                )}
                                            </div>
                                        </TD>

                                        <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                            {formatDate(document.issued_at)}
                                        </TD>

                                        <TD className="whitespace-nowrap text-sm">
                                            {document.expires_at ? (
                                                <span
                                                    className={cn(
                                                        document.is_expired
                                                            ? 'font-medium text-destructive'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {formatDate(document.expires_at)}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">—</span>
                                            )}
                                        </TD>

                                        <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                            {formatBytes(document.file_size)}
                                        </TD>

                                        <TD>
                                            <div className="flex items-center justify-end gap-1">
                                                {/* Only offered when the browser
                                                    can actually render it — a
                                                    .docx has no viewer. */}
                                                {document.preview_as && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setPreview(document)}
                                                        aria-label={`View ${document.title}`}
                                                        className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                                                    >
                                                        <Eye
                                                            className="h-4 w-4"
                                                            aria-hidden="true"
                                                        />
                                                    </button>
                                                )}

                                                <a
                                                    href={document.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    aria-label={`Download ${document.title}`}
                                                    className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                                                >
                                                    <Download
                                                        className="h-4 w-4"
                                                        aria-hidden="true"
                                                    />
                                                </a>

                                                {can.manageDocuments && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setPendingDocument(document)
                                                        }
                                                        aria-label={`Delete ${document.title}`}
                                                        className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                                    >
                                                        <Trash2
                                                            className="h-4 w-4"
                                                            aria-hidden="true"
                                                        />
                                                    </button>
                                                )}
                                            </div>
                                        </TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </Card>
            </section>

            {/* Upload document */}
            <Modal
                show={uploadOpen}
                onClose={closeUpload}
                title="Upload Document"
                description={`Attach a file to ${record.full_name}'s 201 file.`}
                maxWidth="lg"
            >
                <form onSubmit={submitDocument} className="space-y-4">
                    <Field label="Document Type" required error={upload.errors.type}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={upload.data.type}
                                onChange={(event) => upload.setData('type', event.target.value)}
                                options={documentTypes.map((type) => ({
                                    value: type,
                                    label: titleCase(type),
                                }))}
                            />
                        )}
                    </Field>

                    <Field label="Title" required error={upload.errors.title}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={upload.data.title}
                                onChange={(event) =>
                                    upload.setData('title', event.target.value)
                                }
                                error={upload.errors.title}
                                placeholder="e.g. Employment Contract 2026"
                            />
                        )}
                    </Field>

                    <Field label="Description" error={upload.errors.description}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={2}
                                value={upload.data.description}
                                onChange={(event) =>
                                    upload.setData('description', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Issued Date" error={upload.errors.issued_at}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={upload.data.issued_at}
                                    onChange={(event) =>
                                        upload.setData('issued_at', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        {/* Asked only for the types that normally lapse. A
                            résumé has no expiry, and a field that is always
                            there invites a date that means nothing —
                            CredentialExpiryScanner would then warn about a CV
                            going out of date. */}
                        {/* The refusal is stated on the field itself, not
                            only in the scan panel — the date can be typed by
                            hand with no scan at all, and a disabled button
                            with no reason beside it is where a user stops
                            trusting the screen. */}
                        {showExpiry ? (
                            <Field
                                label="Expiry Date"
                                error={
                                    upload.errors.expires_at ??
                                    (blockedByExpiry
                                        ? 'This date has already passed, so the document cannot be filed. Correct it if it was read wrongly.'
                                        : undefined)
                                }
                            >
                                {({ id }) => (
                                    <DateInput
                                        id={id}
                                        value={upload.data.expires_at}
                                        onChange={(event) =>
                                            upload.setData('expires_at', event.target.value)
                                        }
                                        error={upload.errors.expires_at || blockedByExpiry}
                                    />
                                )}
                            </Field>
                        ) : (
                            /* Still reachable, because "normally" is not
                               "always": a passport is filed as a government ID
                               and does expire, and a project-based contract has
                               an end date. Hiding it outright would leave HR
                               unable to record a real one. */
                            <div className="flex items-end">
                                <button
                                    type="button"
                                    onClick={() => setExpiryShown(true)}
                                    className="pb-2 text-xs font-medium text-primary hover:underline"
                                >
                                    + Add an expiry date
                                </button>
                            </div>
                        )}
                    </div>

                    <Field
                        label="File"
                        required
                        hint="PDF, JPG, PNG, DOC, or DOCX — max 10 MB"
                        error={upload.errors.file}
                    >
                        {({ id }) => (
                            <input
                                id={id}
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                onChange={(event) => {
                                    const file = event.target.files[0] ?? null;
                                    upload.setData('file', file);
                                    scanDocument(file);
                                }}
                                className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-xs file:font-medium file:text-secondary-foreground hover:file:bg-secondary/70"
                            />
                        )}
                    </Field>

                    {scanning && (
                        <p className="flex items-center gap-2 text-xs text-muted-foreground">
                            <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                            Reading the document…
                        </p>
                    )}

                    {/* What was read, shown separately from the fields it
                        filled — HR has to be able to tell a scanned value from
                        a typed one before saving.

                        Two sections, deliberately: what the document *says*,
                        then what was *checked*. These used to be one pile of
                        sentences at four severities, and the one line that
                        refuses the upload has to stand apart from the six
                        that do not. */}
                    {scan && !scanning && (
                        <div className="overflow-hidden rounded-lg border border-border bg-secondary/40">
                            <p className="flex items-center gap-1.5 border-b border-border/60 px-3 py-2 text-xs font-medium text-foreground">
                                <ScanLine className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                Read from the document
                                <span className="ml-auto font-normal text-muted-foreground">
                                    Check before saving
                                </span>
                            </p>

                            <dl className="divide-y divide-border/40">
                                <ScanField
                                    label="Type"
                                    value={scan.type ? titleCase(scan.type) : null}
                                    filled={Boolean(scan.type)}
                                />
                                {/* What the keyword rules actually read. On
                                    screen because it is the only thing that
                                    explains a wrong type, and without it a
                                    misread is a mystery to everybody. */}
                                <ScanField label="Heading" value={scan.heading} />
                                <ScanField label="Name" value={scan.name_on_document} />
                                <ScanField label="Number" value={scan.document_number} mono />
                                <ScanField
                                    label="Issued"
                                    value={scan.issued_at ? formatDate(scan.issued_at) : null}
                                    filled={Boolean(scan.issued_at)}
                                />
                                {/* A document that cannot expire says so,
                                    rather than showing "Not found" — which
                                    would read as the scanner having looked and
                                    missed, when in fact there is nothing to
                                    look for, and invites somebody to type a
                                    date that does not exist.

                                    Read off the scan rather than matched
                                    against a list of types here: a TIN ID
                                    carries no expiry and a passport does, and
                                    both are `government_id`, so the type alone
                                    cannot answer it. The scanner decides from
                                    the heading printed on the card. */}
                                {scan.never_expires ? (
                                    <ScanField label="Expires" value="Does not expire" />
                                ) : (
                                    <ScanField
                                        label="Expires"
                                        value={
                                            scan.expires_at ? formatDate(scan.expires_at) : null
                                        }
                                        filled={Boolean(scan.expires_at)}
                                    />
                                )}
                            </dl>

                            {/* What a civil registry document says, read in
                                its own terms by a second pass. The general
                                prompt asks for an ID card's fields and a PSA
                                has none of them, so it used to answer with
                                whatever sat nearby. */}
                            {scan.registry && (
                                <dl className="divide-y divide-border/40 border-t border-border/60">
                                    <ScanField
                                        label="Registry"
                                        value={scan.registry.registry_no}
                                        mono
                                    />
                                    <ScanField label="Child" value={scan.registry.child} />
                                    <ScanField
                                        label="Born"
                                        value={
                                            scan.registry.birth_date
                                                ? formatDate(scan.registry.birth_date)
                                                : null
                                        }
                                    />
                                    <ScanField label="Mother" value={scan.registry.mother} />
                                    <ScanField label="Father" value={scan.registry.father} />
                                </dl>
                            )}
                            {/* The verdicts, gathered in one place and at one
                                size. Each says what was compared rather than
                                only whether it passed — a bare green tick is
                                not something HR can act on. */}
                            <div className="space-y-1.5 border-t border-border/60 px-3 py-2.5">
                                <ScanCheck state={nameCheckState}>
                                    {scan.name_matches === true &&
                                        `Named as ${record.full_name}.`}
                                    {scan.name_matches === false &&
                                        (scan.name_may_differ
                                            ? `Names ${scan.name_on_document}. A certificate filed here usually names a dependant, so this is not treated as a mismatch — check it is the right one.`
                                            : `Names ${scan.name_on_document}, not ${record.full_name}.`)}
                                    {scan.name_matches === null && 'No name could be read.'}
                                </ScanCheck>

                                {/* Said at the moment of filing rather than
                                    left to the Credentials screen to find
                                    later. An expired document is still filed —
                                    it is often filed deliberately, for the
                                    record or while a renewal is in progress —
                                    but nobody should be able to file one
                                    without being told. */}
                                {scan.expiry && (
                                    <ScanCheck
                                        state={
                                            scan.expiry.state === 'expired'
                                                ? scan.expiry.blocking
                                                    ? 'bad'
                                                    : 'note'
                                                : scan.expiry.state === 'expiring'
                                                  ? 'note'
                                                  : 'ok'
                                        }
                                    >
                                        {scan.expiry.state === 'expired' &&
                                            `Already expired — ${Math.abs(scan.expiry.days)} day${
                                                Math.abs(scan.expiry.days) === 1 ? '' : 's'
                                            } ago.${
                                                scan.expiry.blocking
                                                    ? ' This one is required to work, so it will show as blocked until it is renewed.'
                                                    : ''
                                            }`}
                                        {scan.expiry.state === 'expiring' &&
                                            `Expires in ${scan.expiry.days} day${
                                                scan.expiry.days === 1 ? '' : 's'
                                            } — inside the renewal window for this type.`}
                                        {/* Months once it's this far out — a
                                            licence reads as "valid for another
                                            27 months," not "816 days," and the
                                            renewal window above still counts
                                            in days because that one is a
                                            precise countdown, not a rough
                                            distance. */}
                                        {scan.expiry.state === 'valid' &&
                                            (() => {
                                                const months = Math.round(
                                                    scan.expiry.days / 30.44,
                                                );

                                                return months >= 1
                                                    ? `Valid for another ${months} month${months === 1 ? '' : 's'}.`
                                                    : `Valid for another ${scan.expiry.days} day${scan.expiry.days === 1 ? '' : 's'}.`;
                                            })()}
                                    </ScanCheck>
                                )}

                                {/* The claim a person actually has to a birth
                                    certificate is being a parent on it — the
                                    name on the page is their child's and can
                                    never match. */}
                                {scan.registry && (
                                    <ScanCheck
                                        state={
                                            scan.registry.claimed_by_employee === true
                                                ? 'ok'
                                                : scan.registry.claimed_by_employee === false
                                                  ? 'note'
                                                  : 'unknown'
                                        }
                                    >
                                        {scan.registry.claimed_by_employee === true &&
                                            `${record.full_name} is named as a parent on this certificate.`}
                                        {scan.registry.claimed_by_employee === false &&
                                            `${record.full_name} is not named as a parent here. Check this is the right certificate.`}
                                        {scan.registry.claimed_by_employee === null &&
                                            (scan.registry.parents_uncertain
                                                ? 'Both parents were read with the same given names, so one box was misread — the parents cannot be checked. Read them off the certificate yourself.'
                                                : 'No parent could be read, so there is nothing to check against.')}
                                    </ScanCheck>
                                )}
                                <ScanCheck
                                    state={
                                        numberMatch === true
                                            ? 'ok'
                                            : numberMatch === false
                                              ? 'bad'
                                              : 'unknown'
                                    }
                                >
                                    {numberMatch === true &&
                                        'ID number matches the one on file.'}
                                    {numberMatch === false &&
                                        'ID number contradicts the one on file.'}
                                    {numberMatch === null &&
                                        'No ID number to compare against the file.'}
                                </ScanCheck>

                                <ScanCheck
                                    state={
                                        !scan.type
                                            ? 'unknown'
                                            : !typeConflict
                                              ? 'ok'
                                              : blockedByType
                                                ? 'bad'
                                                : 'note'
                                    }
                                >
                                    {!scan.type &&
                                        'Could not tell what kind of document this is — set the type yourself.'}
                                    {!typeConflict &&
                                        `Matches the Document Type chosen${
                                            TYPE_SOURCES[scan.type_source]
                                                ? ` — read from ${TYPE_SOURCES[scan.type_source]}`
                                                : ''
                                        }.`}
                                    {typeConflict &&
                                        `Looks like a ${titleCase(scan.type)}, not a ${titleCase(
                                            upload.data.type,
                                        )} — read from ${
                                            TYPE_SOURCES[scan.type_source] ?? 'the scan'
                                        }.`}
                                </ScanCheck>

                                {/* The model's own note. Its `confidence` field
                                    is not reported: measured across six
                                    documents it answered "high" every time,
                                    including on the answers that were wrong. */}
                                {/* The model's own remark, shown as a remark.
                                    It was styled as a warning, and the model
                                    fills it with text copied off the document
                                    — a real scan put "NO RECORD ON FILE", the
                                    words printed on an NBI clearance, in
                                    warning orange where it read as a problem
                                    with the employee. It is the scanner
                                    talking, not a finding. */}
                                {scan.note && (
                                    <ScanCheck state="unknown">
                                        Scanner note: {scan.note}
                                    </ScanCheck>
                                )}

                                {/* Advisory, never blocking — a card issued
                                    under an older format is still that
                                    person's card. */}
                                {scan.number_format_ok === false && (
                                    <ScanCheck state="note">
                                        {scan.document_number} is not shaped like a{' '}
                                        {titleCase(scan.type ?? 'document')} number.
                                    </ScanCheck>
                                )}
                            </div>

                            {/* The refusal: one box, at the bottom, in the one
                                colour nothing else in this panel uses. It says
                                what to do, because a refusal with no way
                                forward is where somebody works around the
                                system instead of with it. */}
                            {blockedByMismatch && (
                                <div className="border-t border-destructive/30 bg-destructive/10 px-3 py-2.5">
                                    <p className="flex items-start gap-1.5 text-xs font-medium text-destructive">
                                        <TriangleAlert
                                            className="mt-0.5 h-3.5 w-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                        {blockedByNotDocument
                                            ? 'This file is not a valid document.'
                                            : 'This document cannot be filed here.'}
                                    </p>
                                    <p className="mt-1 pl-5 text-xs text-muted-foreground">
                                        {blockedByNotDocument
                                            ? 'The scanner could not find any document information — no name, ID number, or date. Pick a file that contains an actual HR document (ID, clearance, certificate, etc.).'
                                            : blockedByExpiry
                                              ? 'It expired on the date below. Correct the Expiry Date if it was read wrongly — a date of birth is often picked up by mistake — or file the renewed copy instead.'
                                              : blockedByType
                                                ? `Set Document Type to ${titleCase(scan.type)}, or pick a different file.`
                                                : `Open ${scan.name_on_document ?? 'the right employee'}\u2019s record and upload it there, or pick a different file.`}
                                    </p>
                                </div>
                            )}
                        </div>
                    )}

                    <div className="flex items-center justify-end gap-2 pt-2">
                        {/* Two different refusals, so two different sentences.
                            A reader who is told "names someone else" about a
                            document that plainly names them stops believing
                            the message rather than fixing the type. */}
                        {blockedByMismatch && (
                            <p className="mr-auto text-xs text-destructive">
                                {blockedByNotDocument
                                    ? 'Not a valid document.'
                                    : blockedByExpiry
                                      ? 'This document has expired.'
                                      : blockedByType
                                        ? `This is a ${titleCase(scan?.type)}.`
                                        : 'This file names someone else.'}
                            </p>
                        )}

                        <Button variant="outline" onClick={closeUpload}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            loading={upload.processing}
                            disabled={blockedByMismatch}
                        >
                            Upload
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Archive employee */}
            <Modal
                show={deleteOpen}
                onClose={() => setDeleteOpen(false)}
                title="Archive this employee?"
                maxWidth="md"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setDeleteOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={confirmDeleteEmployee}>
                            Archive Employee
                        </Button>
                    </>
                }
            >
                <div className="flex gap-3">
                    <TriangleAlert
                        className="mt-0.5 h-5 w-5 shrink-0 text-destructive"
                        aria-hidden="true"
                    />
                    <p className="text-sm text-muted-foreground">
                        <span className="font-medium text-foreground">{record.full_name}</span>{' '}
                        will be archived and their login deactivated. The 201 file is retained
                        for audit and payroll history, and an administrator can restore it
                        later.
                    </p>
                </div>
            </Modal>

            {/* Delete document */}
            <Modal
                show={Boolean(pendingDocument)}
                onClose={() => setPendingDocument(null)}
                title="Delete this document?"
                maxWidth="md"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setPendingDocument(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={deleteDocument}>
                            Delete
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">
                        {pendingDocument?.title}
                    </span>{' '}
                    will be permanently removed. This cannot be undone.
                </p>
            </Modal>

            {/* View document. The file is streamed from the private disk
                through an authorized route — the browser never sees a storage
                path, so a preview is exactly as gated as a download. */}
            <Modal
                show={Boolean(preview)}
                onClose={() => setPreview(null)}
                title={preview?.title ?? 'Document'}
                description={preview?.file_name}
                maxWidth="3xl"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setPreview(null)}>
                            Close
                        </Button>
                        {preview && (
                            <Button href={preview.url} external>
                                <Download className="h-4 w-4" />
                                Download
                            </Button>
                        )}
                    </>
                }
            >
                {preview?.preview_as === 'image' && (
                    <img
                        src={preview.preview_url}
                        alt={preview.title}
                        className="mx-auto max-h-[70vh] w-auto rounded-md border border-border object-contain"
                    />
                )}

                {(preview?.preview_as === 'pdf' || preview?.preview_as === 'text') && (
                    <iframe
                        src={preview.preview_url}
                        title={preview.title}
                        className="h-[70vh] w-full rounded-md border border-border bg-background"
                    />
                )}
            </Modal>
        </AppLayout>
    );
}
