import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Award, GraduationCap, Plus, Sparkles } from 'lucide-react';
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
} from '@/Components/ui';
import { cn, formatDate } from '@/lib/utils';

const BLANK_EDUCATION = {
    level: '',
    school: '',
    course: '',
    year_graduated: '',
    honors: '',
};

const BLANK_TRAINING = {
    title: '',
    provider: '',
    reference_number: '',
    completed_at: '',
    expires_at: '',
    hours: '',
    remarks: '',
};

const BLANK_SKILL = {
    name: '',
    proficiency: '',
};

/*
 * Tone is valence, as everywhere else. `expiring` is a thing somebody has to
 * do something about; `expired` is a qualification that no longer counts,
 * which for a TESDA card on a deployment sheet is the harder fact.
 */
const EXPIRY_TONE = {
    expired: 'destructive',
    expiring: 'warning',
    valid: 'success',
};

const EXPIRY_LABEL = {
    expired: 'Expired',
    expiring: 'Expiring',
    valid: 'Valid',
};

/**
 * Educational & qualification records — the part of the 201 file that says
 * what somebody is qualified for rather than who they are.
 *
 * Three lists, not one, because the three are only alike in belonging to a
 * person: a school has a course and a year, a training has a provider and an
 * expiry, a skill has a grade and neither.
 *
 * The papers stay in Documents. A diploma, a transcript, and a TESDA
 * certificate are evidence; these rows are the fact, and filing the fact twice
 * would be two answers to one question.
 */
export default function Qualifications({
    employee,
    educationLevels,
    levelsWithCourse,
    proficiencyLevels,
    canManage,
}) {
    const [editing, setEditing] = useState(null); // { kind, row } | { kind, row: null }
    const [pendingDelete, setPendingDelete] = useState(null);

    const education = useForm(BLANK_EDUCATION);
    const training = useForm(BLANK_TRAINING);
    const skill = useForm(BLANK_SKILL);

    const forms = { education, training, skill };
    const blanks = {
        education: BLANK_EDUCATION,
        training: BLANK_TRAINING,
        skill: BLANK_SKILL,
    };

    const open = (kind, row = null) => {
        const form = forms[kind];

        form.clearErrors();
        form.setData(
            row
                ? Object.fromEntries(
                      // Only the fields the form owns, and never null — a
                      // controlled input handed null logs a React warning and
                      // then silently stops being controlled.
                      Object.keys(blanks[kind]).map((key) => [key, row[key] ?? '']),
                  )
                : blanks[kind],
        );

        setEditing({ kind, row });
    };

    const close = () => {
        if (editing) forms[editing.kind].reset();
        setEditing(null);
    };

    const submit = (event) => {
        event.preventDefault();

        const { kind, row } = editing;
        const form = forms[kind];
        const base = `/hr/employees/${employee.id}/${kind}`;

        const done = { preserveScroll: true, onSuccess: close };

        if (row) {
            form.put(`${base}/${row.id}`, done);
        } else {
            form.post(base, done);
        }
    };

    const remove = () => {
        const { kind, row } = pendingDelete;

        router.delete(`/hr/employees/${employee.id}/${kind}/${row.id}`, {
            preserveScroll: true,
            onFinish: () => setPendingDelete(null),
        });
    };

    const educations = employee.educations ?? [];
    const trainings = employee.trainings ?? [];
    const skills = employee.skills ?? [];

    const showsCourse = levelsWithCourse.includes(education.data.level);

    return (
        <>
            {/* The same two-column grid the Overview section uses, and the same
                gap — these are cards on the same page, so a full-width stack
                here reads as a different screen pasted in. Skills spans both
                columns beneath: it is a row of pills rather than a field grid,
                and it wants the width. */}
            <div className="grid gap-5 lg:grid-cols-2">
                <Card>
                    <CardHeader
                        title="Education"
                        action={
                            canManage && (
                                <Button size="sm" onClick={() => open('education')}>
                                    <Plus className="h-4 w-4" aria-hidden="true" />
                                    Add
                                </Button>
                            )
                        }
                    />

                    <CardBody className="space-y-4">
                        {/* Highest attainment reads as a field of the record
                            rather than as a sentence in the card's subtitle,
                            which is the language the rest of this page speaks
                            — and it is derived from the rows below it. */}
                        <dl className="grid gap-4 sm:grid-cols-2">
                            <DetailRow
                                label="Highest Attainment"
                                value={employee.highest_education}
                            />
                            <DetailRow
                                label="Diploma / Transcript"
                                value="Filed under Documents"
                            />
                        </dl>

                        {educations.length === 0 ? (
                            <EmptyBlock
                                icon={GraduationCap}
                                title="No education recorded"
                                description="Add the schools and the levels reached."
                            />
                        ) : (
                            educations.map((row) => (
                                <RecordBlock
                                    key={row.id}
                                    heading={row.level_label}
                                    canManage={canManage}
                                    onEdit={() => open('education', row)}
                                    onDelete={() =>
                                        setPendingDelete({
                                            kind: 'education',
                                            row,
                                            label: `${row.level_label} — ${row.school}`,
                                        })
                                    }
                                >
                                    <DetailRow label="School" value={row.school} />
                                    <DetailRow label="Course / strand" value={row.course} />
                                    <DetailRow
                                        label="Year Graduated"
                                        value={row.year_graduated}
                                    />
                                    <DetailRow label="Honors" value={row.honors} />
                                </RecordBlock>
                            ))
                        )}
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader
                        title="Certifications & Trainings"
                        action={
                            canManage && (
                                <Button size="sm" onClick={() => open('training')}>
                                    <Plus className="h-4 w-4" aria-hidden="true" />
                                    Add
                                </Button>
                            )
                        }
                    />

                    <CardBody className="space-y-4">
                        {trainings.length === 0 ? (
                            <EmptyBlock
                                icon={Award}
                                title="No trainings recorded"
                                description="Add completed courses, seminars, and certifications."
                            />
                        ) : (
                            trainings.map((row) => (
                                <RecordBlock
                                    key={row.id}
                                    heading={row.title}
                                    badge={
                                        row.expiry_state && (
                                            <Badge variant={EXPIRY_TONE[row.expiry_state]}>
                                                {EXPIRY_LABEL[row.expiry_state]}
                                            </Badge>
                                        )
                                    }
                                    canManage={canManage}
                                    onEdit={() => open('training', row)}
                                    onDelete={() =>
                                        setPendingDelete({
                                            kind: 'training',
                                            row,
                                            label: row.title,
                                        })
                                    }
                                >
                                    <DetailRow label="Provider" value={row.provider} />
                                    <DetailRow
                                        label="Certificate No."
                                        value={row.reference_number}
                                    />
                                    <DetailRow
                                        label="Completed"
                                        value={
                                            row.completed_at
                                                ? formatDate(row.completed_at)
                                                : null
                                        }
                                    />
                                    <DetailRow
                                        label="Expires"
                                        /* Said as a fact rather than left as a
                                           dash: "—" here would read as a date
                                           somebody forgot on a card that
                                           simply has none. */
                                        value={
                                            row.expires_at
                                                ? formatDate(row.expires_at)
                                                : 'Does not expire'
                                        }
                                    />
                                    <DetailRow label="Hours" value={row.hours} />
                                    <DetailRow
                                        label="Remarks"
                                        value={row.remarks}
                                        className="sm:col-span-2"
                                    />
                                </RecordBlock>
                            ))
                        )}
                    </CardBody>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader
                        title="Skills & Competencies"
                        action={
                            canManage && (
                                <Button size="sm" onClick={() => open('skill')}>
                                    <Plus className="h-4 w-4" aria-hidden="true" />
                                    Add
                                </Button>
                            )
                        }
                    />

                    <CardBody>
                        {/* Pills rather than a field grid, and that is not an
                            inconsistency: a skill is a single word with no
                            second half to label. Giving each one a "Skill:"
                            caption would be a form where a list belongs. */}
                        {skills.length === 0 ? (
                            <EmptyBlock
                                icon={Sparkles}
                                title="No skills recorded"
                                description="Add what this employee is competent to do."
                            />
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {skills.map((row) => (
                                    <span
                                        key={row.id}
                                        className={cn(
                                            'inline-flex items-center gap-2 rounded-full border border-border bg-secondary/60 py-1 pl-3 text-sm text-foreground',
                                            canManage ? 'pr-1' : 'pr-3',
                                        )}
                                    >
                                        {row.name}
                                        {row.proficiency_label && (
                                            <span className="text-xs text-muted-foreground">
                                                {row.proficiency_label}
                                            </span>
                                        )}
                                        {canManage && (
                                            <span className="flex items-center">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-6 px-2 text-xs"
                                                    onClick={() => open('skill', row)}
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-6 px-2 text-xs"
                                                    onClick={() =>
                                                        setPendingDelete({
                                                            kind: 'skill',
                                                            row,
                                                            label: row.name,
                                                        })
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            </span>
                                        )}
                                    </span>
                                ))}
                            </div>
                        )}
                    </CardBody>
                </Card>
            </div>

            <Modal
                show={editing?.kind === 'education'}
                onClose={close}
                title={editing?.row ? 'Edit education record' : 'Add education record'}
                maxWidth="lg"
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Level" required error={education.errors.level}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={education.data.level}
                                    onChange={(event) =>
                                        education.setData('level', event.target.value)
                                    }
                                    placeholder="Select level"
                                    error={education.errors.level}
                                    options={Object.entries(educationLevels).map(
                                        ([value, label]) => ({ value, label }),
                                    )}
                                />
                            )}
                        </Field>

                        <Field
                            label="Year graduated"
                            hint="Leave blank if not completed."
                            error={education.errors.year_graduated}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    inputMode="numeric"
                                    value={education.data.year_graduated}
                                    onChange={(event) =>
                                        education.setData('year_graduated', event.target.value)
                                    }
                                    error={education.errors.year_graduated}
                                />
                            )}
                        </Field>
                    </div>

                    <Field label="School" required error={education.errors.school}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={education.data.school}
                                onChange={(event) =>
                                    education.setData('school', event.target.value)
                                }
                                error={education.errors.school}
                            />
                        )}
                    </Field>

                    {/* Hidden below senior high, where there is nothing to
                        name — a blank box on an elementary record reads as
                        something somebody forgot to fill in. */}
                    {showsCourse && (
                        <Field label="Course / strand" error={education.errors.course}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={education.data.course}
                                    onChange={(event) =>
                                        education.setData('course', event.target.value)
                                    }
                                    error={education.errors.course}
                                />
                            )}
                        </Field>
                    )}

                    <Field label="Honors / distinctions" error={education.errors.honors}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={education.data.honors}
                                onChange={(event) =>
                                    education.setData('honors', event.target.value)
                                }
                                error={education.errors.honors}
                            />
                        )}
                    </Field>

                    <FormActions form={education} onCancel={close} />
                </form>
            </Modal>

            <Modal
                show={editing?.kind === 'training'}
                onClose={close}
                title={editing?.row ? 'Edit training record' : 'Add training record'}
                description="Leave the expiry blank for a qualification that does not lapse."
                maxWidth="lg"
            >
                <form onSubmit={submit} className="space-y-4">
                    <Field label="Title" required error={training.errors.title}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={training.data.title}
                                onChange={(event) =>
                                    training.setData('title', event.target.value)
                                }
                                placeholder="e.g. Forklift Operation NC II"
                                error={training.errors.title}
                            />
                        )}
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Provider" error={training.errors.provider}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={training.data.provider}
                                    onChange={(event) =>
                                        training.setData('provider', event.target.value)
                                    }
                                    placeholder="e.g. TESDA"
                                    error={training.errors.provider}
                                />
                            )}
                        </Field>

                        <Field
                            label="Certificate number"
                            error={training.errors.reference_number}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={training.data.reference_number}
                                    onChange={(event) =>
                                        training.setData('reference_number', event.target.value)
                                    }
                                    error={training.errors.reference_number}
                                />
                            )}
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Completed" error={training.errors.completed_at}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={training.data.completed_at}
                                    onChange={(event) =>
                                        training.setData('completed_at', event.target.value)
                                    }
                                    error={training.errors.completed_at}
                                />
                            )}
                        </Field>

                        <Field
                            label="Expires"
                            hint="Blank = never"
                            error={training.errors.expires_at}
                        >
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={training.data.expires_at}
                                    onChange={(event) =>
                                        training.setData('expires_at', event.target.value)
                                    }
                                    error={training.errors.expires_at}
                                />
                            )}
                        </Field>

                        <Field label="Hours" error={training.errors.hours}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    inputMode="numeric"
                                    value={training.data.hours}
                                    onChange={(event) =>
                                        training.setData('hours', event.target.value)
                                    }
                                    error={training.errors.hours}
                                />
                            )}
                        </Field>
                    </div>

                    <Field label="Remarks" error={training.errors.remarks}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={training.data.remarks}
                                onChange={(event) =>
                                    training.setData('remarks', event.target.value)
                                }
                                error={training.errors.remarks}
                            />
                        )}
                    </Field>

                    <FormActions form={training} onCancel={close} />
                </form>
            </Modal>

            <Modal
                show={editing?.kind === 'skill'}
                onClose={close}
                title={editing?.row ? 'Edit skill' : 'Add skill'}
                maxWidth="md"
            >
                <form onSubmit={submit} className="space-y-4">
                    <Field label="Skill" required error={skill.errors.name}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={skill.data.name}
                                onChange={(event) => skill.setData('name', event.target.value)}
                                placeholder="e.g. Defensive Driving"
                                error={skill.errors.name}
                            />
                        )}
                    </Field>

                    <Field
                        label="Proficiency"
                        hint="Optional — record the skill before anyone has graded it."
                        error={skill.errors.proficiency}
                    >
                        {({ id }) => (
                            <Select
                                id={id}
                                value={skill.data.proficiency}
                                onChange={(event) =>
                                    skill.setData('proficiency', event.target.value)
                                }
                                placeholder="Not graded"
                                error={skill.errors.proficiency}
                                options={Object.entries(proficiencyLevels).map(
                                    ([value, label]) => ({ value, label }),
                                )}
                            />
                        )}
                    </Field>

                    <FormActions form={skill} onCancel={close} />
                </form>
            </Modal>

            <Modal
                show={Boolean(pendingDelete)}
                onClose={() => setPendingDelete(null)}
                title="Remove this record?"
                maxWidth="md"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setPendingDelete(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={remove}>
                            Remove
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">{pendingDelete?.label}</span>{' '}
                    will be taken off this 201 file. Any document filed for it stays under
                    Documents.
                </p>
            </Modal>
        </>
    );
}

/**
 * One label above its value — the same shape every other card on this profile
 * uses, so a qualification reads like the rest of the 201 file rather than
 * like a report embedded in it.
 *
 * A copy of Show.jsx's DetailRow rather than an import: that one is a private
 * helper of the page, and reaching into a page component from a partial is
 * how two files come to own one thing. If a third screen needs it, it belongs
 * in the UI kit.
 */
function DetailRow({ label, value, className }) {
    return (
        <div className={cn('min-w-0', className)}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 break-words text-sm text-foreground">{value || '—'}</dd>
        </div>
    );
}

/**
 * One qualification, as a field grid under its own heading.
 *
 * The records are repeating, which is what a table is for — but a table beside
 * eight field-grid cards reads as a different screen pasted into this one, and
 * these have four or five fields each rather than the dozen that would earn
 * the columns.
 */
function RecordBlock({ heading, badge, canManage, onEdit, onDelete, children }) {
    return (
        <div className="rounded-lg border border-border p-4">
            <div className="mb-3 flex items-start gap-2">
                <p className="min-w-0 flex-1 truncate text-sm font-semibold text-foreground">
                    {heading}
                </p>
                {badge}
                {canManage && (
                    <div className="flex shrink-0 items-center">
                        <Button
                            size="sm"
                            variant="ghost"
                            className="h-7 px-2 text-xs"
                            onClick={onEdit}
                        >
                            Edit
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            className="h-7 px-2 text-xs"
                            onClick={onDelete}
                        >
                            Remove
                        </Button>
                    </div>
                )}
            </div>

            <dl className="grid gap-4 sm:grid-cols-2">{children}</dl>
        </div>
    );
}

/**
 * One quiet line, not a poster.
 *
 * These cards sit beside Personal Information and Government IDs, where an
 * unfilled value is a dash and nothing else. A dashed panel with an icon in it
 * shouts about an empty list on a page whose whole language is a small grey
 * label over a value — and it made the two cards twice the height of the ones
 * across from them for the sake of saying "nothing here yet".
 */
function EmptyBlock({ icon: Icon, title, description }) {
    return (
        <div className="flex items-center gap-2.5 py-1">
            <Icon className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <p className="text-sm text-muted-foreground">
                {title}. <span className="text-xs">{description}</span>
            </p>
        </div>
    );
}

function FormActions({ form, onCancel }) {
    return (
        <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" onClick={onCancel}>
                Cancel
            </Button>
            <Button type="submit" loading={form.processing}>
                Save
            </Button>
        </div>
    );
}
