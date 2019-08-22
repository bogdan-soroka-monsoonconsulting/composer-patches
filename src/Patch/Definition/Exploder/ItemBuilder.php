<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Patch\Definition\Exploder;

class ItemBuilder
{
    public function createItem($label, $item, $updates)
    {
        return array(
            $label,
            array_replace($item, $updates)
        );
    }
    
    public function createMultiple($label, $data, $keyName)
    {
        $items = array();

        foreach ($data as $source => $subItem) {
            $items[] = $this->createItem($label, $subItem, array($keyName => $source));
        }

        return $items;
    }
}
