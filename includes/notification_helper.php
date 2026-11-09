<?php
function createNotification($pdo, $userId, $type, $title, $message) {
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, is_read, created_at) 
        VALUES (?, ?, ?, ?, 0, NOW())
    ");
    return $stmt->execute([$userId, $type, $title, $message]);
}
?>