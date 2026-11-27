<?php
declare(strict_types=1);

function programEligibilitySql(string $programAlias = 'p', string $userAlias = 'applicant', string $degreeAlias = 'degree'): string
{
    foreach ([$programAlias, $userAlias, $degreeAlias] as $alias) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('Invalid SQL alias.');
        }
    }

    $participantTypes = "CASE WHEN JSON_VALID(COALESCE($programAlias.eligible_participant_types, '[]')) "
        . "THEN COALESCE($programAlias.eligible_participant_types, '[]') ELSE JSON_ARRAY() END";
    $campuses = "CASE WHEN JSON_VALID(COALESCE($programAlias.eligible_campuses, '[]')) "
        . "THEN COALESCE($programAlias.eligible_campuses, '[]') ELSE JSON_ARRAY() END";
    $degreePrograms = "CASE WHEN JSON_VALID(COALESCE($programAlias.eligible_degree_programs, '[]')) "
        . "THEN COALESCE($programAlias.eligible_degree_programs, '[]') ELSE JSON_ARRAY() END";

    return "(
        (
            JSON_CONTAINS($participantTypes, JSON_QUOTE(LOWER($userAlias.account_type)))
            OR (
                JSON_LENGTH($participantTypes) = 0
                AND (
                    LOWER($programAlias.eligible_account_type) = LOWER($userAlias.account_type)
                    OR LOWER($programAlias.eligible_account_type) = 'both'
                )
            )
        )
        AND (
            JSON_LENGTH($campuses) = 0
            OR JSON_CONTAINS($campuses, CAST($userAlias.campus_id AS CHAR))
        )
        AND (
            JSON_CONTAINS($degreePrograms, CAST($degreeAlias.id AS CHAR))
            OR (
                JSON_LENGTH($degreePrograms) = 0
                AND (
                    $programAlias.eligible_department = 'All Departments'
                    OR FIND_IN_SET(
                        LOWER($userAlias.program),
                        REPLACE(LOWER($programAlias.eligible_department), ', ', ',')
                    ) > 0
                )
            )
        )
    )";
}

function programApplicationWindowSql(string $programAlias = 'p'): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $programAlias)) {
        throw new InvalidArgumentException('Invalid SQL alias.');
    }

    return "COALESCE($programAlias.application_start_date, CURDATE()) <= CURDATE()
        AND COALESCE($programAlias.application_end_date, $programAlias.end_date) >= CURDATE()";
}
