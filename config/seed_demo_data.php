<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (getenv('APP_ENV') !== 'demo') {
    fwrite(STDERR, "Refusing to seed data unless APP_ENV=demo.\n");
    exit(1);
}

$demoPassword = getenv('DEMO_SEED_PASSWORD');
if (!is_string($demoPassword) || strlen($demoPassword) < 12) {
    fwrite(STDERR, "Set DEMO_SEED_PASSWORD to a password of at least 12 characters.\n");
    exit(1);
}

require_once __DIR__ . '/db.php';

function demoColumns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }
    return $columns;
}

function demoInsert(PDO $pdo, string $table, array $data): int
{
    $columns = array_intersect_key($data, demoColumns($pdo, $table));
    if (!$columns) {
        throw new RuntimeException("No seedable columns found for $table.");
    }

    $names = array_keys($columns);
    $quotedNames = array_map(static function (string $name): string {
        return "`$name`";
    }, $names);
    $sql = "INSERT INTO `$table` (" . implode(', ', $quotedNames) . ') VALUES ('
        . implode(', ', array_fill(0, count($names), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($columns));
    return (int)$pdo->lastInsertId();
}

function demoFindId(PDO $pdo, string $table, string $field, string $value): int
{
    $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE `$field` = ? LIMIT 1");
    $stmt->execute([$value]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function demoEnsureUser(PDO $pdo, string $firstName, string $lastName, string $email, string $role, string $accountType, ?int $campusId, string $program, string $passwordHash): int
{
    $existingId = demoFindId($pdo, 'users', 'email', $email);
    if ($existingId > 0) {
        return $existingId;
    }

    return demoInsert($pdo, 'users', [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'user_role' => $role,
        'account_type' => $accountType,
        'campus_id' => $campusId,
        'program' => $program,
        'status' => 'active',
        'password_hash' => $passwordHash,
    ]);
}

function demoWritePdf(string $path): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the demo document directory.');
    }
    if (is_file($path)) {
        return;
    }

    $stream = "BT /F1 18 Tf 50 740 Td (Demo application document) Tj ET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n$stream\nendstream",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n$object\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n$xrefOffset\n%%EOF";

    if (file_put_contents($path, $pdf) === false) {
        throw new RuntimeException('Unable to write the demo application document.');
    }
}

$pdo = db();
$accountTypeColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'account_type'")->fetch(PDO::FETCH_ASSOC);
if (!$accountTypeColumn || stripos((string)$accountTypeColumn['Type'], 'varchar') === false) {
    throw new RuntimeException('Apply the campus and staff-role migrations before seeding demo users.');
}
if (!isset(demoColumns($pdo, 'user_documents')['status'])) {
    throw new RuntimeException('Apply migration_add_user_document_review_status.sql before seeding demo documents.');
}

$campus = $pdo->query("SELECT id FROM campuses WHERE LOWER(status) = 'active' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$campus) {
    throw new RuntimeException('Seed campuses before running the demo data seeder.');
}
$campusId = (int)$campus['id'];

$degreeStmt = $pdo->prepare("SELECT program_name, id FROM degree_programs WHERE campus_id = ? AND LOWER(status) = 'active' ORDER BY id LIMIT 1");
$degreeStmt->execute([$campusId]);
$degree = $degreeStmt->fetch(PDO::FETCH_ASSOC);
if (!$degree) {
    throw new RuntimeException('Seed at least one active degree program for the first active campus.');
}

$today = new DateTimeImmutable('today');
$passwordHash = password_hash($demoPassword, PASSWORD_DEFAULT);
$documentFullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'user_docs' . DIRECTORY_SEPARATOR . 'demo-defense-document.pdf';
$documentRelativePath = 'uploads/user_docs/demo-defense-document.pdf';

try {
    $pdo->beginTransaction();

    $countryId = demoFindId($pdo, 'countries', 'country_name', 'Demo Republic');
    if ($countryId === 0) {
        $countryId = demoInsert($pdo, 'countries', [
            'country_name' => 'Demo Republic',
            'country_code' => 'DM',
            'status' => 'Active',
        ]);
    }

    $categoryId = demoFindId($pdo, 'program_category', 'category_name', 'Demo Exchange');
    if ($categoryId === 0) {
        demoInsert($pdo, 'program_category', [
            'category_name' => 'Demo Exchange',
            'description' => 'Sample program type for the defense demonstration.',
            'status' => 'Active',
        ]);
    }

    $partnerName = 'Demo International University';
    $partnerId = demoFindId($pdo, 'partners', 'institution_name', $partnerName);
    if ($partnerId === 0) {
        $partnerId = demoInsert($pdo, 'partners', [
            'institution_name' => $partnerName,
            'country_id' => $countryId,
            'status' => 'Active',
        ]);
    }

    $programName = 'Demo Student Exchange';
    $programData = [
        'program_name' => $programName,
        'program_type' => 'Demo Exchange',
        'program_types' => json_encode(['Demo Exchange']),
        'eligible_department' => (string)$degree['program_name'],
        'eligible_account_type' => 'Student',
        'eligible_participant_types' => json_encode(['student', 'faculty', 'non-teaching']),
        'eligible_campuses' => json_encode([$campusId]),
        'eligible_degree_programs' => json_encode([(int)$degree['id']]),
        'country_id' => $countryId,
        'partner_id' => $partnerId,
        'partner_institution' => $partnerName,
        'status' => 'Active',
        'mobility_direction' => 'outbound',
        'start_date' => $today->modify('+30 days')->format('Y-m-d'),
        'end_date' => $today->modify('+90 days')->format('Y-m-d'),
        'application_start_date' => $today->format('Y-m-d'),
        'application_end_date' => $today->modify('+14 days')->format('Y-m-d'),
        'description' => 'Sample exchange program seeded for a defense demonstration.',
        'slots' => 10,
    ];
    $programId = demoFindId($pdo, 'programs', 'program_name', $programName);
    if ($programId === 0) {
        $programId = demoInsert($pdo, 'programs', $programData);
    } else {
        $updates = array_diff_key($programData, ['program_name' => true]);
        $updates = array_intersect_key($updates, demoColumns($pdo, 'programs'));
        $setSql = implode(', ', array_map(static function (string $column): string {
            return "`$column` = ?";
        }, array_keys($updates)));
        $pdo->prepare("UPDATE programs SET $setSql WHERE id = ?")->execute([...array_values($updates), $programId]);
    }

    $adminId = demoEnsureUser(
        $pdo,
        'Demo',
        'Administrator',
        'demo.superadmin@psuxizn.com',
        'super admin',
        'Student',
        null,
        '',
        $passwordHash
    );

    $participants = [
        ['Demo', 'Student', 'demo.student@psuxizn.com', 'student'],
        ['Demo', 'Faculty', 'demo.faculty@psuxizn.com', 'faculty'],
        ['Demo', 'Staff', 'demo.staff@psuxizn.com', 'non-teaching'],
    ];
    foreach ($participants as [$firstName, $lastName, $email, $accountType]) {
        $userId = demoEnsureUser($pdo, $firstName, $lastName, $email, 'applicant', $accountType, $campusId, (string)$degree['program_name'], $passwordHash);
        $applicationCheck = $pdo->prepare('SELECT id FROM applications WHERE applicant_id = ? AND program_id = ? LIMIT 1');
        $applicationCheck->execute([$userId, $programId]);
        if (!$applicationCheck->fetchColumn()) {
            demoInsert($pdo, 'applications', [
                'applicant_id' => $userId,
                'program_id' => $programId,
                'mobility_type' => 'Outbound',
                'status' => 'submitted',
                'documents_status' => 'pending',
            ]);
        }
    }

    $requirementId = demoFindId($pdo, 'requirements', 'requirement_name', 'Demo Application Document');
    if ($requirementId === 0) {
        $requirementId = demoInsert($pdo, 'requirements', [
            'requirement_name' => 'Demo Application Document',
            'description' => 'Sample document for admin review.',
        ]);
    }
    $programRequirementStmt = $pdo->prepare('SELECT id FROM program_requirements WHERE program_id = ? AND requirement_id = ? LIMIT 1');
    $programRequirementStmt->execute([$programId, $requirementId]);
    $programRequirementId = (int)($programRequirementStmt->fetchColumn() ?: 0);
    if ($programRequirementId === 0) {
        $programRequirementId = demoInsert($pdo, 'program_requirements', [
            'program_id' => $programId,
            'requirement_id' => $requirementId,
            'is_required' => 1,
        ]);
    }
    demoWritePdf($documentFullPath);
    $studentId = demoFindId($pdo, 'users', 'email', 'demo.student@psuxizn.com');
    $documentCheck = $pdo->prepare('SELECT id FROM user_documents WHERE user_id = ? AND requirement_id = ? LIMIT 1');
    $documentCheck->execute([$studentId, $programRequirementId]);
    if (!$documentCheck->fetchColumn()) {
        demoInsert($pdo, 'user_documents', [
            'user_id' => $studentId,
            'requirement_id' => $programRequirementId,
            'file_path' => $documentRelativePath,
            'status' => 'pending',
        ]);
    }

    $announcementTitle = 'Demo Exchange Program Announcement';
    $announcementCheck = $pdo->prepare('SELECT id FROM announcements WHERE title = ? LIMIT 1');
    $announcementCheck->execute([$announcementTitle]);
    if (!$announcementCheck->fetchColumn()) {
        demoInsert($pdo, 'announcements', [
            'title' => $announcementTitle,
            'announcement' => 'Sample announcement seeded for the defense demonstration.',
            'publish_date' => $today->format('Y-m-d'),
            'expiry_date' => $today->modify('+90 days')->format('Y-m-d'),
            'status' => 'ongoing',
        ]);
    }

    $inboundCheck = $pdo->prepare("SELECT id FROM inbound_participants WHERE first_name = 'Demo' AND last_name = 'Visitor' LIMIT 1");
    $inboundCheck->execute();
    if (!$inboundCheck->fetchColumn()) {
        demoInsert($pdo, 'inbound_participants', [
            'first_name' => 'Demo',
            'last_name' => 'Visitor',
            'participant_type' => 'faculty',
            'home_institution' => $partnerName,
            'country_name' => 'Demo Republic',
            'program_id' => $programId,
            'arrival_date' => $today->modify('+30 days')->format('Y-m-d'),
            'departure_date' => $today->modify('+60 days')->format('Y-m-d'),
            'notes' => 'Sample inbound participant record; no login account.',
            'created_by' => $adminId,
        ]);
    }

    $pdo->commit();
    fwrite(STDOUT, "Demo records are ready. Sign-in emails: demo.superadmin@psuxizn.com, demo.student@psuxizn.com, demo.faculty@psuxizn.com, demo.staff@psuxizn.com. Use the password supplied in DEMO_SEED_PASSWORD.\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Demo seed failed: ' . $exception->getMessage());
    fwrite(STDERR, "Demo seed failed: " . $exception->getMessage() . "\n");
    exit(1);
}
