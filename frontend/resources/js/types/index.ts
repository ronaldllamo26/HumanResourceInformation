/**
 * The shapes the Laravel side actually sends.
 *
 * These are hand-written rather than generated, and that is the honest
 * trade-off to state out loud: nothing forces them to stay in step with the
 * Resource classes in `app/Http/Resources`. What they buy is that a page which
 * reads `employee.clint.name` fails in the editor instead of at render, and
 * that a field removed from a Resource shows up as a type error in every page
 * that still reads it.
 *
 * Where a field is gated server-side — salary and government IDs behind
 * `EmployeePolicy::viewSensitive` — it is optional here, because the API
 * genuinely omits it rather than sending null. A page has to handle its
 * absence, and the type should say so.
 */

export type Role = 'super_admin' | 'admin' | 'hr_staff' | 'supervisor' | 'employee';

export type EmploymentCategory = 'internal' | 'external';

export type RecordStatus = 'active' | 'inactive' | 'on_leave';

export type EmploymentStatus =
    'regular' | 'probationary' | 'contractual' | 'project-based' | 'resigned' | 'terminated';

export interface User {
    id: number;
    name: string;
    email: string;
    role: Role;
    is_active: boolean;
}

export interface Department {
    id: number;
    name: string;
}

export interface Position {
    id: number;
    title: string;
    department_id?: number;
}

export interface Client {
    id: number;
    code: string;
    name: string;
    wage_region?: string | null;
}

export interface EmployeeDocument {
    id: number;
    employee_id: number;
    type: string;
    title: string;
    file_name: string;
    file_size: number | null;
    mime_type: string | null;
    url: string;
    preview_url: string;
    /** What the browser can render it as; null offers download only. */
    preview_as: 'image' | 'pdf' | 'text' | null;
    issued_at: string | null;
    expires_at: string | null;
    is_expired: boolean;
    uploaded_by?: string | null;
    created_at: string | null;
}

export interface Employee {
    id: number;
    employee_number: string;
    full_name: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;

    employment_category: EmploymentCategory;
    client_id: number | null;
    client?: Pick<Client, 'id' | 'code' | 'name'> | null;
    department_id: number | null;
    department?: Department | null;
    position_id: number | null;
    position?: Position | null;

    employment_status: EmploymentStatus;
    status: RecordStatus;
    date_hired: string | null;

    /** Omitted entirely unless the viewer passes `viewSensitive`. */
    basic_salary?: number;
    sss_number?: string | null;
    philhealth_number?: string | null;
    pagibig_number?: string | null;
    tin?: string | null;

    photo_url: string | null;
    has_account: boolean;
    documents?: EmployeeDocument[];
}

/**
 * Laravel's paginator, as Inertia receives it.
 *
 * `links` and `meta.links` are different shapes and mixing them crashes the
 * page — the object is the first/last/prev/next set, the array is the numbered
 * buttons. Typing them apart is the point: `<Pagination>` takes the array, and
 * handing it the object is now a compile error rather than a blank screen.
 */
export interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: PageLink[];
    };
}

/** Props every authenticated page receives from HandleInertiaRequests. */
export interface SharedProps {
    auth: { user: User | null };
    flash: { success?: string; error?: string };
    pendingApprovals: number;
    expiringCredentials: number;
    [key: string]: unknown;
}
