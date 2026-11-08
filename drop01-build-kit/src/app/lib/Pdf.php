<?php
// Minimal PDF writer (no library): A4 pages with Helvetica text, lines and filled boxes. Enough for clean invoices.
defined('DROP01') || exit;

final class Pdf {
    private array $pages = [];
    private string $cur = '';
    public const W = 595.28, H = 841.89;
    // Helvetica glyph widths for ASCII 32–126 (per 1000 em); other characters use 556.
    private const WIDTHS = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];

    public function addPage(): void { if ($this->cur !== '') $this->pages[] = $this->cur; $this->cur = ' '; }

    private static function enc(string $s): string {
        $s = iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: '';
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    public static function width(string $s, float $size, bool $bold = false): float {
        $w = 0;
        foreach (str_split(iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: '') as $c) { $o = ord($c); $w += self::WIDTHS[$o - 32] ?? 556; }
        return $w * $size / 1000 * ($bold ? 1.05 : 1);
    }

    /** Text with its top-left near (x, y) in points from the top of the page. align: L, R (x is the right edge) or C. */
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, string $align = 'L', array $rgb = [0.07, 0.07, 0.09]): void {
        if ($align === 'R') $x -= self::width($s, $size, $bold);
        if ($align === 'C') $x -= self::width($s, $size, $bold) / 2;
        $this->cur .= sprintf("BT %.3F %.3F %.3F rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $rgb[0], $rgb[1], $rgb[2], $bold ? 'F2' : 'F1', $size, $x, self::H - $y - $size, self::enc($s));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.5, array $rgb = [0.8, 0.8, 0.82]): void {
        $this->cur .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $rgb[0], $rgb[1], $rgb[2], $w, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void {
        $this->cur .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $rgb[0], $rgb[1], $rgb[2], $x, self::H - $y - $h, $w, $h);
    }

    public function output(): string {
        if ($this->cur !== '') { $this->pages[] = $this->cur; $this->cur = ''; }
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->pages as $content) {
            $objs[$n] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $n + 1);
            $objs[$n + 1] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            $kids[] = "$n 0 R"; $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $xref = [];
        foreach ($objs as $i => $o) { $xref[$i] = strlen($out); $out .= "$i 0 obj\n$o\nendobj\n"; }
        $start = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($xref as $off) $out .= sprintf("%010d 00000 n \n", $off);
        return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$start\n%%EOF\n";
    }
}
