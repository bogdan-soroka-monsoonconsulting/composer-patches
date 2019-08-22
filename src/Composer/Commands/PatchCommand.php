<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Composer\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Composer\Script\ScriptEvents;

use Vaimo\ComposerPatches\Patch\Definition as Patch;
use Vaimo\ComposerPatches\Patch\Definition as PatchDefinition;

use Vaimo\ComposerPatches\Repository\PatchesApplier\ListResolvers;
use Vaimo\ComposerPatches\Config;

use Vaimo\ComposerPatches\Environment;

class PatchCommand extends \Composer\Command\BaseCommand
{
    protected function configure()
    {
        $this->setName('patch');
        $this->setDescription('Apply registered patches to current project');

        $this->addArgument(
            'targets',
            \Symfony\Component\Console\Input\InputArgument::IS_ARRAY,
            'Packages for the patcher to target',
            array()
        );

        $this->addOption(
            '--redo',
            null,
            InputOption::VALUE_NONE,
            'Re-patch all packages or a specific package when targets defined'
        );

        $this->addOption(
            '--undo',
            null,
            InputOption::VALUE_NONE,
            'Remove all patches or a specific patch when targets defined'
        );

        $this->addOption(
            '--no-dev',
            null,
            InputOption::VALUE_NONE,
            'Disables installation of require-dev packages'
        );

        $this->addOption(
            '--filter',
            null,
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'Apply only those patch files/sources that match with provided filter'
        );
        
        $this->addOption(
            '--explicit',
            null,
            InputOption::VALUE_NONE,
            'Show information for every patch that gets re-applied (due to package reset)'
        );

        $this->addOption(
            '--show-reapplies',
            null,
            InputOption::VALUE_NONE,
            'Alias for \'explicit\' argument'
        );
        
        $this->addOption(
            '--from-source',
            null,
            InputOption::VALUE_NONE,
            'Apply patches based on information directly from packages in vendor folder'
        );

        $this->addOption(
            '--graceful',
            null,
            InputOption::VALUE_NONE,
            'Continue even when some patch fails to apply'
        );

        $this->addOption(
            '--force',
            null,
            InputOption::VALUE_NONE,
            'Force package reset even when it has local change'
        );
    }

    protected function getBehaviourFlags(InputInterface $input)
    {
        return array(
            'redo' => (bool)$input->getOption('redo'),
            'undo' => (bool)$input->getOption('undo'),
            'force' => (bool)$input->getOption('force'),
            'explicit' => $input->getOption('explicit') || $input->getOption('show-reapplies')
        );
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void|null
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configDefaults = new \Vaimo\ComposerPatches\Config\Defaults();
        $defaults = $configDefaults->getPatcherConfig();

        $composer = $this->getComposer();

        $appIO = $this->getIO();
        
        $isDevMode = !$input->getOption('no-dev');

        $behaviourFlags = $this->getBehaviourFlags($input);
        $shouldUndo = !$behaviourFlags['redo'] && $behaviourFlags['undo'];
        
        $bootstrapFactory = new \Vaimo\ComposerPatches\Factories\BootstrapFactory($composer, $appIO);

        $configFactory = new \Vaimo\ComposerPatches\Factories\ConfigFactory($composer, array(
            Config::PATCHER_FORCE_REAPPLY => $behaviourFlags['redo'],
            Config::PATCHER_FROM_SOURCE => (bool)$input->getOption('from-source'),
            Config::PATCHER_GRACEFUL => (bool)$input->getOption('graceful')
                || $behaviourFlags['redo']
                || $behaviourFlags['undo'],
            Config::PATCHER_SOURCES => array_fill_keys(array_keys($defaults[Config::PATCHER_SOURCES]), true)
        ));
        
        $filters = $this->resolveActiveFilters($input, $behaviourFlags);
        
        $listResolver = $this->createListResolver($behaviourFlags, $filters);
        
        $this->configureEnvironmentForBehaviour($behaviourFlags);

        $outputTriggers = $this->resolveOutputTriggers($filters, $behaviourFlags);
        $outputStrategy = new \Vaimo\ComposerPatches\Strategies\OutputStrategy($outputTriggers);
        $bootstrap = $bootstrapFactory->create($configFactory, $listResolver, $outputStrategy);

        $runtimeUtils = new \Vaimo\ComposerPatches\Utils\RuntimeUtils();
        $lockSanitizer = new \Vaimo\ComposerPatches\Repository\Lock\Sanitizer($appIO);
        $repository = $composer->getRepositoryManager()->getLocalRepository();
        
        $result = $runtimeUtils->executeWithPostAction(
            function () use ($shouldUndo, $filters, $bootstrap, $isDevMode) {
                if ($shouldUndo && !array_filter($filters)) {
                    $bootstrap->stripPatches($isDevMode);
                    
                    return true;
                }

                return $bootstrap->applyPatches($isDevMode);
            },
            function () use ($repository, $lockSanitizer) {
                $repository->write();
                $lockSanitizer->sanitize();
            }
        );

        $composer->getEventDispatcher()->dispatchScript(ScriptEvents::POST_INSTALL_CMD, $isDevMode);

        return (int)!$result;
    }
    
    private function resolveActiveFilters(InputInterface $input, $behaviourFlags)
    {
        $filters = array(
            Patch::SOURCE => $input->getOption('filter'),
            Patch::TARGETS => $input->getArgument('targets')
        );

        $hasFilers = (bool)array_filter($filters);


        
        if (!$hasFilers && $behaviourFlags['redo']) {
            $filters[Patch::SOURCE] = array('*');
        }

        return $filters;
    }
    
    private function configureEnvironmentForBehaviour(array $behaviourFlags)
    {
        $runtimeUtils = new \Vaimo\ComposerPatches\Utils\RuntimeUtils();

        $runtimeUtils->setEnvironmentValues(array(
            Environment::FORCE_RESET => (int)$behaviourFlags['force']
        ));
    }
    
    private function resolveOutputTriggers(array $filters, array $behaviourFlags)
    {
        $hasFilers = (bool)array_filter($filters);

        $isExplicit = $behaviourFlags['explicit'];
        
        if (!$hasFilers && $behaviourFlags['redo']) {
            $isExplicit = true;
        }
        
        $outputTriggerFlags = array(
            Patch::STATUS_NEW => !$hasFilers,
            Patch::STATUS_CHANGED => !$hasFilers,
            Patch::STATUS_MATCH => true,
            Patch::SOURCE => $isExplicit,
            Patch::URL => $isExplicit
        );

        return array_keys(
            array_filter($outputTriggerFlags)
        );
    }
    
    private function createListResolver(array $behaviourFlags, array $filters)
    {
        $listResolver = new ListResolvers\FilteredListResolver($filters);

        $shouldUndo = !$behaviourFlags['redo'] && $behaviourFlags['undo'];
        $isDefaultBehaviour = !$behaviourFlags['redo'] && !$behaviourFlags['undo'];
        
        if ($shouldUndo) {
            $listResolver = new ListResolvers\InvertedListResolver($listResolver);
        } else {
            $listResolver = new ListResolvers\InclusiveListResolver($listResolver);
        }

        if ($isDefaultBehaviour) {
            $listResolver = new ListResolvers\ChangesListResolver($listResolver);
        }
        
        return $listResolver;
    }
}
