<?php

namespace App\Services\Translations;

use Spatie\TranslationLoader\TranslationLoaderManager;

class Loader extends TranslationLoaderManager
{
    protected function loadPaths(array $paths, $locale, $group)
    {
        $translations = [];
        foreach ($paths as $path) {
            $full = "{$path}/{$locale}/{$group}.yml";
            if ($this->files->exists($full)) {
                $translations = array_replace_recursive(
                    $translations,
                    resolve(Yaml::class)->parse($full)
                );
            }
        }

        return array_replace_recursive($translations, parent::loadPaths($paths, $locale, $group));
    }
}
