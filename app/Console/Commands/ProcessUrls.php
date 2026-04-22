<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\BrokenLinksExport;

class ProcessUrls extends Command
{
    protected $signature = 'process:urls';
    protected $description = 'Check URLs, find broken links and download images, export Excel';

    public function handle()
    {
        $urls = [
            "https://lanoequip.com/",
            "https://lanoequip.com/new-equipment.html",
            "https://lanoequip.com/parts-toro.html"
        ];

        $checkedLinks = [];
        $results = [];

        foreach ($urls as $pageUrl) {

            $this->info("Processing: $pageUrl");

            $html = $this->safeGet($pageUrl);

            if (!$html) {
                $this->error("Failed to load: $pageUrl");
                continue;
            }

            $crawler = new Crawler($html);
//links
            $links = $crawler->filter('a')->each(fn($n) => $n->attr('href'));
            $links = array_unique($links);

            foreach ($links as $link) {

                if (
                    !$link ||
                    str_starts_with($link, '#') ||
                    str_starts_with($link, 'mailto:') ||
                    str_starts_with($link, 'tel:') ||
                    str_starts_with($link, 'javascript:')
                ) {
                    continue;
                }

                $link = $this->toAbsoluteUrl($link, $pageUrl);

                if (in_array($link, $checkedLinks)) continue;
                $checkedLinks[] = $link;

                $this->line("Checking: $link");

                if (!$this->checkUrl($link)) {
                    $results[] = [$pageUrl, $link];
                }
            }

            //images
            $images = $crawler->filter('img')->each(fn($n) => $n->attr('src'));

            foreach ($images as $img) {

                if (!$img) continue;

                $img = $this->toAbsoluteUrl($img, $pageUrl);

                $imgData = $this->safeGet($img);

                if ($imgData) {
                    $name = basename(parse_url($img, PHP_URL_PATH)) ?: uniqid() . '.jpg';
                    Storage::put('images/' . $name, $imgData);
                }
            }
        }
// export excel
        $fileName = 'broken_links.xlsx';

        Excel::store(
            new BrokenLinksExport($results),
            $fileName,
            'local'
        );

        $this->info("...........");
        $this->info("Task Completed");
        $this->info("Excel: storage/app/private/$fileName");
        $this->info("Images: storage/app/private/images");

        return 0;
    }
    private function safeGet($url)
    {
        $context = stream_context_create([
            'http' => ['timeout' => 10],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        return @file_get_contents($url, false, $context);
    }

//CHECK URL
    private function checkUrl($url)
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        curl_exec($ch);

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        return ($code >= 200 && $code < 400);
    }

    //ABSOLUTE URL
    private function toAbsoluteUrl($link, $baseUrl)
    {
        if (str_starts_with($link, 'http')) return $link;

        $parsed = parse_url($baseUrl);
        $base = $parsed['scheme'] . '://' . $parsed['host'];

        return $base . '/' . ltrim($link, '/');
    }
}