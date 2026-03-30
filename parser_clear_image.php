use Bitrix\Main\Loader;

Loader::includeModule("iblock");
Loader::includeModule("catalog");

// ──────────────────────────────────────────────
define('TEST_MODE',          true);
define('TEST_ELEMENT_ID',    649501);
define('TEST_IBLOCK_ID',     125);
define('DRY_RUN',            false);          // true = без реальных изменений

define('STATUS_PROP',        'I_STATUS');
define('MORE_PHOTO_PROP',    'MORE_PHOTO');
define('GLUSHILKA_PATH',     '/upload/glushilka.jpg');

define('BAD_STATUSES', [
    'Выведен из ассортимента',
    'К выведению',
    'Снято с производства'
]);
// ──────────────────────────────────────────────
$logFile = $_SERVER["DOCUMENT_ROOT"] . '/local/log/clear_images_' . date('Y-m-d') . '.log';
function logMsg(string $msg): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . "] $msg\n";
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
function isProductForGlushilka(array $item): bool
{
    // Проверяем статус
    $status = trim((string)($item['PROPERTY_' . STATUS_PROP . '_VALUE'] ?? ''));
    $statusLower = mb_strtolower($status);
    foreach (BAD_STATUSES as $bad) {
        if ($statusLower === mb_strtolower(trim($bad))) {
            return true;
        }
    }
    // Проверяем остаток
    $quantity = (float)($item['CATALOG_QUANTITY'] ?? 0);
    if ($quantity <= 0) {
        return true;
    }
    return false;
}
function hasAnyPicture(array $item): bool
{
    $moreCode = 'PROPERTY_' . MORE_PHOTO_PROP . '_VALUE';
    $more = $item[$moreCode] ?? false;
    $moreCount = 0;
    if (is_array($more)) {
        $moreCount = count($more);
    } elseif (is_string($more) && $more !== '' && $more !== '0') {
        $moreCount = 1;
    }
    return !empty($item['PREVIEW_PICTURE'])
        || !empty($item['DETAIL_PICTURE'])
        || $moreCount > 0;
}
// Основной блок
$iblockIds = [];
if (TEST_MODE) {
    $iblockIds = [TEST_IBLOCK_ID];
    logMsg("ТЕСТОВЫЙ РЕЖИМ - только инфоблок " . TEST_IBLOCK_ID);
} else {
    $res = CCatalog::GetList([], [], false, false, ['IBLOCK_ID']);
    while ($row = $res->Fetch()) {
        $iblockIds[] = (int)$row['IBLOCK_ID'];
    }
    $iblockIds = array_unique($iblockIds);
}
if (empty($iblockIds)) {
    logMsg("Не найдено инфоблоков каталога");
    die();
}
$totalProcessed = 0;
$totalUpdated   = 0;
foreach ($iblockIds as $iblockId) {
    logMsg("Обработка инфоблока $iblockId");
    $filter = [
        'IBLOCK_ID' => $iblockId,
        'ACTIVE'    => 'Y',
    ];
    if (TEST_MODE) {
        $filter['ID'] = TEST_ELEMENT_ID;
    }
    $select = [
        'ID',
        'NAME',
        'PREVIEW_PICTURE',
        'DETAIL_PICTURE',
        'CATALOG_QUANTITY',
        'PROPERTY_' . STATUS_PROP,
        'PROPERTY_' . MORE_PHOTO_PROP,
    ];
    $res = CIBlockElement::GetList([], $filter, false, false, $select);
    while ($item = $res->Fetch()) {
        $totalProcessed++;
        if (!isProductForGlushilka($item)) {
            continue;
        }
        if (!hasAnyPicture($item)) {
            continue;
        }
        $elemId = $item['ID'];
        $name   = $item['NAME'];
        logMsg("Обрабатываем #{$elemId} «{$name}»");

        if (DRY_RUN) {
            $totalUpdated++;
            logMsg("DRY RUN — изменения не применяются");
            continue;
        }
        // Подготовка заглушки
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . GLUSHILKA_PATH;
        if (!file_exists($fullPath) || !is_readable($fullPath)) {
            logMsg("Ошибка: файл заглушки недоступен → $fullPath");
            continue;
        }
        $glushFile = CFile::MakeFileArray($fullPath);
        if (!is_array($glushFile) || empty($glushFile['tmp_name'])) {
            logMsg("Ошибка: CFile::MakeFileArray вернул некорректный массив");
            continue;
        }
        // Удаляем все старые фото в MORE_PHOTO
        CIBlockElement::SetPropertyValuesEx(
            $elemId,
            $iblockId,
            [MORE_PHOTO_PROP => ['del' => 'Y']]
        );
        // Ставим заглушку в анонс и детальную
        $el = new CIBlockElement();
        $success = $el->Update($elemId, [
//            'PREVIEW_PICTURE' => $glushFile,
            'DETAIL_PICTURE'  => $glushFile,
        ]);
        if ($success) {
            logMsg("Успех: #{$elemId} обновлён");
            $totalUpdated++;
        } else {
            logMsg("Ошибка обновления #{$elemId}: " . $el->LAST_ERROR);
        }
        if (TEST_MODE) {
            logMsg("Тестовый режим — останавливаемся после первого элемента");
            break 2;
        }
    }
}
logMsg("Завершено. Просмотрено: $totalProcessed, Обновлено: $totalUpdated");
