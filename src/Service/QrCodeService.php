<?php

namespace App\Service;

use App\Qr\QrTerminal;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

final class QrCodeService extends AbstractService {

    private Main $mainService;
    private Github $githubService;

    public static function instance(): QrCodeService {
        return self::setup_singleton();
    }

    public function generateQrCode(string $text): string {
       $options = new QROptions([
            'outputInterface' => QrTerminal::class,
            'quietzoneSize' => 2,
            'eol' => PHP_EOL,
        ]);
        return (new QRCode($options))->render($text);
    }

    /**
     * Create and publish a redirect HTML file to a GitHub repo.
     *
     * @return array{id:string,resourceUrl:string,shortUrl:string}
     */
    public function publishRedirectUrl(string $url, string $repo, string $token): array {
        $linkHash = sha1($url);
        $path = 'links/' . $linkHash . '.txt';
        
        $resourceUrl = $this->githubService->publishUrlToRepository($repo, $path, $url, $token, $linkHash);

        return [
            'id' => $linkHash,
            'resourceUrl' => $resourceUrl,
            'shortUrl' => $this->githubService->buildGithubPagesUrl($repo, $linkHash),
        ];
    }
}