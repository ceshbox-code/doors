<?php
declare(strict_types=1);

/** Начальные виды работ (ТЗ 3.2, 11.3) и счётчик номеров дверей. Идемпотентно. */
return static function (PDO $pdo): void {
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO work_types (name, description, unit, category, door_required, allow_multiple_per_door, is_active)
         VALUES (:name, :description, :unit, :category, 1, 0, 1)'
    );
    foreach ([
        ['Ремонт двери',  'Ремонт межкомнатной двери',  'шт', 'repair'],
        ['Упаковка двери', 'Упаковка межкомнатной двери', 'шт', 'packing'],
    ] as [$name, $description, $unit, $category]) {
        $stmt->execute(['name' => $name, 'description' => $description, 'unit' => $unit, 'category' => $category]);
    }

    $pdo->exec("INSERT IGNORE INTO door_sequences (name, last_value) VALUES ('door', 0)");
};
