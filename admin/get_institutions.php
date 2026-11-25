
<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();

    $country_id = filter_input(
        INPUT_GET,
        'country_id',
        FILTER_VALIDATE_INT
    );

    if (!$country_id || $country_id < 1) {
        http_response_code(422);
        echo json_encode([]);
        exit;
    }

    // Resolve an address-table ID or a countries-table ID to the countries FK.
    $resolvedCountryId = 0;
    $stmt = $pdo->prepare("SELECT id FROM countries WHERE id = ? LIMIT 1");
    $stmt->execute([$country_id]);
    $resolvedCountryId = (int)($stmt->fetchColumn() ?: 0);

    if ($resolvedCountryId <= 0) {
        $stmt = $pdo->prepare("SELECT country_name FROM countries_addresses WHERE id = ? LIMIT 1");
        $stmt->execute([$country_id]);
        $countryName = (string)($stmt->fetchColumn() ?: '');

        if ($countryName !== '') {
            $stmt = $pdo->prepare("SELECT id FROM countries WHERE country_name = ? LIMIT 1");
            $stmt->execute([$countryName]);
            $resolvedCountryId = (int)($stmt->fetchColumn() ?: 0);

            if ($resolvedCountryId <= 0) {
                $stmt = $pdo->prepare("INSERT INTO countries (country_name, status) VALUES (?, 'Active')");
                $stmt->execute([$countryName]);
                $resolvedCountryId = (int)$pdo->lastInsertId();
            }
        }
    }

    if ($resolvedCountryId <= 0) {
        http_response_code(404);
        echo json_encode([]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, institution_name
        FROM partners
        WHERE country_id = ?
          AND LOWER(status) = 'active'
        ORDER BY institution_name ASC
    ");

    $stmt->execute([$resolvedCountryId]);

    echo json_encode(
        $stmt->fetchAll(PDO::FETCH_ASSOC),
        JSON_UNESCAPED_UNICODE
    );

} catch (PDOException $e) {
    error_log('get_institution.php: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([]);
}
