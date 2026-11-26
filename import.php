<?php
// import.php: модуль імпорту прайс-листів (Микита)
require_once __DIR__ . '/db.php';

$errors = [];   // список проблем (рядки, які не вдалося імпортувати)
$stats  = null; // підсумок імпорту

// ---------- Допоміжні функції ----------

// Приводимо текст до UTF-8 (прибираємо BOM, за потреби перекодовуємо з Windows-1251)
function toUtf8(string $text): string {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1251');
    }
    return $text;
}

// Артикул: великі літери, без пробілів, дефісів і підкреслень ("xp-58" -> "XP58")
function normalizeSku(string $sku): string {
    $sku = mb_strtoupper(trim($sku), 'UTF-8');
    return preg_replace('/[\s\-_]+/u', '', $sku);
}

// Ціна: "1 200,50 грн" -> 1200.50. Якщо не вдалося, повертаємо null
function normalizePrice(string $raw): ?float {
    $raw = str_replace(["\xC2\xA0", ' '], '', $raw);   // пробіли та нерозривні пробіли
    $raw = str_replace(',', '.', $raw);                // кома -> крапка
    $raw = preg_replace('/[^0-9.]/', '', $raw);        // лишаємо цифри й крапку
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $price = round((float)$raw, 2);
    return ($price > 0 && $price < 99999999) ? $price : null;
}

// ---------- Обробка форми ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 1. Перевірка файлу
        if (!isset($_FILES['pricefile']) || $_FILES['pricefile']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Файл не завантажено. Оберіть CSV-файл.');
        }
        $ext = strtolower(pathinfo($_FILES['pricefile']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            throw new Exception('Дозволені лише файли .csv');
        }

        // 2. Постачальник: або існуючий зі списку, або новий
        $newName = trim($_POST['new_supplier'] ?? '');
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        if ($newName !== '') {
            $stmt = $pdo->prepare(
                'INSERT INTO suppliers (name) VALUES (:name)
                 ON CONFLICT (name) DO UPDATE SET name = EXCLUDED.name
                 RETURNING id'
            );
            $stmt->execute([':name' => $newName]);
            $supplierId = (int)$stmt->fetchColumn();
        }
        if ($supplierId <= 0) {
            throw new Exception('Оберіть постачальника або впишіть нового.');
        }

        // 3. Читаємо файл
        $text  = toUtf8(file_get_contents($_FILES['pricefile']['tmp_name']));
        $lines = preg_split('/\R/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (count($lines) < 2) {
            throw new Exception('У файлі немає даних (потрібен заголовок і хоча б один рядок).');
        }
        // Роздільник: ; або ,
        $delimiter = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        array_shift($lines); // пропускаємо рядок із заголовками

        // 4. Підготовлені запити (захист від SQL-ін'єкцій)
        $stmtProduct = $pdo->prepare(
            'INSERT INTO products (sku, name) VALUES (:sku, :name)
             ON CONFLICT (sku) DO UPDATE SET name = products.name
             RETURNING id'
        );
        $stmtPrice = $pdo->prepare(
            'INSERT INTO prices (product_id, supplier_id, price) VALUES (:pid, :sid, :price)
             ON CONFLICT (product_id, supplier_id)
             DO UPDATE SET price = EXCLUDED.price, updated_at = CURRENT_TIMESTAMP'
        );

        // 5. Запис у БД однією транзакцією
        $total = 0;
        $ok    = 0;
        $pdo->beginTransaction();
        foreach ($lines as $i => $line) {
            $total++;
            $lineNo = $i + 2; // номер рядка у файлі (з урахуванням заголовка)
            $row = str_getcsv($line, $delimiter);

            if (count($row) < 3) {
                $errors[] = "Рядок $lineNo: потрібно 3 колонки (Артикул;Назва;Ціна)";
                continue;
            }
            $sku   = normalizeSku($row[0]);
            $name  = trim($row[1]);
            $price = normalizePrice($row[2]);

            if ($sku === '' || $name === '') {
                $errors[] = "Рядок $lineNo: порожній артикул або назва";
                continue;
            }
            if ($price === null) {
                $errors[] = "Рядок $lineNo: некоректна ціна «" . $row[2] . "»";
                continue;
            }

            $stmtProduct->execute([':sku' => $sku, ':name' => $name]);
            $productId = (int)$stmtProduct->fetchColumn();
            $stmtPrice->execute([':pid' => $productId, ':sid' => $supplierId, ':price' => $price]);
            $ok++;
        }

        // 6. Журнал імпорту
        $stmtLog = $pdo->prepare(
            'INSERT INTO import_log (supplier_id, filename, rows_total, rows_ok)
             VALUES (:sid, :fn, :total, :ok)'
        );
        $stmtLog->execute([
            ':sid' => $supplierId,
            ':fn' => $_FILES['pricefile']['name'],
            ':total' => $total,
            ':ok' => $ok,
        ]);
        $pdo->commit();

        $stats = ['total' => $total, 'ok' => $ok, 'failed' => $total - $ok];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $e->getMessage();
    }
}

// ---------- Дані для сторінки ----------
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
$log = $pdo->query(
    'SELECT l.imported_at, s.name AS supplier, l.filename, l.rows_total, l.rows_ok
     FROM import_log l JOIN suppliers s ON s.id = l.supplier_id
     ORDER BY l.imported_at DESC LIMIT 10'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Імпорт прайс-листів</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; max-width: 900px; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f4f4f4; }
        .ok { background: #e6f7e6; padding: 10px; }
        .err { background: #fdecea; padding: 10px; margin-top: 10px; }
        label { display: block; margin-top: 12px; }
    </style>
</head>
<body>
<h2>Імпорт прайс-листа постачальника</h2>

<form method="POST" enctype="multipart/form-data">
    <label>Постачальник:
        <select name="supplier_id">
            <option value="0">— оберіть —</option>
            <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>або новий постачальник: <input type="text" name="new_supplier"></label>
    <label>CSV-файл (Артикул;Назва;Ціна): <input type="file" name="pricefile" accept=".csv" required></label>
    <p><button type="submit">Імпортувати</button></p>
</form>

<?php if ($stats): ?>
    <div class="ok">
        Імпорт завершено. Рядків: <?= $stats['total'] ?>,
        успішно: <?= $stats['ok'] ?>, з помилками: <?= $stats['failed'] ?>.
    </div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="err">
        <b>Проблеми:</b>
        <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<h3>Останні імпорти</h3>
<table>
    <tr><th>Дата</th><th>Постачальник</th><th>Файл</th><th>Рядків</th><th>Успішно</th></tr>
    <?php foreach ($log as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['imported_at']) ?></td>
            <td><?= htmlspecialchars($r['supplier']) ?></td>
            <td><?= htmlspecialchars($r['filename']) ?></td>
            <td><?= (int)$r['rows_total'] ?></td>
            <td><?= (int)$r['rows_ok'] ?></td>
        </tr>
    <?php endforeach; ?>
</table>
</body>
</html>