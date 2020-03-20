<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cms\Dir;
use Kirby\Cms\Page;
use Kirby\Toolkit\A;

final class Bolt
{
    /**
     * @var string
     */
    private $root;

    /**
     * @var string
     */
    private $extension;

    /**
     * @var array<string>
     */
    private $modelFiles;
    /**
     * @var Page|null
     */
    private $parent;

    /**
     * @var array<string,Page>
     */
    private static $idToPage;

    public function __construct(?Page $parent = null)
    {
        $kirby = kirby();
        $this->root = $kirby->root('content');
        if ($parent) {
            $this->parent = $parent;
            $this->root = $parent->root();
        }

        $this->extension = $kirby->contentExtension();
        if ($kirby->multilang()) {
            $this->extension = $kirby->defaultLanguage()->code() . '.' . $this->extension;
        }

        $extension = $this->extension;
        $this->modelFiles = array_map(static function ($value) use ($extension) {
            return $value . '.' . $extension;
        }, array_keys(Page::$models));
    }

    public function lookup(string $id): ?Page
    {
        if (!static::$idToPage) {
            static::$idToPage = [];
        }
        return A::get(static::$idToPage, $id);
    }

    public function pushLookup(string $id, Page $page): void
    {
        static::$idToPage[$id] = $page;
    }

    public function findByID(string $id): ?Page
    {
        $page = $this->lookup($id);
        if ($page) {
            return $page;
        }
        $draft = false;
        $treeid = null;
        $parent = $this->parent;
        $parts = explode('/', $id);

        foreach ($parts as $part) {
            if ($part === '_drafts') {
                $draft = true;
                $this->root .= '/_drafts';
                continue;
            }
            $treeid = $treeid ? $treeid . '/' . $part : $part;
            $page = $this->lookup($treeid);
            if ($page) {
                $parent = $page;
                $this->root = $page->root(); // loop
                continue;
            }

            $params = [
                'root' => null,
                'parent' => $parent,
                'slug' => $part,
                'num' => null,
                'model' => null,
            ];
            $directory = opendir($this->root);
            while ($file = readdir($directory)) {
                if (strpos($file, '.') !== false ) {
                    continue;
                }
                if ($file === $part) {
                    $params['root'] = $this->root . '/' . $file;
                } elseif (strpos($file, Dir::$numSeparator . $part) !== false) {
                    $params['root'] = $this->root . '/' . $file;
                    if (preg_match('/^([0-9]+)'.Dir::$numSeparator.'(.*)$/', $file, $match)) {
                        $params['num'] = intval($match[1]);
                        $params['slug'] = $match[2];
                    }
                }
                if ($params['root']) {
                    foreach ($this->modelFiles as $modelFile) {
                        if (file_exists($params['root'] . '/' . $modelFile)) {
                            $template = str_replace('.' . $this->extension, '', $modelFile);
                            $params['template'] = $template;
                            $params['model'] = $template;
                            break;
                        }
                    }
                    break;
                }
            }
            closedir($directory);

            if (! $params['root']) {
                return null; // not found
            }
            if ($draft === true) {
                $params['isDraft'] = $draft;
                // Only direct subpages are marked as drafts
                $draft = false;
            }
            $page = null; //kirby()->extension('pages', $this->root);
            if (! $page) {
                $page = Page::factory($params);
                $this->pushLookup($treeid, $page);
                kirby()->extend([
                    'pages' => [$this->root => $page,]
                ]);
            }
            $parent = $page;
            $this->root = $params['root']; // loop
        }
        return $page;
    }

    public static function page(string $id, ?Page $parent = null): ?Page
    {
        return (new self($parent))->findByID($id);
    }
}
