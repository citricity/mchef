<?php

namespace App\Service;

use App\Model\DockerContainer;
use App\Model\DockerData;
use App\Model\DockerNetwork;
use App\Traits\ExecTrait;
use splitbrain\phpcli\Exception;

class Docker extends AbstractService {
    use ExecTrait;

    final public static function instance(): Docker {
        return self::setup_singleton();
    }

    private function getTableHeadingPositions(string $table, array $headings): array {
        $lines = explode("\n", trim($table));

        $headingPositions = [];

        // Loop through the headings.
        $prevHeading = null;
        foreach ($headings as $heading) {
            // Find the position of the heading in the first line.
            $pos = strpos($lines[0], $heading);
            if ($pos !== false) {
                $headingPositions[$heading] = (object) ['start' => $pos, 'end' => null];
                if ($prevHeading) {
                    $headingPositions[$prevHeading]->end = $pos - 1;
                }
            }
            $prevHeading = $heading;
        }
        return $headingPositions;
    }

    /**
     * Parse a table of information returned by the docker cli commands.
     *
     * @param array $fieldMappings
     * @param string $table
     * @param callable $createModel
     * @return array
     */
    private function parseTable(array $fieldMappings, string $table, callable $createModel): array {
        $lines = explode("\n", trim($table));
        $headings = array_keys($fieldMappings);
        $headingPositions = $this->getTableHeadingPositions($table, $headings);
        $data = [];
        // Parse the docker ps output.
        foreach (array_slice($lines, 1) as $line) { // Loop through the remaining lines.
            $parsedRow = [];
            foreach ($headings as $heading) {
                $offset = $headingPositions[$heading]->start;
                $length = null;
                if (!empty($headingPositions[$heading]->end)) {
                    $length = $headingPositions[$heading]->end - $headingPositions[$heading]->start;
                }
                $parsedRow[$heading] = trim(substr($line, $offset, $length));
            }

            foreach ($fieldMappings as $field => $alt) {
                if (!isset($parsedRow[$field])) {
                    throw new Exception('Docker ps unexpected output format - expected '.$field.' to be present');
                }
            }
            $modelData = [];
            foreach ($parsedRow as $key => $val) {
                if (!empty($fieldMappings[$key])) {
                    $useProp = $fieldMappings[$key];
                } else {
                    $useProp = strtolower($key);
                }
                $modelData[$useProp] = $val;
            }

            $data[] = $createModel($modelData);
        }

        return $data;
    }

    private function parseContainerTable(string $table): array {
        $dockerFields = [
            'CONTAINER ID' => 'containerId',
            'IMAGE' => null,
            'COMMAND' => null,
            'CREATED' => null,
            'STATUS' => null,
            'PORTS' => null,
            'NAMES' => null
        ];
        return $this->parseTable($dockerFields, $table, function($modelData) {
            return new DockerContainer(...$modelData);
        });
    }

    /**
     * @param string $table
     * @return DockerNetwork[]
     */
    private function parseNetworkTable(string $table): array {
        $dockerFields = [
            'NETWORK ID' => 'networkId',
            'NAME' => 'name',
            'DRIVER' => 'driver',
            'SCOPE' => 'scope'
        ];
        return $this->parseTable($dockerFields, $table, function($modelData) {
            return new DockerNetwork(...$modelData);
        });
    }

    public function networkExists(string $networkName): bool {
        $table = $this->exec('docker network ls');
        $networks = $this->parseNetworkTable($table);
        foreach ($networks as $network) {
            if ($network->name === $networkName) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return DockerContainer[]
     * @throws \App\Exceptions\ExecFailed
     */
    public function getDockerPs(): array {
        $output = $this->exec('docker ps');
        return $this->parseContainerTable($output);
    }

    /**
     * @return DockerContainer[]
     * @throws \App\Exceptions\ExecFailed
     */
    public function getDockerContainers($includeStopped = true): array {
        $output = $this->exec('docker container ls'.($includeStopped ? ' -a' : ''));
        return $this->parseContainerTable($output);
    }

    public function stopDockerContainer(string $containerName) {
        $this->exec('docker container stop '.$containerName);
    }

    public function recreateDockerContainer(string $containerName) {
        $this->stopDockerContainer($containerName);
        $this->removeDockerContainer($containerName);
    }

    public function removeDockerContainer(string $containerName) {
        $this->exec('docker rm '.$containerName);
    }

    public function startDockerContainer(string $containerName) {
        $this->exec('docker start '.$containerName);
    }

    public function execute(string $containerName, string $cmd, ?string $options = null): string {
        return $this->exec('docker exec '.($options ? $options.' ' : '').$containerName.' '.$cmd);
    }

    public function checkContainerRunning(string $containerName) {
        $cmd = "docker inspect -f {{.State.Running}} $containerName";
        $onErrorMsg = 'Failed to get container running status for '.$containerName;
        // We need to exec silently or it will show errors if the container has been deleted.
        return $this->exec($cmd, $onErrorMsg, true) === 'true';
    }

    public function checkPortAvailable(int $port): bool {
      $containers = $this->getDockerContainers(true);
        for($i=0;$i<count($containers);$i++) {
          $containerSpecs = json_decode($this->exec('docker inspect --format json '.$containers[$i]->containerId));
          if (!$containerSpecs[0]->State->Running) {
              continue;
          }
          if($containerSpecs[0]->HostConfig->PortBindings) {
             if(property_exists($containerSpecs[0]->HostConfig->PortBindings, $port.'/tcp')) {
              $this->cli->error('Portbinding '.$port.'/tcp'.' is already in use (containerId: '.$containers[$i]->containerId.')');
              return false;
            }
          }
        }
      return true;
    }

    public function windowsToDockerPath($windowsPath) {
        // Replace backslashes with forward slashes
        $dockerPath = str_replace("\\", "/", $windowsPath);

        // Convert the drive letter (e.g., 'C:') to the corresponding Unix-style path
        if (preg_match('/^[A-Za-z]:\//', $dockerPath)) {
            // Change the drive letter (e.g., C:\) to /c/
            $dockerPath = "/" . strtolower($dockerPath[0]) . substr($dockerPath, 2);
        }

        return $dockerPath;
    }

    /**
     * Get all volumes attached to a specific container.
     * 
     * @param string $containerName The name of the container
     * @return array Array of volume names
     */
    public function getContainerVolumes(string $containerName): array {
        try {
            // Safely escape container name for shell usage
            $escapedContainerName = escapeshellarg($containerName);
            
            // Use docker inspect to get mount information
            $cmd = "docker inspect --format '{{range .Mounts}}{{if eq .Type \"volume\"}}{{.Name}}{{\"\\n\"}}{{end}}{{end}}' $escapedContainerName";
            $output = $this->exec($cmd, null, true);
            
            if (empty(trim($output))) {
                return [];
            }
            
            $volumes = array_filter(explode("\n", trim($output)), function($volume) {
                return !empty(trim($volume));
            });
            
            return array_map('trim', $volumes);
        } catch (\Exception $e) {
            // Container might not exist, return empty array
            return [];
        }
    }

    /**
     * Get all volumes for multiple containers associated with an instance.
     * 
     * @param string $instanceName The MChef instance name
     * @return array Array of unique volume names
     */
    public function getInstanceVolumes(string $instanceName): array {
        $containers = [
            "{$instanceName}-moodle",
            "{$instanceName}-db"
        ];
        
        $allVolumes = [];
        
        foreach ($containers as $containerName) {
            $volumes = $this->getContainerVolumes($containerName);
            $allVolumes = array_merge($allVolumes, $volumes);
        }
        
        // Return unique volumes only
        return array_unique($allVolumes);
    }

    /**
     * Remove a volume by name.
     * 
     * @param string $volumeName The name of the volume to remove
     * @return bool True if successful, false otherwise
     */
    public function removeVolume(string $volumeName): bool {
        try {
            $escapedVolumeName = escapeshellarg($volumeName);
            $this->exec("docker volume rm $escapedVolumeName", null, true);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a volume exists.
     * 
     * @param string $volumeName The name of the volume
     * @return bool True if volume exists, false otherwise
     */
    public function volumeExists(string $volumeName): bool {
        try {
            $escapedVolumeName = escapeshellarg($volumeName);
            $this->exec("docker volume inspect $escapedVolumeName", null, true);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Login to Docker registry using provided credentials.
     * 
     * @param array $registryConfig Registry configuration with url, username, password/token
     * @throws Exception If login fails
     */
    public function loginToRegistry(array $registryConfig): void {
        $url = escapeshellarg($registryConfig['url']);
        $username = escapeshellarg($registryConfig['username']);
        
        if (!empty($registryConfig['token'])) {
            // Token-based authentication (e.g., GitHub)
            $token = escapeshellarg($registryConfig['token']);
            $cmd = "echo {$token} | docker login {$url} --username {$username} --password-stdin";
        } else {
            // Password-based authentication
            $password = escapeshellarg($registryConfig['password']);
            $cmd = "echo {$password} | docker login {$url} --username {$username} --password-stdin";
        }
        
        $this->exec($cmd, "Failed to login to registry: {$registryConfig['url']}");
    }

    /**
     * Tag a Docker image with a new name/tag.
     * 
     * @param string $sourceImage Source image name (e.g., "myapp:v1.0.0")
     * @param string $targetImage Target image name (e.g., "registry.com/myapp:v1.0.0")
     * @throws Exception If tagging fails
     */
    public function tagImage(string $sourceImage, string $targetImage): void {
        $sourceEscaped = escapeshellarg($sourceImage);
        $targetEscaped = escapeshellarg($targetImage);
        
        $cmd = "docker tag {$sourceEscaped} {$targetEscaped}";
        $this->exec($cmd, "Failed to tag image: {$sourceImage} -> {$targetImage}");
    }

    /**
     * Push a Docker image to registry.
     * 
     * @param string $imageName Full image name including registry (e.g., "registry.com/myapp:v1.0.0")
     * @throws Exception If push fails
     */
    public function pushImage(string $imageName): void {
        $imageEscaped = escapeshellarg($imageName);
        
        $cmd = "docker push {$imageEscaped}";
        $this->exec($cmd, "Failed to push image: {$imageName}");
    }

    /**
     * Build Docker image with custom name using docker compose.
     * 
     * @param string $composeFile Path to docker-compose.yml file
     * @param DockerData $dockerData Docker data used for the build
     * @param string $imageName Custom image name to tag as
     * @param string $projectDir Project directory for docker compose
     * @throws Exception If build fails
     */
    public function buildImageWithCompose(string $composeFile, DockerData $dockerData, string $imageName, string $projectDir, ?bool $usesSsh = false): void {
        $composeFileEscaped = escapeshellarg($composeFile);
        $projectDirEscaped = escapeshellarg($projectDir);
        
        // Build using docker compose but don't start containers
        $dockerBuildKit = $usesSsh ? 'DOCKER_BUILDKIT=1 COMPOSE_DOCKER_CLI_BUILD=1 ' : '';
        $cmd = "{$dockerBuildKit}docker compose --project-directory {$projectDirEscaped} -f {$composeFileEscaped} build";
        $this->exec($cmd, "Failed to build image with docker compose");
        
        // Get the built image name from compose and tag it with our custom name
        // This is a bit tricky - we need to inspect what compose built and rename it
        // For now, we'll assume the main service in compose is called 'moodle'
        $serviceName = $this->extractServiceNameFromCompose($composeFile);
        $builtImageName = $dockerData->containerName ?? $this->getComposeImageName($projectDir, $serviceName);
        
        if ($builtImageName !== $imageName) {
            $this->tagImage($builtImageName, $imageName);
        }
    }

    /**
     * Extract the main service name from docker-compose file.
     * 
     * @param string $composeFile Path to compose file
     * @return string Service name (defaults to 'moodle')
     */
    private function extractServiceNameFromCompose(string $composeFile): string {
        // For MChef, the main service is typically 'moodle'
        // In a more robust implementation, we'd parse the YAML
        return 'moodle';
    }

    /**
     * Get the image name that docker compose would generate.
     * 
     * @param string $projectDir Project directory
     * @param string $serviceName Service name
     * @return string Generated image name
     */
    private function getComposeImageName(string $projectDir, string $serviceName): string {
        // Docker compose generates image names like: {project}_{service}
        $projectName = basename($projectDir);
        return "{$projectName}_{$serviceName}";
    }

    /**
     * Copy a file from host to a Docker container
     * 
     * @param string $sourcePath Source file path on host
     * @param string $container Container name
     * @param string $destinationPath Destination path inside container (including filename)
     * @throws Exception If copy fails
     */
    public function copyFileToContainer(string $sourcePath, string $container, string $destinationPath): void {
        $cmd = sprintf(
            'docker cp %s %s:%s',
            escapeshellarg($sourcePath),
            escapeshellarg($container),
            escapeshellarg($destinationPath)
        );
        $this->exec($cmd, "Failed to copy file to container");
    }

    /**
     * Download a file from URL to a specific path inside a Docker container using curl
     * 
     * @param string $container Container name
     * @param string $url URL to download from
     * @param string $destinationPath Full path inside container (including filename)
     * @throws Exception If download fails
     */
    public function downloadFileInContainer(string $container, string $url, string $destinationPath): void {
        $cmd = sprintf(
            'docker exec %s bash -c "curl -L --fail --silent --show-error -o %s %s"',
            escapeshellarg($container),
            escapeshellarg($destinationPath),
            escapeshellarg($url)
        );
        $this->exec($cmd, "Failed to download file to container");

        // Verify file was downloaded and has content
        $verifyCmd = sprintf(
            'docker exec %s bash -c "test -s %s || exit 1"',
            escapeshellarg($container),
            escapeshellarg($destinationPath)
        );
        $this->exec($verifyCmd, "Downloaded file is empty or does not exist");
    }

    /**
     * Normalize a CSV file in Docker container: remove BOM, convert line endings to Unix format
     * 
     * @param string $container Container name
     * @param string $filePath Path to CSV file inside container
     * @throws Exception If normalization fails
     */
    public function normalizeCsvFileInContainer(string $container, string $filePath): void {
        $normalizeCmd = sprintf(
            'docker exec %s bash -c "sed -i \'1s/^\\xEF\\xBB\\xBF//\' %s && dos2unix %s 2>/dev/null || sed -i \'s/\\r$//\' %s"',
            escapeshellarg($container),
            escapeshellarg($filePath),
            escapeshellarg($filePath),
            escapeshellarg($filePath)
        );
        $this->exec($normalizeCmd, "Failed to normalize CSV file");
    }

    /**
     * Execute a PHP script in Docker container with environment variable and capture output
     * 
     * @param string $container Container name
     * @param string $envVarName Environment variable name (e.g., 'MCHEF_RECIPE_PATH')
     * @param string $envVarValue Environment variable value
     * @param string $scriptPath Path to PHP script in container
     * @return array Array with output string at index 0 and returnVar at index 1
     */
    public function executeInContainerWithEnv(string $container, string $envVarName, string $envVarValue, string $scriptPath): array {
        $cmd = sprintf(
            'docker exec -e %s=%s %s php %s',
            escapeshellarg($envVarName),
            escapeshellarg($envVarValue),
            escapeshellarg($container),
            escapeshellarg($scriptPath)
        );
        // Capture both stdout and stderr
        $cmdWithStderr = $cmd . ' 2>&1';
        $output = [];
        $returnVar = 0;
        exec($cmdWithStderr, $output, $returnVar);
        
        return [
            implode("\n", $output),
            $returnVar
        ];
    }

    /**
     * Execute a PHP script in Docker container and pass output through (for interactive scripts)
     * 
     * @param string $container Container name
     * @param string $scriptPath Path to PHP script in container
     * @param array $arguments Additional arguments to pass to the script
     * @throws Exception If execution fails
     */
    public function executePhpScriptPassthru(string $container, string $scriptPath, array $arguments = []): void {
        $argsString = '';
        if (!empty($arguments)) {
            $argsString = ' ' . implode(' ', array_map('escapeshellarg', $arguments));
        }
        $cmd = sprintf(
            'docker exec %s php %s%s',
            escapeshellarg($container),
            escapeshellarg($scriptPath),
            $argsString
        );
        $this->execPassthru($cmd, "Failed to execute PHP script");
    }
}
