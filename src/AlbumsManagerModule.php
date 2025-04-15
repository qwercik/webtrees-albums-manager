<?php

declare(strict_types=1);

namespace Komputeryk\Webtrees\AlbumsManager;

use Aura\Router\RouterContainer;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use Komputeryk\Webtrees\AlbumsManager\Controller\AlbumsPage;
use Komputeryk\Webtrees\AlbumsManager\Controller\ImportAlbumsAction;
use Komputeryk\Webtrees\AlbumsManager\Controller\ImportSettingsModal;

class AlbumsManagerModule extends AbstractModule implements ModuleCustomInterface, ModuleMenuInterface, ModuleGlobalInterface
{
    use ModuleCustomTrait;
    use ModuleMenuTrait;
    use ModuleGlobalTrait;

    public const MODULE_DIR = __DIR__ . '/../';

    public function __construct()
    {
        global $albumsManagerModule;
        $albumsManagerModule = $this;
    }

    /**
     * Bootstrap.  This function is called on *enabled* modules.
     * It is a good place to register routes and views.
     * Note that it is only called on genealogy pages - not on admin pages.
     *
     * @return void
     */
    public function boot(): void
    {
        View::registerNamespace($this->name(), static::MODULE_DIR . 'resources/views/');
        View::registerCustomView('::media-page', $this->name() . '::media-page');

        $router = Registry::container()->get(RouterContainer::class)->getMap();
        $router->get(AlbumsPage::class, '/tree/{tree}/albums', new AlbumsPage($this))->wildcard('path');
        $router->post(ImportAlbumsAction::class, '/tree/{tree}/albums', new ImportAlbumsAction($this))->wildcard('path');
        $router->get(ImportSettingsModal::class, '/tree/{tree}/albums-import-settings', new ImportSettingsModal($this));
    }

    public function title(): string
    {
        return I18N::translate('LBL_MODULE_NAME');
    }

    public function description(): string
    {
        return I18N::translate('LBL_MODULE_DESCRIPTION');
    }

    public function customModuleAuthorName(): string
    {
        return 'Eryk Andrzejewski';
    }

    public function customModuleVersion(): string
    {
        return file_get_contents(static::MODULE_DIR . 'VERSION');
    }

    /**
     * A URL that will provide the latest version of this module.
     *
     * @return string
     */
    public function customModuleLatestVersionUrl(): string
    {
        return 'https://github.com/qwercik/webtrees-albums-manager/raw/master/VERSION';
    }

    /**
     * Where to get support for this module.  Perhaps a github repository?
     *
     * @return string
     */
    public function customModuleSupportUrl(): string
    {
        return 'https://github.com/qwercik/webtrees-albums-manager';
    }

    public function customTranslations(string $language): array
    {
        $file = $this->getLangFilePath($language);
        return file_exists($file)
            ? require $file
            : require $this->getLangFilePath('en');
    }

    private function getLangFilePath(string $language): string
    {
        return static::MODULE_DIR . "/resources/lang/{$language}.php";
    }

    public function getMenu(Tree $tree): Menu|null
    {
        if (Auth::check() === false) {
            return null;
        }

        return new Menu(
            I18N::translate('LBL_MENU_ENTRY'),
            route(AlbumsPage::class, [
                'tree' => $tree->name(),
            ]),
            'menu-album',
            ['rel' => 'nofollow'],
        );
    }

    public function getIcon(string $filename): string
    {
        return file_get_contents(static::MODULE_DIR . 'resources/icons/' . $filename);
    }

    public function resourcesFolder(): string
    {
        return static::MODULE_DIR . 'resources/';
    }

    public function headContent(): string
    {
        $url = $this->assetUrl('icons/folder.svg');
        return "<style>
            .menu-album .nav-link:before {
                content: url({$url});
                width: 57px;
                height: 57px;
            }
        </style>";
    }
}