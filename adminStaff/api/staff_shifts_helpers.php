<?php

function ensure_staff_shifts_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS staff_shifts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_id INT NOT NULL,
            shift_date DATE NOT NULL,
            logged_in_at DATETIME NOT NULL,
            logged_out_at DATETIME NULL,
            UNIQUE KEY uniq_staff_shift_date (staff_id, shift_date),
            CONSTRAINT fk_staff_shifts_user
                FOREIGN KEY (staff_id) REFERENCES users(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function register_staff_shift_login(PDO $pdo, int $staffId): array
{
    ensure_staff_shifts_schema($pdo);
    if ($staffId <= 0) {
        return [];
    }

    $shiftDate = date('Y-m-d');
    $loggedInAt = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO staff_shifts (staff_id, shift_date, logged_in_at)
         VALUES (:sid, :d, :t)
         ON DUPLICATE KEY UPDATE logged_in_at = VALUES(logged_in_at)'
    );
    $stmt->execute([
        ':sid' => $staffId,
        ':d'   => $shiftDate,
        ':t'   => $loggedInAt,
    ]);

    $fetch = $pdo->prepare(
        'SELECT id, staff_id, shift_date, logged_in_at, logged_out_at
           FROM staff_shifts
          WHERE staff_id = :sid AND shift_date = :d
          LIMIT 1'
    );
    $fetch->execute([':sid' => $staffId, ':d' => $shiftDate]);
    $row = $fetch->fetch();
    if (!$row) {
        return [];
    }

    return [
        'id' => (int)$row['id'],
        'staff_id' => (int)$row['staff_id'],
        'shift_date' => (string)$row['shift_date'],
        'logged_in_at' => (string)$row['logged_in_at'],
        'logged_out_at' => $row['logged_out_at'] ? (string)$row['logged_out_at'] : null,
    ];
}
