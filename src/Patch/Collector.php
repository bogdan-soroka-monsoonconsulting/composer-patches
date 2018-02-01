<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Patch;

use Composer\Package\RootPackage;
use Vaimo\ComposerPatches\Interfaces\PatchSourceLoaderInterface;
use Vaimo\ComposerPatches\Patch\Definition as PatchDefinition;

class Collector
{
    /**
     * @var \Vaimo\ComposerPatches\Patch\ListNormalizer
     */
    private $listNormalizer;
    
    /**
     * @var \Vaimo\ComposerPatches\Interfaces\PackageConfigExtractorInterface
     */
    private $infoExtractor;
    
    /**
     * @var PatchSourceLoaderInterface[]
     */
    private $sourceLoaders;

    /**
     * @param \Vaimo\ComposerPatches\Patch\ListNormalizer $listNormalizer
     * @param \Vaimo\ComposerPatches\Interfaces\PackageConfigExtractorInterface $infoExtractor
     * @param PatchSourceLoaderInterface[] $sourceLoaders
     */
    public function __construct(
        \Vaimo\ComposerPatches\Patch\ListNormalizer $listNormalizer,
        \Vaimo\ComposerPatches\Interfaces\PackageConfigExtractorInterface $infoExtractor,
        array $sourceLoaders
    ) {
        $this->listNormalizer = $listNormalizer;
        $this->infoExtractor = $infoExtractor;
        $this->sourceLoaders = $sourceLoaders;
    }

    /**
     * @param \Composer\Package\PackageInterface[] $packages
     * @return array
     */
    public function collect(array $packages)
    {
        $patchList = array();

        foreach ($packages as $owner) {
            $packageConfig = $this->infoExtractor->getConfig($owner);

            /** @var PatchSourceLoaderInterface[] $sourceLoaders */
            $sourceLoaders = array_intersect_key(
                $this->sourceLoaders, 
                $packageConfig
            );
            
            foreach ($sourceLoaders as $key => $loader) {
                $groups = $loader->load($owner, $packageConfig[$key]);
                
                foreach ($groups as $list) {
                    $patchesByTarget = $this->listNormalizer->normalize($list);

                    if ($loader instanceof \Vaimo\ComposerPatches\Interfaces\PatchListUpdaterInterface) {
                        $patchesByTarget = $loader->update($patchesByTarget);
                    }

                    foreach ($patchesByTarget as $target => $patches) {
                        if (!isset($patchList[$target])) {
                            $patchList[$target] = array();
                        }

                        foreach ($patches as $patchDefinition) {
                            $patchList[$target][] = array_replace(
                                $patchDefinition,
                                array(
                                    PatchDefinition::OWNER => $owner->getName(),
                                    PatchDefinition::OWNER_IS_ROOT => ($owner instanceof RootPackage),
                                )
                            );
                        }
                    }                    
                }
            }
        }
        
        return $patchList;
    }
}
