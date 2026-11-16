<?php

require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freportsana\Config;
use GlpiPlugin\G4freportsana\Security;
use GlpiPlugin\G4freportsana\Ana\SnapshotStore;
use GlpiPlugin\G4freportsana\Ana\DocxExporter;
use GlpiPlugin\G4freportsana\Ana\XlsxExporter;

@ini_set('display_errors', '0');
@set_time_limit(120);
$owner = Security::currentUserId();
if ($owner <= 0 || !Security::canUse(Config::merged())) {
    http_response_code(403);
    exit('Acesso negado.');
}
$token = $_GET['token'] ?? '';
$format = $_GET['format'] ?? '';
if (!is_string($token) || !in_array($format, ['docx', 'xlsx', 'json'], true)) {
    http_response_code(400);
    exit('Selecione um formato de relatório válido.');
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
try {
    $snapshot = SnapshotStore::load($token, $owner);
    SnapshotStore::assertCurrentRules($snapshot);
    if ($format === 'json') {
        $binary = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $filename = 'ANA-rascunho-' . preg_replace('/[^0-9-]/', '', (string)($snapshot['period']['month'] ?? '')) . '.json';
        $mime = 'application/json';
    } else {
        $exporter = $format === 'docx' ? new DocxExporter() : new XlsxExporter();
        $binary = $exporter->toBinary($snapshot);
        $filename = $exporter->filename($snapshot);
        $mime = $format === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $binary;
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
} catch (RuntimeException $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Não foi possível exportar o relatório. Gere o rascunho novamente.';
}
