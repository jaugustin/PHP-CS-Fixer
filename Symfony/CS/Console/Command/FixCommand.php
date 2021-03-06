<?php

/*
 * This file is part of the PHP CS utility.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Symfony\CS\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\CS\Fixer;
use Symfony\CS\FixerInterface;
use Symfony\CS\Config\Config;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class FixCommand extends Command
{
    protected $fixer;

    public function __construct()
    {
        $this->fixer = new Fixer();
        $this->fixer->registerBuiltInFixers();
        $this->fixer->registerBuiltInConfigs();

        parent::__construct();
    }

    /**
     * @see Command
     */
    protected function configure()
    {
        $this
            ->setName('fix')
            ->setDefinition(array(
                new InputArgument('path', InputArgument::REQUIRED, 'The path'),
                new InputOption('config', '', InputOption::VALUE_REQUIRED, 'The configuration name', null),
                new InputOption('dry-run', '', InputOption::VALUE_NONE, 'Only shows which files would have been modified'),
                new InputOption('level', '', InputOption::VALUE_REQUIRED, 'The level of fixes (can be psr1, psr2, or all)', null),
                new InputOption('fixers', '', InputOption::VALUE_REQUIRED, 'A list of fixers to run'),
            ))
            ->setDescription('Fixes a directory or a file')
            ->setHelp(<<<EOF
The <info>%command.name%</info> command tries to fix as much coding standards
problems as possible on a given file or directory:

    <info>php %command.full_name% /path/to/dir</info>
    <info>php %command.full_name% /path/to/file</info>

You can limit the fixers you want to use on your project by using the
<comment>--level</comment> option:

    <info>php %command.full_name% /path/to/project --level=psr1</info>
    <info>php %command.full_name% /path/to/project --level=psr2</info>
    <info>php %command.full_name% /path/to/project --level=all</info>

When the level option is not passed, all PSR-2 fixers and some additional ones
are run.

You can also explicitly name the fixers you want to use (a list of fixer names
separated by a comma):

    <info>php %command.full_name% /path/to/dir --fixers=linefeed,short_tag,indentation</info>

Here is the list of built-in fixers:

{$this->getFixersHelp()}
You can also use built-in configurations, for instance when ran for Symfony:

    <comment># For the Symfony 2.1 branch</comment>
    <info>php %command.full_name% /path/to/sf21 --config=symfony21</info>

Here is the list of built-in configs:

{$this->getConfigsHelp()}
Instead of using the command line arguments, you can save your configuration
in a <comment>.php_cs</comment> file in the root directory of your project. It
must return an instance of `Symfony\CS\ConfigInterface` and it lets you
configure the fixers and the files and directories that need to be analyzed:

    <?php

    \$finder = Symfony\CS\Finder\DefaultFinder::create()
        ->exclude('somefile')
        ->in(__DIR__)
    ;

    return Symfony\CS\Config::create()
        ->fixers(array('indentation', 'elseif'))
        ->finder(\$finder)
    ;
EOF
            );
    }

    /**
     * @see Command
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $input->getArgument('path');
        $filesystem = new Filesystem();
        if (!$filesystem->isAbsolutePath($path)) {
            $path = getcwd().DIRECTORY_SEPARATOR.$path;
        }

        if ($input->getOption('config')) {
            $config = null;
            foreach ($this->fixer->getConfigs() as $c) {
                if ($c->getName() == $input->getOption('config')) {
                    $config = $c;
                    break;
                }
            }

            if (null === $config) {
                throw new \InvalidArgumentException(sprintf('The configuration "%s" is not defined', $input->getOption('config')));
            }
        } elseif (file_exists($file = $path.'/.php_cs')) {
            $config = include $file;
        } else {
            $config = new Config();
        }

        if (is_file($path)) {
            $config->finder(new \ArrayIterator(array(new \SplFileInfo($path))));
        } else {
            $config->setDir($path);
        }

        if ($input->getOption('fixers')) {
            $config->fixers(array_map('trim', explode(',', $input->getOption('fixers'))));
        } else {
            switch ($input->getOption('level')) {
                case 'psr1':
                    $config->fixers(FixerInterface::PSR1_LEVEL);
                    break;
                case 'psr2':
                    $config->fixers(FixerInterface::PSR2_LEVEL);
                    break;
                case 'all':
                    $config->fixers(FixerInterface::ALL_LEVEL);
                    break;
                case null:
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('The level "%s" is not defined.', $input->getOption('level')));
            }
        }

        $changed = $this->fixer->fix($config, $input->getOption('dry-run'));

        foreach ($changed as $i => $file) {
            $output->writeln(sprintf('%4d) %s', $i, $file));
        }
    }

    protected function getFixersHelp()
    {
        $fixers = '';
        $maxName = 0;
        foreach ($this->fixer->getFixers() as $fixer) {
            if (strlen($fixer->getName()) > $maxName) {
                $maxName = strlen($fixer->getName());
            }
        }

        $count = count($this->fixer->getFixers()) - 1;
        foreach ($this->fixer->getFixers() as $i => $fixer) {
            $chunks = explode("\n", wordwrap(sprintf('[%s] %s', $this->fixer->getLevelAsString($fixer), $fixer->getDescription()), 72 - $maxName, "\n"));
            $fixers .= sprintf(" * <comment>%s</comment>%s %s\n", $fixer->getName(), str_repeat(' ', $maxName - strlen($fixer->getName())), array_shift($chunks));
            while ($c = array_shift($chunks)) {
                $fixers .= str_repeat(' ', $maxName + 4).$c."\n";
            }

            if ($count != $i) {
                $fixers .= "\n";
            }
        }

        return $fixers;
    }

    protected function getConfigsHelp()
    {
        $configs = '';
        $maxName = 0;
        foreach ($this->fixer->getConfigs() as $config) {
            if (strlen($config->getName()) > $maxName) {
                $maxName = strlen($config->getName());
            }
        }

        $count = count($this->fixer->getConfigs()) - 1;
        foreach ($this->fixer->getConfigs() as $i => $config) {
            $chunks = explode("\n", wordwrap($config->getDescription(), 72 - $maxName, "\n"));
            $configs .= sprintf(" * <comment>%s</comment>%s %s\n", $config->getName(), str_repeat(' ', $maxName - strlen($config->getName())), array_shift($chunks));
            while ($c = array_shift($chunks)) {
                $configs .= str_repeat(' ', $maxName + 4).$c."\n";
            }

            if ($count != $i) {
                $configs .= "\n";
            }
        }

        return $configs;
    }
}
