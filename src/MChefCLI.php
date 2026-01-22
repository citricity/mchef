<?php

namespace App;

use splitbrain\phpcli\CLI;
use splitbrain\phpcli\Options;
use App\Helpers\OS;
use App\Helpers\SplitbrainWrapper;

class MChefCLI extends CLI {
    static $version = '1.1.5'; // This gets replaced during phar build process.

    /**
     * @var \App\Service\Main;
     */
    public $main;
    /**
     * @var \App\Service\Dependencies;
     */
    public $depService;
    /**
     * @var \App\Service\Docker;
     */
    public $dockerService;

    /**
     * @var bool $verbose - verbose mode
     */
    public bool $verbose = false;

    public function __construct($autocatch = true) {
        require_once(__DIR__ . '/lib.php');
        require_once(__DIR__ . '/Polyfills/index.php');

        $this->setVersion();
        
        // Suppress splitbrain deprecation warnings during construction
        SplitbrainWrapper::suppressDeprecationWarnings(function() use ($autocatch) {
            parent::__construct($autocatch);
        });
        StaticVars::$cli = $this;
    }

    private function setVersion() {
        // This gets added to phar during build process.
        $versionFile = __DIR__ . '/../VERSION';
        if (file_exists($versionFile)) {
            self::$version = trim(file_get_contents($versionFile));
        }
    }

    private function registerCommands(Options $options) {
        $files = scandir(OS::path(__DIR__.'/../src/Command'));

        $files = array_filter($files, function($file) {
            return strpos($file, '.') !== 0 && strpos($file, 'AbstractCommand') !== 0;
        });
        foreach ($files as $file) {
            $class = 'App\\Command\\'.ucfirst(basename($file, '.php'));
            if (class_exists($class)) {
                /** @var \App\Command\AbstractCommand $classInstance */
                $classInstance = $class::instance();
                $classInstance->registerCommand($options);
            }
        }
    }

    protected function setup(Options $options) {
        // Run dependency checks. If one of them fails, program will die()
        $this->depService = \App\Service\Dependencies::instance($this);
        $this->depService->check();        
        $options->setHelp('Facilitates the creation of moodle docker instances with custom configurations.');

        // Register options for sub commands.
        $this->registerCommands($options);

        // Register options for main mchef command
        $options->registerArgument('recipe', 'File location of recipe', false);
        $options->registerOption('start', 'Start all containers associated with this recipe', 's');
        $options->registerOption('installexec', 'Install executable version of mchef.php to users bin folder', 'i');
        $options->registerOption('version', 'Print version', 'v');
        $options->registerOption('nocache', 'Disable caching when adding plugins and pulling docker images', null, false);
        $options->registerOption('yes', 'Skip confirmation prompts', 'y', false);
    }

    /**
     * Override to handle command-specific help
     */
    protected function handleDefaultOptions() {
        $options = $this->options;
        if ($options->getOpt('help')) {
            $cmd = $options->getCmd();
            if ($cmd) {
                // Show command-specific help
                $this->showCommandSpecificHelp($cmd);
                exit(0);
            }
        }
        
        // Call parent for other default options (like version, etc.)
        parent::handleDefaultOptions();
    }


    /**
     * Get the private 'setup' property from Options class
     */
    private function getSetup(): array {
        $ref = new \ReflectionClass($this->options);
        $prop = $ref->getProperty('setup');
        return $prop->getValue($this->options);
    }

    /**
     * Show help for a specific command (with colors and table formatting).
     */
    private function showCommandSpecificHelp(string $commandName): void {
        // Grab private props from Options
        $ref   = new \ReflectionClass($this->options);

        $colorsProp = $ref->getProperty('colors');
        $colors = $colorsProp->getValue($this->options);

        $newlineProp = $ref->getProperty('newline');
        $newline = $newlineProp->getValue($this->options);

        $bin = $this->options->getBin();

        $setup = $this->getSetup();
        if (!isset($setup[$commandName])) {
            throw new \Exception("Command '$commandName' not registered");
        }
        $config = $setup[$commandName];

        $tf   = new \splitbrain\phpcli\TableFormatter($colors);
        $text = '';

        // --- Syntax line ---
        if ($commandName === '') {
            $text .= $colors->wrap('USAGE:', \splitbrain\phpcli\Colors::C_BROWN) . "\n";
            $text .= '   ' . $bin;
            $mv = 2;
        } else {
            $text .= $colors->wrap("COMMAND: $commandName", \splitbrain\phpcli\Colors::C_BROWN) . $newline;
            $text .= $colors->wrap('   ' . $commandName, \splitbrain\phpcli\Colors::C_PURPLE);
            $mv = 4;
        }

        if ($config['opts']) {
            $text .= ' ' . $colors->wrap('<OPTIONS>', \splitbrain\phpcli\Colors::C_GREEN);
        }
        foreach ($config['args'] as $arg) {
            $out = $colors->wrap('<' . $arg['name'] . '>', \splitbrain\phpcli\Colors::C_CYAN);
            if (!$arg['required']) {
                $out = '[' . $out . ']';
            }
            $text .= ' ' . $out;
        }
        $text .= $newline . $newline;

        // --- Description ---
        if ($config['help']) {
            $text .= $tf->format([$mv, '*'], ['', $config['help'] . $newline]);
        }

        // --- Options ---
        if ($config['opts']) {
            $text .= "\n" . $colors->wrap('OPTIONS:', \splitbrain\phpcli\Colors::C_BROWN) . "\n";
            foreach ($config['opts'] as $long => $opt) {
                $name = '';
                if ($opt['short']) {
                    $name .= '-' . $opt['short'];
                    if ($opt['needsarg']) {
                        $name .= ' <' . $opt['needsarg'] . '>';
                    }
                    $name .= ', ';
                }
                $name .= "--$long";
                if ($opt['needsarg']) {
                    $name .= ' <' . $opt['needsarg'] . '>';
                }

                $text .= $tf->format(
                    [$mv, '30%', '*'],
                    ['', $name, $opt['help']],
                    ['', 'green', '']
                );
                $text .= $newline;
            }
        }

        // --- Arguments ---
        if ($config['args']) {
            $text .= "\n" . $colors->wrap('ARGUMENTS:', \splitbrain\phpcli\Colors::C_BROWN) . $newline;
            foreach ($config['args'] as $arg) {
                $name = '<' . $arg['name'] . '>';
                $text .= $tf->format(
                    [$mv, '30%', '*'],
                    ['', $name, $arg['help']],
                    ['', 'cyan', '']
                );
            }
        }

        echo $text;
    }

    private function welcomeLine() {
        $welcomeLine = 'Mchef: '.self::$version.' © Citricity 2024 onwards. www.citricity.com';
        $this->info($welcomeLine);
    }

    protected function main(Options $options) {
        $this->main = \App\Service\Main::instance($this);

        if ($options->getOpt('nocache')) {
            StaticVars::$noCache = true;
        }

        if ($cmd = $options->getCmd()) {
            $this->welcomeLine();
            $class = 'App\\Command\\'.ucfirst($cmd);
            if (!class_exists($class)) {
                $cli = \App\Service\CliService::instance();
                $class = $cli->locateCommandClass($cmd);
                if (!$class || !class_exists($class)) {
                    throw new \splitbrain\phpcli\Exception('Invalid command! Command not implemented.');
                }
            }
            try {
                $class::instance()->execute($options);
            } catch (\App\Exceptions\CliRuntimeException $e) {
                $this->error($e->getMessage());
                foreach ($e->getInfo() as $infoLine) {
                    $this->info($infoLine);
                }
                exit(1);
            }
            return;
        }

        if ($args = $options->getArgs()) {
            $recipe = $args[0];

            // Resolve recipe path:
            // - If an absolute path is provided, use it directly.
            // - Otherwise, look for the recipe relative to the current working directory.
            $cwd = getcwd();
            $isAbsolute = !OS::isWindows() ?
                (strlen($recipe) > 0 && ($recipe[0] === '/' || $recipe[0] === '\\')) // Unix or UNC-style
                : preg_match('/^[A-Za-z]:[\\\\\\/]/', $recipe) === 1; // Windows drive letter
            if ($isAbsolute) {
                $recipePath = $recipe;
            } else {
                // See if recipe is local to cwd.
                $recipePath = OS::path($cwd . '/' . $recipe);
            }
            if (!file_exists($recipePath)) {
                throw new \splitbrain\phpcli\Exception('Recipe file does not exist: ' . $recipe);
            }

            // Important - if we are upping a new recipe then we should temporarily unset the currently selected instance.
            $configurator = \App\Service\Configurator::instance();

            $instance = $configurator->getMainConfig()->instance;
            $configurator->setMainConfigField('instance', null);
            $this->main->up($recipePath);
            
            // Get the recipe to find the container prefix (new instance name)
            $recipeObj = $this->main->getRecipe();
            $newInstanceName = $recipeObj->containerPrefix;
            
            // Prompt user if they want to set this as the active instance
            $setAsActive = $this->promptYesNo("Do you want to set '$newInstanceName' as the active instance?", null, null, 'y');
            
            if ($setAsActive) {
                $configurator->setMainConfigField('instance', $newInstanceName);
                $this->success("Set '$newInstanceName' as the active instance.");
            } else {
                // Restore the previous instance
                $configurator->setMainConfigField('instance', $instance);
                if ($instance) {
                    $this->info("Restored previous active instance: '$instance'");
                }
            }
            return;
        }

        $this->welcomeLine();

        if ($options->getOpt('installexec')) {
            $this->info('Installing to bin folder');
            \App\Service\InstallToBin::instance($this)->install();
        } else if ($options->getOpt('version')) {
            $this->info(self::$version);
        } else if ($options->getOpt('start')) {
            $this->main->startContainers();
        } else {
            echo $options->help();
        }
    }

    /**
     * Prompt user yes no, returning input OR returning callable depending on $onYes or $onNo.
     *
     * @param string $msg
     * @param callable|null $onYes
     * @param callable|null $onNo
     * @return mixed
     */
    public function promptYesNo(
        string $msg,
        ?callable $onYes = null,
        ?callable $onNo = null,
        string $default = 'n'
    ): mixed {
        echo $msg;

        $forceAllYes = $this->options->getOpt('yes');
        if ($forceAllYes) {
            echo " (Forced Yes)\n";
            return $onYes ? $onYes('y') : true;
        }

        $suffix = $default === 'y' ? '[Y/n]' : '[y/N]';
        $input = readline(" $suffix ");
        $input = trim($input);

        if ($input === '') {
            $input = $default;
        }

        $normalized = strtolower($input);

        if (in_array($normalized, ['y', 'yes'], true)) {
            return $onYes ? $onYes($input) : true;
        }

        return $onNo ? $onNo($input) : false;
    }

    /**
     * Reads user input from the command line and returns it.
     * Optionally displays a prompt message.
     * @param string $prompt
     * @return string
     */
    public function promptInput(string $prompt = ''): string {
        if ($prompt) {
            $input = readline($prompt);
        } else {
            $input = readline();
        }
        return $input === false ? '' : trim($input);
    }

    public function promptForOption(string $prompt, array $options): string {
        $this->info($prompt, []);
        foreach ($options as $index => $option) {
            $this->info(sprintf("%d) %s", $index + 1, $option), []);
        }
        while (true) {
            $input = $this->promptInput("Enter number (1-" . count($options) . "): ");
            $selection = intval($input);
            if ($selection > 0 && $selection <= count($options)) {
                return $options[$selection - 1];
            }
            $this->error("Invalid selection. Please try again.");
        }
    }

    public function debug($message, array $context = array()) {
        if (!$this->verbose) {
            return;
        }
        $this->log('debug', $message, $context);
    }

}
