<?php
/**
 * Remove a customer user and related rows (reservations, linked payments, password reset tokens).
 * Walk-in sessions that were linked to this user are unlinked (user_id set to NULL).
 */
function parky_admin_delete_user(PDO $pdo, int $userId): void
{
    $st = $pdo->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }


    $email = $row['email'];


    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            DELETE p FROM payments p
            INNER JOIN reservations r ON r.id = p.session_id AND p.session_type = 'reservation'
            WHERE r.user_id = ?
        ")->execute([$userId]);


        $pdo->prepare('DELETE FROM reservations WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE sessions_walkin SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}



