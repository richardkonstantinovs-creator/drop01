<?php
// Image uploads: finfo MIME check, re-encode through GD (strips EXIF), resize to 1600px and 600px, WebP with JPEG fallback.
defined('DROP01') || exit;

final class Images {
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    public const MAX_BYTES = 15 * 1024 * 1024;

    /** Processes an uploaded file ($_FILES entry). Returns ['path','path_sm','w','h'] relative to the web root, or throws. */
    public static function store(array $file, string $sub = 'products'): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException(self::uploadError($file['error'] ?? UPLOAD_ERR_NO_FILE));
        if ($file['size'] > self::MAX_BYTES) throw new RuntimeException('That photo is larger than 15 MB.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, self::MIMES, true)) throw new RuntimeException('Please upload a JPG, PNG or WebP photo.');
        $src = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if (!$src) throw new RuntimeException('This photo could not be read.');
        if ($mime === 'image/jpeg') $src = self::autoRotate($src, $file['tmp_name']);

        $dir = ROOT . '/uploads/' . $sub . '/' . gmdate('Y-m');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('The uploads folder is not writable.');
        $name = Crypto::token(12);
        $webp = function_exists('imagewebp');
        $ext = $webp ? 'webp' : 'jpg';
        [$w, $h] = [imagesx($src), imagesy($src)];
        $out = [];
        foreach (['path' => 1600, 'path_sm' => 600] as $key => $max) {
            $img = self::resize($src, $max);
            $f = "$dir/$name-$max.$ext";
            $ok = $webp ? imagewebp($img, $f, 82) : imagejpeg($img, $f, 84);
            if ($img !== $src) imagedestroy($img);
            if (!$ok) throw new RuntimeException('Saving the photo failed.');
            $out[$key] = substr($f, strlen(ROOT));
        }
        $scale = min(1, 1600 / max($w, $h));
        imagedestroy($src);
        return $out + ['w' => (int) round($w * $scale), 'h' => (int) round($h * $scale)];
    }

    private static function resize($src, int $max) {
        [$w, $h] = [imagesx($src), imagesy($src)];
        $k = min(1, $max / max($w, $h));
        if ($k >= 1) return $src;
        $nw = (int) round($w * $k); $nh = (int) round($h * $k);
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $dst;
    }

    /** Phone photos store rotation in EXIF; apply it before EXIF is discarded by re-encoding. */
    private static function autoRotate($img, string $path) {
        if (!function_exists('exif_read_data')) return $img;
        $o = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        return $deg ? imagerotate($img, $deg, 0) : $img;
    }

    private static function uploadError(int $code): string {
        return [UPLOAD_ERR_INI_SIZE => 'That photo is too large for the server upload limit.', UPLOAD_ERR_FORM_SIZE => 'That photo is too large.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.', UPLOAD_ERR_NO_FILE => 'No photo was chosen.'][$code] ?? 'The upload failed.';
    }

    public static function delete(?string $rel): void {
        if ($rel && str_starts_with($rel, '/uploads/') && !str_contains($rel, '..')) @unlink(ROOT . $rel);
    }
}
