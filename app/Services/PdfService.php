<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Формирование PDF на сервере (mPDF). Шрифт DejaVu Sans входит в поставку mPDF и поддерживает кириллицу.
 * Нумерация страниц и дата формирования — в колонтитуле; шапка таблиц повторяется на каждой странице,
 * строки не разрываются, широкие таблицы сжимаются по ширине страницы.
 */
final class PdfService
{
    public static function render(array $base, string $bodyHtml, string $orientation = 'P'): string
    {
        $tmp = self::tempDir();
        $mpdf = new \Mpdf\Mpdf([
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'orientation'       => $orientation === 'L' ? 'L' : 'P',
            'margin_left'       => 12,
            'margin_right'      => 12,
            'margin_top'        => 14,
            'margin_bottom'     => 18,
            'margin_footer'     => 7,
            'default_font'      => 'dejavusans',
            'default_font_size' => 9,
            'tempDir'           => $tmp,
        ]);
        $mpdf->shrink_tables_to_fit = 1;
        $mpdf->SetTitle($base['title']);
        $mpdf->SetAuthor($base['org']);
        $mpdf->SetCreator('Учёт дверей');
        $e = [ReportHtml::class, 'e'];
        $mpdf->SetHTMLFooter(
            '<table width="100%" style="font-size:7.5pt;color:#6b7a89;border-top:0.5pt solid #c9d3de;"><tr>'
            . '<td width="60%">' . $e($base['org']) . ' · ' . $e($base['title']) . ' · сформировано ' . $e($base['generated']) . '</td>'
            . '<td width="40%" align="right">Страница {PAGENO} из {nbpg}</td></tr></table>'
        );
        $mpdf->WriteHTML(ReportHtml::css(), \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(ReportHtml::layout($base, $bodyHtml), \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * Каталог временных файлов mPDF (кэш шрифтов). Основной — в storage/tmp, но он может оказаться недоступным процессу веб-сервера,
     * например если его создал root при запуске тестов из консоли. Тогда берётся системный временный каталог процесса.
     */
    private static function tempDir(): string
    {
        $uid = function_exists('posix_geteuid') ? (string) posix_geteuid() : 'u';
        foreach ([Config::get('paths.storage') . '/tmp/mpdf', sys_get_temp_dir() . '/door-mpdf-' . $uid] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
        }
        throw new \RuntimeException('Нет доступного для записи каталога для временных файлов PDF');
    }
}
