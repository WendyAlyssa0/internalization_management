<?php
/**
 * Shared rules for outbound PSU participant accounts.
 * Used by public/register.php (sign-up) and user/profile.php (profile edits)
 * so both collect and validate the same fields the same way.
 */
declare(strict_types=1);

/** Institutional email domains. Subdomains (e.g. lingayen.psu.edu.ph) are accepted too. */
const PSU_EMAIL_DOMAINS = ['psu.edu.ph'];

/** Stored value => label. Lowercase values match admin/users.php and program eligibility. */
const PARTICIPANT_CLASSIFICATIONS = [
    'student'      => 'Student',
    'faculty'      => 'Faculty',
    'non-teaching' => 'Non-teaching',
];

const PARTICIPANT_YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', 'Graduate Student'];

const PARTICIPANT_FACULTY_RANKS = [
    'Instructor',
    'Assistant Professor',
    'Associate Professor',
    'Professor',
    'University Professor',
    'Lecturer / Part-time Faculty',
];

function isInstitutionalEmail(string $email): bool
{
    $at = strrpos($email, '@');
    if ($at === false) {
        return false;
    }
    $domain = strtolower(substr($email, $at + 1));
    foreach (PSU_EMAIL_DOMAINS as $allowed) {
        $suffix = '.' . $allowed;
        if ($domain === $allowed || substr($domain, -strlen($suffix)) === $suffix) {
            return true;
        }
    }
    return false;
}

function institutionalEmailHint(): string
{
    return '@' . implode(' or @', PSU_EMAIL_DOMAINS);
}

/** Active campuses, id => name. */
function participantCampuses(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id, campus_name FROM campuses WHERE LOWER(status) = 'active' ORDER BY campus_name")
        ->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $out[(int)$row['id']] = (string)$row['campus_name'];
    }
    return $out;
}

/** Active degree programs grouped by campus: [campus_id => [program_name, ...]]. */
function participantProgramsByCampus(PDO $pdo): array
{
    $rows = $pdo->query("SELECT campus_id, program_name FROM degree_programs WHERE LOWER(status) = 'active' ORDER BY program_name")
        ->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $out[(int)$row['campus_id']][] = (string)$row['program_name'];
    }
    return $out;
}

/**
 * Read and validate the classification-dependent part of the form.
 *
 * @param array $input  Usually $_POST.
 * @return array{0: array<string,mixed>, 1: string[]}  [cleaned values, error messages]
 */
function validateParticipantDetails(array $input, array $campuses, array $programsByCampus): array
{
    $str = static fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';

    $values = [
        'account_type'   => strtolower($str('account_type')),
        'campus_id'      => (int)$str('campus_id'),
        'program'        => $str('program'),
        'student_number' => $str('student_number'),
        'year_level'     => $str('year_level'),
        'position_rank'  => '',
    ];
    $errors = [];

    if (!array_key_exists($values['account_type'], PARTICIPANT_CLASSIFICATIONS)) {
        $errors[] = 'Select whether you are a student, faculty member, or non-teaching personnel.';
        $values['account_type'] = 'student';
    }

    if (!array_key_exists($values['campus_id'], $campuses)) {
        $errors[] = 'Select your PSU campus.';
        $values['campus_id'] = 0;
    }

    $campusPrograms = $programsByCampus[$values['campus_id']] ?? [];
    $isNonTeaching = $values['account_type'] === 'non-teaching';
    if ($values['program'] === '') {
        // Non-teaching personnel may belong to an office rather than a degree program.
        if (!$isNonTeaching) {
            $errors[] = 'Select your department / degree program.';
        }
    } elseif ($values['campus_id'] > 0 && !in_array($values['program'], $campusPrograms, true)) {
        $errors[] = 'Select a department / degree program offered by your campus.';
        $values['program'] = '';
    }

    switch ($values['account_type']) {
        case 'student':
            if ($values['student_number'] === '') {
                $errors[] = 'Enter your student number.';
            } elseif (mb_strlen($values['student_number']) > 30) {
                $errors[] = 'Student number must be 30 characters or fewer.';
            }
            if (!in_array($values['year_level'], PARTICIPANT_YEAR_LEVELS, true)) {
                $errors[] = 'Select your year level.';
                $values['year_level'] = '';
            }
            break;

        case 'faculty':
            $values['position_rank'] = $str('faculty_rank');
            if (!in_array($values['position_rank'], PARTICIPANT_FACULTY_RANKS, true)) {
                $errors[] = 'Select your academic rank.';
                $values['position_rank'] = '';
            }
            break;

        case 'non-teaching':
            $values['position_rank'] = $str('position');
            if ($values['position_rank'] === '') {
                $errors[] = 'Enter your position / designation.';
            } elseif (mb_strlen($values['position_rank']) > 120) {
                $errors[] = 'Position must be 120 characters or fewer.';
            }
            break;
    }

    // Clear fields that do not apply to the chosen classification.
    if ($values['account_type'] !== 'student') {
        $values['student_number'] = '';
        $values['year_level'] = '';
    }

    return [$values, $errors];
}

/** Column values for an INSERT/UPDATE on users, from validateParticipantDetails() output. */
function participantDetailColumns(array $v): array
{
    $nullable = static fn(string $s): ?string => $s !== '' ? $s : null;
    return [
        'account_type'      => $v['account_type'],
        'campus_id'         => $v['campus_id'] ?: null,
        'program'           => $nullable($v['program']),
        // Kept in sync so existing admin listings that show "department" keep working.
        'department'        => $nullable(mb_substr($v['program'], 0, 100)),
        'student_id_number' => $nullable($v['student_number']),
        'year_level'        => $nullable($v['year_level']),
        'faculty_rank'      => $nullable($v['position_rank']),
    ];
}
