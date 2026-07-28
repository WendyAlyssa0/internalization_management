<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

try {

    $pdo = db();

    /* =========================
       GET FORM DATA
    ========================= */

    $country = trim($_POST['country'] ?? '');
    $countryManual = trim($_POST['country_manual'] ?? '');

    if ($country === '__other__') {
        $country = $countryManual;
    }

    $institution = trim($_POST['institution_name'] ?? '');
    $institutionManual = trim($_POST['institution_manual'] ?? '');

    if ($institution === '__other__') {
        $institution = $institutionManual;
    }

    $agreement = trim($_POST['agreement_type'] ?? '');
    $status = trim($_POST['status'] ?? 'active');

    $firstName = trim($_POST['contact_first_name'] ?? '');
    $middleName = trim($_POST['contact_mi'] ?? '');
    $lastName = trim($_POST['contact_last_name'] ?? '');

    $email = trim($_POST['contact_email'] ?? '');
    $expiry = trim($_POST['expiry_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($country === '' || $institution === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Country and Institution are required.'
        ]);
        exit;
    }

    /* =========================
       COUNTRY ID
    ========================= */

    $stmt = $pdo->prepare("SELECT id FROM countries WHERE country_name = ?");
    $stmt->execute([$country]);
    $countryId = $stmt->fetchColumn();

    if (!$countryId) {

        $stmt = $pdo->prepare("
            INSERT INTO countries(country_name)
            VALUES(?)
        ");

        $stmt->execute([$country]);

        $countryId = $pdo->lastInsertId();
    }

    /* =========================
       AGREEMENT TYPE ID
    ========================= */

    $agreementId = null;

    if ($agreement !== '') {

        $stmt = $pdo->prepare("
            SELECT id
            FROM agreement_types
            WHERE agreement_name = ?
        ");

        $stmt->execute([$agreement]);

        $agreementId = $stmt->fetchColumn();

        if (!$agreementId) {

            $stmt = $pdo->prepare("
                INSERT INTO agreement_types(agreement_name)
                VALUES(?)
            ");

            $stmt->execute([$agreement]);

            $agreementId = $pdo->lastInsertId();
        }
    }

    /* =========================
       INSERT PARTNER
    ========================= */

    $stmt = $pdo->prepare("
        INSERT INTO partners
        (
            institution_name,
            country_id,
            agreement_type_id,
            contact_first_name,
            contact_middle_name,
            contact_last_name,
            contact_email,
            expiry_date,
            status,
            notes
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $institution,
        $countryId,
        $agreementId,
        $firstName ?: null,
        $middleName ?: null,
        $lastName ?: null,
        $email ?: null,
        $expiry ?: null,
        $status,
        $notes ?: null
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Partner added successfully.'
    ]);

} catch (PDOException $e) {

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

}