<?php

namespace App\Service;

use App\Helpers\OS;
use App\Traits\ExecTrait;

final class Dependencies extends AbstractService {

    use ExecTrait;

    // Service Dependencies
    private Docker $dockerService;

    public static function instance(bool $reset = false): Dependencies {
        return self::setup_singleton($reset);
    }

    private function dockerIsInstalled(): bool {
        // Use *nix 'which' or Windows PowerShell to check if docker is installed
        if (OS::isWindows()) {
            // Windows: use PowerShell to check for docker.exe
            $cmd = 'powershell -Command "Get-Command docker.exe"';
        } else {
            // Unix-like: use 'which docker'
            $cmd = 'which docker';
        }
        exec($cmd, $output, $returnVar);
        return $returnVar === 0 && !empty($output);
    }

    public function check(): void {
      $failed = false;

      if (!$this->dockerIsInstalled()) {
          $this->cli->error('Docker does not appear to be installed');
          $failed = true;
      }

      if (!$failed) {
          $this->cli->debug('Checking your docker version');
          $cmd = "docker version";
          try {
              $this->exec($cmd, "Error - Is your docker daemon running?");
          } catch (\Exception $ex) {
              $this->cli->error($ex);
              $failed = true;
          }
      }

      if (!$failed) {
          $this->cli->debug('Checking your docker compose version');
          $resolvedComposeCommand = $this->dockerService->resolveComposeCommand();
          $composeStatus = $this->dockerService->getLastComposeResolutionError();
          if (empty($resolvedComposeCommand)) {
              if ($composeStatus === Docker::COMPOSE_VERSION_UNSUPPORTED) {
                  // Error already shown by resolveComposeCommand.
              } else if ($composeStatus === Docker::COMPOSE_VERSION_NOT_PARSABLE) {
                  $this->cli->error('Docker Compose version output is not parsable.');
              } else {
                  $this->cli->warning('Docker Compose is not installed.');
                  $this->cli->error('Tried both compose commands: docker compose and docker-compose.');
              }
              $failed = true;
          }
      }

      $this->cli->debug('Checking your php version.');

      $phpParts = explode('.', phpversion(), 2);
      $major = intval($phpParts[0]);
      $point = intval($phpParts[1]);
      if ($major < 8 || $point < 2) {
          $this->cli->error('PHP 8.2+ supported - you are using '.phpversion());
          $failed = true;
      }

      if($failed) {
          $this->cli->error('Please correct your system errors and try again.');
          die;
      }
    }
}
