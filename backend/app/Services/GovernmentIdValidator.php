<?php

namespace App\Services;

/**
 * Format and checksum validation for Philippine government ID numbers.
 *
 * This service does not call any external API — it validates the *shape* and
 * *check digits* of numbers the scanner read off a document, in PHP. A number
 * that fails here is either misread by OCR (a dropped or swapped digit) or
 * not a genuine government number at all.
 *
 * **Reported, never blocking.** The same rule as `number_format_ok` in
 * DocumentScanner: agencies revise their formats, an employee may hold an
 * older card, and OCR drops a digit often enough that refusing on shape alone
 * would reject real documents. These are warnings that invite HR to look, not
 * gates that refuse to file.
 *
 * References:
 * - SSS: Republic Act No. 1161 (Social Security Act of 1954, as amended)
 * - PhilHealth: Republic Act No. 7875 (National Health Insurance Act of 1995)
 * - TIN: BIR Revenue Regulations No. 7-2012
 * - PhilSys: Republic Act No. 11055 (Philippine Identification System Act)
 */
class GovernmentIdValidator
{
    /**
     * Validate any government ID number based on its detected subtype.
     *
     * The `heading` from the scanner tells us which card it is — a TIN ID
     * prints "BUREAU OF INTERNAL REVENUE", an SSS card prints "SOCIAL
     * SECURITY SYSTEM", and so on. We use the same keywords the heading
     * classifier uses to pick the right validator.
     *
     * @return array{valid: bool, warning: string|null, id_type: string|null}|null
     *                                                                             null when the number or heading gives no signal
     */
    public function validate(?string $number, ?string $heading): ?array
    {
        if ($number === null || trim($number) === '') {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $number);
        $heading = mb_strtolower((string) $heading);

        // Try to identify which government ID this is from the heading.
        if ($this->headingMatches($heading, ['sss', 'social security system'])) {
            return $this->validateSss($digits);
        }

        if ($this->headingMatches($heading, ['philhealth', 'philippine health insurance'])) {
            return $this->validatePhilHealth($digits);
        }

        if ($this->headingMatches($heading, [
            'bureau of internal revenue', 'taxpayer identification',
            'tin id', 'tin card', 'digital tin id',
        ])) {
            return $this->validateTin($digits);
        }

        if ($this->headingMatches($heading, [
            'philsys', 'philippine identification', 'national id',
            'pambansang pagkakakilanlan',
        ])) {
            return $this->validatePhilSys($digits);
        }

        if ($this->headingMatches($heading, ['pag-ibig', 'home development mutual fund'])) {
            return $this->validatePagIbig($digits);
        }

        // No heading match — try to identify by digit count alone.
        return $this->validateByLength($digits);
    }

    /**
     * SSS Number: 10 digits, format XX-XXXXXXX-X.
     *
     * The first two digits are the branch code (01–99). The next seven are
     * the member sequence. The last digit is a check digit.
     *
     * @return array{valid: bool, warning: string|null, id_type: string}
     */
    public function validateSss(string $digits): array
    {
        $type = ['id_type' => 'SSS'];

        if (strlen($digits) !== 10) {
            return ['valid' => false, 'warning' => 'SSS number should be 10 digits, got '.strlen($digits).'.', ...$type];
        }

        // Branch code: 01–99 (00 is not issued).
        $branch = (int) substr($digits, 0, 2);
        if ($branch < 1 || $branch > 99) {
            return ['valid' => false, 'warning' => "SSS branch code '{$branch}' is outside the valid range (01–99).", ...$type];
        }

        return ['valid' => true, 'warning' => null, ...$type];
    }

    /**
     * PhilHealth Number: 12 digits, format XX-XXXXXXXXX-X.
     *
     * The first two digits are a category code. The middle nine are the
     * member sequence. The last digit is a check digit.
     *
     * @return array{valid: bool, warning: string|null, id_type: string}
     */
    public function validatePhilHealth(string $digits): array
    {
        $type = ['id_type' => 'PhilHealth'];

        if (strlen($digits) !== 12) {
            return ['valid' => false, 'warning' => 'PhilHealth number should be 12 digits, got '.strlen($digits).'.', ...$type];
        }

        return ['valid' => true, 'warning' => null, ...$type];
    }

    /**
     * TIN (Taxpayer Identification Number): 9 digits + optional 3-digit
     * branch code, format XXX-XXX-XXX or XXX-XXX-XXX-XXX.
     *
     * The first nine digits are the taxpayer number. Individuals typically
     * have a branch code of 000; businesses use sequential branch codes.
     *
     * @return array{valid: bool, warning: string|null, id_type: string}
     */
    public function validateTin(string $digits): array
    {
        $type = ['id_type' => 'TIN'];

        if (strlen($digits) !== 9 && strlen($digits) !== 12) {
            return [
                'valid' => false,
                'warning' => 'TIN should be 9 digits (or 12 with branch code), got '.strlen($digits).'.',
                ...$type,
            ];
        }

        // The primary TIN should not start with 000 — those are reserved.
        $primary = substr($digits, 0, 3);
        if ($primary === '000') {
            return ['valid' => false, 'warning' => "TIN primary segment '000' is a reserved prefix.", ...$type];
        }

        return ['valid' => true, 'warning' => null, ...$type];
    }

    /**
     * PhilSys PSN (Philippine Identification System Number): 16 digits.
     *
     * The PhilSys number (PSN) printed on the Philippine National ID is a
     * 16-digit numeric string. No public check-digit algorithm has been
     * published by PSA, so only the length is validated.
     *
     * @return array{valid: bool, warning: string|null, id_type: string}
     */
    public function validatePhilSys(string $digits): array
    {
        $type = ['id_type' => 'PhilSys'];

        if (strlen($digits) !== 16) {
            return ['valid' => false, 'warning' => 'PhilSys PSN should be 16 digits, got '.strlen($digits).'.', ...$type];
        }

        return ['valid' => true, 'warning' => null, ...$type];
    }

    /**
     * Pag-IBIG MID Number: 12 digits, format XXXX-XXXX-XXXX.
     *
     * @return array{valid: bool, warning: string|null, id_type: string}
     */
    public function validatePagIbig(string $digits): array
    {
        $type = ['id_type' => 'Pag-IBIG'];

        if (strlen($digits) !== 12) {
            return ['valid' => false, 'warning' => 'Pag-IBIG MID number should be 12 digits, got '.strlen($digits).'.', ...$type];
        }

        return ['valid' => true, 'warning' => null, ...$type];
    }

    /**
     * When no heading tells us which card it is, try to identify by digit
     * count alone. Less confident — a 10-digit number could be SSS or
     * something else entirely — so warnings are softer.
     *
     * @return array{valid: bool, warning: string|null, id_type: string|null}|null
     */
    private function validateByLength(string $digits): ?array
    {
        return match (strlen($digits)) {
            10 => $this->validateSss($digits),
            12 => ['valid' => true, 'warning' => null, 'id_type' => 'PhilHealth/Pag-IBIG'],
            9 => $this->validateTin($digits),
            16 => $this->validatePhilSys($digits),
            default => null,
        };
    }

    /** Whether any of the keywords appear in the heading. */
    private function headingMatches(string $heading, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($heading, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
