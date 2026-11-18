<?php
// Sem declare(strict_types): porte fiel do gerador de PDF que já rodava em produção (pdf-simples.php), com a mesma tipagem solta.

namespace App\Services;

/**
 * Gerador de PDF mínimo, sem biblioteca externa (o antigo pdf-simples.php / classe PdfSimples).
 *   - páginas A4 em paisagem; retângulos, linhas e texto em Helvetica (acentos em WinAnsi), com compressão Flate quando há zlib;
 *   - sem mbstring nem iconv (roda igual no ambiente local e na HostGator).
 * O desenho dos relatórios fica em RelatorioClosersPdf; esta classe só sabe desenhar e montar o arquivo.
 */
final class PdfWriter
{
    public $W = 841.89;          // A4 paisagem, em pontos
    public $H = 595.28;
    private $pages = [];         // operadores de cada página
    private $cur = -1;
    private $titulo;
    private $autor;

    // Larguras (milésimos de em) de Helvetica e Helvetica-Bold nos caracteres 32..126
    private static $WR = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,
        667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,
        556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private static $WB = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,
        722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,
        556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    public function __construct(string $titulo = '', string $autor = '') { $this->titulo = $titulo; $this->autor = $autor; }

    // ── Páginas ──────────────────────────────────────────────────────────
    public function addPage(): void { $this->pages[] = ''; $this->cur = count($this->pages) - 1; }
    public function pageCount(): int { return count($this->pages); }
    public function gotoPage(int $i): void { $this->cur = $i; }

    private function op(string $s): void { $this->pages[$this->cur] .= $s . "\n"; }
    private function n($v): string { return sprintf('%.2F', $v); }
    private function col(array $c, bool $stroke = false): string {
        return sprintf('%.3F %.3F %.3F %s', $c[0] / 255, $c[1] / 255, $c[2] / 255, $stroke ? 'RG' : 'rg');
    }
    /** y medido do topo da página (como na tela) → coordenada do PDF */
    private function Y($y): float { return $this->H - $y; }

    // ── Desenho ──────────────────────────────────────────────────────────
    public function rect(float $x, float $y, float $w, float $h, ?array $fill, ?array $stroke = null, float $lw = 0.6): void {
        $this->roundRect($x, $y, $w, $h, 0, $fill, $stroke, $lw);
    }
    public function roundRect(float $x, float $y, float $w, float $h, float $r, ?array $fill, ?array $stroke = null, float $lw = 0.6): void {
        if ($fill === null && $stroke === null) return;
        $r  = max(0, min($r, $w / 2, $h / 2));
        $yb = $this->Y($y + $h);
        $o  = '';
        if ($fill)   $o .= $this->col($fill) . ' ';
        if ($stroke) $o .= $this->col($stroke, true) . ' ' . $this->n($lw) . ' w ';
        if ($r <= 0.01) {
            $o .= sprintf('%s %s %s %s re', $this->n($x), $this->n($yb), $this->n($w), $this->n($h));
        } else {
            $k = 0.5523 * $r;
            $o .= sprintf('%s %s m %s %s l ', $this->n($x + $r), $this->n($yb), $this->n($x + $w - $r), $this->n($yb));
            $o .= sprintf('%s %s %s %s %s %s c ', $this->n($x + $w - $r + $k), $this->n($yb), $this->n($x + $w), $this->n($yb + $r - $k), $this->n($x + $w), $this->n($yb + $r));
            $o .= sprintf('%s %s l ', $this->n($x + $w), $this->n($yb + $h - $r));
            $o .= sprintf('%s %s %s %s %s %s c ', $this->n($x + $w), $this->n($yb + $h - $r + $k), $this->n($x + $w - $r + $k), $this->n($yb + $h), $this->n($x + $w - $r), $this->n($yb + $h));
            $o .= sprintf('%s %s l ', $this->n($x + $r), $this->n($yb + $h));
            $o .= sprintf('%s %s %s %s %s %s c ', $this->n($x + $r - $k), $this->n($yb + $h), $this->n($x), $this->n($yb + $h - $r + $k), $this->n($x), $this->n($yb + $h - $r));
            $o .= sprintf('%s %s l ', $this->n($x), $this->n($yb + $r));
            $o .= sprintf('%s %s %s %s %s %s c h', $this->n($x), $this->n($yb + $r - $k), $this->n($x + $r - $k), $this->n($yb), $this->n($x + $r), $this->n($yb));
        }
        $this->op('q ' . $o . ' ' . ($fill && $stroke ? 'B' : ($fill ? 'f' : 'S')) . ' Q');
    }
    public function line(float $x1, float $y1, float $x2, float $y2, array $color, float $lw = 0.6): void {
        $this->op(sprintf('q %s %s w %s %s m %s %s l S Q', $this->col($color, true), $this->n($lw),
            $this->n($x1), $this->n($this->Y($y1)), $this->n($x2), $this->n($this->Y($y2))));
    }

    // ── Texto ────────────────────────────────────────────────────────────
    /** UTF-8 → bytes WinAnsi (cp1252). O que não for do alfabeto latino vira "?". */
    public static function enc(string $s): string {
        static $extra = [0x20AC => 128, 0x2026 => 133, 0x2018 => 145, 0x2019 => 146, 0x201C => 147, 0x201D => 148, 0x2022 => 149, 0x2013 => 150, 0x2014 => 151, 0x2122 => 153];
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) $chars = str_split($s);            // não é UTF-8 válido: segue byte a byte
        $out = '';
        foreach ($chars as $ch) {
            $len = strlen($ch);
            if ($len === 1) { $o = ord($ch); $out .= ($o >= 32 && $o < 127) ? $ch : ' '; continue; }
            if ($len === 2)     $cp = ((ord($ch[0]) & 0x1F) << 6) | (ord($ch[1]) & 0x3F);
            elseif ($len === 3) $cp = ((ord($ch[0]) & 0x0F) << 12) | ((ord($ch[1]) & 0x3F) << 6) | (ord($ch[2]) & 0x3F);
            else                $cp = 0;
            if ($cp >= 0xA0 && $cp <= 0xFF) $out .= chr($cp);
            elseif (isset($extra[$cp]))     $out .= chr($extra[$cp]);
            else                            $out .= '?';
        }
        return $out;
    }
    private static function esc(string $s): string { return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s); }

    /** milésimos de em de um byte WinAnsi (letras acentuadas medem como a letra-base) */
    private static function glyphW(int $b, bool $bold): int {
        $t = $bold ? self::$WB : self::$WR;
        if ($b >= 32 && $b <= 126) return $t[$b - 32];
        static $base = [];
        if (!$base) {
            $map = ['A' => [192, 197], 'C' => [199, 199], 'E' => [200, 203], 'I' => [204, 207], 'N' => [209, 209], 'O' => [210, 214], 'U' => [217, 220], 'Y' => [221, 221],
                    'a' => [224, 229], 'c' => [231, 231], 'e' => [232, 235], 'i' => [236, 239], 'n' => [241, 241], 'o' => [242, 246], 'u' => [249, 252], 'y' => [253, 255]];
            foreach ($map as $ch => $r) for ($i = $r[0]; $i <= $r[1]; $i++) $base[$i] = $ch;
        }
        if (isset($base[$b])) return $t[ord($base[$b]) - 32];
        $fixo = [160 => 278, 170 => 370, 176 => 400, 177 => 584, 183 => 278, 186 => 365, 198 => 1000, 215 => 584, 223 => 611, 230 => 889, 247 => 584,
                 133 => 1000, 149 => 350, 150 => 556, 151 => 1000, 153 => 1000];
        if (isset($fixo[$b])) return $fixo[$b];
        if ($b === 145 || $b === 146) return $bold ? 278 : 222;
        if ($b === 147 || $b === 148) return $bold ? 500 : 333;
        return 556;
    }
    /** largura em pontos de $s (UTF-8) no tamanho $size */
    public function width(string $s, float $size, bool $bold = false): float {
        $b = self::enc($s); $w = 0;
        for ($i = 0, $n = strlen($b); $i < $n; $i++) $w += self::glyphW(ord($b[$i]), $bold);
        return $w * $size / 1000;
    }
    /** corta $s com "…" para caber em $maxW */
    public function fit(string $s, float $maxW, float $size, bool $bold = false): string {
        if ($this->width($s, $size, $bold) <= $maxW) return $s;
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) return $s;
        while ($chars && $this->width(implode('', $chars) . '…', $size, $bold) > $maxW) array_pop($chars);
        return implode('', $chars) . '…';
    }
    /** $y = linha de base do texto (medida do topo). $align: L, C ou R em relação a $x. */
    public function text(float $x, float $y, string $s, float $size = 9, bool $bold = false, array $color = [0, 0, 0], string $align = 'L'): void {
        if ($s === '') return;
        $w = $this->width($s, $size, $bold);
        if ($align === 'R') $x -= $w; elseif ($align === 'C') $x -= $w / 2;
        $this->op(sprintf('BT %s /%s %s Tf %s %s Td (%s) Tj ET', $this->col($color), $bold ? 'F2' : 'F1', $this->n($size),
            $this->n($x), $this->n($this->Y($y)), self::esc(self::enc($s))));
    }

    // ── Saída ────────────────────────────────────────────────────────────
    private static function utf16hex(string $s): string {
        $h = 'FEFF';
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        foreach (($chars === false ? [] : $chars) as $ch) {
            $len = strlen($ch);
            if ($len === 1)     $cp = ord($ch);
            elseif ($len === 2) $cp = ((ord($ch[0]) & 0x1F) << 6) | (ord($ch[1]) & 0x3F);
            elseif ($len === 3) $cp = ((ord($ch[0]) & 0x0F) << 12) | ((ord($ch[1]) & 0x3F) << 6) | (ord($ch[2]) & 0x3F);
            else                $cp = 63;
            $h .= sprintf('%04X', $cp);
        }
        return $h;
    }

    public function output(): string {
        $n = count($this->pages);
        if ($n === 0) $this->addPage();
        $n = count($this->pages);
        $pdf  = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offs = [];
        $put  = function (int $id, string $body) use (&$pdf, &$offs) {
            $offs[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        };
        $kids = [];
        for ($i = 0; $i < $n; $i++) $kids[] = (6 + 2 * $i) . ' 0 R';
        $put(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $put(2, '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>');
        $put(3, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $put(4, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $put(5, '<< /Title <' . self::utf16hex($this->titulo) . '> /Author <' . self::utf16hex($this->autor) . '> /Creator (PdfWriter) /Producer (PdfWriter) >>');
        for ($i = 0; $i < $n; $i++) {
            $pid = 6 + 2 * $i; $cid = $pid + 1;
            $put($pid, sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->n($this->W), $this->n($this->H), $cid));
            $c = $this->pages[$i];
            $z = function_exists('gzcompress') ? gzcompress($c, 6) : false;
            if ($z !== false) $put($cid, '<< /Length ' . strlen($z) . " /Filter /FlateDecode >>\nstream\n" . $z . "\nendstream");
            else              $put($cid, '<< /Length ' . strlen($c) . " >>\nstream\n" . $c . "\nendstream");
        }
        $max  = 5 + 2 * $n;
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) $pdf .= sprintf("%010d 00000 n \n", $offs[$i]);
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }
}
