<?php

namespace App\Service;
use App\Exceptions\ExecFailed;
use App\Helpers\OS;
use App\Traits\ExecTrait;

final class Dependencies extends AbstractService {

    use ExecTrait;

    private const COMPOSE_OK = 'ok';
    private const COMPOSE_NOT_AVAILABLE = 'not_available';
    private const COMPOSE_VERSION_NOT_PARSABLE = 'version_not_parsable';
    private const COMPOSE_VERSION_UNSUPPORTED = 'version_unsupported';

    private Configurator $configurator;
    private string $lastComposeResolutionError = self::COMPOSE_OK;

    public static function instance(): Dependencies {
        return self::setup_singleton();
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

    private function getAlternateComposeCommand(string $cmd): string {
        return $cmd === 'docker-compose' ? 'docker compose' : 'docker-compose';
    }

    /**
     * @return array{0:string,1:int}
     */
    private function probeComposeVersion(string $composeCmd): array {
        $versionString = '';

        try {
            $jsonCmd = "$composeCmd version -f json";
            // Do not output an error if exec fails.
            $jsonOutput = $this->exec($jsonCmd);
            $decoded = json_decode($jsonOutput);
            if (!empty($decoded->version)) {
                $versionString = (string) $decoded->version;
            }
        } catch (ExecFailed $ignored) {
            // Some docker-compose installations do not support json output for version.
        }

        if (empty($versionString)) {
            // Fallback to non JSON docker compose version output.
            $plainCmd = "$composeCmd version";
            $plainOutput = $this->exec($plainCmd, "Error - Is docker compose installed?");
            if (preg_match('/([0-9]+(?:\.[0-9]+)+)/', $plainOutput, $matches)) {
                $versionString = $matches[1];
            }
        }

        if (empty($versionString)) {
            throw new \Exception("Failed to parse docker compose version output for '$composeCmd'");
        }

        if (!preg_match('/([0-9]+(?:\.[0-9]+)+)/', $versionString, $matches)) {
            throw new \Exception("Failed to parse docker compose version output for '$composeCmd'");
        }

        $version = $matches[1];
        $major = intval(explode('.', $version)[0]);
        return [$version, $major];
    }

    private function resolveComposeCommand(): ?string {
        $this->lastComposeResolutionError = self::COMPOSE_OK;
        $mainConfig = $this->configurator->getMainConfig();
        $preferred = $mainConfig->dockerComposeCommand;
        $candidates = [];
        $unsupportedVersionCandidates = [];
        $hadExecFailure = false;
        $hadParseFailure = false;

        if (!empty($preferred)) {
            $candidates[] = $preferred;
            $alternate = $this->getAlternateComposeCommand($preferred);
            if (!in_array($alternate, $candidates, true)) {
                $candidates[] = $alternate;
            }
        }

        if (!in_array('docker compose', $candidates, true)) {
            $candidates[] = 'docker compose';
        }
        if (!in_array('docker-compose', $candidates, true)) {
            $candidates[] = 'docker-compose';
        }

        foreach ($candidates as $candidate) {
            try {
                [, $major] = $this->probeComposeVersion($candidate);
                if ($major < 2) {
                    $unsupportedVersionCandidates[] = $candidate;
                    continue;
                }
                if ($mainConfig->dockerComposeCommand !== $candidate) {
                    $this->configurator->setMainConfigField('dockerComposeCommand', $candidate);
                    $this->cli->notice("Using compose command: $candidate");
                }
                return $candidate;
            } catch (ExecFailed $ignored) {
                // Continue trying alternates.
                $hadExecFailure = true;
            } catch (\Exception $e) {
                $this->cli->debug($e->getMessage());
                $hadParseFailure = true;
                continue;
            }
        }

        if (!empty($unsupportedVersionCandidates)) {
            $versions = implode(', ', $unsupportedVersionCandidates);
            $this->cli->error("docker compose version >= 2.x required (checked: $versions)");
            $this->lastComposeResolutionError = self::COMPOSE_VERSION_UNSUPPORTED;
            return null;
        }

        if ($hadParseFailure) {
            $this->lastComposeResolutionError = self::COMPOSE_VERSION_NOT_PARSABLE;
            return null;
        }

        if ($hadExecFailure) {
            $this->lastComposeResolutionError = self::COMPOSE_NOT_AVAILABLE;
            return null;
        }

        $this->lastComposeResolutionError = self::COMPOSE_NOT_AVAILABLE;

        return null;
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
          $resolvedComposeCommand = $this->resolveComposeCommand();
          if (empty($resolvedComposeCommand)) {
              if ($this->lastComposeResolutionError === self::COMPOSE_VERSION_UNSUPPORTED) {
                  // Error already shown by resolveComposeCommand.
              } else if ($this->lastComposeResolutionError === self::COMPOSE_VERSION_NOT_PARSABLE) {
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
