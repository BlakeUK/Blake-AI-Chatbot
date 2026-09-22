<?php
// src/Products/LeafletPdf.php
// The page layout for a Blake UK technical data sheet, matching the printed
// house style: navy header band with the logo, product title and code, intro,
// images beside a grey "Technical specification" panel, highlight strip, key
// features, then the disclaimer and footer. No prices anywhere.

declare(strict_types=1);

require_once __DIR__ . '/Vendor/fpdf.php';

class LeafletPdf extends FPDF
{
    public string $category = 'PRODUCT';
    private const NAVY = [26, 35, 71];
    private const BLUE = [43, 87, 195];
    private const GREY_BG = [243, 245, 249];
    private const GREY_TX = [110, 118, 135];

    private function t(string $s): string
    {
        $s = str_replace(['–', '—', '’', '‘', '“', '”', '·', '×', 'µ', '≤', '≥', '±'], ['-', '-', "'", "'", '"', '"', '-', 'x', 'u', '<=', '>=', '+/-'], $s);
        return (string)iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
    }

    public function Header(): void
    {
        $this->SetFillColor(...self::NAVY);
        $this->Rect(0, 0, 210, 22, 'F');
        $logo = ROOT . '/public/widget/img/blake-uk-logo.png';
        if (is_file($logo)) {
            $this->Image($logo, 14, 5, 42);
        } else {
            $this->SetXY(14, 8); $this->SetFont('Helvetica', 'B', 16); $this->SetTextColor(255); $this->Cell(50, 6, $this->t('blake UK'));
        }
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(255);
        $this->SetXY(120, 8.5);
        $this->Cell(76, 5, $this->t($this->category . '  |  TECHNICAL DATA'), 0, 0, 'R');
        $this->SetTextColor(0);
        $this->SetY(28);
    }

    public function Footer(): void
    {
        $this->SetY(-16);
        $this->SetDrawColor(...self::NAVY);
        $this->SetLineWidth(0.4);
        $this->Line(14, $this->GetY(), 196, $this->GetY());
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(...self::GREY_TX);
        $this->SetXY(14, $this->GetY() + 2);
        $this->Cell(60, 4, $this->t('Blake UK Ltd  |  Sheffield S3 9PT'), 0, 0, 'L');
        $this->Cell(62, 4, $this->t('+44 (0)114 223 5000  |  blake-uk.com'), 0, 0, 'C');
        $this->Cell(60, 4, $this->t('Issued ' . date('j M Y') . '  |  Page ' . $this->PageNo()), 0, 0, 'R');
        $this->SetTextColor(0);
    }

    public function body(array $d): void
    {
        // Title, code and subtitle
        $this->SetFont('Helvetica', 'B', 24);
        $this->SetXY(14, 30);
        $this->MultiCell(182, 10, $this->t($d['name']), 0, 'L');
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetTextColor(...self::BLUE);
        $this->SetX(14);
        $this->Cell($this->GetStringWidth($this->t($d['code'])) + 1, 6, $this->t($d['code']));
        $this->SetTextColor(...self::GREY_TX);
        $this->SetFont('Helvetica', '', 10);
        $sub = trim((string)($d['title'] ?? ''));
        if ($sub !== '' && $sub !== $d['name']) $this->Cell(140, 6, $this->t('  |  ' . mb_substr($sub, 0, 90)));
        $this->Ln(9);

        // Intro
        $this->SetTextColor(40);
        $this->SetFont('Helvetica', '', 9.5);
        $this->SetX(14);
        $this->MultiCell(182, 4.6, $this->t($d['intro']));
        $this->Ln(3);

        $top = $this->GetY();

        // Specification panel (right)
        $px = 112; $pw = 84;
        $rows = $d['specs'];
        $panelH = 14 + count($rows) * 8.4 + 4;
        $this->SetFillColor(...self::GREY_BG);
        $this->Rect($px, $top, $pw, $panelH, 'F');
        $this->SetXY($px + 5, $top + 4);
        $this->SetFont('Helvetica', 'B', 12.5);
        $this->SetTextColor(...self::BLUE);
        $this->Cell($pw - 10, 7, $this->t('Technical specification'));
        $y = $top + 13;
        foreach ($rows as $label => $value) {
            // Value first (it must never be squeezed), then as much of the
            // label as fits, shrinking the label a little if it is long.
            $this->SetFont('Helvetica', 'B', 9);
            $vw = min(44, $this->GetStringWidth($this->t($value)) + 1);
            $lw = $pw - 10 - $vw - 3;
            $this->SetTextColor(60, 70, 95);
            $this->SetXY($px + 5, $y);
            $size = 8.5;
            $this->SetFont('Helvetica', 'B', $size);
            $lt = $this->t($label);
            while ($this->GetStringWidth($lt) > $lw && $size > 6.5) { $size -= 0.5; $this->SetFont('Helvetica', 'B', $size); }
            if ($this->GetStringWidth($lt) > $lw) {
                while (strlen($lt) > 4 && $this->GetStringWidth($lt . '...') > $lw) $lt = substr($lt, 0, -1);
                $lt .= '...';
            }
            $this->Cell($lw, 6, $lt);
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetTextColor(20, 25, 45);
            $this->SetXY($px + $pw - 5 - $vw, $y);
            $this->Cell($vw, 6, $this->t($value), 0, 0, 'R');
            $this->SetDrawColor(220, 224, 232);
            $this->SetLineWidth(0.2);
            $this->Line($px + 5, $y + 7, $px + $pw - 5, $y + 7);
            $y += 8.4;
        }

        // Images (left)
        $imgs = $d['images'];
        $files = [];
        foreach ($imgs as $u) { $f = self::jpeg($u); if ($f) $files[] = $f; }
        $ix = 14; $iw = 92;
        if ($files) {
            $mainH = min(62, $panelH - 20);
            $this->fit($files[0], $ix, $top + 2, $iw * 0.62, $mainH);
            $sx = $ix + $iw * 0.64;
            $sy = $top + 4;
            foreach (array_slice($files, 1, 2) as $f) {
                $this->fit($f, $sx, $sy, 30, 26);
                $sy += 30;
            }
        }
        $this->SetY($top + $panelH + 6);

        // Highlight strip: the first four specifications
        // Short, punchy specs only - long values would be cut off.
        $cells = [];
        foreach ($rows as $l => $v) {
            if (mb_strlen($v) <= 16 && mb_strlen($l) <= 28) $cells[$l] = $v;
            if (count($cells) >= 4) break;
        }
        if ($cells) {
            $y = $this->GetY();
            $w = 182 / max(1, count($cells));
            $i = 0;
            foreach ($cells as $label => $value) {
                $x = 14 + $i * $w;
                $this->SetFillColor(...($i % 2 ? self::BLUE : self::NAVY));
                $this->Rect($x, $y, $w, 15, 'F');
                $this->SetTextColor(255);
                $this->SetFont('Helvetica', 'B', 9);
                $this->SetXY($x, $y + 2.5);
                $this->Cell($w, 5, $this->t(strtoupper(mb_substr($value, 0, 22))), 0, 0, 'C');
                $this->SetFont('Helvetica', '', 7.5);
                $this->SetXY($x, $y + 8);
                $this->Cell($w, 4.5, $this->t(mb_substr($label, 0, 30)), 0, 0, 'C');
                $i++;
            }
            $this->SetY($y + 21);
        }

        // Key features
        if ($d['bullets']) {
            $this->SetTextColor(...self::BLUE);
            $this->SetFont('Helvetica', 'B', 12);
            $this->SetX(14);
            $this->Cell(182, 7, $this->t('Key features'));
            $this->Ln(8);
            $this->SetFont('Helvetica', '', 9);
            $this->SetTextColor(40);
            $half = (int)ceil(count($d['bullets']) / 2);
            $cols = [array_slice($d['bullets'], 0, $half), array_slice($d['bullets'], $half)];
            $startY = $this->GetY();
            $maxY = $startY;
            foreach ($cols as $c => $list) {
                $y = $startY;
                foreach ($list as $b) {
                    $this->SetXY(14 + $c * 91, $y);
                    $this->Cell(4, 4.6, $this->t('-'));
                    $this->MultiCell(84, 4.6, $this->t($b));
                    $y = $this->GetY() + 1;
                }
                $maxY = max($maxY, $y);
            }
            $this->SetY($maxY + 3);
        }

        // Source + disclaimer
        $this->SetFillColor(238, 242, 250);
        $y = $this->GetY();
        $this->Rect(14, $y, 182, 9, 'F');
        $this->SetXY(16, $y + 2);
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->SetTextColor(...self::BLUE);
        $this->Cell(24, 5, $this->t('SOURCE'));
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(40);
        $this->Cell(150, 5, $this->t($d['url']));
        $this->SetY($y + 12);
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(...self::GREY_TX);
        $this->SetX(14);
        $this->MultiCell(182, 3.6, $this->t(\Products\Leaflet::DISCLAIMER));
        $this->SetTextColor(0);
    }

    // Draws an image inside a box, keeping its proportions and centring it.
    private function fit(string $file, float $x, float $y, float $w, float $h): void
    {
        $size = @getimagesize($file);
        if (!$size) return;
        [$iw, $ih] = $size;
        $scale = min($w / $iw, $h / $ih);
        $dw = $iw * $scale; $dh = $ih * $scale;
        $this->Image($file, $x + ($w - $dw) / 2, $y + ($h - $dh) / 2, $dw, $dh, 'JPG');
    }

    // Downloads an image and converts it to a flat JPEG (PDF-safe).
    public static function jpeg(string $url): ?string
    {
        $cache = \Products\Leaflet::dir() . '/img-' . sha1($url) . '.jpg';
        if (is_file($cache)) return $cache;
        try {
            $r = \Http\SafeFetcher::get($url, 20, 8, 'Mozilla/5.0 (compatible; BlakeUKSupport/1.0)');
            if (!$r['ok'] || strlen((string)$r['body']) < 200) return null;
            $im = @imagecreatefromstring((string)$r['body']);
            if (!$im) return null;
            $w = imagesx($im); $h = imagesy($im);
            $flat = imagecreatetruecolor($w, $h);
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagealphablending($flat, true);
            imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
            imagejpeg($flat, $cache, 88);
            imagedestroy($im); imagedestroy($flat);
            return is_file($cache) ? $cache : null;
        } catch (\Throwable $e) { return null; }
    }
}
