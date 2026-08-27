<?php

namespace justinholtweb\cleanair\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The filter builder's own CSS and JS. No build step: it's a few hundred lines of vanilla
 * JavaScript against Craft's own control panel APIs, and a plugin that needs npm installed
 * to change a stylesheet is a plugin nobody patches.
 */
class CleanAirAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = ['cleanair.css'];
        $this->js = ['cleanair.js'];

        parent::init();
    }
}
