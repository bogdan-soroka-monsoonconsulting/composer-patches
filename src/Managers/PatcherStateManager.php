<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Managers;

use Composer\Repository\WritableRepositoryInterface;
use Vaimo\ComposerPatches\Config as PluginConfig;
use Vaimo\ComposerPatches\Patch\Definition as PatchDefinition;
use Vaimo\ComposerPatches\Composer\Constraint;

class PatcherStateManager
{
    /**
     * @var \Vaimo\ComposerPatches\Utils\PackageUtils
     */
    private $packageUtils;
    
    /**
     * @var array
     */
    private $appliedPatches = array();

    public function __construct()
    {
        $this->packageUtils = new \Vaimo\ComposerPatches\Utils\PackageUtils();
    }
    
    public function extractAppliedPatchesInfo(WritableRepositoryInterface $repository)
    {
        $this->appliedPatches = array();

        foreach ($repository->getPackages() as $package) {
            $package = $this->packageUtils->getRealPackage($package);
            $name = $package->getName();
            
            if (isset($this->appliedPatches[$name])) {
                continue;
            }

            $this->appliedPatches[$name] = $this->packageUtils->resetAppliedPatches($package, true);
        }
    }

    public function restoreAppliedPatchesInfo(WritableRepositoryInterface $repository)
    {
        foreach ($repository->getPackages() as $package) {
            $name = $package->getName();
            
            if (!isset($this->appliedPatches[$name]) || !$this->appliedPatches[$name]) {
                continue;
            }

            $this->packageUtils->resetAppliedPatches($package, $this->appliedPatches[$name]);
        }
    }

    public function registerAppliedPatches(WritableRepositoryInterface $repository, array $patches)
    {
        $packages = array();

        foreach ($patches as $source => $patchInfo) {
            foreach ($patchInfo[PatchDefinition::TARGETS] as $target) {
                if (!$package = $repository->findPackage($target, Constraint::ANY)) {
                    continue;
                }
                
                /** @var \Composer\Package\CompletePackage $package */
                $package = $this->packageUtils->getRealPackage($package);
                
                $info = array_replace_recursive($package->getExtra(), array(
                    PluginConfig::APPLIED_FLAG => array(
                        $source => $patchInfo[PatchDefinition::LABEL]
                    )
                ));
                
                $package->setExtra($info);

                $packages[] = $package;
            }
        }

        foreach ($packages as $package) {
            $extra = $package->getExtra();

            ksort($extra[PluginConfig::APPLIED_FLAG]);

            $package->setExtra($extra);
        }
    }
}
