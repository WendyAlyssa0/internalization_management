<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

try {

    $pdo = db();

    if (empty($_GET['id'])) {
        echo json_encode([
            "error" => "Missing ID"
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT

            p.id,
            p.institution_name,

            c.country_name AS country,

            p.contact_first_name,
            p.contact_middle_name,
            p.contact_last_name,

            p.contact_email,

            a.agreement_name AS agreement_type,

            p.expiry_date,
            p.notes,
            p.status

        FROM partners p

        LEFT JOIN countries c
            ON p.country_id = c.id

        LEFT JOIN agreement_types a
            ON p.agreement_type_id = a.id

        WHERE p.id = ?
    ");

    $stmt->execute([$_GET['id']]);

    $partner = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$partner) {
        echo json_encode([
            "error" => "Partner not found."
        ]);
        exit;
    }

    echo json_encode($partner);

} catch (PDOException $e) {

    echo json_encode([
        "error" => $e->getMessage()
    ]);

}