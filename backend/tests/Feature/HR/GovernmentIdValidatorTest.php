<?php

namespace Tests\Feature\HR;

use App\Services\GovernmentIdValidator;
use Tests\TestCase;

class GovernmentIdValidatorTest extends TestCase
{
    private GovernmentIdValidator $validator;

    // ── SSS ──────────────────────────────────────────────────────────

    public function test_valid_sss_number(): void
    {
        $result = $this->validator->validateSss('0312345678');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['warning']);
        $this->assertSame('SSS', $result['id_type']);
    }

    public function test_sss_number_wrong_length(): void
    {
        $result = $this->validator->validateSss('12345');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('10 digits', $result['warning']);
    }

    public function test_sss_branch_code_zero(): void
    {
        $result = $this->validator->validateSss('0012345678');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('branch code', $result['warning']);
    }

    // ── PhilHealth ───────────────────────────────────────────────────

    public function test_valid_philhealth_number(): void
    {
        $result = $this->validator->validatePhilHealth('012345678901');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['warning']);
        $this->assertSame('PhilHealth', $result['id_type']);
    }

    public function test_philhealth_wrong_length(): void
    {
        $result = $this->validator->validatePhilHealth('1234567');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('12 digits', $result['warning']);
    }

    // ── TIN ──────────────────────────────────────────────────────────

    public function test_valid_tin_9_digits(): void
    {
        $result = $this->validator->validateTin('123456789');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['warning']);
        $this->assertSame('TIN', $result['id_type']);
    }

    public function test_valid_tin_12_digits_with_branch(): void
    {
        $result = $this->validator->validateTin('123456789000');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['warning']);
    }

    public function test_tin_wrong_length(): void
    {
        $result = $this->validator->validateTin('12345');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('9 digits', $result['warning']);
    }

    public function test_tin_reserved_prefix(): void
    {
        $result = $this->validator->validateTin('000456789');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('reserved prefix', $result['warning']);
    }

    // ── PhilSys ──────────────────────────────────────────────────────

    public function test_valid_philsys_number(): void
    {
        $result = $this->validator->validatePhilSys('1234567890123456');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['warning']);
        $this->assertSame('PhilSys', $result['id_type']);
    }

    public function test_philsys_wrong_length(): void
    {
        $result = $this->validator->validatePhilSys('12345678901234');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('16 digits', $result['warning']);
    }

    // ── Pag-IBIG ─────────────────────────────────────────────────────

    public function test_valid_pagibig_number(): void
    {
        $result = $this->validator->validatePagIbig('123456789012');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['warning']);
        $this->assertSame('Pag-IBIG', $result['id_type']);
    }

    public function test_pagibig_wrong_length(): void
    {
        $result = $this->validator->validatePagIbig('1234567890');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('12 digits', $result['warning']);
    }

    // ── Heading-based dispatch ────────────────────────────────────────

    public function test_validate_dispatches_to_sss_from_heading(): void
    {
        $result = $this->validator->validate('03-1234567-8', 'Social Security System');

        $this->assertNotNull($result);
        $this->assertSame('SSS', $result['id_type']);
    }

    public function test_validate_dispatches_to_tin_from_heading(): void
    {
        $result = $this->validator->validate('123-456-789', 'Bureau of Internal Revenue TIN ID');

        $this->assertNotNull($result);
        $this->assertSame('TIN', $result['id_type']);
    }

    public function test_validate_dispatches_to_philsys_from_heading(): void
    {
        $result = $this->validator->validate('1234-5678-9012-3456', 'Philippine Identification System');

        $this->assertNotNull($result);
        $this->assertSame('PhilSys', $result['id_type']);
    }

    public function test_validate_returns_null_for_empty_number(): void
    {
        $this->assertNull($this->validator->validate(null, 'Some heading'));
        $this->assertNull($this->validator->validate('', 'Some heading'));
    }

    public function test_validate_falls_back_to_length_when_no_heading_matches(): void
    {
        // 10 digits -> SSS by length
        $result = $this->validator->validate('0312345678', 'Unknown Document');

        $this->assertNotNull($result);
        $this->assertSame('SSS', $result['id_type']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new GovernmentIdValidator;
    }
}
