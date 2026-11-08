<?php
// Admin JSON API for Texts: every customer-facing text with its original, saving changes, the downloadable texts file and uploading it back.
defined('DROP01') || exit;

final class TextsApi {
    public static function routes(Router $r): void {
        $r->get('/api/admin/texts', [self::class, 'list']);
        $r->post('/api/admin/texts', [self::class, 'save']);
        $r->get('/api/admin/texts/export', [self::class, 'export']);
        $r->post('/api/admin/texts/import', [self::class, 'import']);
        $r->post('/api/admin/texts/reset', [self::class, 'reset']);
    }

    public static function list(): void {
        Auth::require('texts');
        Http::json(Texts::forAdmin());
    }

    /** {changes: {key: text | [[q, a]…] | null}}: null (or the original, or empty) brings the original back. */
    public static function save(): void {
        Auth::require('texts'); Auth::checkCsrf();
        $ch = Http::body()['changes'] ?? null;
        if (!is_array($ch) || !$ch) Http::error('Nothing to save.', 422);
        try { $n = Texts::save($ch); } catch (InvalidArgumentException $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('texts.save', 'texts', null, ['keys' => array_keys($ch)]);
        Http::json(['ok' => true, 'changed' => $n]);
    }

    public static function export(): void {
        Auth::require('texts');
        Auth::audit('texts.export', 'texts');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . (Products::slugify(Settings::get('store_name')) ?: 'store') . '-texts-' . date('Y-m-d') . '.json"');
        header('Cache-Control: no-store');
        echo json_encode(Texts::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** A texts file, uploaded as "file" (form) or sent as JSON. */
    public static function import(): void {
        Auth::require('texts'); Auth::checkCsrf();
        $raw = null;
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            if ($_FILES['file']['size'] > 2_000_000) Http::error('This file is too big for a texts file.', 422);
            $raw = file_get_contents($_FILES['file']['tmp_name']);
        }
        $data = $raw === null ? Http::body() : json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw), true);
        if (!is_array($data)) Http::error('This is not a texts file. ' . ($raw !== null ? 'Check the commas and quotes (JSON error: ' . json_last_error_msg() . ').' : ''), 422);
        try { $r = Texts::import($data); } catch (InvalidArgumentException $e) { Http::error($e->getMessage(), 422); }
        Auth::audit('texts.import', 'texts', null, ['changed' => $r['changed'], 'skipped' => count($r['skipped'])]);
        Http::json($r);
    }

    public static function reset(): void {
        Auth::require('texts'); Auth::checkCsrf();
        Texts::resetAll();
        Auth::audit('texts.reset', 'texts');
        Http::json(['ok' => true]);
    }
}
