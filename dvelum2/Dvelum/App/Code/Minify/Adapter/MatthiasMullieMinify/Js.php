<?php
/**
 *  DVelum project http://code.google.com/p/dvelum/ , https://github.com/k-samuel/dvelum , http://dvelum.net
 *  Copyright (C) 2011-2017  Kirill Yegorov
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
declare(strict_types=1);

namespace Dvelum\App\Code\Minify\Adapter\MatthiasMullieMinify;

use Dvelum\App\Code\Minify\Adapter\AdapterInterface;
use MatthiasMullie\Minify;

class Js implements AdapterInterface
{
    public function minify(string $source): string
    {
        $minifier = new Minify\JS();
        $minifier->add($source);
        return $minifier->minify();
    }

    /**
     * Combine and minify code files
     * @param array $files
     * @param string $toFile
     * @return bool
     */
    public function minifyFiles(array $files, string $toFile): bool
    {
        $minifier = new Minify\JS();
        foreach ($files as $file){
            $minifier->add($file);
        }
        $minifier->minify($toFile);
        return true;
    }
}
