<?php

namespace App\Support;

final class PasswordSecurity
{
    private const CONSECUTIVE_SEQUENCE_LENGTH = 3;
    private const MAX_LEVENSHTEIN_SIMILARITY = 0.25;
    private const MAX_COMMON_SUBSTRING_RATIO = 0.60;

    /**
     * Return the additional password-policy violations for a new password.
     *
     * The previous password is received only for comparison. It is never
     * persisted by this class.
     *
     * @return list<string>
     */
    public static function violations(string $newPassword, string $previousPassword): array
    {
        $violations = [];

        if (self::containsConsecutiveDigits($newPassword)) {
            $violations[] = 'La nueva contraseña no puede contener secuencias de dígitos consecutivos, ascendentes o descendentes.';
        }

        if (self::containsConsecutiveLetters($newPassword)) {
            $violations[] = 'La nueva contraseña no puede contener secuencias de letras consecutivas, ascendentes o descendentes.';
        }

        if ($previousPassword !== '' && self::isTooSimilar($newPassword, $previousPassword)) {
            $violations[] = 'La nueva contraseña es similar a la contraseña anterior. Elija una combinación diferente.';
        }

        return $violations;
    }

    public static function containsConsecutiveDigits(string $password): bool
    {
        $indices = array_map(
            static fn (string $character): ?int => preg_match('/^[0-9]$/', $character) === 1
                ? ord($character) - ord('0')
                : null,
            self::characters($password)
        );

        return self::containsConsecutiveSequence($indices);
    }

    public static function containsConsecutiveLetters(string $password): bool
    {
        $indices = array_map(
            static function (string $character): ?int {
                $lower = mb_strtolower($character, 'UTF-8');

                if (strlen($lower) !== 1 || $lower < 'a' || $lower > 'z') {
                    return null;
                }

                return ord($lower) - ord('a');
            },
            self::characters($password)
        );

        return self::containsConsecutiveSequence($indices);
    }

    public static function isTooSimilar(string $newPassword, string $previousPassword): bool
    {
        $newCharacters = self::normalizedCharacters($newPassword);
        $previousCharacters = self::normalizedCharacters($previousPassword);

        if ($newCharacters === [] || $previousCharacters === []) {
            return false;
        }

        $longestLength = max(count($newCharacters), count($previousCharacters));
        $distance = self::levenshteinDistance($newCharacters, $previousCharacters);

        if ($distance <= $longestLength * self::MAX_LEVENSHTEIN_SIMILARITY) {
            return true;
        }

        $commonSubstringLength = self::longestCommonSubstringLength($newCharacters, $previousCharacters);
        $shortestLength = min(count($newCharacters), count($previousCharacters));

        return $commonSubstringLength > $shortestLength * self::MAX_COMMON_SUBSTRING_RATIO;
    }

    /**
     * @param array<int, ?int> $indices
     */
    private static function containsConsecutiveSequence(array $indices): bool
    {
        $runLength = 1;
        $direction = 0;
        $previous = null;

        foreach ($indices as $current) {
            if ($current === null || $previous === null) {
                $runLength = 1;
                $direction = 0;
                $previous = $current;
                continue;
            }

            $difference = $current - $previous;

            if (abs($difference) !== 1) {
                $runLength = 1;
                $direction = 0;
            } elseif ($direction === $difference) {
                $runLength++;
            } else {
                $runLength = 2;
                $direction = $difference;
            }

            if ($runLength >= self::CONSECUTIVE_SEQUENCE_LENGTH) {
                return true;
            }

            $previous = $current;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function characters(string $value): array
    {
        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @return list<string>
     */
    private static function normalizedCharacters(string $value): array
    {
        return self::characters(mb_strtolower($value, 'UTF-8'));
    }

    /**
     * @param list<string> $first
     * @param list<string> $second
     */
    private static function levenshteinDistance(array $first, array $second): int
    {
        $previousRow = range(0, count($second));

        foreach ($first as $firstIndex => $firstCharacter) {
            $currentRow = [$firstIndex + 1];

            foreach ($second as $secondIndex => $secondCharacter) {
                $currentRow[] = $firstCharacter === $secondCharacter
                    ? $previousRow[$secondIndex]
                    : min(
                        $previousRow[$secondIndex] + 1,
                        $currentRow[$secondIndex] + 1,
                        $previousRow[$secondIndex + 1] + 1
                    );
            }

            $previousRow = $currentRow;
        }

        return $previousRow[count($second)] ?? count($first);
    }

    /**
     * @param list<string> $first
     * @param list<string> $second
     */
    private static function longestCommonSubstringLength(array $first, array $second): int
    {
        $previousRow = array_fill(0, count($second) + 1, 0);
        $longest = 0;

        foreach ($first as $firstCharacter) {
            $currentRow = array_fill(0, count($second) + 1, 0);

            foreach ($second as $secondIndex => $secondCharacter) {
                if ($firstCharacter === $secondCharacter) {
                    $currentRow[$secondIndex + 1] = $previousRow[$secondIndex] + 1;
                    $longest = max($longest, $currentRow[$secondIndex + 1]);
                }
            }

            $previousRow = $currentRow;
        }

        return $longest;
    }
}
