<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ArtikelModel;
use finfo;
use GdImage;
use RuntimeException;
use Throwable;

/**
 * Artikelbilder unter `<basis>/artikelbilder/` (Standard WRITEPATH; im Backup als `artikelbilder_<datum>.tar.gz`).
 *
 * Eingang: JPEG, PNG, WebP (WebP nur, wenn GD es lesen kann), höchstens 5 MB und 8000 × 8000 Pixel (Schutz vor
 * Dekompressionsbomben). Der Typ kommt aus `finfo` auf der Datei, nie vom Client. Jedes Bild wird neu codiert
 * (Metadaten fallen weg) und proportional auf höchstens 600 px Kantenlänge verkleinert (nie vergrößert):
 * JPEG Qualität 85, PNG nur bei tatsächlicher Transparenz (mindestens ein Pixel mit Alpha nach dem Skalieren).
 * Die EXIF-Ausrichtung von JPEG-Fotos (Drehung 90/180/270°) wird vor dem Speichern angewendet.
 *
 * Ablauf beim Ändern: Datei schreiben (`schreibe`, Temp-Datei + rename) → in der Artikel-Transaktion `uebernehme`
 * → bei Fehler neue Datei löschen, nach dem Commit die alte (`loescheDatei`).
 */
class Artikelbild
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_KANTE = 600;
    public const MAX_PIXEL = 8000;

    public const MELDUNG_GROESSE = 'Das Bild ist zu groß (max. 5 MB).';
    public const MELDUNG_TYP     = 'Bitte ein JPG-, PNG- oder WebP-Bild hochladen.';

    private const LADER = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
    ];

    private const DATEINAME = '/\A[0-9a-f]{32}\.(jpg|png)\z/';

    private string $verzeichnis;

    /**
     * @param string|null $basis Verzeichnis, unter dem `artikelbilder/` liegt (Standard: WRITEPATH)
     */
    public function __construct(?string $basis = null)
    {
        $this->verzeichnis = rtrim($basis ?? WRITEPATH, '/\\') . '/artikelbilder';
    }

    /**
     * Relative URL des Bildes mit Cache-Buster (`artikelbild/<id>?v=<bild_version>`), null ohne Bild.
     *
     * @param array<string, mixed> $artikel mit `id`, `bild_datei`, `bild_version`
     */
    public static function url(array $artikel): ?string
    {
        if (($artikel['bild_datei'] ?? null) === null) {
            return null;
        }

        return 'artikelbild/' . (int) $artikel['id'] . '?v=' . (int) ($artikel['bild_version'] ?? 0);
    }

    /**
     * Zielgröße bei proportionaler Verkleinerung auf höchstens `$max` px Kantenlänge (nie vergrößern).
     *
     * @return array{0: int, 1: int}
     */
    public static function zielgroesse(int $breite, int $hoehe, int $max = self::MAX_KANTE): array
    {
        $faktor = min(1.0, $max / max($breite, $hoehe));

        return [max(1, (int) round($breite * $faktor)), max(1, (int) round($hoehe * $faktor))];
    }

    /**
     * Prüft Größe, echten Typ und Pixelmaße; null = in Ordnung, sonst deutsche Fehlermeldung.
     */
    public function pruefe(string $tmpPfad, int $groesseBytes): ?string
    {
        $tatsaechlich = is_file($tmpPfad) ? (int) filesize($tmpPfad) : 0;

        if (max($groesseBytes, $tatsaechlich) > self::MAX_BYTES) {
            return self::MELDUNG_GROESSE;
        }

        if ($tatsaechlich === 0) {
            return self::MELDUNG_TYP;
        }

        $typ = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPfad);

        if (! is_string($typ) || ! isset(self::LADER[$typ]) || ! self::kannLesen($typ)) {
            return self::MELDUNG_TYP;
        }

        $info = @getimagesize($tmpPfad);

        if ($info === false || $info['mime'] !== $typ || $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_PIXEL || $info[1] > self::MAX_PIXEL) {
            return self::MELDUNG_TYP;
        }

        return null;
    }

    /**
     * Prüft, codiert neu und schreibt die Datei; gibt den neuen Dateinamen zurück (DB noch unverändert).
     *
     * @throws BildAbgelehnt bei ungültigem Bild
     */
    public function schreibe(string $tmpPfad, int $groesseBytes): string
    {
        if (($fehler = $this->pruefe($tmpPfad, $groesseBytes)) !== null) {
            throw new BildAbgelehnt($fehler);
        }

        $typ = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmpPfad);
        $this->speicherFuerDekodieren();
        $quelle = @(self::LADER[$typ])($tmpPfad);

        if (! $quelle instanceof GdImage) {
            throw new BildAbgelehnt(self::MELDUNG_TYP);
        }

        $ziel = $this->verkleinere($quelle);
        unset($quelle);

        if ($typ === 'image/jpeg') {
            $ziel = $this->richteAus($ziel, $tmpPfad);
        }

        $png  = $typ !== 'image/jpeg' && self::hatTransparenz($ziel);
        $name = bin2hex(random_bytes(16)) . ($png ? '.png' : '.jpg');

        if (! is_dir($this->verzeichnis) && ! @mkdir($this->verzeichnis, 0775, true) && ! is_dir($this->verzeichnis)) {
            throw new RuntimeException("Bildverzeichnis {$this->verzeichnis} konnte nicht angelegt werden.");
        }

        $temp = $this->verzeichnis . '/.' . $name . '.tmp';
        $ok   = $png ? imagepng($ziel, $temp, 9) : imagejpeg($ziel, $temp, 85);

        if (! $ok || ! rename($temp, $this->verzeichnis . '/' . $name)) {
            @unlink($temp);

            throw new RuntimeException('Artikelbild konnte nicht gespeichert werden.');
        }

        return $name;
    }

    /**
     * Setzt das Bild des Artikels (null = entfernen), erhöht `bild_version` und protokolliert. Muss in einer
     * Transaktion laufen; gibt den bisherigen Dateinamen zurück (erst nach dem Commit löschen).
     * Entfernen ohne vorhandenes Bild ändert nichts.
     */
    public function uebernehme(int $artikelId, ?string $datei, int $personId): ?string
    {
        $model = new ArtikelModel();
        $zeile = db_connect()->query('SELECT bild_datei, bild_version FROM artikel WHERE id = ? FOR UPDATE', [$artikelId])->getRowArray();

        if ($zeile === null) {
            throw new RuntimeException("Artikel {$artikelId} nicht gefunden.");
        }

        if ($datei === null && $zeile['bild_datei'] === null) {
            return null;
        }

        $version = (int) $zeile['bild_version'] + 1;
        $model->update($artikelId, ['bild_datei' => $datei, 'bild_version' => $version]);
        service('protokollierer')->schreibe($personId, $datei === null ? 'bild_entfernt' : 'bild_geaendert', 'artikel', $artikelId, null, ['bild_version' => $version]);

        return $zeile['bild_datei'];
    }

    /**
     * Neues Bild für einen Artikel (eigene Transaktion). Gibt den neuen Dateinamen zurück.
     *
     * @throws BildAbgelehnt bei ungültigem Bild
     */
    public function speichere(int $artikelId, string $tmpPfad, int $groesseBytes, int $personId): string
    {
        $neu = $this->schreibe($tmpPfad, $groesseBytes);
        $this->wechsle($neu, fn (): ?string => $this->uebernehme($artikelId, $neu, $personId));

        return $neu;
    }

    /**
     * Entfernt das Bild eines Artikels (eigene Transaktion, Datei nach dem Commit gelöscht).
     */
    public function entferne(int $artikelId, int $personId): void
    {
        $this->wechsle(null, fn (): ?string => $this->uebernehme($artikelId, null, $personId));
    }

    /**
     * Artikel-Transaktion mit Bildwechsel: `$arbeit` läuft in `ArtikelModel::transaktion()` und liefert den bisherigen
     * Dateinamen (oder null). Scheitert die Transaktion, wird `$neueDatei` gelöscht; der bisherige erst nach dem Commit.
     *
     * @param callable(): ?string $arbeit
     */
    public function wechsle(?string $neueDatei, callable $arbeit): void
    {
        $alt = null;

        try {
            (new ArtikelModel())->transaktion(static function () use ($arbeit, &$alt): void {
                $alt = $arbeit();
            });
        } catch (Throwable $e) {
            $this->loescheDatei($neueDatei);

            throw $e;
        }

        $this->loescheDatei($alt);
    }

    /**
     * Absoluter Pfad einer gespeicherten Bilddatei; null bei ungültigem Namen oder fehlender Datei.
     */
    public function pfad(?string $datei): ?string
    {
        if ($datei === null || preg_match(self::DATEINAME, $datei) !== 1) {
            return null;
        }

        $pfad = $this->verzeichnis . '/' . $datei;

        return is_file($pfad) ? $pfad : null;
    }

    public function loescheDatei(?string $datei): void
    {
        if (($pfad = $this->pfad($datei)) !== null) {
            @unlink($pfad);
        }
    }

    public static function contentType(string $datei): string
    {
        return str_ends_with($datei, '.png') ? 'image/png' : 'image/jpeg';
    }

    private static function kannLesen(string $typ): bool
    {
        if ($typ !== 'image/webp') {
            return true;
        }

        return function_exists('imagecreatefromwebp') && (gd_info()['WebP Support'] ?? false) === true;
    }

    private function verkleinere(GdImage $quelle): GdImage
    {
        if (! imageistruecolor($quelle)) {
            imagepalettetotruecolor($quelle); // transparenter Paletteneintrag wird zu Alpha
        }

        $breite = imagesx($quelle);
        $hoehe  = imagesy($quelle);
        [$b, $h] = self::zielgroesse($breite, $hoehe);
        $ziel    = imagecreatetruecolor($b, $h);
        imagealphablending($ziel, false);
        imagesavealpha($ziel, true);
        imagecopyresampled($ziel, $quelle, 0, 0, 0, 0, $b, $h, $breite, $hoehe);

        return $ziel;
    }

    /**
     * EXIF-Ausrichtung 3/6/8 (Drehung) anwenden; gespiegelte Varianten (2/4/5/7) bleiben unberücksichtigt.
     */
    private function richteAus(GdImage $bild, string $tmpPfad): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $bild;
        }

        $exif   = @exif_read_data($tmpPfad);
        $winkel = match ((int) (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1)) {
            3       => 180,
            6       => -90,
            8       => 90,
            default => 0,
        };

        if ($winkel === 0) {
            return $bild;
        }

        $gedreht = imagerotate($bild, $winkel, 0);

        return $gedreht instanceof GdImage ? $gedreht : $bild;
    }

    /**
     * Mindestens ein Pixel nicht voll deckend? (nach dem Verkleinern höchstens 600 × 600 Pixel)
     */
    private static function hatTransparenz(GdImage $bild): bool
    {
        $breite = imagesx($bild);
        $hoehe  = imagesy($bild);

        for ($y = 0; $y < $hoehe; $y++) {
            for ($x = 0; $x < $breite; $x++) {
                if (((imagecolorat($bild, $x, $y) >> 24) & 0x7F) !== 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Ein Bild mit 8000 × 8000 Pixeln braucht dekodiert gut 256 MB; das Limit wird dafür auf 512 MB angehoben (nie gesenkt).
     */
    private function speicherFuerDekodieren(): void
    {
        $limit = (string) ini_get('memory_limit');

        if ($limit === '-1') {
            return;
        }

        $einheit = strtolower(substr($limit, -1));
        $bytes   = (int) $limit * match ($einheit) {
            'g'     => 1024 ** 3,
            'm'     => 1024 ** 2,
            'k'     => 1024,
            default => 1,
        };

        if ($bytes < 512 * 1024 ** 2) {
            ini_set('memory_limit', '512M');
        }
    }
}
