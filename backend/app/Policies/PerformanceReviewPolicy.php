<?php

namespace App\Policies;

use App\Models\PerformanceReview;
use App\Models\User;

class PerformanceReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PerformanceReview $review): bool
    {
        if ($user->isHrAdmin()) {
            return true;
        }

        // The reviewer sees what they are writing; the employee sees reviews of
        // themselves, but only once submitted — a draft is still being drafted.
        if ($review->reviewer_id === $user->id) {
            return true;
        }

        return $review->employee?->user_id === $user->id
            && $review->status !== PerformanceReview::STATUS_DRAFT;
    }

    /** Only the assigned reviewer edits, and only while the cycle is open. */
    public function update(User $user, PerformanceReview $review): bool
    {
        return $review->reviewer_id === $user->id
            && $review->isEditable()
            && ($review->reviewCycle?->acceptsSubmissions() ?? false);
    }

    public function submit(User $user, PerformanceReview $review): bool
    {
        return $this->update($user, $review) && $review->ratings()->exists();
    }

    /** The employee signs off on a review of themselves — nobody else can. */
    public function acknowledge(User $user, PerformanceReview $review): bool
    {
        return $review->employee?->user_id === $user->id
            && $review->status === PerformanceReview::STATUS_SUBMITTED
            // A self review needs no acknowledgement; they wrote it.
            && $review->reviewer_type !== 'self';
    }

    public function manageCycles(User $user): bool
    {
        return $user->isHrAdmin();
    }

    public function manageKpis(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /** HR sees anyone's history; everyone else sees their own. */
    public function viewHistory(User $user): bool
    {
        return true;
    }
}
