<?php

namespace Tests\Unit;

use App\Support\PasswordSecurity;
use PHPUnit\Framework\TestCase;

class PasswordSecurityTest extends TestCase
{
    public function test_rejects_ascending_and_descending_digit_sequences_of_four_or_more(): void
    {
        $this->assertTrue(PasswordSecurity::containsConsecutiveDigits('Clave1234!A'));
        $this->assertTrue(PasswordSecurity::containsConsecutiveDigits('Clave4321!A'));
        $this->assertTrue(PasswordSecurity::containsConsecutiveDigits('Clave0123!A'));
        $this->assertFalse(PasswordSecurity::containsConsecutiveDigits('Clave1520!A'));
    }

    public function test_rejects_ascending_and_descending_letter_sequences_of_four_or_more(): void
    {
        $this->assertTrue(PasswordSecurity::containsConsecutiveLetters('Claveabcd1!'));
        $this->assertTrue(PasswordSecurity::containsConsecutiveLetters('ClaveDCBA1!'));
        $this->assertTrue(PasswordSecurity::containsConsecutiveLetters('Clavewxyz1!'));
        $this->assertFalse(PasswordSecurity::containsConsecutiveLetters('Claveabcz1!'));
    }

    public function test_rejects_the_mandatory_common_core_similarity_case(): void
    {
        $this->assertTrue(PasswordSecurity::isTooSimilar('Cacerola01!20', 'Cacerola02$2026'));
    }

    public function test_rejects_levenshtein_similarity_at_or_below_the_strict_threshold(): void
    {
        $this->assertTrue(PasswordSecurity::isTooSimilar('NuevaClave2026!', 'NuevaClave2025!'));
    }

    public function test_allows_a_password_without_the_additional_violations(): void
    {
        $this->assertSame([], PasswordSecurity::violations('FiaSeguro!5720', 'Cacerola02$2026'));
    }
}
