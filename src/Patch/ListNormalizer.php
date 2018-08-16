<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Patch;

class ListNormalizer
{
    /**
     * @var \Vaimo\ComposerPatches\Patch\Definition\Exploder
     */
    private $definitionExploder;

    /**
     * @var \Vaimo\ComposerPatches\Patch\Definition\Normalizer
     */
    private $definitionNormalizer;

    /**
     * @var \Vaimo\ComposerPatches\Utils\PatchListUtils
     */
    private $patchListUtils;

    /**
     * @param \Vaimo\ComposerPatches\Patch\Definition\Exploder $definitionExploder
     * @param \Vaimo\ComposerPatches\Patch\Definition\Normalizer $definitionNormalizer
     */
    public function __construct(
        \Vaimo\ComposerPatches\Patch\Definition\Exploder $definitionExploder,
        \Vaimo\ComposerPatches\Patch\Definition\Normalizer $definitionNormalizer
    ) {
        $this->definitionExploder = $definitionExploder;
        $this->definitionNormalizer = $definitionNormalizer;

        $this->patchListUtils = new \Vaimo\ComposerPatches\Utils\PatchListUtils();
    }

    public function normalize(array $list, array $config)
    {
        $result = array();

        foreach ($this->patchListUtils->getSanitizedList($list) as $target => $packagePatches) {
            $patches = array();

            foreach ($packagePatches as $patchLabel => $patchConfig) {
                $definitionItems = $this->definitionExploder->process(
                    $patchLabel,
                    $patchConfig
                );

                foreach ($definitionItems as $patchItem) {
                    list($label, $data) = $patchItem;

                    $patches[] = $this->definitionNormalizer->process(
                        $target,
                        $label,
                        $data,
                        $config
                    );
                }
            }

            $result[$target] = $patches;
        }

        return array_filter(
            array_map('array_filter', $result)
        );
    }
}
