<?php

namespace App\Service;

use App\Helpers\OS;
use App\Model\Recipe;
use App\Traits\ExecTrait;

final class ProxyService extends AbstractService {

    use ExecTrait;

    // Dependencies
    private Configurator $configuratorService;
    private RecipeService $recipeService;

    const PROXY_CONTAINER_NAME = 'mchef-proxy';
    const PROXY_PORT = 80;

    public static function instance(): ProxyService {
        return self::setup_singleton();
    }

    /**
     * Check if proxy mode is enabled in global config
     */
    public function isProxyModeEnabled(): bool {
        $globalConfig = $this->configuratorService->getMainConfig();
        return $globalConfig->useProxy ?? false;
    }

    /**
     * Check if proxy container is running
     */
    public function isProxyContainerRunning(): bool {
        $cmd = "docker ps --filter name=" . self::PROXY_CONTAINER_NAME . " --format \"{{.Names}}\"";
        exec($cmd, $output, $returnVar);

        return $returnVar === 0 && !empty($output) && in_array(self::PROXY_CONTAINER_NAME, $output);
    }

    /**
     * Check if port 80 is bound by the mchef-proxy container (docker ps shows mchef-proxy with port 80).
     * Return true only when mchef-proxy is running and has port 80 in its PORTS.
     */
    public function isPort80UsedByMchefProxy(): bool {
        $cmd = "docker ps --filter name=" . self::PROXY_CONTAINER_NAME . " --format \"{{.Names}}\t{{.Ports}}\"";
        exec($cmd, $output, $returnVar);
        if ($returnVar !== 0 || empty($output)) {
            return false;
        }
        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split("/\s+/", $line, 2);
            $names = $parts[0] ?? '';
            $ports = $parts[1] ?? '';
            if ($names === self::PROXY_CONTAINER_NAME && (str_contains($ports, ':80->') || str_contains($ports, '0.0.0.0:80'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if port 80 is in use by any process (e.g. another web server).
     * Uses a short TCP connection attempt for portability across OS.
     */
    public function isPort80InUse(): bool {
        $fp = @fsockopen('127.0.0.1', self::PROXY_PORT, $errno, $errstr, 1);
        if ($fp !== false) {
            fclose($fp);
            return true;
        }
        return false;
    }

    /**
     * When proxy mode is enabled: if port 80 is in use and not by mchef-proxy, warn that proxy mode will not work.
     * Call from: config --proxy (when enabling), mchef up, and when creating instance from recipe.
     * @param bool $skipEnabledCheck When true, skip the proxy-mode-enabled check (e.g. when enabling proxy via config, useProxy is not yet saved).
     */
    public function warnIfPort80BlockedForProxy(bool $skipEnabledCheck = false): void {
        if (!$skipEnabledCheck && !$this->isProxyModeEnabled()) {
            return;
        }
        if ($this->isPort80UsedByMchefProxy()) {
            return;
        }
        if ($this->isPort80InUse()) {
            $this->cli->warning(
                'Port 80 is already in use by another process (not the mchef proxy). ' .
                'Proxy mode will not work until port 80 is free. Stop the other service or disable proxy mode with: mchef config --proxy'
            );
        }
    }

    /**
     * Check if proxy container exists (running or stopped)
     */
    public function doesProxyContainerExist(): bool {
        $cmd = "docker ps -a --filter name=" . self::PROXY_CONTAINER_NAME . " --format \"{{.Names}}\"";
        exec($cmd, $output, $returnVar);

        return $returnVar === 0 && !empty($output) && in_array(self::PROXY_CONTAINER_NAME, $output);
    }

    /**
     * Start the proxy container
     */
    public function startProxyContainer(): void {
        if ($this->isProxyContainerRunning()) {
            $this->cli->info('Proxy container is already running');
            return;
        }

        if ($this->doesProxyContainerExist()) {
            $this->cli->info('Starting existing proxy container');
            $cmd = "docker start " . self::PROXY_CONTAINER_NAME;
        } else {
            $this->cli->info('Creating and starting proxy container');
            $configPath = $this->getProxyConfigPath();
            $cmd = "docker run -d --name " . self::PROXY_CONTAINER_NAME .
                   " -p " . self::PROXY_PORT . ":" . self::PROXY_PORT .
                   " -v \"$configPath:/etc/nginx/conf.d/default.conf:ro\" " .
                   " --network mc-network nginx:alpine";
        }

        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            $this->cli->error("Failed to start proxy container");
            $this->cli->error(implode("\n", $output));
        } else {
            $this->cli->success("Proxy container started successfully");
        }
    }

    /**
     * Restart the proxy container
     */
    public function restartProxyContainer(): void {
        if (!$this->doesProxyContainerExist()) {
            $this->startProxyContainer();
            return;
        }

        $this->cli->info('Restarting proxy server');

        $cmd = "docker restart " . self::PROXY_CONTAINER_NAME;
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            $this->cli->error("Failed to restart proxy container");
            $this->cli->error(implode("\n", $output));
        } else {
            $this->cli->success('Proxy server restarted');
        }
    }

    /**
     * Get the path to the proxy configuration file
     */
    public function getProxyConfigPath(): string {
        $configDir = $this->configuratorService->configDir();
        return OS::path($configDir . '/proxy.conf');
    }

    /**
     * Generate and write the nginx proxy configuration
     */
    public function generateProxyConfig(): void {
        $instances = $this->configuratorService->getInstanceRegistry();
        $config = $this->buildNginxConfig($instances);

        $configPath = $this->getProxyConfigPath();
        file_put_contents($configPath, $config);

        $this->cli->info("Generated proxy configuration at: $configPath");
    }

    /**
     * Build nginx configuration content
     */
    private function buildNginxConfig(array $instances): string {
        $config = "# Auto-generated nginx proxy configuration for mchef\n\n";

        foreach ($instances as $instance) {
            if ($instance->proxyModePort === null) {
                continue; // Skip non-proxy instances
            }

            try {
                $recipe = Recipe::fromJSONFile($instance->recipePath);
                if (empty($recipe->host)) {
                    continue; // Skip instances without host configuration
                }

                $config .= "upstream {$instance->containerPrefix}_backend {\n";
                $config .= "    server host.docker.internal:{$instance->proxyModePort};\n";
                $config .= "}\n\n";

                $behatHost = $this->recipeService->getBehatHost($recipe);
                $serverName = $recipe->host;
                if ($behatHost) {
                    $serverName .= ' '.$behatHost;
                }

                // Get upload size from recipe, default to 128M
                $uploadSize = $recipe->maxUploadSize ? $recipe->maxUploadSize . 'M' : '128M';

                $config .= "server {\n";
                $config .= "    listen 80;\n";
                $config .= "    server_name {$serverName};\n";
                $config .= "    client_max_body_size {$uploadSize};\n\n";
                $config .= "    location / {\n";
                $config .= "        proxy_pass http://{$instance->containerPrefix}_backend;\n";
                $config .= "        proxy_set_header Host \$host;\n";
                $config .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
                $config .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
                $config .= "        proxy_set_header X-Forwarded-Proto \$scheme;\n";
                $config .= "    }\n";
                $config .= "}\n\n";
            } catch (\Exception $e) {
                $this->cli->warning("Could not parse recipe for {$instance->containerPrefix}: " . $e->getMessage());
                continue;
            }
        }

        // Add default server block to handle requests to unknown hosts
        $config .= "server {\n";
        $config .= "    listen 80 default_server;\n";
        $config .= "    server_name _;\n";
        $config .= "    return 444;\n";
        $config .= "}\n";

        return $config;
    }

    /**
     * Update proxy configuration and restart container
     */
    public function updateProxyConfiguration(): void {
        if (!$this->isProxyModeEnabled()) {
            return;
        }

        $this->generateProxyConfig();

        if ($this->doesProxyContainerExist()) {
            $this->restartProxyContainer();
        } else {
            $this->startProxyContainer();
        }
    }

    /**
     * Ensure proxy is running if in proxy mode
     */
    public function ensureProxyRunning(): void {
        if (!$this->isProxyModeEnabled()) {
            return;
        }

        if (!$this->isProxyContainerRunning()) {
            $this->cli->info('Proxy mode is enabled but proxy container is not running');
            $this->updateProxyConfiguration();
        }
    }
}
