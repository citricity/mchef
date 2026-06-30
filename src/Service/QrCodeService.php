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

    public function renderRedirectHtml(string $url): string {
        return $this->mainService->getTwig()->render('@github/urlFile.twig', ['url' => $url]);
    }

    /**
     * Create and publish a redirect HTML file to a GitHub repo.
     *
     * @return array{id:string,resourceUrl:string,shortUrl:string}
     */
    public function publishRedirectUrl(string $url, string $repo, string $token, ?string $id = null): array {
        $id = $id ?? strtoupper(bin2hex(random_bytes(8)));
        $path = $id . '.html';
        $html = $this->renderRedirectHtml($url);

        $resourceUrl = $this->githubService->publishHtmlToRepository($repo, $path, $html, $token, $id);

        return [
            'id' => $id,
            'resourceUrl' => $resourceUrl,
            'shortUrl' => $this->githubService->buildGithubPagesUrl($repo, $path),
        ];
    }
}